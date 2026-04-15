<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
  header('HTTP/1.1 403 Forbidden');
  echo json_encode(['ok' => false, 'message' => 'Not authorized']);
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
app_storage_init();

// scope: logs|backups|chain|fingerprints|all (can be comma-separated)
$scopeRaw = $_POST['scope'] ?? ($_GET['scope'] ?? 'all');
$scopes = array_map('trim', explode(',', $scopeRaw));
$adminDir = __DIR__;
$logsDir = app_storage_file('logs');
$backupsDir = app_storage_file('backups');
$secureDir = app_storage_file('secure_logs');

$result = ['deleted' => [], 'skipped' => [], 'errors' => []];
$supabase = ['attempted' => false, 'deleted' => [], 'errors' => []];

$cleanupMode = strtolower(trim((string)($_POST['mode'] ?? $_GET['mode'] ?? 'scope')));
$filterKeyword = trim((string)($_POST['filter_keyword'] ?? $_GET['filter_keyword'] ?? ''));
$filterAction = strtolower(trim((string)($_POST['filter_action'] ?? $_GET['filter_action'] ?? 'all')));

function safe_unlink($file)
{
  if (!file_exists($file)) return false;
  try {
    @unlink($file);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function clear_logs_filter_mode($logsDir, $keyword, $actionFilter)
{
  $summary = [
    'files_touched' => 0,
    'lines_removed' => 0,
    'files' => [],
    'errors' => [],
  ];

  if (!is_dir($logsDir)) {
    return $summary;
  }

  $keywordNorm = strtolower(trim((string)$keyword));
  $actionFilter = in_array($actionFilter, ['checkin', 'checkout', 'failed', 'all'], true) ? $actionFilter : 'all';

  foreach (glob($logsDir . '/*.log') ?: [] as $file) {
    if (!is_file($file)) continue;

    $lines = @file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
      $summary['errors'][] = $file;
      continue;
    }

    $kept = [];
    $removedForFile = 0;

    foreach ($lines as $line) {
      $lineRaw = (string)$line;
      $lineNorm = strtolower($lineRaw);

      $actionMatches = true;
      if ($actionFilter !== 'all') {
        $parts = array_map('trim', explode('|', $lineRaw));
        $lineAction = strtolower((string)($parts[2] ?? ''));
        $actionMatches = ($lineAction === $actionFilter);
      }

      $keywordMatches = ($keywordNorm === '') || (strpos($lineNorm, $keywordNorm) !== false);

      if ($actionMatches && $keywordMatches) {
        $removedForFile++;
      } else {
        $kept[] = $lineRaw;
      }
    }

    if ($removedForFile > 0) {
      $payload = implode(PHP_EOL, $kept);
      if ($payload !== '') $payload .= PHP_EOL;
      if (@file_put_contents($file, $payload, LOCK_EX) === false) {
        $summary['errors'][] = $file;
        continue;
      }

      $summary['files_touched']++;
      $summary['lines_removed'] += $removedForFile;
      $summary['files'][] = basename($file) . " ({$removedForFile} removed)";
    }
  }

  return $summary;
}

function clear_supabase_tables(array $tables)
{
  $summary = ['attempted' => false, 'deleted' => [], 'errors' => []];

  if (!function_exists('hybrid_enabled') || !function_exists('hybrid_supabase_delete') || !hybrid_enabled()) {
    return $summary;
  }

  $summary['attempted'] = true;
  foreach ($tables as $table) {
    $err = null;
    $ok = hybrid_supabase_delete((string)$table, ['id' => 'gt.0'], $err);
    if ($ok) {
      $summary['deleted'][] = (string)$table;
    } else {
      $summary['errors'][] = ['table' => (string)$table, 'error' => (string)$err];
    }
  }

  return $summary;
}

if ($cleanupMode === 'filter') {
  $defaultKeyword = $filterKeyword !== '' ? $filterKeyword : 'load test';
  $filterSummary = clear_logs_filter_mode($logsDir, $defaultKeyword, $filterAction);

  if (function_exists('admin_log_action')) {
    $details = "Filtered cleanup executed. keyword={$defaultKeyword}, action={$filterAction}, files_touched=" .
      (int)($filterSummary['files_touched'] ?? 0) . ", lines_removed=" . (int)($filterSummary['lines_removed'] ?? 0);
    admin_log_action('Logs', 'Filtered Log Cleanup', $details);
  }

  // Filtered clear can target attendance rows in Supabase (best effort).
  if (function_exists('hybrid_enabled') && function_exists('hybrid_supabase_delete') && hybrid_enabled()) {
    $supabase['attempted'] = true;
    $filters = [];
    if ($filterAction !== 'all') {
      $filters['action'] = 'eq.' . $filterAction;
    }
    if ($defaultKeyword !== '') {
      $needle = '*' . str_replace(',', ' ', $defaultKeyword) . '*';
      $filters['or'] = '(name.ilike.' . $needle . ',matric.ilike.' . $needle . ',fingerprint.ilike.' . $needle . ',ip.ilike.' . $needle . ',mac.ilike.' . $needle . ',course.ilike.' . $needle . ',reason.ilike.' . $needle . ')';
    }
    $err = null;
    $ok = hybrid_supabase_delete('attendance_logs', $filters, $err);
    if ($ok) {
      $supabase['deleted'][] = 'attendance_logs(filtered)';
    } else {
      $supabase['errors'][] = ['table' => 'attendance_logs', 'error' => (string)$err];
    }
  }

  header('Content-Type: application/json');
  echo json_encode(['ok' => true, 'mode' => 'filter', 'result' => $filterSummary, 'supabase' => $supabase]);
  exit;
}

if (in_array('logs', $scopes) || in_array('all', $scopes)) {
  if (is_dir($logsDir)) {
    $patterns = ["{$logsDir}/*.log", "{$logsDir}/*_failed_attempts.log", "{$logsDir}/*.json", "{$logsDir}/inactivity_log.txt"];
    foreach ($patterns as $pat) {
      foreach (glob($pat) as $f) {
        // do not delete PHP files or export scripts
        if (preg_match('/\.php$/i', $f)) continue;
        if (safe_unlink($f)) $result['deleted'][] = $f;
        else $result['errors'][] = $f;
      }
    }
  }
}

if (in_array('backups', $scopes) || in_array('all', $scopes)) {
  if (is_dir($backupsDir)) {
    foreach (glob($backupsDir . '/*') as $f) {
      if (is_file($f)) {
        if (safe_unlink($f)) $result['deleted'][] = $f;
        else $result['errors'][] = $f;
      } elseif (is_dir($f)) {
        // remove recursively
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($f, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $inner) {
          @unlink($inner->getRealPath());
        }
        @rmdir($f);
        $result['deleted'][] = $f;
      }
    }
  }
}

if (in_array('chain', $scopes) || in_array('all', $scopes)) {
  // chain is important; only delete if explicitly requested
  if (in_array('chain', $scopes) || in_array('all', $scopes)) {
    $chainFile = $secureDir . '/attendance_chain.json';
    if (file_exists($chainFile)) {
      if (safe_unlink($chainFile)) $result['deleted'][] = $chainFile;
      else $result['errors'][] = $chainFile;
    }
  }
}

// fingerprints.json handling
if (in_array('fingerprints', $scopes) || in_array('all', $scopes)) {
  $fpFile = admin_storage_migrate_file('fingerprints.json', app_storage_file('fingerprints.json'));
  if (file_exists($fpFile)) {
    if (safe_unlink($fpFile)) $result['deleted'][] = $fpFile;
    else $result['errors'][] = $fpFile;
  }
}

if (in_array('logs', $scopes, true) || in_array('all', $scopes, true)) {
  // Keep cloud source in sync with local clear operation.
  $tables = ['attendance_logs', 'request_timings', 'admin_audit_logs'];
  if (in_array('all', $scopes, true)) {
    $tables[] = 'support_tickets';
  }
  $supabase = clear_supabase_tables($tables);
}

if (function_exists('admin_log_action')) {
  $scopeLabel = implode(',', $scopes);
  $details = "Scope cleanup executed. scopes={$scopeLabel}, deleted=" . count($result['deleted']) . ", errors=" . count($result['errors']);
  if (!empty($supabase['deleted'])) {
    $details .= ', supabase_deleted=' . implode('|', $supabase['deleted']);
  }
  if (!empty($supabase['errors'])) {
    $details .= ', supabase_errors=' . count($supabase['errors']);
  }
  admin_log_action('Logs', 'Scope Log Cleanup', $details);
}

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'result' => $result, 'supabase' => $supabase]);
