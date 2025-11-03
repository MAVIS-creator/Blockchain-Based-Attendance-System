<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) { header('HTTP/1.1 403 Forbidden'); echo json_encode(['ok'=>false,'message'=>'Not authorized']); exit; }

$admin = __DIR__;
$logs = $admin . '/logs';
$secure = dirname(__DIR__) . '/secure_logs';
$fingerprints = $admin . '/fingerprints.json';
$backupsDir = $admin . '/backups';
if (!is_dir($backupsDir)) @mkdir($backupsDir,0755,true);

$ts = date('Ymd_His');
$zipName = "backup_{$ts}.zip";
$zipPath = $backupsDir . '/' . $zipName;

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE)!==TRUE) {
  echo json_encode(['ok'=>false,'message'=>'Could not create zip']); exit;
}

// add logs
if (is_dir($logs)) {
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($logs, RecursiveDirectoryIterator::SKIP_DOTS));
  foreach ($files as $file) {
    if ($file->isFile()) {
      $zip->addFile($file->getRealPath(), 'logs/'.$file->getFilename());
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

header('Content-Type: application/json');
echo json_encode(['ok'=>true,'file'=>$zipName]);
