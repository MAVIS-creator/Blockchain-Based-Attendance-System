<?php
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
