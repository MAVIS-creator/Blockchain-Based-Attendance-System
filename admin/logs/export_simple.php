<?php
require_once dirname(__DIR__, 2) . '/storage_helpers.php';
require_once dirname(__DIR__) . '/log_helpers.php';
app_storage_init();
$logDate = $_GET['logDate'] ?? date('Y-m-d');
$course = $_GET['course'] ?? 'General';
$logPath = app_storage_file("logs/{$logDate}.log");

// 🔥 Sanitize the course name for filename (remove spaces, special chars, etc.)
$sanitizedCourse = preg_replace('/[^a-zA-Z0-9_-]/', '_', $course);
$filename = "{$sanitizedCourse}_attendance.csv";

header('Content-Type: text/csv');
header("Content-Disposition: attachment; filename=\"$filename\"");

$output = fopen('php://output', 'w');
fputcsv($output, ['Name', 'Matric Number']);

$entries = [];

if (file_exists($logPath)) {
    foreach (admin_attendance_entries_for_date_parsed($logPath, 15) as $entry) {
        if (($entry['course'] ?? '') === $course) {
            $key = ($entry['name'] ?? '') . '|' . ($entry['matric'] ?? '');
            if (!isset($entries[$key])) {
                $entries[$key] = ['checkin' => '', 'checkout' => ''];
            }
            $action = strtolower((string)($entry['action'] ?? ''));
            if (in_array($action, ['checkin', 'in'])) $entries[$key]['checkin'] = $entry['timestamp'] ?? '';
            if (in_array($action, ['checkout', 'out'])) $entries[$key]['checkout'] = $entry['timestamp'] ?? '';
        }
    }

    foreach ($entries as $key => $data) {
        if ($data['checkin'] && $data['checkout']) {
            [$name, $matric] = explode('|', $key);
            fputcsv($output, [$name, $matric]);
        }
    }
}

fclose($output);
exit;
?>
