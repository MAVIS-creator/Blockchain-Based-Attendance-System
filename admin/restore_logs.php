<?php
require_once __DIR__ . '/session_bootstrap.php';
if (empty($_SESSION['admin_logged_in'])) {
  header('HTTP/1.1 403 Forbidden');
  echo json_encode(['ok' => false, 'message' => 'Not authorized']);
  exit;
}

if (empty($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok' => false, 'message' => 'No file uploaded']);
  exit;
}

// CSRF protection
$csrfPath = __DIR__ . '/includes/csrf.php';
if (file_exists($csrfPath)) require_once $csrfPath;
if (function_exists('csrf_check_request') && !csrf_check_request()) {
  header('HTTP/1.1 403 Forbidden');
  echo json_encode(['ok' => false, 'message' => 'csrf_failed']);
  exit;
}

require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/../hybrid_dual_write.php';
require_once __DIR__ . '/log_helpers.php';
app_storage_init();

$tmp = $_FILES['backup']['tmp_name'];
$name = basename($_FILES['backup']['name']);

$zip = new ZipArchive();
if ($zip->open($tmp) !== TRUE) {
  echo json_encode(['ok' => false, 'message' => 'Invalid zip']);
  exit;
}

$admin = __DIR__;
$logs = app_storage_file('logs');
$secure = app_storage_file('secure_logs');
$fingerprints = admin_storage_migrate_file('fingerprints.json', app_storage_file('fingerprints.json'));

// extract files into a temp dir then move
$tempDir = sys_get_temp_dir() . '/restore_' . time();
@mkdir($tempDir, 0755, true);
$zip->extractTo($tempDir);
$zip->close();

// move logs
if (is_dir($tempDir . '/logs')) {
  $it = new DirectoryIterator($tempDir . '/logs');
  foreach ($it as $f) {
    if (!$f->isDot()) {
      copy($f->getPathname(), $logs . '/' . $f->getFilename());
    }
  }
}

// move secure_logs attendance_chain.json
if (file_exists($tempDir . '/secure_logs/attendance_chain.json')) {
  copy($tempDir . '/secure_logs/attendance_chain.json', $secure . '/attendance_chain.json');
}

// restore fingerprints
if (file_exists($tempDir . '/admin_fingerprints.json')) {
  copy($tempDir . '/admin_fingerprints.json', $fingerprints);
}

// cleanup
// (we won't recursively delete tempDir to avoid permission issues; rely on system temp cleanup)

$supabaseSync = [
  'attempted' => false,
  'cleared' => [],
  'inserted' => ['attendance_logs' => 0, 'request_timings' => 0],
  'errors' => [],
];

if (function_exists('hybrid_enabled') && function_exists('hybrid_supabase_delete') && function_exists('hybrid_supabase_insert') && hybrid_enabled()) {
  $supabaseSync['attempted'] = true;

  // Keep remote source aligned with restored local snapshot.
  foreach (['attendance_logs', 'request_timings'] as $table) {
    $err = null;
    if (hybrid_supabase_delete($table, ['id' => 'gt.0'], $err)) {
      $supabaseSync['cleared'][] = $table;
    } else {
      $supabaseSync['errors'][] = ['table' => $table, 'stage' => 'clear', 'error' => (string)$err];
    }
  }

  $restoredLogsDir = $tempDir . '/logs';
  if (is_dir($restoredLogsDir)) {
    foreach (glob($restoredLogsDir . '/*.log') ?: [] as $logFile) {
      // Attendance day logs are date-based (YYYY-MM-DD.log).
      if (!preg_match('/\\d{4}-\\d{2}-\\d{2}\\.log$/', (string)basename($logFile))) {
        continue;
      }

      $entries = admin_attendance_entries_for_date_parsed($logFile, 0);
      if (!is_array($entries)) continue;

      foreach ($entries as $entry) {
        if (!is_array($entry)) continue;
        $rawTs = (string)($entry['timestamp'] ?? '');
        $ts = strtotime($rawTs);
        $payload = [
          'timestamp' => $ts ? gmdate('c', $ts) : gmdate('c'),
          'name' => (string)($entry['name'] ?? ''),
          'matric' => (string)($entry['matric'] ?? ''),
          'action' => strtolower((string)($entry['action'] ?? 'checkin')),
          'fingerprint' => (string)($entry['fingerprint'] ?? ''),
          'ip' => (string)($entry['ip'] ?? ''),
          'mac' => (string)($entry['mac'] ?? 'UNKNOWN'),
          'user_agent' => (string)($entry['device'] ?? ''),
          'course' => (string)($entry['course'] ?? 'General'),
          'reason' => (string)($entry['reason'] ?? '-'),
        ];

        $err = null;
        if (hybrid_supabase_insert('attendance_logs', $payload, $err)) {
          $supabaseSync['inserted']['attendance_logs']++;
        } else {
          $supabaseSync['errors'][] = ['table' => 'attendance_logs', 'stage' => 'insert', 'error' => (string)$err];
        }
      }
    }

    $timingPath = $restoredLogsDir . '/request_timing.jsonl';
    if (file_exists($timingPath)) {
      $timingLines = @file($timingPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
      foreach ($timingLines as $line) {
        $decoded = json_decode((string)$line, true);
        if (!is_array($decoded) || empty($decoded['route'])) {
          continue;
        }

        $payload = [
          'route' => (string)($decoded['route'] ?? 'unknown'),
          'started_at_epoch' => (float)($decoded['started_at'] ?? microtime(true)),
          'finished_at' => (string)($decoded['finished_at'] ?? gmdate('c')),
          'duration_ms' => (float)($decoded['duration_ms'] ?? 0),
          'method' => (string)($decoded['method'] ?? ''),
          'uri' => (string)($decoded['uri'] ?? ''),
          'status_code' => isset($decoded['status_code']) ? (int)$decoded['status_code'] : null,
          'memory_peak_mb' => (float)($decoded['memory_peak_mb'] ?? 0),
          'meta' => is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [],
          'spans' => is_array($decoded['spans'] ?? null) ? $decoded['spans'] : [],
        ];

        $err = null;
        if (hybrid_supabase_insert('request_timings', $payload, $err)) {
          $supabaseSync['inserted']['request_timings']++;
        } else {
          $supabaseSync['errors'][] = ['table' => 'request_timings', 'stage' => 'insert', 'error' => (string)$err];
        }
      }
    }
  }
}

if (function_exists('admin_log_action')) {
  $details = "Restored backup archive: {$name}";
  if ($supabaseSync['attempted']) {
    $details .= '; supabase_attendance=' . (int)$supabaseSync['inserted']['attendance_logs'];
    $details .= '; supabase_timings=' . (int)$supabaseSync['inserted']['request_timings'];
    if (!empty($supabaseSync['errors'])) {
      $details .= '; supabase_errors=' . count($supabaseSync['errors']);
    }
  }
  admin_log_action('Logs', 'Backup Restored', $details);
}

echo json_encode(['ok' => true, 'message' => 'Restore complete', 'supabase' => $supabaseSync]);
