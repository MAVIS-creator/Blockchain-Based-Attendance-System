<?php
require_once __DIR__ . '/session_bootstrap.php';
if (empty($_SESSION['admin_logged_in'])) {
  header('HTTP/1.1 403 Forbidden');
  echo 'Not authorized';
  exit;
}

require_once __DIR__ . '/../storage_helpers.php';
app_storage_init();

$name = isset($_GET['file']) ? basename((string)$_GET['file']) : '';
if ($name === '' || $name !== basename($name)) {
  header('HTTP/1.1 400 Bad Request');
  echo 'Invalid file';
  exit;
}

$path = app_storage_file('backups/' . $name);
if (!file_exists($path)) {
  header('HTTP/1.1 404 Not Found');
  echo 'Not found';
  exit;
}

$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$types = [
  'zip' => 'application/zip',
  'csv' => 'text/csv',
  'pdf' => 'application/pdf',
  'json' => 'application/json',
];
$type = $types[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $name . '"');
readfile($path);
exit;
