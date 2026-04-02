<?php
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="attendance_export.csv"');

require_once dirname(__DIR__, 2) . '/storage_helpers.php';
require_once dirname(__DIR__) . '/cache_helpers.php';
require_once dirname(__DIR__) . '/log_helpers.php';
app_storage_init();

$logDir = app_storage_file('logs');
$today = $_GET['logDate'] ?? date('Y-m-d');
$logFile = $logDir . "/$today.log";
$chainFile = app_storage_migrate_file('secure_logs/attendance_chain.json', dirname(__DIR__) . '/secure_logs/attendance_chain.json');

$chainValid = true;
$prevHash = null;
$chain = admin_cached_json_file('attendance_chain_export', $chainFile, [], 15);
if (empty($chain)) {
    $chainValid = false;
} else {
    foreach ($chain as $i => $block) {
        $blockDataForHash = $block;
        unset($blockDataForHash['hash']);
        ksort($blockDataForHash);
        $expectedHash = hash('sha256', json_encode($blockDataForHash, JSON_UNESCAPED_SLASHES) . $prevHash);

        if (($block['hash'] ?? null) !== $expectedHash || ($i > 0 && ($block['prevHash'] ?? null) !== $prevHash)) {
            $chainValid = false;
            break;
        }
        $prevHash = $block['hash'];
    }
}

$output = fopen('php://output', 'w');
fputcsv($output, ['Name', 'Matric', 'Check-In Time', 'Check-Out Time', 'Fingerprint', 'IP', 'MAC', 'Device', 'Course', 'Integrity']);

$combined = [];
foreach (admin_attendance_entries_for_date_parsed($logFile, 15) as $parsed) {
    $key = ($parsed['name'] ?? '') . '|' . ($parsed['matric'] ?? '');
    if (!isset($combined[$key])) {
        $combined[$key] = [
            'name' => $parsed['name'] ?? '',
            'matric' => $parsed['matric'] ?? '',
            'checkin' => '',
            'checkout' => '',
            'fingerprint' => $parsed['fingerprint'] ?? '',
            'ip' => $parsed['ip'] ?? '',
            'mac' => $parsed['mac'] ?? 'UNKNOWN',
            'device' => $parsed['device'] ?? '',
            'course' => $parsed['course'] ?? '',
        ];
    }

    $action = strtolower((string)($parsed['action'] ?? ''));
    if ($action === 'checkin') {
        $combined[$key]['checkin'] = $parsed['timestamp'] ?? '';
    } elseif ($action === 'checkout') {
        $combined[$key]['checkout'] = $parsed['timestamp'] ?? '';
    }
}

foreach ($combined as $entry) {
    fputcsv($output, [
        $entry['name'],
        $entry['matric'],
        $entry['checkin'],
        $entry['checkout'],
        $entry['fingerprint'],
        $entry['ip'],
        $entry['mac'],
        $entry['device'],
        $entry['course'],
        $chainValid ? 'Valid' : 'Tampered',
    ]);
}

fclose($output);
exit;
