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
  function ticket_append_attendance_log($name, $matric, $action, $reason, $course = 'General', $fingerprint = 'AI_AUTO', $ip = '127.0.0.1', $mac = 'UNKNOWN')
  {
    $today = date('Y-m-d');
    $timestamp = date('Y-m-d H:i:s');
    $logFile = app_storage_file("logs/{$today}.log");
    $name = trim((string)$name);
    $matric = trim((string)$matric);
    $action = strtolower(trim((string)$action));
    $reason = trim((string)$reason);
    $course = trim((string)$course);
    $fingerprint = trim((string)$fingerprint);
    $ip = trim((string)$ip);
    $mac = trim((string)$mac);

    if ($action !== 'checkin' && $action !== 'checkout') {
      return false;
    }

    $line = "{$name} | {$matric} | {$action} | {$fingerprint} | {$ip} | {$mac} | {$timestamp} | Sentinel AI | {$course} | {$reason}\n";
    return (bool)file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
  }
}
