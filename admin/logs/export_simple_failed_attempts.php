<?php
require_once dirname(__DIR__, 2) . '/storage_helpers.php';
require_once dirname(__DIR__) . '/log_helpers.php';
require_once dirname(__DIR__) . '/state_helpers.php';
app_storage_init();

$settings = admin_load_settings_cached(15);
$checkinOnlyCountsAsSuccess = !empty($settings['checkin_only_counts_as_success']);

$logDir = app_storage_file('logs');
$logDate = $_GET['date'] ?? date('Y-m-d');
$course = $_GET['course'] ?? 'All';
$search = trim($_GET['search'] ?? '');

$sanitizedCourse = preg_replace('/[^a-zA-Z0-9_-]/', '_', $course);
$filename = "{$sanitizedCourse}_failed_attendance.csv";

header('Content-Type: text/csv');
header("Content-Disposition: attachment; filename=\"$filename\"");

$output = fopen('php://output', 'w');
fputcsv($output, ['Name', 'Matric Number']);

$logs = [];
$failedLogFile = $logDir . DIRECTORY_SEPARATOR . $logDate . '_failed_attempts.log';
foreach (admin_failed_attempt_entries_for_date($failedLogFile, 15) as $entry) {
    $name = $entry['name'] ?? '';
    $matric = $entry['matric'] ?? '';
    $courseVal = $entry['course'] ?? '';
    if (($course === 'All' || $courseVal === $course) &&
        ($search === '' || stripos($name, $search) !== false || stripos($matric, $search) !== false)) {
        $logs[$matric] = ['name' => $name, 'matric' => $matric];
    }
}

$mainLogFile = $logDir . DIRECTORY_SEPARATOR . $logDate . '.log';
$checkMap = [];
if (!$checkinOnlyCountsAsSuccess) {
    foreach (admin_attendance_entries_for_date_parsed($mainLogFile, 15) as $entry) {
        $name = $entry['name'] ?? '';
        $matric = $entry['matric'] ?? '';
        $action = strtolower((string)($entry['action'] ?? ''));
        $timestamp = $entry['timestamp'] ?? '';
        $courseVal = $entry['course'] ?? '';

        if ($course !== 'All' && $courseVal !== $course) continue;

        if (!isset($checkMap[$matric])) {
            $checkMap[$matric] = ['name' => $name, 'matric' => $matric, 'checkin' => '', 'checkout' => ''];
        }

        if ($action === 'checkin') $checkMap[$matric]['checkin'] = $timestamp;
        if ($action === 'checkout') $checkMap[$matric]['checkout'] = $timestamp;
    }

    foreach ($checkMap as $entry) {
        if ($entry['checkin'] && !$entry['checkout'] &&
            ($search === '' || stripos($entry['name'], $search) !== false || stripos($entry['matric'], $search) !== false)) {
            $logs[$entry['matric']] = ['name' => $entry['name'], 'matric' => $entry['matric']];
        }
    }
}

foreach ($logs as $entry) {
    fputcsv($output, [$entry['name'], $entry['matric']]);
}

fclose($output);
exit;
