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
$subjects = (string)app_env_value('BBAS_UNIFIED_SESSION_SUBJECTS', '');
if ($key === '' || $subjects === '') {
    bbas_mutation_response(404, ['error' => 'Workspace unavailable']);
}
$claims = bbas_unified_admin_claims(
    (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $key, $subjects, time(), 'attendance-session:write'
);
$raw = file_get_contents('php://input', false, null, 0, 2049);
if ($claims === null || !is_string($claims['jti'] ?? null) ||
    !preg_match('/\A[a-f0-9]{32}\z/', $claims['jti']) ||
    !is_string($claims['body_sha256'] ?? null) || !is_string($raw) || strlen($raw) > 2048 ||
    !hash_equals($claims['body_sha256'], hash('sha256', $raw))) {
    bbas_mutation_response(403, ['error' => 'Access denied']);
}
$data = json_decode($raw, true);
if (!is_array($data) || ($data['confirmed'] ?? null) !== true ||
    !in_array($data['mode'] ?? null, ['checkin', 'checkout', 'closed'], true) ||
    !is_int($data['durationMinutes'] ?? null) || $data['durationMinutes'] < 1 || $data['durationMinutes'] > 1440) {
    bbas_mutation_response(422, ['error' => 'Confirm the operation and provide a valid mode and duration']);
}

require_once dirname(__DIR__) . '/storage_helpers.php';
require_once __DIR__ . '/state_helpers.php';
require_once dirname(__DIR__) . '/src/AttendanceSessionStatus.php';
app_storage_init();

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
    'operation' => 'attendance-session:write', 'mode' => $data['mode'],
    'durationMinutes' => $data['durationMinutes'], 'createdAt' => gmdate('c')];
$append = static function (array $record) use ($handle): bool {
    $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
    fseek($handle, 0, SEEK_END);
    return fwrite($handle, $line) === strlen($line) && fflush($handle);
};
if (!$append($entry + ['result' => 'accepted'])) {
    bbas_mutation_response(503, ['error' => 'Operation audit unavailable']);
}
try {
    $status = bbas_set_attendance_status(admin_status_file(), $data['mode'], $data['durationMinutes'] * 60,
        admin_load_settings_cached(0));
} catch (DomainException $e) {
    $append($entry + ['result' => 'rejected']);
    bbas_mutation_response(422, ['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    $append($entry + ['result' => 'failed']);
    bbas_mutation_response(503, ['error' => 'Attendance status could not be saved']);
}
if (!$append($entry + ['result' => 'applied'])) {
    bbas_mutation_response(503, ['error' => 'Refresh attendance status before retrying']);
}
bbas_mutation_response(200, ['status' => $status]);
