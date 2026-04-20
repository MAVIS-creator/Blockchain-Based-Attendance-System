<?php

/**
 * Hybrid file+Supabase dual-write helper.
 *
 * Design goals:
 * - Never break existing file-based flow.
 * - Best-effort Supabase write when HYBRID_MODE=dual_write.
 * - Queue failed DB writes to outbox for later replay.
 */

require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/env_helpers.php';
require_once __DIR__ . '/request_timing.php';

if (!function_exists('hybrid_env')) {
  function hybrid_env($key, $default = null)
  {
    return app_env_value($key, $default, __DIR__ . '/.env');
  }
}

if (!function_exists('hybrid_enabled')) {
  function hybrid_enabled()
  {
    $mode = strtolower((string)hybrid_env('HYBRID_MODE', 'off'));
    return $mode === 'dual_write';
  }
}

if (!function_exists('hybrid_storage_path')) {
  function hybrid_storage_path()
  {
    // Keep one canonical resolver for both hybrid and file storage paths.
    return app_storage_path();
  }
}

if (!function_exists('hybrid_ensure_dir')) {
  function hybrid_ensure_dir($dir)
  {
    if (!is_dir($dir)) {
      @mkdir($dir, 0755, true);
    }
    return is_dir($dir);
  }
}

if (!function_exists('hybrid_outbox_append')) {
  function hybrid_outbox_append(array $record)
  {
    app_storage_init();
    $base = hybrid_storage_path();
    if (!hybrid_ensure_dir($base)) return false;
    $outbox = app_storage_file('logs/hybrid_outbox.jsonl');
    $line = json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return @file_put_contents($outbox, $line, FILE_APPEND | LOCK_EX) !== false;
  }
}

if (!function_exists('hybrid_circuit_file')) {
  function hybrid_circuit_file()
  {
    return app_storage_file('logs/supabase_circuit.json');
  }
}

if (!function_exists('hybrid_circuit_load')) {
  function hybrid_circuit_load()
  {
    $file = hybrid_circuit_file();
    if (!file_exists($file)) {
      return [
        'state' => 'closed',
        'failure_count' => 0,
        'opened_at' => null,
        'last_error' => '',
        'half_open_probe' => false,
      ];
    }

    $raw = @file_get_contents($file);
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
      return [
        'state' => 'closed',
        'failure_count' => 0,
        'opened_at' => null,
        'last_error' => '',
        'half_open_probe' => false,
      ];
    }

    return array_merge([
      'state' => 'closed',
      'failure_count' => 0,
      'opened_at' => null,
      'last_error' => '',
      'half_open_probe' => false,
    ], $decoded);
  }
}

if (!function_exists('hybrid_circuit_save')) {
  function hybrid_circuit_save(array $state)
  {
    $payload = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    @file_put_contents(hybrid_circuit_file(), $payload, LOCK_EX);
  }
}

if (!function_exists('hybrid_circuit_before_request')) {
  function hybrid_circuit_before_request(&$reason = null)
  {
    $state = hybrid_circuit_load();
    $cooldown = (int)hybrid_env('SUPABASE_CIRCUIT_COOLDOWN_SEC', 30);
    $cooldown = max(5, min(600, $cooldown));

    if (($state['state'] ?? 'closed') !== 'open') {
      return true;
    }

    $openedAt = strtotime((string)($state['opened_at'] ?? '')) ?: 0;
    $now = time();
    if ($openedAt > 0 && ($now - $openedAt) >= $cooldown) {
      $state['state'] = 'half_open';
      $state['half_open_probe'] = true;
      hybrid_circuit_save($state);
      return true;
    }

    $reason = 'circuit_open';
    return false;
  }
}

if (!function_exists('hybrid_circuit_after_request')) {
  function hybrid_circuit_after_request($ok, $error = '')
  {
    $state = hybrid_circuit_load();
    $threshold = (int)hybrid_env('SUPABASE_CIRCUIT_FAILURE_THRESHOLD', 3);
    $threshold = max(1, min(20, $threshold));

    if ($ok) {
      $state['state'] = 'closed';
      $state['failure_count'] = 0;
      $state['opened_at'] = null;
      $state['last_error'] = '';
      $state['half_open_probe'] = false;
      hybrid_circuit_save($state);
      return;
    }

    $stateName = (string)($state['state'] ?? 'closed');
    $state['failure_count'] = (int)($state['failure_count'] ?? 0) + 1;
    $state['last_error'] = (string)$error;

    if ($stateName === 'half_open') {
      $state['state'] = 'open';
      $state['opened_at'] = date('c');
      $state['half_open_probe'] = false;
      hybrid_circuit_save($state);
      return;
    }

    if ($state['failure_count'] >= $threshold) {
      $state['state'] = 'open';
      $state['opened_at'] = date('c');
      $state['half_open_probe'] = false;
    }

    hybrid_circuit_save($state);
  }
}

if (!function_exists('hybrid_supabase_insert')) {
  function hybrid_supabase_insert($table, array $payload, &$err = null)
  {
    $resp = null;
    return hybrid_supabase_request('POST', $table, [], $payload, $resp, $err, ['Prefer: return=minimal']);
  }
}

if (!function_exists('hybrid_supabase_request')) {
  function hybrid_supabase_request($method, $table, array $query = [], $body = null, &$respBody = null, &$err = null, array $extraHeaders = [])
  {
    $startedAt = microtime(true);
    $url = rtrim((string)hybrid_env('SUPABASE_URL', ''), '/');
    $key = (string)hybrid_env('SUPABASE_SERVICE_ROLE_KEY', '');
    if ($url === '' || $key === '') {
      $err = 'missing_supabase_config';
      request_timing_span('supabase_request', $startedAt, ['table' => $table, 'method' => $method, 'ok' => false, 'error' => $err]);
      return false;
    }

    $gateReason = null;
    if (!hybrid_circuit_before_request($gateReason)) {
      $err = $gateReason ?: 'circuit_open';
      request_timing_span('supabase_request', $startedAt, ['table' => $table, 'method' => $method, 'ok' => false, 'error' => $err]);
      return false;
    }

    $endpoint = $url . '/rest/v1/' . rawurlencode($table);
    if (!empty($query)) {
      $endpoint .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $ch = curl_init($endpoint);
    if ($ch === false) {
      $err = 'curl_init_failed';
      request_timing_span('supabase_request', $startedAt, ['table' => $table, 'method' => $method, 'ok' => false, 'error' => $err]);
      return false;
    }

    $headers = [
      'apikey: ' . $key,
      'Authorization: Bearer ' . $key,
      'Content-Type: application/json',
    ];
    foreach ($extraHeaders as $h) $headers[] = $h;

    $method = strtoupper((string)$method);
    $opts = [
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => max(2, min(15, (int)hybrid_env('SUPABASE_TIMEOUT_SEC', 5))),
      CURLOPT_CONNECTTIMEOUT => max(1, min(10, (int)hybrid_env('SUPABASE_CONNECT_TIMEOUT_SEC', 2))),
      CURLOPT_HTTPHEADER => $headers,
    ];

    if ($body !== null) {
      $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $opts);

    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $curlErr) {
      $err = 'curl_error:' . $curlErr;
      hybrid_circuit_after_request(false, $err);
      request_timing_span('supabase_request', $startedAt, ['table' => $table, 'method' => $method, 'ok' => false, 'error' => $err]);
      return false;
    }
    if ($http < 200 || $http >= 300) {
      $err = 'http_' . $http . ':' . substr((string)$resp, 0, 400);
      hybrid_circuit_after_request(false, $err);
      request_timing_span('supabase_request', $startedAt, ['table' => $table, 'method' => $method, 'ok' => false, 'http' => $http]);
      return false;
    }

    $respBody = $resp;
    hybrid_circuit_after_request(true, '');
    request_timing_span('supabase_request', $startedAt, ['table' => $table, 'method' => $method, 'ok' => true, 'http' => $http]);
    return true;
  }
}

if (!function_exists('hybrid_supabase_select')) {
  function hybrid_supabase_select($table, array $query = [], &$rows = null, &$err = null)
  {
    $query = array_merge(['select' => '*'], $query);
    $resp = null;
    $ok = hybrid_supabase_request('GET', $table, $query, null, $resp, $err);
    if (!$ok) return false;

    $decoded = json_decode((string)$resp, true);
    if (!is_array($decoded)) {
      $err = 'invalid_json_response';
      return false;
    }
    $rows = $decoded;
    return true;
  }
}

if (!function_exists('hybrid_supabase_update')) {
  function hybrid_supabase_update($table, array $filters, array $payload, &$err = null)
  {
    $resp = null;
    return hybrid_supabase_request('PATCH', $table, $filters, $payload, $resp, $err, ['Prefer: return=minimal']);
  }
}

if (!function_exists('hybrid_supabase_delete')) {
  function hybrid_supabase_delete($table, array $filters, &$err = null)
  {
    $resp = null;
    return hybrid_supabase_request('DELETE', $table, $filters, null, $resp, $err, ['Prefer: return=minimal']);
  }
}

if (!function_exists('hybrid_dual_write')) {
  function hybrid_dual_write($entity, $table, array $payload)
  {
    if (!hybrid_enabled()) return true;

    $err = null;
    $ok = hybrid_supabase_insert($table, $payload, $err);
    if ($ok) return true;

    hybrid_outbox_append([
      'at' => date('c'),
      'entity' => $entity,
      'table' => $table,
      'payload' => $payload,
      'error' => (string)$err,
    ]);

    return false;
  }
}

if (!function_exists('hybrid_replay_outbox')) {
  function hybrid_replay_outbox($max = 200)
  {
    app_storage_init();
    $base = hybrid_storage_path();
    $outbox = app_storage_file('logs/hybrid_outbox.jsonl');
    if (!file_exists($outbox)) {
      return ['ok' => true, 'processed' => 0, 'replayed' => 0, 'remaining' => 0, 'message' => 'outbox_not_found'];
    }

    $lines = file($outbox, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) $lines = [];

    $processed = 0;
    $replayed = 0;
    $remainingLines = [];

    foreach ($lines as $line) {
      $item = json_decode($line, true);
      if (!is_array($item) || empty($item['table']) || !isset($item['payload']) || !is_array($item['payload'])) {
        continue;
      }

      if ($processed >= $max) {
        $remainingLines[] = $line;
        continue;
      }

      $processed++;
      $err = null;
      $ok = hybrid_supabase_insert((string)$item['table'], (array)$item['payload'], $err);
      if ($ok) {
        $replayed++;
      } else {
        $item['replay_error'] = (string)$err;
        $item['replay_attempt_at'] = date('c');
        $remainingLines[] = json_encode($item, JSON_UNESCAPED_SLASHES);
      }
    }

    $payload = '';
    if (!empty($remainingLines)) {
      $payload = implode(PHP_EOL, $remainingLines) . PHP_EOL;
    }
    @file_put_contents($outbox, $payload, LOCK_EX);

    return [
      'ok' => true,
      'processed' => $processed,
      'replayed' => $replayed,
      'remaining' => count($remainingLines),
    ];
  }
}
