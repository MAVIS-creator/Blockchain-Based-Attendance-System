<?php

/**
 * Hybrid file+Supabase dual-write helper.
 *
 * Design goals:
 * - Never break existing file-based flow.
 * - Best-effort Supabase write when HYBRID_MODE=dual_write.
 * - Queue failed DB writes to outbox for later replay.
 */

if (!function_exists('hybrid_env')) {
  function hybrid_env($key, $default = null)
  {
    $val = getenv($key);
    if ($val !== false && $val !== '') return $val;

    static $envCache = null;
    if ($envCache === null) {
      $envCache = [];
      $envPath = __DIR__ . '/.env';
      if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
          $line = trim($line);
          if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
          list($k, $v) = explode('=', $line, 2);
          $envCache[trim($k)] = trim(trim($v), "\"'");
        }
      }
    }

    return array_key_exists($key, $envCache) ? $envCache[$key] : $default;
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
    $configured = trim((string)hybrid_env('STORAGE_PATH', ''));
    if ($configured !== '') {
      return rtrim($configured, '/\\');
    }
    return __DIR__ . '/admin/logs';
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
    $base = hybrid_storage_path();
    if (!hybrid_ensure_dir($base)) return false;
    $outbox = $base . '/hybrid_outbox.jsonl';
    $line = json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return @file_put_contents($outbox, $line, FILE_APPEND | LOCK_EX) !== false;
  }
}

if (!function_exists('hybrid_supabase_insert')) {
  function hybrid_supabase_insert($table, array $payload, &$err = null)
  {
    $url = rtrim((string)hybrid_env('SUPABASE_URL', ''), '/');
    $key = (string)hybrid_env('SUPABASE_SERVICE_ROLE_KEY', '');
    if ($url === '' || $key === '') {
      $err = 'missing_supabase_config';
      return false;
    }

    $endpoint = $url . '/rest/v1/' . rawurlencode($table);
    $ch = curl_init($endpoint);
    if ($ch === false) {
      $err = 'curl_init_failed';
      return false;
    }

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 4,
      CURLOPT_CONNECTTIMEOUT => 2,
      CURLOPT_HTTPHEADER => [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
        'Prefer: return=minimal'
      ],
      CURLOPT_POSTFIELDS => $body,
    ]);

    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $curlErr) {
      $err = 'curl_error:' . $curlErr;
      return false;
    }
    if ($http < 200 || $http >= 300) {
      $err = 'http_' . $http . ':' . substr((string)$resp, 0, 300);
      return false;
    }

    return true;
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
