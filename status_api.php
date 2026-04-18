<?php
require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/request_guard.php';
require_once __DIR__ . '/admin/runtime_storage.php';
require_once __DIR__ . '/admin/cache_helpers.php';
app_storage_init();
app_request_guard('status_api.php', 'public');

$statusFile = admin_storage_migrate_file('status.json', app_storage_file('status.json'));
if (!file_exists($statusFile)) {
  header('Content-Type: application/json');
  echo json_encode(['checkin' => false, 'checkout' => false, 'end_time' => null]);
  exit;
}

function normalize_effective_status($status)
{
  if (!is_array($status)) {
    return ['checkin' => false, 'checkout' => false, 'end_time' => null];
  }

  $normalized = [
    'checkin' => !empty($status['checkin']),
    'checkout' => !empty($status['checkout']),
    'end_time' => isset($status['end_time']) && is_numeric($status['end_time']) ? (int)$status['end_time'] : null,
  ];

  $active = $normalized['checkin'] || $normalized['checkout'];
  $timerValid = $normalized['end_time'] !== null && $normalized['end_time'] > time();
  if ($active && !$timerValid) {
    $normalized = ['checkin' => false, 'checkout' => false, 'end_time' => null];
  }
  if (!$normalized['checkin'] && !$normalized['checkout']) {
    $normalized['end_time'] = null;
  }

  return $normalized;
}

header('Content-Type: application/json');
$decoded = admin_cached_json_file('public_status_api', $statusFile, [], 2);
$normalized = normalize_effective_status($decoded);

if (
  is_array($decoded) &&
  (
    ($decoded['checkin'] ?? null) !== $normalized['checkin'] ||
    ($decoded['checkout'] ?? null) !== $normalized['checkout'] ||
    (($decoded['end_time'] ?? null) !== $normalized['end_time'])
  )
) {
  @file_put_contents($statusFile, json_encode($normalized, JSON_PRETTY_PRINT), LOCK_EX);
}

echo json_encode($normalized);
