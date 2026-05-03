<?php

require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/admin/runtime_storage.php';
require_once __DIR__ . '/admin/cache_helpers.php';

if (!function_exists('public_status_normalize')) {
  function public_status_normalize($status)
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
      return ['checkin' => false, 'checkout' => false, 'end_time' => null];
    }

    if (!$normalized['checkin'] && !$normalized['checkout']) {
      $normalized['end_time'] = null;
    }

    return $normalized;
  }
}

if (!function_exists('public_status_file_path')) {
  function public_status_file_path()
  {
    return admin_storage_migrate_file('status.json', app_storage_file('status.json'));
  }
}

if (!function_exists('public_status_current')) {
  function public_status_current($cachePrefix = 'public_status', $ttl = 2)
  {
    $statusFile = public_status_file_path();
    if (!file_exists($statusFile)) {
      return ['checkin' => false, 'checkout' => false, 'end_time' => null];
    }

    $decoded = admin_cached_json_file($cachePrefix, $statusFile, [], $ttl);
    return public_status_normalize($decoded);
  }
}
