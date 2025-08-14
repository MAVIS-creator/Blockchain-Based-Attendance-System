<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure admin is authenticated
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Get the selected date from URL
$selectedDate = $_GET['logDate'] ?? date('Y-m-d');

// Build log file path
$logFile = __DIR__ . "/{$selectedDate}_failed_attempts.log";

if (!file_exists($logFile)) {
    header("HTTP/1.0 404 Not Found");
    echo "Failed attempts log not found for {$selectedDate}";
    exit;
}

// Prepare file for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="failed_attempts_' . $selectedDate . '.csv"');

// Open output buffer
$output = fopen('php://output', 'w');

// Write CSV headers
fputcsv($output, ['Name', 'Matric Number', 'IP Address', 'Timestamp', 'Device Info', 'Course']);

// Read and parse log file
$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    $parts = array_map('trim', explode('|', $line));
    if (count($parts) >= 6) {
        fputcsv($output, $parts);
    }
}

fclose($output);
exit;
