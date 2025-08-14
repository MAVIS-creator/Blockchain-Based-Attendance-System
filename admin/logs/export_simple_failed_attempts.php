<?php
$logDir = __DIR__ . "/../logs"; // adjust if needed

$logDate = $_GET['date'] ?? date('Y-m-d');
$course = $_GET['course'] ?? 'All';
$search = trim($_GET['search'] ?? '');

// 🔥 Sanitize course name for filename
$sanitizedCourse = preg_replace('/[^a-zA-Z0-9_-]/', '_', $course);
$filename = "{$sanitizedCourse}_failed_attendance.csv";

header('Content-Type: text/csv');
header("Content-Disposition: attachment; filename=\"$filename\"");

$output = fopen('php://output', 'w');
fputcsv($output, ['Name', 'Matric Number']);

$logs = [];

// ✅ Classic failed attempts logs
$logFiles = glob($logDir . '/*_failed_attempts.log');
foreach ($logFiles as $filePath) {
    if (!preg_match('/(\d{4}-\d{2}-\d{2})_failed_attempts\.log$/', $filePath, $match)) continue;
    $logFileDate = $match[1];
    if ($logFileDate !== $logDate) continue;

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) >= 7) {
            $courseVal = trim($parts[6]);
            $name = $parts[0];
            $matric = $parts[1];

            if (($course === 'All' || $courseVal === $course) &&
                ($search === '' || stripos($name, $search) !== false || stripos($matric, $search) !== false)) {
                $logs[$matric] = ['name' => $name, 'matric' => $matric];
            }
        }
    }
}

// ✅ Check-In only logs from main .log file
$mainLogFile = "{$logDir}/{$logDate}.log";
$checkMap = [];

if (file_exists($mainLogFile)) {
    $lines = file($mainLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) >= 8) {
            [$name, $matric, $action, $finger, $ip, $timestamp, $device, $courseVal] = $parts;

            if ($course !== 'All' && $courseVal !== $course) continue;

            if (!isset($checkMap[$matric])) {
                $checkMap[$matric] = ['name' => $name, 'matric' => $matric, 'checkin' => '', 'checkout' => ''];
            }

            if (strtolower($action) === 'checkin') $checkMap[$matric]['checkin'] = $timestamp;
            if (strtolower($action) === 'checkout') $checkMap[$matric]['checkout'] = $timestamp;
        }
    }

    foreach ($checkMap as $entry) {
        if ($entry['checkin'] && !$entry['checkout'] &&
            ($search === '' || stripos($entry['name'], $search) !== false || stripos($entry['matric'], $search) !== false)) {
            $logs[$entry['matric']] = ['name' => $entry['name'], 'matric' => $entry['matric']];
        }
    }
}

// ✅ Write to CSV
foreach ($logs as $entry) {
    fputcsv($output, [$entry['name'], $entry['matric']]);
}

fclose($output);
exit;
?>
