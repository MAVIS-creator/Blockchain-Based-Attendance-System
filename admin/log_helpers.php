<?php

require_once __DIR__ . '/cache_helpers.php';

if (!function_exists('admin_parse_attendance_line')) {
  function admin_parse_attendance_line($line)
  {
    $parts = array_map('trim', explode('|', (string)$line));
    if (count($parts) < 6) {
      return null;
    }

    $macRegex = '/([0-9a-f]{2}[:\\-]){5}[0-9a-f]{2}/i';
    $isAiStructuredRow = count($parts) >= 10 && in_array(strtolower((string)($parts[7] ?? '')), ['ai ticket processor', 'sentinel ai'], true);
    $hasTimestampAtIndex6 = isset($parts[6]) && preg_match('/^\d{4}-\d{2}-\d{2}(?:\s+|T)\d{2}:\d{2}:\d{2}/', (string)$parts[6]);
    $newFormat = count($parts) >= 10 && (
      $isAiStructuredRow
      || (isset($parts[5]) && preg_match($macRegex, (string)$parts[5]))
      || $hasTimestampAtIndex6
    );

    if ($newFormat) {
      return [
        'name' => $parts[0] ?? '',
        'matric' => $parts[1] ?? '',
        'action' => $parts[2] ?? '',
        'fingerprint' => $parts[3] ?? '',
        'ip' => $parts[4] ?? '',
        'mac' => $parts[5] ?? 'UNKNOWN',
        'timestamp' => $parts[6] ?? '',
        'device' => $parts[7] ?? '',
        'course' => $parts[8] ?? 'General',
        'reason' => $parts[9] ?? '-',
      ];
    }

    return [
      'name' => $parts[0] ?? '',
      'matric' => $parts[1] ?? '',
      'action' => $parts[2] ?? '',
      'fingerprint' => $parts[3] ?? '',
      'ip' => $parts[4] ?? '',
      'mac' => 'UNKNOWN',
      'timestamp' => $parts[5] ?? '',
      'device' => $parts[6] ?? '',
      'course' => $parts[7] ?? 'General',
      'reason' => $parts[8] ?? '-',
    ];
  }
}

if (!function_exists('admin_cached_file_lines')) {
  function admin_cached_file_lines($cachePrefix, $path, $ttl = 15)
  {
    $mtime = @filemtime($path) ?: 0;
    $size = @filesize($path) ?: 0;
    $key = $cachePrefix . ':' . md5($path . '|' . $mtime . '|' . $size);

    return admin_cache_remember($key, $ttl, function () use ($path) {
      if (!file_exists($path)) {
        return [];
      }
      return @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    });
  }
}

if (!function_exists('admin_attendance_entries_for_date_parsed')) {
  function admin_attendance_entries_for_date_parsed($logFile, $ttl = 15)
  {
    $mtime = @filemtime($logFile) ?: 0;
    $size = @filesize($logFile) ?: 0;
    $key = 'attendance_parsed:' . md5($logFile . '|' . $mtime . '|' . $size);

    return admin_cache_remember($key, $ttl, function () use ($logFile, $ttl) {
      $entries = [];
      foreach (admin_cached_file_lines('attendance_lines', $logFile, $ttl) as $line) {
        $entry = admin_parse_attendance_line($line);
        if ($entry !== null) {
          $entries[] = $entry;
        }
      }
      return $entries;
    });
  }
}

if (!function_exists('admin_failed_attempt_entries_for_date')) {
  function admin_failed_attempt_entries_for_date($logFile, $ttl = 15)
  {
    $mtime = @filemtime($logFile) ?: 0;
    $size = @filesize($logFile) ?: 0;
    $key = 'failed_attempts_parsed:' . md5($logFile . '|' . $mtime . '|' . $size);

    return admin_cache_remember($key, $ttl, function () use ($logFile, $ttl) {
      $entries = [];
      foreach (admin_cached_file_lines('failed_attempt_lines', $logFile, $ttl) as $line) {
        $entry = admin_parse_attendance_line($line);
        if ($entry !== null) {
          $entries[] = $entry;
        }
      }
      return $entries;
    });
  }
}
