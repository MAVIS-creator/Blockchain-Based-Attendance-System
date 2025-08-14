<?php
// Set headers for CSV export
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="attendance_export.csv"');

// Paths
$logDir = __DIR__;
$today = $_GET['logDate'] ?? date('Y-m-d');
$logFile = $logDir . "/$today.log";
$chainFile = dirname(__DIR__) . '/secure_logs/attendance_chain.json';

// Validate chain
$chainValid = true;
$errorMsg = '';
$prevHash = null;

if (!file_exists($chainFile)) {
    $chainValid = false;
    $errorMsg = 'Chain file not found.';
} else {
    $chain = json_decode(file_get_contents($chainFile), true);
    if (!is_array($chain) || count($chain) === 0) {
        $chainValid = false;
        $errorMsg = 'Chain is empty or invalid.';
    } else {
        foreach ($chain as $i => $block) {
            $blockDataForHash = $block;
            unset($blockDataForHash['hash']);

            // Sort keys for consistent hashing
            ksort($blockDataForHash);

            $expectedHash = hash('sha256', json_encode($blockDataForHash, JSON_UNESCAPED_SLASHES) . $prevHash);

            if (($block['hash'] ?? null) !== $expectedHash) {
                $chainValid = false;
                $errorMsg = "Tampering detected at block #$i (hash mismatch)";
                break;
            }
            if ($i > 0 && ($block['prevHash'] ?? null) !== $prevHash) {
                $chainValid = false;
                $errorMsg = "Tampering detected at block #$i (prevHash mismatch)";
                break;
            }

            $prevHash = $block['hash'];
        }
    }
}

// Proceed regardless, but append tampering note in CSV
$lines = [];
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

$output = fopen('php://output', 'w');
fputcsv($output, ['Name', 'Matric', 'Check-In Time', 'Check-Out Time', 'Fingerprint', 'IP', 'Device', 'Course', 'Integrity']);

$combined = [];

// Combine check-in/out into one row
foreach ($lines as $line) {
    $parts = array_map('trim', explode('|', $line));
    if (count($parts) >= 8) {
        [$name, $matric, $action, $fingerprint, $ip, $timestamp, $device, $course] = $parts;
        $key = $name . '|' . $matric;

        if (!isset($combined[$key])) {
            $combined[$key] = [
                'name' => $name,
                'matric' => $matric,
                'checkin' => '',
                'checkout' => '',
                'fingerprint' => $fingerprint,
                'ip' => $ip,
                'device' => $device,
                'course' => $course,
            ];
        }

        if (strtolower($action) === 'checkin') {
            $combined[$key]['checkin'] = $timestamp;
        }
        if (strtolower($action) === 'checkout') {
            $combined[$key]['checkout'] = $timestamp;
        }
    }
}

// Write rows
foreach ($combined as $entry) {
    fputcsv($output, [
        $entry['name'],
        $entry['matric'],
        $entry['checkin'],
        $entry['checkout'],
        $entry['fingerprint'],
        $entry['ip'],
        $entry['device'],
        $entry['course'],
        $chainValid ? '✅ Valid' : '⚠ Tampered'
    ]);
}

fclose($output);
exit;
?>
