<?php

require_once __DIR__ . '/runtime_storage.php';

// APCu polyfills for development/linting: these allow safe use of apcu_* functions
// in code even when APCu extension is not installed
if (!function_exists('apcu_enabled')) {
  function apcu_enabled()
  {
    return false;
  }
}

if (!function_exists('apcu_fetch')) {
  function apcu_fetch($key, &$success = null)
  {
    $success = false;
    return null;
  }
}

if (!function_exists('apcu_store')) {
  function apcu_store($key, $var, $ttl = 0)
  {
    return false;
  }
}

if (!function_exists('admin_cache_enabled')) {
  function admin_cache_enabled()
  {
    if (!function_exists('apcu_fetch') || !filter_var((string)ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)) {
      return @apcu_enabled();
    }
    return true;
  }
}

if (!function_exists('admin_cache_status')) {
  function admin_cache_status()
  {
    return [
      'apcu_available' => function_exists('apcu_fetch'),
      'apcu_enabled' => admin_cache_enabled(),
      'sapi' => PHP_SAPI,
    ];
  }
}

if (!function_exists('admin_cache_remember')) {
  function admin_cache_remember($key, $ttl, callable $resolver)
  {
    static $requestCache = [];
    $ttl = max(1, (int)$ttl);
    $cacheKey = (string)$key;

    if (array_key_exists($cacheKey, $requestCache)) {
      return $requestCache[$cacheKey];
    }

    if (admin_cache_enabled() && function_exists('apcu_fetch')) {
      $success = false;
      $value = @apcu_fetch($cacheKey, $success);
      if ($success) {
        $requestCache[$cacheKey] = $value;
        return $value;
      }
    }

    $value = $resolver();
    $requestCache[$cacheKey] = $value;

    if (admin_cache_enabled() && function_exists('apcu_store')) {
      @apcu_store($cacheKey, $value, $ttl);
    }

    return $value;
  }
}

if (!function_exists('admin_cached_json_file')) {
  function admin_cached_json_file($cachePrefix, $path, $default = [], $ttl = 15)
  {
    $mtime = @filemtime($path) ?: 0;
    $size = @filesize($path) ?: 0;
    $key = $cachePrefix . ':' . md5($path . '|' . $mtime . '|' . $size);

    return admin_cache_remember($key, $ttl, function () use ($path, $default) {
      if (!file_exists($path)) {
        return $default;
      }
      $decoded = json_decode((string)file_get_contents($path), true);
      return is_array($decoded) ? $decoded : $default;
    });
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

      $lines = @file($path, FILE_IGNORE_NEW_LINES);
      return is_array($lines) ? $lines : [];
    });
  }
}

if (!function_exists('admin_support_ticket_count')) {
  function admin_support_ticket_count($ttl = 15)
  {
    $ticketsFile = admin_storage_migrate_file('support_tickets.json', app_storage_file('support_tickets.json'));
    $tickets = admin_cached_json_file('support_tickets', $ticketsFile, [], $ttl);
    $count = 0;
    foreach ($tickets as $ticket) {
      if (!($ticket['resolved'] ?? false)) {
        $count++;
      }
    }
    return $count;
  }
}

if (!function_exists('admin_fingerprint_count_cached')) {
  function admin_fingerprint_count_cached($ttl = 15)
  {
    $fingerprintFile = admin_storage_migrate_file('fingerprints.json', app_storage_file('fingerprints.json'));
    $fingerprints = admin_cached_json_file('fingerprints', $fingerprintFile, [], $ttl);
    return is_array($fingerprints) ? count($fingerprints) : 0;
  }
}

if (!function_exists('admin_active_course_name_cached')) {
  function admin_active_course_name_cached($ttl = 15)
  {
    $activeFile = admin_course_storage_migrate_file('active_course.json');
    $active = admin_cached_json_file('active_course', $activeFile, [], $ttl);
    return trim((string)($active['course'] ?? '')) ?: 'General';
  }
}

if (!function_exists('admin_dashboard_log_summary')) {
  function admin_dashboard_log_summary($ttl = 20, $recentDays = 2)
  {
    $logDir = app_storage_file('logs');
    $today = new DateTime();
    $recentThreshold = (clone $today)->modify('-' . max(0, (int)$recentDays) . ' days');

    $manifest = [];
    if (is_dir($logDir)) {
      $it = new DirectoryIterator($logDir);
      foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile()) continue;
        $name = $fileInfo->getFilename();
        if (!preg_match('/\.log$/', $name)) continue;
        $manifest[] = $name . '|' . $fileInfo->getMTime() . '|' . $fileInfo->getSize();
      }
    }
    sort($manifest);
    $cacheKey = 'dashboard_log_summary:' . md5(implode(';', $manifest));

    return admin_cache_remember($cacheKey, $ttl, function () use ($logDir, $recentThreshold) {
      $dailyCounts = [];
      $courseCounts = [];
      $failedCounts = [];
      $uniqueStudents = [];
      $recentLogs = [];
      $attendanceFileCount = 0;
      $failedFileCount = 0;
      $macRegex = '/([0-9a-f]{2}[:\\\\-]){5}[0-9a-f]{2}/i';

      if (!is_dir($logDir)) {
        return [
          'dailyCounts' => [],
          'courseCounts' => [],
          'failedCounts' => [],
          'uniqueStudentCount' => 0,
          'recentLogs' => [],
          'attendanceFileCount' => 0,
          'failedFileCount' => 0,
        ];
      }

      $it = new DirectoryIterator($logDir);
      foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile()) continue;
        $name = $fileInfo->getFilename();
        $path = $fileInfo->getPathname();

        if (preg_match('/(\d{4}-\d{2}-\d{2})_failed_attempts\.log$/', $name, $match)) {
          $failedFileCount++;
          $failedCounts[$match[1]] = count(@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
          continue;
        }

        if (!preg_match('/(\d{4}-\d{2}-\d{2})\.log$/', $name, $match)) {
          continue;
        }

        $attendanceFileCount++;
        $date = $match[1];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $dailyCounts[$date] = count($lines);

        foreach ($lines as $line) {
          $parts = array_map('trim', explode('|', $line));
          if (isset($parts[1]) && $parts[1] !== '') {
            $uniqueStudents[$parts[1]] = true;
          }

          $course = 'General';
          if (isset($parts[5]) && preg_match($macRegex, $parts[5])) {
            $course = isset($parts[8]) ? trim((string)$parts[8]) : 'General';
          } else {
            $course = isset($parts[7]) ? trim((string)$parts[7]) : 'General';
          }
          $course = $course !== '' ? $course : 'General';
          $courseCounts[$course] = ($courseCounts[$course] ?? 0) + 1;
        }

        try {
          $fileDate = new DateTime($date);
          if ($fileDate >= $recentThreshold) {
            foreach (array_reverse($lines) as $recentLine) {
              $recentLogs[] = $recentLine;
            }
          }
        } catch (Throwable $e) {
        }
      }

      ksort($dailyCounts);
      ksort($failedCounts);
      arsort($courseCounts);

      return [
        'dailyCounts' => $dailyCounts,
        'courseCounts' => $courseCounts,
        'failedCounts' => $failedCounts,
        'uniqueStudentCount' => count($uniqueStudents),
        'recentLogs' => array_slice($recentLogs, 0, 20),
        'attendanceFileCount' => $attendanceFileCount,
        'failedFileCount' => $failedFileCount,
      ];
    });
  }
}

if (!function_exists('admin_log_groups_summary')) {
  function admin_log_groups_summary($ttl = 20)
  {
    $logDir = app_storage_file('logs');
    $manifest = [];
    if (is_dir($logDir)) {
      $it = new DirectoryIterator($logDir);
      foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile()) continue;
        $name = $fileInfo->getFilename();
        if (preg_match('/\.(php|css)$/i', $name)) continue;
        $manifest[] = $name . '|' . $fileInfo->getMTime() . '|' . $fileInfo->getSize();
      }
    }
    sort($manifest);
    $cacheKey = 'log_groups_summary:' . md5(implode(';', $manifest));

    return admin_cache_remember($cacheKey, $ttl, function () use ($logDir) {
      $groups = [];
      if (!is_dir($logDir)) {
        return $groups;
      }

      $it = new DirectoryIterator($logDir);
      foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $fn = $f->getFilename();
        if (preg_match('/\.(php|css)$/i', $fn)) continue;
        $lines = @file($logDir . DIRECTORY_SEPARATOR . $fn, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $ln) {
          $parts = array_map('trim', explode('|', $ln));
          $parts = array_pad($parts, 10, '');
          $dt = $parts[6] ?? '';
          $dateOnly = null;
          if ($dt && preg_match('/(20\d{2}-\d{2}-\d{2})/', $dt, $md)) $dateOnly = $md[1];
          if (!$dateOnly && preg_match('/(20\d{2}-\d{2}-\d{2})/', $fn, $mf)) $dateOnly = $mf[1];
          if (!$dateOnly) $dateOnly = date('Y-m-d');
          $course = ($parts[8] ?? '') !== '' ? $parts[8] : 'Unknown';
          $key = $dateOnly . '|' . $course;
          if (!isset($groups[$key])) $groups[$key] = ['date' => $dateOnly, 'course' => $course, 'entries' => 0, 'failed' => 0, 'files' => []];
          $groups[$key]['entries']++;
          $txt = strtolower($ln);
          if (strpos($txt, 'failed') !== false || strpos($txt, 'invalid') !== false) $groups[$key]['failed']++;
          if (!in_array($fn, $groups[$key]['files'], true)) $groups[$key]['files'][] = $fn;
        }
      }

      uasort($groups, function ($a, $b) {
        return strcmp($b['date'] . $b['course'], $a['date'] . $a['course']);
      });

      return $groups;
    });
  }
}
