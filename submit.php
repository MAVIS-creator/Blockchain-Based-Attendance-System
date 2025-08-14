<?php

// ✅ Set timezone to Nigeria
date_default_timezone_set('Africa/Lagos');

// 🔒 Sanitize inputs
$name = filter_var(trim($_POST['name']), FILTER_SANITIZE_STRING);
$matric = filter_var(trim($_POST['matric']), FILTER_SANITIZE_STRING);
$fingerprint = filter_var(trim($_POST['fingerprint']), FILTER_SANITIZE_STRING);
$action = filter_var($_POST['action'], FILTER_SANITIZE_STRING);
$course = isset($_POST['course']) ? filter_var($_POST['course'], FILTER_SANITIZE_STRING) : "General";

$ip = $_SERVER['REMOTE_ADDR'];
$userAgent = $_SERVER['HTTP_USER_AGENT'];
$today = date("Y-m-d");

// ✅ Check attendance status
$statusFile = "status.json";
if (!file_exists($statusFile)) {
    die("Attendance status file not found.");   
}

$statusJson = file_get_contents($statusFile);
$status = json_decode($statusJson, true);
if (!is_array($status) || json_last_error() !== JSON_ERROR_NONE) {
    die("Error reading status file.");
}

if (!isset($status[$action]) || !$status[$action]) {
    die("The $action mode is not currently enabled.");
}

// Load existing fingerprints
$fingerprintFile = __DIR__ . '/admin/fingerprints.json';
$fingerprintsData = file_exists($fingerprintFile) ? json_decode(file_get_contents($fingerprintFile), true) : [];
if (!is_array($fingerprintsData)) {
    $fingerprintsData = [];
}

$hashedFingerprint = hash('sha256', $fingerprint);

// If fingerprint is already linked to this matric, check
if (isset($fingerprintsData[$matric])) {
    if ($fingerprintsData[$matric] !== $hashedFingerprint) {
        die("❌ Fingerprint does not match this Matric Number.");
    }
} else {
    // Link it automatically since it’s not yet saved
    $fingerprintsData[$matric] = $hashedFingerprint;
    file_put_contents($fingerprintFile, json_encode($fingerprintsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// ✅ Prepare log file paths
$logDir = __DIR__ . "/admin/logs";
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logFile = $logDir . "/" . $today . ".log";
$failedLog = $logDir . "/" . $today . "_failed_attempts.log";

// ✅ Read log lines
$lines = file_exists($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

// 🔐 Check for duplicate actions
foreach ($lines as $line) {
    $fields = array_map('trim', explode('|', $line));
    if (count($fields) < 7) continue;

    list($logName, $logMatric, $logAction, $logFingerprint, $logIp, $logTimestamp, $logUserAgent) = $fields;

    if ($logMatric === $matric && $logAction === $action) {
        die("This Matric Number has already submitted $action today.");
    }

    if ($logIp === $ip && $logAction === $action && $ip !== '127.0.0.1') {
        die("This device has already submitted $action today.");
    }
}

// ⛔ Block checkout if no prior check-in
if ($action === "checkout") {
    $hasCheckedIn = false;

    foreach (array_reverse($lines) as $line) {
        $fields = array_map('trim', explode('|', $line));
        if (count($fields) < 4) continue;

        if ($fields[1] === $matric && $fields[2] === "checkin") {
            $hasCheckedIn = true;
            break;
        }
    }

    if (!$hasCheckedIn) {
        $failedLogEntry = "$name | $matric | $ip | $fingerprint | $today " . date("H:i:s") . " | $userAgent | $course\n";
        file_put_contents($failedLog, $failedLogEntry, FILE_APPEND | LOCK_EX);
        die("❌ You cannot check out without checking in first.");
    }
}

// ✅ Save to .log file
$logEntry = "$name | $matric | $action | $fingerprint | $ip | " . date("Y-m-d H:i:s") . " | $userAgent | $course\n";
file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

// ✅ Save as blockchain block (JSON)
$chainFile = __DIR__ . '/secure_logs/attendance_chain.json';
$chain = file_exists($chainFile) ? json_decode(file_get_contents($chainFile), true) : [];
if (!is_array($chain)) {
    $chain = [];
}

$prevHash = count($chain) > 0 ? $chain[count($chain) - 1]['hash'] : null;

$block = [
    'timestamp' => date('Y-m-d H:i:s'),
    'name'      => $name,
    'matric'    => $matric,
    'action'    => $action,
    'fingerprint' => $fingerprint,
    'ip'        => $ip,
    'userAgent' => $userAgent,
    'course'    => $course,
    'prevHash'  => $prevHash
];

$blockDataForHash = $block;
unset($blockDataForHash['hash']);
ksort($blockDataForHash);

$block['hash'] = hash('sha256', json_encode($blockDataForHash, JSON_UNESCAPED_SLASHES) . $prevHash);

$chain[] = $block;
file_put_contents($chainFile, json_encode($chain, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

// ⚡ Optional: Polygon integration
require_once __DIR__ . '/polygon_hash.php';
try {
    $txHash = sendLogHashToPolygon($logFile);
} catch (Exception $e) {
    error_log('Polygon error: ' . $e->getMessage());
}

echo "✅ Your $action was recorded successfully!";

?>
