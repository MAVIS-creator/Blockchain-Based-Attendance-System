<?php
$logDate = $_GET['logDate'] ?? date('Y-m-d');
$course = $_GET['course'] ?? 'General';
$logPath = __DIR__ . "/{$logDate}.log";

// 🔥 Sanitize the course name for filename (remove spaces, special chars, etc.)
$sanitizedCourse = preg_replace('/[^a-zA-Z0-9_-]/', '_', $course);
$filename = "{$sanitizedCourse}_attendance.csv";

header('Content-Type: text/csv');
header("Content-Disposition: attachment; filename=\"$filename\"");

$output = fopen('php://output', 'w');
fputcsv($output, ['Name', 'Matric Number']);

$entries = [];

if (file_exists($logPath)) {
    $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) >= 8 && $parts[7] === $course) {
            $key = $parts[0] . '|' . $parts[1];
            if (!isset($entries[$key])) {
                $entries[$key] = ['checkin' => '', 'checkout' => ''];
            }
            $action = strtolower($parts[2]);
            if (in_array($action, ['checkin', 'in'])) $entries[$key]['checkin'] = $parts[5];
            if (in_array($action, ['checkout', 'out'])) $entries[$key]['checkout'] = $parts[5];
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
