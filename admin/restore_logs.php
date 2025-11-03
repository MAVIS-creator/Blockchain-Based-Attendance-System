<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) { header('HTTP/1.1 403 Forbidden'); echo json_encode(['ok'=>false,'message'=>'Not authorized']); exit; }

if (empty($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok'=>false,'message'=>'No file uploaded']); exit;
}

$tmp = $_FILES['backup']['tmp_name'];
$name = basename($_FILES['backup']['name']);

$zip = new ZipArchive();
if ($zip->open($tmp) !== TRUE) { echo json_encode(['ok'=>false,'message'=>'Invalid zip']); exit; }

$admin = __DIR__;
$logs = $admin . '/logs';
$secure = dirname(__DIR__) . '/secure_logs';
$fingerprints = $admin . '/fingerprints.json';

// extract files into a temp dir then move
$tempDir = sys_get_temp_dir() . '/restore_' . time();
@mkdir($tempDir, 0755, true);
$zip->extractTo($tempDir);
$zip->close();

// move logs
if (is_dir($tempDir . '/logs')) {
  $it = new DirectoryIterator($tempDir . '/logs');
  foreach ($it as $f) { if (!$f->isDot()) { copy($f->getPathname(), $logs . '/' . $f->getFilename()); } }
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

echo json_encode(['ok'=>true,'message'=>'Restore complete']);
