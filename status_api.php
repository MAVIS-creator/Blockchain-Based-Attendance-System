<?php
require_once __DIR__ . '/storage_helpers.php';
app_storage_init();

$statusFile = app_storage_migrate_file('status.json', __DIR__ . '/status.json');
if (!file_exists($statusFile)) {
  header('Content-Type: application/json');
  echo json_encode(['checkin' => false, 'checkout' => false, 'end_time' => null]);
  exit;
}

header('Content-Type: application/json');
echo file_get_contents($statusFile);
