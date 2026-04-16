<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}
require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/cache_helpers.php';
require_once __DIR__ . '/../request_timing.php';
app_storage_init();
request_timing_start('admin/_last_updates.php');
$base = __DIR__ . '/';
$accounts = admin_storage_migrate_file('accounts.json');
$settings = admin_storage_migrate_file('settings.json');
$chain = app_storage_migrate_file('secure_logs/attendance_chain.json', __DIR__ . '/../secure_logs/attendance_chain.json');
// additional watched files/pages/data
$ticketsFile = admin_storage_migrate_file('support_tickets.json', app_storage_file('support_tickets.json'));
$fingerprints = admin_storage_migrate_file('fingerprints.json', app_storage_file('fingerprints.json'));
$courses = admin_course_storage_migrate_file('course.json');
$activeCourse = admin_course_storage_migrate_file('active_course.json');
$statusFile = admin_storage_migrate_file('status.json', app_storage_file('status.json'));
$viewTicketsPage = $base . 'view_tickets.php';
$unlinkPage = $base . 'unlink_fingerprint.php';
$addCoursePage = $base . 'courses/add.php';
$chatFile = admin_storage_migrate_file('chat.json');

function last_update_mtime($path)
{
    return @filemtime($path) ?: 0;
}

$cacheKey = 'admin_last_updates:' . md5(
    implode('|', [
        last_update_mtime($accounts),
        last_update_mtime($settings),
        last_update_mtime($chain),
        last_update_mtime($ticketsFile),
        last_update_mtime($fingerprints),
        last_update_mtime($courses),
        last_update_mtime($activeCourse),
        last_update_mtime($statusFile),
        last_update_mtime($viewTicketsPage),
        last_update_mtime($unlinkPage),
        last_update_mtime($addCoursePage),
        last_update_mtime($chatFile),
    ])
);

$updateSpan = microtime(true);
$out = admin_cache_remember($cacheKey, 2, function () use ($accounts, $settings, $chain, $ticketsFile, $fingerprints, $courses, $activeCourse, $statusFile, $viewTicketsPage, $unlinkPage, $addCoursePage, $chatFile) {
    return [
        'accounts' => @filemtime($accounts) ?: 0,
        'settings' => @filemtime($settings) ?: 0,
        'chain' => @filemtime($chain) ?: 0,
        'tickets' => @filemtime($ticketsFile) ?: 0,
        'fingerprints' => @filemtime($fingerprints) ?: 0,
        'courses' => @filemtime($courses) ?: 0,
        'active_course' => @filemtime($activeCourse) ?: 0,
        'status' => @filemtime($statusFile) ?: 0,
        'view_tickets_page' => @filemtime($viewTicketsPage) ?: 0,
        'unlink_page' => @filemtime($unlinkPage) ?: 0,
        'add_course_page' => @filemtime($addCoursePage) ?: 0,
        'chat' => @filemtime($chatFile) ?: 0
    ];
});
request_timing_span('compute_last_updates', $updateSpan);
echo json_encode($out);
