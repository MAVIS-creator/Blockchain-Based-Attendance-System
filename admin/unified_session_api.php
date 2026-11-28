<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/env_helpers.php';
require_once dirname(__DIR__) . '/src/UnifiedAdminDashboardAuth.php';

function bbas_mutation_response(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    bbas_mutation_response(405, ['error' => 'Method not allowed']);
}
$key = (string)app_env_value('BBAS_UNIFIED_PUBLIC_KEY', '');
$raw = file_get_contents('php://input', false, null, 0, 2049);
$data = is_string($raw) && strlen($raw) <= 2048 ? json_decode($raw, true) : null;
$courseChange = ($data['mode'] ?? null) === 'set-course';
$addCourse = ($data['mode'] ?? null) === 'add-course';
$scope = match (true) {
    $courseChange => 'active-course:write',
    $addCourse => 'course-catalogue:write',
    default => 'attendance-session:write',
};
$subjects = (string)app_env_value(($courseChange || $addCourse) ? 'BBAS_UNIFIED_COURSE_SUBJECTS' : 'BBAS_UNIFIED_SESSION_SUBJECTS', '');
if ($key === '' || $subjects === '') {
    bbas_mutation_response(404, ['error' => 'Workspace unavailable']);
}
$claims = bbas_unified_admin_claims(
    (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $key, $subjects, time(), $scope
);
if ($claims === null || !is_string($claims['jti'] ?? null) ||
    !preg_match('/\A[a-f0-9]{32}\z/', $claims['jti']) ||
    !is_string($claims['body_sha256'] ?? null) || !is_string($raw) || strlen($raw) > 2048 ||
    !hash_equals($claims['body_sha256'], hash('sha256', $raw))) {
    bbas_mutation_response(403, ['error' => 'Access denied']);
}
$valid = ($courseChange || $addCourse)
    ? is_string($data['course'] ?? null) && strlen($data['course']) <= 191
    : in_array($data['mode'] ?? null, ['checkin', 'checkout', 'closed'], true)
        && is_int($data['durationMinutes'] ?? null) && $data['durationMinutes'] >= 1 && $data['durationMinutes'] <= 1440;
if ($addCourse) {
    $valid = $valid && trim($data['course']) !== '' && trim($data['course']) === $data['course']
        && is_string($data['outline'] ?? null) && trim($data['outline']) !== '' && strlen($data['outline']) <= 512;
}
if (!$valid || ($data['confirmed'] ?? null) !== true) {
    bbas_mutation_response(422, ['error' => 'Confirm the operation and provide valid values']);
}

require_once dirname(__DIR__) . '/storage_helpers.php';
require_once __DIR__ . '/state_helpers.php';
require_once dirname(__DIR__) . '/src/AttendanceSessionStatus.php';
app_storage_init();

$operationLock = fopen(app_storage_file('logs/unified_admin_operations.lock'), 'c+');
if ($operationLock === false || !flock($operationLock, LOCK_EX)) {
    bbas_mutation_response(503, ['error' => 'Attendance operation lock unavailable']);
}

$auditFile = app_storage_file('logs/unified_admin_' . gmdate('Y-m-d', $claims['iat']) . '.jsonl');
$handle = fopen($auditFile, 'c+');
if ($handle === false || !flock($handle, LOCK_EX)) {
    bbas_mutation_response(503, ['error' => 'Operation audit unavailable']);
}
while (($line = fgets($handle)) !== false) {
    $entry = json_decode($line, true);
    if (($entry['request'] ?? null) === $claims['jti']) {
        bbas_mutation_response(409, ['error' => 'This request has already been processed']);
    }
}
$entry = ['request' => $claims['jti'], 'actor' => 'attendancebot:' . $claims['sub'],
    'operation' => $scope, 'mode' => $data['mode'], 'createdAt' => gmdate('c')];
$entry += ($courseChange || $addCourse) ? ['course' => $data['course']] : ['durationMinutes' => $data['durationMinutes']];
$append = static function (array $record) use ($handle): bool {
    $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
    fseek($handle, 0, SEEK_END);
    return fwrite($handle, $line) === strlen($line) && fflush($handle);
};
if (!$append($entry + ['result' => 'accepted'])) {
    bbas_mutation_response(503, ['error' => 'Operation audit unavailable']);
}
try {
    if ($addCourse) {
        require_once dirname(__DIR__) . '/src/UnifiedAdminCourses.php';
        bbas_unified_add_course($data['course'], $data['outline']);
        $result = ['course' => $data['course']];
    } elseif ($courseChange) {
        require_once dirname(__DIR__) . '/public_status_helpers.php';
        require_once dirname(__DIR__) . '/src/UnifiedAdminCourses.php';
        $status = public_status_current('unified_course_change', 0);
        if (!empty($status['checkin']) || !empty($status['checkout'])) {
            throw new DomainException('Close attendance before changing the active course.');
        }
        bbas_unified_set_active_course($data['course']);
        $result = ['course' => $data['course']];
    } else {
        $result = ['status' => bbas_set_attendance_status(admin_status_file(), $data['mode'], $data['durationMinutes'] * 60,
            admin_load_settings_cached(0))];
    }
} catch (DomainException $e) {
    $append($entry + ['result' => 'rejected']);
    bbas_mutation_response(422, ['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    $append($entry + ['result' => 'failed']);
    bbas_mutation_response(503, ['error' => 'BBAS change could not be saved']);
}
if (!$append($entry + ['result' => 'applied'])) {
    bbas_mutation_response(503, ['error' => 'Refresh attendance status before retrying']);
}
bbas_mutation_response(200, $result);
