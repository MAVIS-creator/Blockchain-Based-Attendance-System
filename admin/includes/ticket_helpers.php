<?php

require_once __DIR__ . '/../../storage_helpers.php';
require_once __DIR__ . '/../runtime_storage.php';

if (!function_exists('ticket_support_file')) {
  function ticket_support_file()
  {
    return admin_storage_migrate_file('support_tickets.json', app_storage_file('support_tickets.json'));
  }
}

if (!function_exists('ticket_read_all')) {
  function ticket_read_all()
  {
    $file = ticket_support_file();
    $raw = @file_get_contents($file);
    $tickets = json_decode((string)$raw, true);
    return is_array($tickets) ? $tickets : [];
  }
}

if (!function_exists('ticket_resolve_atomic')) {
  function ticket_resolve_atomic($resolveTime, array $extraUpdates = [])
  {
    $file = ticket_support_file();
    $resolveTime = (string)$resolveTime;
    if ($resolveTime === '') {
      return false;
    }

    $fp = fopen($file, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) {
      fclose($fp);
      return false;
    }

    rewind($fp);
    $raw = stream_get_contents($fp);
    $tickets = json_decode($raw ?: '[]', true);
    if (!is_array($tickets)) $tickets = [];

    $updated = false;
    foreach ($tickets as &$ticket) {
      if ((string)($ticket['timestamp'] ?? '') !== $resolveTime) {
        continue;
      }
      $ticket['resolved'] = true;
      foreach ($extraUpdates as $k => $v) {
        $ticket[$k] = $v;
      }
      $updated = true;
      break;
    }
    unset($ticket);

    if ($updated) {
      rewind($fp);
      ftruncate($fp, 0);
      fwrite($fp, json_encode($tickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      fflush($fp);
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    return $updated;
  }
}

if (!function_exists('ticket_update_atomic')) {
  function ticket_update_atomic($ticketTimestamp, array $updates = [], $markResolved = false)
  {
    $file = ticket_support_file();
    $ticketTimestamp = (string)$ticketTimestamp;
    if ($ticketTimestamp === '') {
      return false;
    }

    $fp = fopen($file, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) {
      fclose($fp);
      return false;
    }

    rewind($fp);
    $raw = stream_get_contents($fp);
    $tickets = json_decode($raw ?: '[]', true);
    if (!is_array($tickets)) $tickets = [];

    $updated = false;
    foreach ($tickets as &$ticket) {
      if ((string)($ticket['timestamp'] ?? '') !== $ticketTimestamp) {
        continue;
      }

      foreach ($updates as $k => $v) {
        $ticket[$k] = $v;
      }
      if ($markResolved) {
        $ticket['resolved'] = true;
      }
      $updated = true;
      break;
    }
    unset($ticket);

    if ($updated) {
      rewind($fp);
      ftruncate($fp, 0);
      fwrite($fp, json_encode($tickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      fflush($fp);
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    return $updated;
  }
}

if (!function_exists('ticket_append_attendance_log')) {
  /**
   * Guarded AI/manual append shares the ordinary submit duplicate-index lock.
   * An LLM never bypasses the duplicate, checkin-before-checkout or hash-chain
   * requirements. This is still the legacy file backend pending SQL cutover.
   */
  function ticket_append_attendance_log($name, $matric, $action, $reason, $course = 'General', $fingerprint = 'AI_AUTO', $ip = '127.0.0.1', $mac = 'UNKNOWN')
  {
    require_once __DIR__ . '/../../src/AttendanceChainStore.php';
    $clean = static fn($s) => trim(preg_replace('/[|\\r\\n]+/', ' ', (string)$s));
    $name = $clean($name);
    $matric = $clean($matric);
    $action = strtolower($clean($action));
    $reason = $clean($reason);
    $course = $clean($course);
    $fingerprint = $clean($fingerprint);
    $ip = $clean($ip);
    $mac = $clean($mac);
    if ($name === '' || $course === '' || !preg_match('/^\\d{6,20}$/', $matric) ||
        !in_array($action, ['checkin', 'checkout'], true)) {
      return false;
    }
    $today = date('Y-m-d');
    $time = date('Y-m-d H:i:s');
    $logDir = app_storage_file('logs');
    if (!is_dir($logDir) && !@mkdir($logDir, 0770, true) && !is_dir($logDir)) {
      return false;
    }
    $logFile = $logDir . '/' . $today . '.log';
    $indexFile = $logDir . '/attendance_index_' . $today . '.json';
    $fp = fopen($indexFile, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    try {
      rewind($fp);
      $raw = stream_get_contents($fp);
      $index = json_decode($raw ?: '{}', true);
      if (!is_array($index)) $index = [];
      foreach (['seen_matric', 'seen_device', 'seen_token', 'has_checkin'] as $key) {
        if (!isset($index[$key]) || !is_array($index[$key])) $index[$key] = [];
      }
      $courseKey = strtolower($course);
      $matricKey = $matric . '|' . $action . '|' . $courseKey;
      $checkinKey = $matric . '|' . $courseKey;
      $clientToken = '';
      if (str_contains($fingerprint, '_')) {
        $parts = explode('_', $fingerprint);
        $tail = end($parts);
        if (is_string($tail) && preg_match('/^[0-9a-f-]{36}$/i', $tail)) $clientToken = $tail;
      }
      $tokenKey = $clientToken !== '' ? ('tok:' . $clientToken . '|' . $action . '|' . $courseKey) : '';
      // Scan the authoritative daily file in case a legacy/manual path missed
      // updating the JSON index. The index lock also serializes ordinary submit.
      $hasCheckin = false;
      foreach (file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $p = array_map('trim', explode('|', $line));
        if (count($p) < 9 || strtolower($p[8]) !== $courseKey) continue;
        if ($p[1] === $matric && strtolower($p[2]) === 'checkin') $hasCheckin = true;
        if ($p[1] === $matric && strtolower($p[2]) === $action) return false;
        if ($tokenKey !== '' && $p[1] !== $matric && strtolower($p[2]) === $action &&
            str_ends_with($p[3], '_' . $clientToken)) return false;
      }
      if ($action === 'checkout' && !$hasCheckin) return false;
      if (isset($index['seen_matric'][$matricKey]) || ($tokenKey !== '' && isset($index['seen_token'][$tokenKey]))) {
        return false;
      }
      $entry = "{$name} | {$matric} | {$action} | {$fingerprint} | {$ip} | {$mac} | {$time} | Sentinel AI | {$course} | {$reason}\\n";
      if (file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX) !== strlen($entry)) {
        return false;
      }
      try {
        $result = attendance_chain_append_atomic(app_storage_file('secure_logs/attendance_chain.json'), [
          'timestamp' => $time, 'name' => $name, 'matric' => $matric,
          'action' => $action, 'fingerprint' => $fingerprint, 'ip' => $ip,
          'userAgent' => 'Sentinel AI', 'course' => $course,
        ]);
      } catch (Throwable $e) {
        error_log('Guarded AI chain append failed: ' . $e->getMessage());
        return false;
      }
      $index['seen_matric'][$matricKey] = 1;
      if ($action === 'checkin') $index['has_checkin'][$checkinKey] = 1;
      if ($tokenKey !== '') $index['seen_token'][$tokenKey] = 1;
      $payload = json_encode($index, JSON_UNESCAPED_SLASHES);
      rewind($fp);
      if (ftruncate($fp, 0) === false || fwrite($fp, $payload) !== strlen($payload) || !fflush($fp)) {
        error_log('Guarded AI duplicate index update failed');
        return false;
      }
      if ((string)getenv('BBAS_ASYNC_POST_SUBMIT') === '1') {
        require_once __DIR__ . '/../../src/PostSubmitQueue.php';
        try {
          bbas_post_submit_enqueue([
            'day'=>$today,'name'=>$name,'matric'=>$matric,'action'=>$action,
            'fingerprint'=>$fingerprint,'ip'=>$ip,'mac'=>$mac,
            'user_agent'=>'Sentinel AI','course'=>$course,'reason'=>$reason,
            'chain_hash'=>(string)($result['block']['hash'] ?? ''),'auto_send'=>[]
          ]);
        } catch (Throwable $e) {
          error_log('AI attendance post-submit enqueue failed: ' . $e->getMessage());
        }
      }
      return true;
    } finally {
      flock($fp, LOCK_UN); fclose($fp);
    }
  }
}
