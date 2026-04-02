<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__) . '/log_helpers.php';
require_once dirname(__DIR__, 2) . '/storage_helpers.php';
app_storage_init();

// Ensure admin is authenticated
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Get the selected date from URL
$selectedDate = $_GET['logDate'] ?? date('Y-m-d');

// Build log file path
$logFile = app_storage_file("logs/{$selectedDate}_failed_attempts.log");

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

// Write CSV headers (include MAC and fingerprint)
fputcsv($output, ['Name', 'Matric Number', 'Action', 'Fingerprint', 'IP Address', 'MAC', 'Timestamp', 'Device Info', 'Course', 'Reason']);

foreach (admin_failed_attempt_entries_for_date($logFile, 15) as $entry) {
    fputcsv($output, [
        $entry['name'] ?? '',
        $entry['matric'] ?? '',
        $entry['action'] ?? '',
        $entry['fingerprint'] ?? '',
        $entry['ip'] ?? '',
        $entry['mac'] ?? 'UNKNOWN',
        $entry['timestamp'] ?? '',
        $entry['device'] ?? '',
        $entry['course'] ?? '',
        $entry['reason'] ?? '',
    ]);
}

fclose($output);
exit;
