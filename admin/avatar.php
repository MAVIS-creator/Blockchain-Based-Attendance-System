<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_logged_in'])) {
  header('HTTP/1.1 403 Forbidden');
  exit;
}

require_once __DIR__ . '/runtime_storage.php';

$file = isset($_GET['file']) ? basename((string)$_GET['file']) : '';
if ($file === '') {
  header('HTTP/1.1 400 Bad Request');
  exit;
}

$path = admin_storage_file('avatars/' . $file);
if (!file_exists($path)) {
  header('HTTP/1.1 404 Not Found');
  exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = [
  'jpg' => 'image/jpeg',
  'jpeg' => 'image/jpeg',
  'png' => 'image/png',
  'gif' => 'image/gif',
];

header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
