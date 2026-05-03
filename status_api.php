<?php
require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/request_guard.php';
require_once __DIR__ . '/admin/runtime_storage.php';
require_once __DIR__ . '/admin/cache_helpers.php';
require_once __DIR__ . '/public_status_helpers.php';
app_storage_init();
app_request_guard('status_api.php', 'public');

$statusFile = public_status_file_path();
if (!file_exists($statusFile)) {
  header('Content-Type: application/json');
  echo json_encode(['checkin' => false, 'checkout' => false, 'end_time' => null]);
  exit;
}

header('Content-Type: application/json');
echo json_encode(public_status_current('public_status_api', 2));
