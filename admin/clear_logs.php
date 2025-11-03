<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
  header('HTTP/1.1 403 Forbidden');
  echo json_encode(['ok'=>false,'message'=>'Not authorized']);
  exit;
}

// scope: logs|backups|chain|all
$scope = $_POST['scope'] ?? ($_GET['scope'] ?? 'all');
$adminDir = __DIR__;
$logsDir = $adminDir . '/logs';
$backupsDir = $adminDir . '/backups';
$secureDir = dirname(__DIR__) . '/secure_logs';

$result = ['deleted'=>[],'skipped'=>[],'errors'=>[]];

function safe_unlink($file){
  if (!file_exists($file)) return false;
  try { @unlink($file); return true; } catch (Throwable $e) { return false; }
}

if ($scope === 'logs' || $scope === 'all') {
  if (is_dir($logsDir)){
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

if ($scope === 'backups' || $scope === 'all') {
  if (is_dir($backupsDir)){
    foreach (glob($backupsDir . '/*') as $f) {
      if (is_file($f)) { if (safe_unlink($f)) $result['deleted'][] = $f; else $result['errors'][] = $f; }
      elseif (is_dir($f)) {
        // remove recursively
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($f, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $inner){ @unlink($inner->getRealPath()); }
        @rmdir($f);
        $result['deleted'][] = $f;
      }
    }
  }
}

if ($scope === 'chain' || $scope === 'all') {
  // chain is important; only delete if explicitly requested
  if ($scope === 'chain' || $scope === 'all') {
    $chainFile = $secureDir . '/attendance_chain.json';
    if (file_exists($chainFile)) {
      if (safe_unlink($chainFile)) $result['deleted'][] = $chainFile; else $result['errors'][] = $chainFile;
    }
  }
}

header('Content-Type: application/json');
echo json_encode(['ok'=>true,'result'=>$result]);
