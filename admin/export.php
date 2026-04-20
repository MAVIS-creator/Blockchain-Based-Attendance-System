<?php
require_once __DIR__ . '/session_bootstrap.php';
if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    exit('Forbidden');
}

$logFile = "log.txt";

if (!file_exists($logFile)) {
    die("Log file not found.");
}

// Set headers to force download as a CSV
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="attendance.csv"');

// Open output stream
$output = fopen('php://output', 'w');

// Write CSV column headers (include MAC and Course/Reason when present)
fputcsv($output, ['Name', 'Matric Number', 'Action', 'Fingerprint ID', 'IP Address', 'MAC', 'Timestamp', 'Device Info', 'Course', 'Reason']);

// Read the log file and convert each entry to a CSV row
$lines = file($logFile);
foreach ($lines as $line) {
    $fields = array_map('trim', explode('|', $line));
    if (count($fields) >= 7) {
        fputcsv($output, $fields);
    }
}

fclose($output);
exit;
?>
