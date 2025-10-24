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
        if (count($parts) < 5) continue;

        $macRegex = '/([0-9a-f]{2}[:\\-]){5}[0-9a-f]{2}/i';
        if (count($parts) >= 9 && preg_match($macRegex, $parts[5])) {
            // new format: name|matric|action|fingerprint|ip|mac|timestamp|device|course|reason
            $courseIdx = 8;
            $timeIdx = 6;
        } else {
            // old format: name|matric|action|fingerprint|ip|timestamp|device|course|reason
            $courseIdx = 7;
            $timeIdx = 5;
        }

        if (isset($parts[$courseIdx]) && $parts[$courseIdx] === $course) {
            $key = $parts[0] . '|' . $parts[1];
            if (!isset($entries[$key])) {
                $entries[$key] = ['checkin' => '', 'checkout' => ''];
            }
            $action = strtolower($parts[2]);
            if (in_array($action, ['checkin', 'in'])) $entries[$key]['checkin'] = $parts[$timeIdx] ?? '';
            if (in_array($action, ['checkout', 'out'])) $entries[$key]['checkout'] = $parts[$timeIdx] ?? '';
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
