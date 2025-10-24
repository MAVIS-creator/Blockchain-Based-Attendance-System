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

// Include shared MAC helper
require_once __DIR__ . '/admin/includes/get_mac.php';

$mac = get_mac_from_ip($ip);
$today = date("Y-m-d");

// ✅ Check attendance status
$statusFile = "status.json";
if (!file_exists($statusFile)) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Attendance status file not found.']);
    exit;
}

$statusJson = file_get_contents($statusFile);
$status = json_decode($statusJson, true);
if (!is_array($status) || json_last_error() !== JSON_ERROR_NONE) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Error reading status file.']);
    exit;
}

if (!isset($status[$action]) || !$status[$action]) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => "The $action mode is not currently enabled."]);
    exit;
}

// -----------------------
// Load admin settings (try JSON, else decrypt ENC:)
// -----------------------
$settingsPath = __DIR__ . '/admin/settings.json';
$settings = [];
if (file_exists($settingsPath)) {
    $raw = file_get_contents($settingsPath);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $settings = $decoded;
    } else if (strpos($raw, 'ENC:') === 0) {
        $keyFile = __DIR__ . '/admin/.settings_key';
        if (file_exists($keyFile)) {
            $key = trim(file_get_contents($keyFile));
            $blob = base64_decode(substr($raw,4));
            $iv = substr($blob,0,16);
            $ct = substr($blob,16);
            $plain = openssl_decrypt($ct, 'AES-256-CBC', base64_decode($key), OPENSSL_RAW_DATA, $iv);
            $decoded2 = json_decode($plain, true);
            if (is_array($decoded2)) $settings = $decoded2;
        }
    }
}

// Helper: respond JSON and exit
function fail($code, $message) {
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'code'=>$code,'message'=>$message]);
    exit;
}

// -----------------------
// IP whitelist
// -----------------------
if (!empty($settings['ip_whitelist']) && is_array($settings['ip_whitelist'])) {
    $whitelist = $settings['ip_whitelist'];
    // normalize
    $allowed = false;
    foreach ($whitelist as $w) {
        $w = trim($w);
        if ($w === '') continue;
        if ($w === $ip) { $allowed = true; break; }
    }
    if (!$allowed) {
        fail('IP_BLOCK','Your IP is not allowed to submit attendance.');
    }
}

// -----------------------
// Geo-fence enforcement (if configured)
// -----------------------
if (!empty($settings['geo_fence']) && is_array($settings['geo_fence'])) {
    $gf = $settings['geo_fence'];
    $gfLat = isset($gf['lat']) ? floatval($gf['lat']) : null;
    $gfLng = isset($gf['lng']) ? floatval($gf['lng']) : null;
    $gfRadius = isset($gf['radius_m']) ? intval($gf['radius_m']) : 0;
    if ($gfLat !== null && $gfLng !== null && $gfRadius > 0) {
        // require client to send lat/lng
        $postLat = isset($_POST['lat']) ? floatval($_POST['lat']) : null;
        $postLng = isset($_POST['lng']) ? floatval($_POST['lng']) : null;
        if ($postLat === null || $postLng === null) {
            fail('GEOFENCE_MISSING','Location required for attendance at this time.');
        }
        // haversine
        $earthRadius = 6371000; // meters
        $dLat = deg2rad($postLat - $gfLat);
        $dLon = deg2rad($postLng - $gfLng);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($gfLat)) * cos(deg2rad($postLat)) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $dist = $earthRadius * $c;
        if ($dist > $gfRadius) {
            fail('GEOFENCE_OUTSIDE','You are outside the allowed attendance area.');
        }
    }
}

// -----------------------
// Device identifier (prefer MAC)
// -----------------------
$deviceId = 'NOID';
if (!empty($mac) && $mac !== 'UNKNOWN') $deviceId = $mac;
else $deviceId = hash('sha256', $userAgent);

// -----------------------
// Device cooldown
// -----------------------
$now = time();
if (!empty($settings['device_cooldown_seconds']) && intval($settings['device_cooldown_seconds']) > 0) {
    $cool = intval($settings['device_cooldown_seconds']);
    $cdFile = __DIR__ . '/admin/logs/device_cooldowns_' . $today . '.json';
    $cdData = file_exists($cdFile) ? json_decode(file_get_contents($cdFile), true) : [];
    if (!is_array($cdData)) $cdData = [];
    $key = $fingerprint . '|' . $deviceId;
    $last = isset($cdData[$key]) ? intval($cdData[$key]) : 0;
    if ($last > 0 && ($now - $last) < $cool) {
        $wait = $cool - ($now - $last);
        fail('COOLDOWN','Please wait ' . $wait . ' seconds before checking in again from this device.');
    }
    // update
    $cdData[$key] = $now;
    file_put_contents($cdFile, json_encode($cdData, JSON_PRETTY_PRINT), LOCK_EX);
}

// -----------------------
// User-agent lock
// -----------------------
if (!empty($settings['user_agent_lock'])) {
    $uaFile = __DIR__ . '/admin/logs/fp_useragent_' . $today . '.json';
    $uaData = file_exists($uaFile) ? json_decode(file_get_contents($uaFile), true) : [];
    if (!is_array($uaData)) $uaData = [];
    $uaHash = hash('sha256', $userAgent);
    if (isset($uaData[$fingerprint]) && $uaData[$fingerprint] !== $uaHash) {
        fail('UA_MISMATCH','Device change detected for this fingerprint; attendance blocked.');
    }
    $uaData[$fingerprint] = $uaHash;
    file_put_contents($uaFile, json_encode($uaData, JSON_PRETTY_PRINT), LOCK_EX);
}

// -----------------------
// Enforce one device per fingerprint per day
// -----------------------
if (!empty($settings['enforce_one_device_per_day'])) {
    $mapFile = __DIR__ . '/admin/logs/fp_devices_' . $today . '.json';
    $mapData = file_exists($mapFile) ? json_decode(file_get_contents($mapFile), true) : [];
    if (!is_array($mapData)) $mapData = [];
    $devList = isset($mapData[$fingerprint]) ? (array)$mapData[$fingerprint] : [];
    if (count($devList) > 0 && !in_array($deviceId, $devList)) {
        fail('DEVICE_MISMATCH','This fingerprint has already been used with a different device today.');
    }
    if (!in_array($deviceId, $devList)) $devList[] = $deviceId;
    $mapData[$fingerprint] = $devList;
    file_put_contents($mapFile, json_encode($mapData, JSON_PRETTY_PRINT), LOCK_EX);
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
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Fingerprint does not match this Matric Number.']);
        exit;
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
    // Support old logs (without MAC) and new logs (with MAC).
    if (count($fields) < 7) continue;

    // Possible formats:
    // Old: Name | Matric | Action | Fingerprint | IP | Timestamp | UserAgent
    // New: Name | Matric | Action | Fingerprint | IP | MAC | Timestamp | UserAgent
    if (count($fields) === 7) {
        list($logName, $logMatric, $logAction, $logFingerprint, $logIp, $logTimestamp, $logUserAgent) = $fields;
        $logMac = 'UNKNOWN';
    } else {
        list($logName, $logMatric, $logAction, $logFingerprint, $logIp, $logMac, $logTimestamp, $logUserAgent) = $fields;
    }
    if ($logMatric === $matric && $logAction === $action) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => "This Matric Number has already submitted $action today."]);
    exit;
    }

    // Consult admin setting for preference: prefer_mac true = check MAC first, else check IP first
    $settingsFile = __DIR__ . '/admin/settings.json';
    $preferMac = true;
    if (file_exists($settingsFile)) {
        $s = json_decode(file_get_contents($settingsFile), true);
        if (isset($s['prefer_mac'])) $preferMac = (bool)$s['prefer_mac'];
    }

    $sameDevice = false;
    if ($preferMac) {
        if (isset($logMac) && $logMac !== 'UNKNOWN' && $mac !== 'UNKNOWN' && $logMac === $mac && $logAction === $action) {
            $sameDevice = true;
        }
        if (!$sameDevice && $logIp === $ip && $logAction === $action && $ip !== '127.0.0.1') {
            $sameDevice = true;
        }
    } else {
        if ($logIp === $ip && $logAction === $action && $ip !== '127.0.0.1') {
            $sameDevice = true;
        }
        if (!$sameDevice && isset($logMac) && $logMac !== 'UNKNOWN' && $mac !== 'UNKNOWN' && $logMac === $mac && $logAction === $action) {
            $sameDevice = true;
        }
    }

    if ($sameDevice) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => "This device has already submitted $action today."]);
    exit;
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
        // Standardized failed log format: name | matric | action | fingerprint | ip | mac | timestamp | userAgent | course | reason
        $failedLogEntry = "$name | $matric | failed | $fingerprint | $ip | $mac | $today " . date("H:i:s") . " | $userAgent | $course | NO_CHECKIN\n";
        file_put_contents($failedLog, $failedLogEntry, FILE_APPEND | LOCK_EX);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'You cannot check out without checking in first.']);
        exit;
    }
}
// ✅ Save to .log file (include MAC when available)
$logEntry = "$name | $matric | $action | $fingerprint | $ip | $mac | " . date("Y-m-d H:i:s") . " | $userAgent | $course | -\n";
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

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'message' => "Your $action was recorded successfully!"]);

?>
