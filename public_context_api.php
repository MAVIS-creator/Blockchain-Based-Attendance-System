<?php
require_once __DIR__ . '/src/StaticFrontendCors.php';
bbas_public_frontend_cors(['GET', 'OPTIONS']);
require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/admin/runtime_storage.php';
require_once __DIR__ . '/admin/cache_helpers.php';
require_once __DIR__ . '/public_status_helpers.php';
require_once __DIR__ . '/request_guard.php';
app_storage_init();
app_request_guard('status_api.php', 'public');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$status = public_status_current('static_context', 2);
$mode = !empty($status['checkin']) ? 'checkin' : (!empty($status['checkout']) ? 'checkout' : null);
$course = 'General';
$activeFile = admin_course_storage_migrate_file('active_course.json');
if (file_exists($activeFile)) {
    $active = admin_cached_json_file('static_active_course', $activeFile, [], 5);
    if (is_array($active) && isset($active['course']) && is_string($active['course'])) {
        $course = $active['course'];
    }
}
$notice = admin_cached_json_file('public_announcement', admin_storage_migrate_file('announcement.json'), [], 3);
$announcement = [
    'enabled' => !empty($notice['enabled']),
    'message' => (string)($notice['message'] ?? ''),
];
date_default_timezone_set('Africa/Lagos');
echo json_encode([
    'active' => $mode !== null,
    'action' => $mode,
    'course' => $course,
    'end_time' => isset($status['end_time']) && is_numeric($status['end_time']) ? (int)$status['end_time'] : null,
    'day' => date('Y-m-d'),
    'server_time' => time(),
    'announcement' => $announcement
], JSON_UNESCAPED_SLASHES);
