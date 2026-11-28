<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/env_helpers.php';
require_once dirname(__DIR__) . '/src/UnifiedAdminDashboardAuth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$key = (string)app_env_value('BBAS_UNIFIED_PUBLIC_KEY', '');
$subjects = (string)app_env_value('BBAS_UNIFIED_DASHBOARD_SUBJECTS', '');
if ($key === '' || $subjects === '') {
    http_response_code(404);
    echo json_encode(['error' => 'Workspace unavailable']);
    exit;
}

$claims = bbas_unified_admin_claims((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $key, $subjects, time());
if ($claims === null) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

require_once dirname(__DIR__) . '/storage_helpers.php';
require_once dirname(__DIR__) . '/public_status_helpers.php';
app_storage_init();

$status = public_status_current('unified_admin_dashboard', 2);
$action = !empty($status['checkin']) ? 'checkin' : (!empty($status['checkout']) ? 'checkout' : null);
$activeCourse = admin_cached_json_file(
    'unified_admin_active_course',
    admin_course_storage_migrate_file('active_course.json'),
    [],
    5
);
$response = [
    'course' => is_string($activeCourse['course'] ?? null) ? $activeCourse['course'] : null,
    'action' => $action,
    'endsAt' => isset($status['end_time']) && is_numeric($status['end_time']) ? (int)$status['end_time'] : null,
];

$sessionSubjects = (string)app_env_value('BBAS_UNIFIED_SESSION_SUBJECTS', '');
$courseSubjects = (string)app_env_value('BBAS_UNIFIED_COURSE_SUBJECTS', '');
$response['canManageSession'] = in_array($claims['sub'], array_map('trim', explode(',', $sessionSubjects)), true);
$response['canManageCourse'] = in_array($claims['sub'], array_map('trim', explode(',', $courseSubjects)), true);
if ($response['canManageCourse']) {
    require_once dirname(__DIR__) . '/src/UnifiedAdminCourses.php';
    try {
        $response['courses'] = bbas_unified_course_names();
    } catch (RuntimeException $e) {
        $response['canManageCourse'] = false;
    }
}
require_once dirname(__DIR__) . '/src/UnifiedAdminOverview.php';
$response['overview'] = bbas_unified_overview($claims['sub']);
echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
