<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
  header('HTTP/1.1 403 Forbidden');
  echo json_encode(['ok' => false, 'message' => 'Not authorized']);
  exit;
}

require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/state_helpers.php';
app_storage_init();

$admin = __DIR__;
$logs = app_storage_file('logs');
$secure = app_storage_file('secure_logs');
$fingerprints = admin_storage_migrate_file('fingerprints.json', app_storage_file('fingerprints.json'));
$backupsDir = app_storage_file('backups');
if (!is_dir($backupsDir)) @mkdir($backupsDir, 0755, true);
// CSRF protection
$csrfPath = __DIR__ . '/includes/csrf.php';
if (file_exists($csrfPath)) require_once $csrfPath;
if (function_exists('csrf_check_request') && !csrf_check_request()) {
  header('HTTP/1.1 403 Forbidden');
  echo json_encode(['ok' => false, 'message' => 'csrf_failed']);
  exit;
}

$ts = date('Ymd_His');
$zipName = "backup_{$ts}.zip";
$zipPath = $backupsDir . '/' . $zipName;

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
  echo json_encode(['ok' => false, 'message' => 'Could not create zip']);
  exit;
}

// add logs
if (is_dir($logs)) {
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($logs, RecursiveDirectoryIterator::SKIP_DOTS));
  foreach ($files as $file) {
    if ($file->isFile()) {
      $zip->addFile($file->getRealPath(), 'logs/' . $file->getFilename());
    }
  }
}

// add secure chain
if (is_dir($secure)) {
  $chain = $secure . '/attendance_chain.json';
  if (file_exists($chain)) $zip->addFile($chain, 'secure_logs/attendance_chain.json');
}

// add fingerprints
if (file_exists($fingerprints)) $zip->addFile($fingerprints, 'admin_fingerprints.json');

$zip->close();

if (function_exists('admin_log_action')) {
  admin_log_action('Logs', 'Backup Created', "Created backup archive: {$zipName}");
}

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'file' => $zipName]);
