<?php

require_once __DIR__ . '/hybrid_dual_write.php';
require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/admin/runtime_storage.php';
require_once __DIR__ . '/admin/cache_helpers.php';
require_once __DIR__ . '/request_timing.php';
require_once __DIR__ . '/request_guard.php';
app_storage_init();
app_request_guard('submit.php', 'public');
request_timing_start('submit.php');

// ✅ Set timezone to Nigeria
date_default_timezone_set('Africa/Lagos');

function post_string($key, $default = '')
{
    if (!isset($_POST[$key])) {
        return $default;
    }

    $value = $_POST[$key];
    if (is_array($value)) {
        return $default;
    }

    return trim((string)$value);
}

function client_request_ip()
{
    $cfIp = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP)) {
        return $cfIp;
    }

    $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($xff !== '') {
        $parts = array_map('trim', explode(',', $xff));
        foreach ($parts as $part) {
            if ($part !== '' && filter_var($part, FILTER_VALIDATE_IP)) {
                return $part;
            }
        }
    }

    return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

function ip_is_lan_or_loopback($ip)
{
    $ip = trim((string)$ip);
    if ($ip === '') {
        return false;
    }

    if ($ip === '127.0.0.1' || $ip === '::1') {
        return true;
    }

    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

// Normalize inputs. Escape on output, validate where rules are strict.
$name = post_string('name');
$matric = preg_replace('/\D+/', '', post_string('matric'));
$fingerprint = post_string('fingerprint');
$action = strtolower(post_string('action'));
$course = post_string('course', 'General');
$course = $course !== '' ? $course : 'General';
$courseNormalized = strtolower(trim($course));

if ($name === '' || $matric === '' || $fingerprint === '' || $action === '') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Missing required attendance fields.']);
    exit;
}

if (!preg_match('/^\d{6,20}$/', $matric)) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Enter a valid matric number using digits only.']);
    exit;
}

if (!in_array($action, ['checkin', 'checkout'], true)) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Invalid attendance action supplied.']);
    exit;
}

$ip = client_request_ip();
$userAgent = $_SERVER['HTTP_USER_AGENT'];

// Include shared MAC helper
require_once __DIR__ . '/admin/includes/get_mac.php';

$mac = 'UNKNOWN';
$today = date("Y-m-d");
$allowServerMacResolution = ip_is_lan_or_loopback($ip) && app_is_local_environment();
$resolveMac = static function () use (&$mac, $ip, $allowServerMacResolution) {
    if ($mac !== 'UNKNOWN') {
        return $mac;
    }
    if (!$allowServerMacResolution) {
        return $mac;
    }
    $macSpan = microtime(true);
    $resolved = get_mac_from_ip($ip);
    $mac = $resolved ?: 'UNKNOWN';
    request_timing_span('resolve_mac', $macSpan, ['resolved' => $mac !== 'UNKNOWN']);
    return $mac;
};

// Extract client token from fingerprint payload format: visitorId_token
$clientToken = '';
if (strpos($fingerprint, '_') !== false) {
    $parts = explode('_', $fingerprint);
    $clientToken = trim((string)end($parts));
}

// ✅ Check attendance status
$statusFile = admin_storage_migrate_file('status.json', app_storage_file('status.json'));
$span = microtime(true);
if (!file_exists($statusFile)) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Attendance status file not found.']);
    exit;
}

$status = admin_cached_json_file('submit_status', $statusFile, [], 2);
if (!is_array($status)) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Error reading status file.']);
    exit;
}

$normalizedStatus = [
    'checkin' => !empty($status['checkin']),
    'checkout' => !empty($status['checkout']),
    'end_time' => isset($status['end_time']) && is_numeric($status['end_time']) ? (int)$status['end_time'] : null,
];
$activeModeConfigured = $normalizedStatus['checkin'] || $normalizedStatus['checkout'];
$timerValid = $normalizedStatus['end_time'] !== null && $normalizedStatus['end_time'] > time();
if ($activeModeConfigured && !$timerValid) {
    $normalizedStatus = ['checkin' => false, 'checkout' => false, 'end_time' => null];
}
if (!$normalizedStatus['checkin'] && !$normalizedStatus['checkout']) {
    $normalizedStatus['end_time'] = null;
}
if (($status['checkin'] ?? null) !== $normalizedStatus['checkin'] ||
    ($status['checkout'] ?? null) !== $normalizedStatus['checkout'] ||
    (($status['end_time'] ?? null) !== $normalizedStatus['end_time'])
) {
    @file_put_contents($statusFile, json_encode($normalizedStatus, JSON_PRETTY_PRINT), LOCK_EX);
}

$status = $normalizedStatus;

if (!isset($status[$action]) || !$status[$action]) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => "The $action mode is not currently enabled."]);
    exit;
}
request_timing_span('load_status', $span, ['action' => $action]);

// -----------------------
// Load admin settings (try JSON, else decrypt ENC:)
// -----------------------
$settingsPath = admin_storage_migrate_file('settings.json', __DIR__ . '/admin/settings.json');
$span = microtime(true);
$settings = [];
if (file_exists($settingsPath)) {
    $raw = file_get_contents($settingsPath);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $settings = $decoded;
    } else if (strpos($raw, 'ENC:') === 0) {
        $keyFile = admin_storage_migrate_file('.settings_key', __DIR__ . '/admin/.settings_key');
        if (file_exists($keyFile)) {
            $key = trim(file_get_contents($keyFile));
            $blob = base64_decode(substr($raw, 4));
            $iv = substr($blob, 0, 16);
            $ct = substr($blob, 16);
            $plain = openssl_decrypt($ct, 'AES-256-CBC', base64_decode($key), OPENSSL_RAW_DATA, $iv);
            $decoded2 = json_decode($plain, true);
            if (is_array($decoded2)) $settings = $decoded2;
        }
    }
}
request_timing_span('load_settings', $span, ['encrypted' => strpos((string)($raw ?? ''), 'ENC:') === 0]);

// Device identity is MAC-first with fingerprint fallback only.
$deviceIdentityMode = 'mac';

$loadTestRelaxUntil = isset($settings['load_test_relax_until']) && is_numeric($settings['load_test_relax_until'])
    ? (int)$settings['load_test_relax_until']
    : 0;
$loadTestRelaxActive = !empty($settings['load_test_relax_enabled']) && $loadTestRelaxUntil > time();
request_timing_note('load_test_relax_active', $loadTestRelaxActive ? 'true' : 'false');

// Helper: respond JSON and exit
function fail($code, $message)
{
    $diagFile = app_storage_file('logs/submit_failures.jsonl');
    $diagEntry = [
        'timestamp' => date('c'),
        'code' => (string)$code,
        'message' => (string)$message,
        'matric' => preg_replace('/\D+/', '', (string)($_POST['matric'] ?? '')),
        'action' => strtolower(trim((string)($_POST['action'] ?? ''))),
        'course' => trim((string)($_POST['course'] ?? '')),
    ];
    @file_put_contents($diagFile, json_encode($diagEntry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);

    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'code' => $code, 'message' => $message]);
    exit;
}

function is_revoked_value($bucket, $value, $now)
{
    if ($value === '' || !is_array($bucket)) return false;
    if (!isset($bucket[$value])) return false;
    $meta = $bucket[$value];
    $expiry = is_array($meta) ? intval($meta['expiry'] ?? 0) : 0;
    if ($expiry !== 0 && $expiry < $now) return false;
    return true;
}

function sanitize_log_field($value)
{
    $v = (string)$value;
    $v = str_replace(["\r", "\n", "|"], ' ', $v);
    return trim(preg_replace('/\s+/', ' ', $v));
}

function normalize_fingerprint_identity_value($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $parts = explode('_', $value, 2);
    return strtolower(trim((string)($parts[0] ?? $value)));
}

function build_attendance_duplicate_index($logFile, $today)
{
    $index = [
        'seen_matric' => [],
        'seen_device' => [],
        'seen_token'  => [],   // Layer 2: localStorage UUID token
        'has_checkin' => [],
    ];

    if (!file_exists($logFile)) {
        return $index;
    }

    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || empty($lines)) {
        return $index;
    }

    foreach ($lines as $line) {
        $fields = array_map('trim', explode('|', (string)$line));
        if (count($fields) < 7 || count($fields) > 10) {
            continue;
        }

        if (count($fields) === 7) {
            list($logName, $logMatric, $logAction, $logFingerprint, $logIp, $logTimestamp, $logUserAgent) = $fields;
            $logMac = 'UNKNOWN';
            $logCourse = 'general';
        } else {
            list($logName, $logMatric, $logAction, $logFingerprint, $logIp, $logMac, $logTimestamp, $logUserAgent) = $fields;
            $logCourse = isset($fields[8]) ? strtolower(trim((string)$fields[8])) : 'general';
        }

        $logDate = substr((string)$logTimestamp, 0, 10);
        if ($logDate !== $today) {
            continue;
        }

        if (!preg_match('/^\d{6,20}$/', (string)$logMatric)) {
            continue;
        }

        $logAction = strtolower(trim((string)$logAction));
        if (!in_array($logAction, ['checkin', 'checkout'], true)) {
            continue;
        }

        $matricKey = (string)$logMatric . '|' . $logAction . '|' . $logCourse;
        $index['seen_matric'][$matricKey] = 1;

        if ($logAction === 'checkin') {
            $index['has_checkin'][(string)$logMatric . '|' . $logCourse] = 1;
        }

        // Layer 1: hardware composite fingerprint (part before the underscore)
        $deviceIdentity = '';
        if ((string)$logMac !== '' && strtoupper((string)$logMac) !== 'UNKNOWN') {
            $deviceIdentity = 'mac:' . (string)$logMac;
        } else {
            $fpIdentity = normalize_fingerprint_identity_value($logFingerprint ?? '');
            if ($fpIdentity !== '') {
                $deviceIdentity = 'fp:' . $fpIdentity . ':' . (string)$logMatric;
            }
        }
        if ($deviceIdentity !== '') {
            $deviceKey = $deviceIdentity . '|' . $logAction . '|' . $logCourse;
            $index['seen_device'][$deviceKey] = 1;
        }

        // Layer 2: localStorage UUID token (part after the underscore)
        $logFpStr = trim((string)($logFingerprint ?? ''));
        if (strpos($logFpStr, '_') !== false) {
            $logToken = trim((string)end(explode('_', $logFpStr)));
            if ($logToken !== '') {
                $tokenKey = 'tok:' . $logToken . '|' . $logAction . '|' . $logCourse;
                $index['seen_token'][$tokenKey] = 1;
            }
        }
    }

    return $index;
}

function detect_proxy_vpn_indicators()
{
    $indicators = [];

    $forwardedHeaders = [
        'HTTP_FORWARDED',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_CLIENT_IP',
        'HTTP_VIA',
        'HTTP_PROXY_CONNECTION',
        'HTTP_TRUE_CLIENT_IP',
        'HTTP_CF_CONNECTING_IP',
    ];

    foreach ($forwardedHeaders as $hdr) {
        $val = trim((string)($_SERVER[$hdr] ?? ''));
        if ($val !== '') {
            $indicators[] = $hdr;
        }
    }

    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remoteAddr === '' || !filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        $indicators[] = 'REMOTE_ADDR_INVALID';
    }

    // If XFF contains multiple hops, treat as proxied path.
    $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($xff !== '' && strpos($xff, ',') !== false) {
        $indicators[] = 'HTTP_X_FORWARDED_FOR_MULTI_HOP';
    }

    return array_values(array_unique($indicators));
}

function geo_distance_m($lat1, $lng1, $lat2, $lng2)
{
    $earthRadius = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

// -----------------------
// VPN / Proxy tunneling enforcement (optional)
// -----------------------
if (!empty($settings['block_vpn_proxy'])) {
    $vpnIndicators = detect_proxy_vpn_indicators();
    if (!empty($vpnIndicators)) {
        request_timing_note('vpn_proxy_blocked', implode(',', $vpnIndicators));
        fail('VPN_PROXY_DETECTED', 'VPN or proxy-like routing was detected. Disable VPN/proxy and retry attendance with your direct network.');
    }
}

// Helper: read/write store with optional encryption using settings key
function read_store($file, $encrypt = false)
{
    if (!file_exists($file)) return [];
    $raw = file_get_contents($file);
    if (!$encrypt) {
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }
    // decrypt
    if (strpos($raw, 'ENC:') !== 0) return [];
    $keyFile = admin_storage_migrate_file('.settings_key', __DIR__ . '/admin/.settings_key');
    if (!file_exists($keyFile)) return [];
    $key = trim(file_get_contents($keyFile));
    $blob = base64_decode(substr($raw, 4));
    $iv = substr($blob, 0, 16);
    $ct = substr($blob, 16);
    $plain = openssl_decrypt($ct, 'AES-256-CBC', base64_decode($key), OPENSSL_RAW_DATA, $iv);
    $d = json_decode($plain, true);
    return is_array($d) ? $d : [];
}

function write_store($file, $data, $encrypt = false)
{
    $payload = json_encode($data, JSON_PRETTY_PRINT);
    if (!$encrypt) {
        file_put_contents($file, $payload, LOCK_EX);
        return;
    }
    $keyFile = admin_storage_migrate_file('.settings_key', __DIR__ . '/admin/.settings_key');
    if (!file_exists($keyFile)) {
        // try to create key
        $k = base64_encode(random_bytes(32));
        @file_put_contents($keyFile, $k);
        @chmod($keyFile, 0600);
    }
    $key = trim(file_get_contents($keyFile));
    $iv = random_bytes(16);
    $ct = openssl_encrypt($payload, 'AES-256-CBC', base64_decode($key), OPENSSL_RAW_DATA, $iv);
    $blob = base64_encode($iv . $ct);
    file_put_contents($file, 'ENC:' . $blob, LOCK_EX);
}

function inspect_fingerprint_atomic($file, $matric, $hashedFingerprint)
{
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $fp = fopen($file, 'c+');
    if (!$fp) {
        return ['ok' => false, 'reason' => 'open_failed'];
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return ['ok' => false, 'reason' => 'lock_failed'];
    }

    rewind($fp);
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: '[]', true);
    if (!is_array($data)) $data = [];

    if (isset($data[$matric])) {
        $matched = ((string)$data[$matric] === (string)$hashedFingerprint);
        flock($fp, LOCK_UN);
        fclose($fp);
        return ['ok' => true, 'status' => $matched ? 'matched' : 'mismatch'];
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    return ['ok' => true, 'status' => 'unlinked'];
}

function link_fingerprint_if_missing_atomic($file, $matric, $hashedFingerprint)
{
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $fp = fopen($file, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    rewind($fp);
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: '[]', true);
    if (!is_array($data)) $data = [];

    if (!isset($data[$matric])) {
        $data[$matric] = $hashedFingerprint;
        $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, $payload);
        fflush($fp);
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

// -----------------------
// Revocation enforcement (token / IP / MAC)
// -----------------------
$revokedFile = admin_storage_migrate_file('revoked.json', app_storage_file('revoked.json'));
if (file_exists($revokedFile)) {
    $revokedData = admin_cached_json_file('submit_revoked', $revokedFile, [], 2);
    if (is_array($revokedData)) {
        $nowTs = time();
        $tokensBucket = is_array($revokedData['tokens'] ?? null) ? $revokedData['tokens'] : [];
        $ipsBucket = is_array($revokedData['ips'] ?? null) ? $revokedData['ips'] : [];
        $macsBucket = is_array($revokedData['macs'] ?? null) ? $revokedData['macs'] : [];

        if (is_revoked_value($tokensBucket, $clientToken, $nowTs)) {
            fail('TOKEN_REVOKED', 'This client token has been revoked by an administrator.');
        }
        if (!empty($macsBucket)) {
            $mac = $resolveMac();
        }
        if ($mac !== 'UNKNOWN' && is_revoked_value($macsBucket, $mac, $nowTs)) {
            fail('MAC_REVOKED', 'Your device MAC has been revoked by an administrator.');
        }
    }
}

// -----------------------
// Geo-fence enforcement (if configured)
// -----------------------
if (!empty($settings['geo_fence_enabled']) && !empty($settings['geo_fence']) && is_array($settings['geo_fence'])) {
    $geoSpan = microtime(true);
    $gf = $settings['geo_fence'];
    $gfLat = isset($gf['lat']) ? floatval($gf['lat']) : null;
    $gfLng = isset($gf['lng']) ? floatval($gf['lng']) : null;
    $gfRadius = isset($gf['radius_m']) ? intval($gf['radius_m']) : 0;
    if ($gfLat !== null && $gfLng !== null && $gfRadius > 0) {
        // Require numeric client location payload.
        $rawLat = post_string('lat');
        $rawLng = post_string('lng');
        if ($rawLat === '' || $rawLng === '') {
            request_timing_note('geo_fence_result', 'missing_client_location');
            fail('GEOFENCE_MISSING', 'Location required for attendance at this time. Enable device location (high accuracy) and disable VPN/proxy if active.');
        }
        if (!is_numeric($rawLat) || !is_numeric($rawLng)) {
            request_timing_note('geo_fence_result', 'invalid_client_location');
            fail('GEOFENCE_INVALID_COORDS', 'Invalid location payload. Please retry with location services enabled.');
        }

        $postLat = floatval($rawLat);
        $postLng = floatval($rawLng);
        if ($postLat < -90 || $postLat > 90 || $postLng < -180 || $postLng > 180) {
            request_timing_note('geo_fence_result', 'out_of_range_client_location');
            fail('GEOFENCE_INVALID_COORDS', 'Location coordinates are out of range. Please retry attendance.');
        }

        $dist = geo_distance_m($gfLat, $gfLng, $postLat, $postLng);
        if ($dist > $gfRadius) {
            request_timing_note('geo_fence_result', 'outside');
            request_timing_note('geo_fence_distance_m', round($dist, 2));
            fail('GEOFENCE_OUTSIDE', 'You are outside the allowed attendance area. If this is unexpected, disable VPN/proxy and retry with accurate GPS location.');
        }

        request_timing_note('geo_fence_result', 'inside');
        request_timing_note('geo_fence_distance_m', round($dist, 2));
    }
    request_timing_span('geo_fence_check', $geoSpan, ['enabled' => true]);
} else {
    request_timing_note('geo_fence_result', 'disabled');
}

// -----------------------
// Device identifier based on configured mode
// -----------------------
$hasMac = !empty($mac) && $mac !== 'UNKNOWN';
$mac = $resolveMac();
$hasMac = !empty($mac) && $mac !== 'UNKNOWN';
$deviceId = $hasMac ? ('mac:' . $mac) : ('fp:' . hash('sha256', $fingerprint));

// -----------------------
// Device cooldown
// -----------------------
$now = time();
if (!$loadTestRelaxActive && !empty($settings['device_cooldown_seconds']) && intval($settings['device_cooldown_seconds']) > 0) {
    $cool = intval($settings['device_cooldown_seconds']);
    $cdFile = app_storage_file('logs/device_cooldowns_' . $today . '.json');
    $cdData = read_store($cdFile, !empty($settings['encrypt_logs']));
    $key = $fingerprint . '|' . $deviceId;
    $last = isset($cdData[$key]) ? intval($cdData[$key]) : 0;
    if ($last > 0 && ($now - $last) < $cool) {
        $wait = $cool - ($now - $last);
        fail('COOLDOWN', 'Please wait ' . $wait . ' seconds before checking in again from this device.');
    }
    // update
    $cdData[$key] = $now;
    write_store($cdFile, $cdData, !empty($settings['encrypt_logs']));
}

// -----------------------
// User-agent lock
// -----------------------
if (!$loadTestRelaxActive && !empty($settings['user_agent_lock'])) {
    $uaFile = app_storage_file('logs/fp_useragent_' . $today . '.json');
    $uaData = read_store($uaFile, !empty($settings['encrypt_logs']));
    $uaHash = hash('sha256', $userAgent);
    if (isset($uaData[$fingerprint]) && $uaData[$fingerprint] !== $uaHash) {
        fail('UA_MISMATCH', 'Device change detected for this fingerprint; attendance blocked.');
    }
    $uaData[$fingerprint] = $uaHash;
    write_store($uaFile, $uaData, !empty($settings['encrypt_logs']));
}

// -----------------------
// Enforce one device per fingerprint per day
// -----------------------
if (!$loadTestRelaxActive && !empty($settings['enforce_one_device_per_day'])) {
    $mapFile = app_storage_file('logs/fp_devices_' . $today . '.json');
    $mapData = read_store($mapFile, !empty($settings['encrypt_logs']));
    $devList = isset($mapData[$fingerprint]) ? (array)$mapData[$fingerprint] : [];
    if (count($devList) > 0 && !in_array($deviceId, $devList)) {
        fail('DEVICE_MISMATCH', 'This fingerprint has already been used with a different device today.');
    }
    if (!in_array($deviceId, $devList)) $devList[] = $deviceId;
    $mapData[$fingerprint] = $devList;
    write_store($mapFile, $mapData, !empty($settings['encrypt_logs']));
}

// Load/update fingerprints atomically (toggleable from settings)
$fingerprintFile = admin_storage_migrate_file('fingerprints.json', app_storage_file('fingerprints.json'));
$hashedFingerprint = hash('sha256', $fingerprint);
$shouldLinkFingerprintOnSuccess = false;
$fpInspect = inspect_fingerprint_atomic($fingerprintFile, $matric, $hashedFingerprint);

if (empty($fpInspect['ok'])) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Unable to verify fingerprint at the moment. Please try again.']);
    exit;
}

if (!empty($settings['require_fingerprint_match'])) {
    if (($fpInspect['status'] ?? '') === 'mismatch') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Fingerprint does not match this Matric Number.']);
        exit;
    }
    if (($fpInspect['status'] ?? '') === 'unlinked') {
        $shouldLinkFingerprintOnSuccess = true;
    }
} else {
    // In relaxed mode, defer first-time link until attendance write succeeds.
    if (($fpInspect['status'] ?? '') === 'unlinked') {
        $shouldLinkFingerprintOnSuccess = true;
    }
}
request_timing_note('fingerprint_link_plan', $shouldLinkFingerprintOnSuccess ? 'defer_until_success' : 'already_linked_or_mismatch_checked');

// ✅ Prepare log file paths
$logDir = app_storage_file('logs');
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// ✅ SAFEGUARD: Clean up old log files older than 7 days
$cleanupSpan = microtime(true);
$sevenDaysAgo = time() - (7 * 86400);
if (is_dir($logDir)) {
    $files = @scandir($logDir);
    if (is_array($files)) {
        foreach ($files as $file) {
            if (in_array($file, ['.', '..'])) continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}.*\.log$/', $file)) continue;
            $filePath = $logDir . '/' . $file;
            if (is_file($filePath) && filemtime($filePath) < $sevenDaysAgo) {
                @unlink($filePath);
            }
        }
    }
}
request_timing_span('log_cleanup', $cleanupSpan);

$logFile = $logDir . "/" . $today . ".log";
$failedLog = $logDir . "/" . $today . "_failed_attempts.log";

$logReason = '-';
$logEntry = sanitize_log_field($name) . ' | ' . sanitize_log_field($matric) . ' | ' . sanitize_log_field($action) . ' | ' . sanitize_log_field($fingerprint) . ' | ' . sanitize_log_field($ip) . ' | ' . sanitize_log_field($mac) . ' | ' . date("Y-m-d H:i:s") . ' | ' . sanitize_log_field($userAgent) . ' | ' . sanitize_log_field($course) . ' | ' . sanitize_log_field($logReason) . "\n";

if (!$loadTestRelaxActive) {
    $dupSpan = microtime(true);
    $currentFingerprintIdentity = normalize_fingerprint_identity_value($fingerprint);
    $currentDeviceIdentity = '';
    if ($mac !== 'UNKNOWN') {
        $currentDeviceIdentity = 'mac:' . $mac;
    } elseif ($currentFingerprintIdentity !== '') {
        // Include matric in fp-based identity to prevent false cross-student collisions.
        // Two students on similar devices can share the same FingerprintJS visitorId;
        // scoping per-matric ensures only the same student's re-submission is blocked.
        $currentDeviceIdentity = 'fp:' . $currentFingerprintIdentity . ':' . $matric;
    }

    $indexFile = $logDir . '/attendance_index_' . $today . '.json';
    $indexFp = fopen($indexFile, 'c+');
    if (!$indexFp || !flock($indexFp, LOCK_EX)) {
        if (is_resource($indexFp)) {
            fclose($indexFp);
        }
        fail('DUPLICATE_INDEX_LOCK_FAILED', 'Attendance is busy right now. Please retry in a moment.');
    }

    $rebuiltFromLog = false;
    rewind($indexFp);
    $indexRaw = stream_get_contents($indexFp);
    $index = json_decode($indexRaw ?: '[]', true);
    if (!is_array($index) || !isset($index['seen_matric'], $index['seen_device'], $index['has_checkin'])) {
        $index = build_attendance_duplicate_index($logFile, $today);
        $rebuiltFromLog = true;
    }
    // Ensure seen_token exists even if index was written by an older version.
    if (!isset($index['seen_token']) || !is_array($index['seen_token'])) {
        $index['seen_token'] = [];
    }

    $matricKey = $matric . '|' . $action . '|' . $courseNormalized;
    $deviceKey  = $currentDeviceIdentity !== '' ? ($currentDeviceIdentity . '|' . $action . '|' . $courseNormalized) : '';
    $checkinKey = $matric . '|' . $courseNormalized;
    // Layer 2: localStorage token key — device-level, NOT scoped per-matric,
    // so the same browser/device cannot submit under two different matric numbers.
    $tokenKey = ($clientToken !== '') ? ('tok:' . $clientToken . '|' . $action . '|' . $courseNormalized) : '';

    if (isset($index['seen_matric'][$matricKey])) {
        flock($indexFp, LOCK_UN);
        fclose($indexFp);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => "This Matric Number has already submitted $action for {$course} today."]);
        exit;
    }

    // Layer 1: hardware composite fingerprint check
    if ($deviceKey !== '' && isset($index['seen_device'][$deviceKey])) {
        flock($indexFp, LOCK_UN);
        fclose($indexFp);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => "This device has already submitted $action for {$course} today."]);
        exit;
    }

    // Layer 2: localStorage token check — catches re-submissions even if hardware
    // fingerprint changes (e.g. incognito mode or browser data cleared).
    if ($tokenKey !== '' && isset($index['seen_token'][$tokenKey])) {
        flock($indexFp, LOCK_UN);
        fclose($indexFp);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => "This device has already submitted $action for {$course} today."]);
        exit;
    }

    if ($action === 'checkout' && !isset($index['has_checkin'][$checkinKey])) {
        $failedLogEntry = sanitize_log_field($name) . ' | ' . sanitize_log_field($matric) . ' | failed | ' . sanitize_log_field($fingerprint) . ' | ' . sanitize_log_field($ip) . ' | ' . sanitize_log_field($mac) . ' | ' . $today . ' ' . date("H:i:s") . ' | ' . sanitize_log_field($userAgent) . ' | ' . sanitize_log_field($course) . " | NO_CHECKIN\n";
        file_put_contents($failedLog, $failedLogEntry, FILE_APPEND | LOCK_EX);
        flock($indexFp, LOCK_UN);
        fclose($indexFp);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => "You cannot check out for {$course} without checking in first."]);
        exit;
    }

    $logWriteSpan = microtime(true);
    $logWriteResult = file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    if ($logWriteResult === false) {
        flock($indexFp, LOCK_UN);
        fclose($indexFp);
        fail('ATTENDANCE_LOG_WRITE_FAILED', 'Unable to save attendance right now. Please retry.');
    }
    request_timing_span('append_attendance_log', $logWriteSpan);

    $index['seen_matric'][$matricKey] = 1;
    if ($deviceKey !== '') {
        $index['seen_device'][$deviceKey] = 1;   // Layer 1 written
    }
    if ($tokenKey !== '') {
        $index['seen_token'][$tokenKey] = 1;     // Layer 2 written
    }
    if ($action === 'checkin') {
        $index['has_checkin'][$checkinKey] = 1;
    }

    rewind($indexFp);
    ftruncate($indexFp, 0);
    fwrite($indexFp, json_encode($index, JSON_UNESCAPED_SLASHES));
    fflush($indexFp);
    flock($indexFp, LOCK_UN);
    fclose($indexFp);

    request_timing_span('duplicate_scan', $dupSpan, ['mode' => 'index', 'rebuilt' => $rebuiltFromLog ? 1 : 0]);
} else {
    $logWriteSpan = microtime(true);
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    request_timing_span('append_attendance_log', $logWriteSpan);
}

if ($shouldLinkFingerprintOnSuccess) {
    $linkSpan = microtime(true);
    $linked = link_fingerprint_if_missing_atomic($fingerprintFile, $matric, $hashedFingerprint);
    request_timing_span('link_fingerprint_after_success', $linkSpan, ['linked' => (bool)$linked]);
}

$pipelineDiagFile = app_storage_file('logs/submit_pipeline.jsonl');
$pipelineEntry = [
    'timestamp' => date('c'),
    'matric' => $matric,
    'action' => $action,
    'course' => $course,
    'attendance_log_written' => true,
    'fingerprint_deferred_link' => (bool)$shouldLinkFingerprintOnSuccess,
    'fingerprint_linked_after_success' => isset($linked) ? (bool)$linked : false,
];
@file_put_contents($pipelineDiagFile, json_encode($pipelineEntry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);

// ✅ Save as blockchain block (JSON)
$chainSpan = microtime(true);
$chainFile = app_storage_file('secure_logs/attendance_chain.json');
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
request_timing_span('write_chain', $chainSpan, ['chain_blocks' => count($chain)]);

// ✅ Send success response to the browser IMMEDIATELY.
// The .log file and local blockchain chain are already written above — those are
// the tamper-proof records. Supabase dual-write + Polygon anchoring run AFTER
// the response is flushed so the user never waits on network I/O.
header('Content-Type: application/json');
header('Content-Length: ' . strlen(json_encode(['ok' => true, 'message' => "Your $action was recorded successfully!"])));
echo json_encode(['ok' => true, 'message' => "Your $action was recorded successfully!"]);

// Flush the response to the browser now.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request(); // On Azure/Nginx-FPM: sends response, keeps PHP running
} elseif (function_exists('ob_end_flush')) {
    ob_end_flush();
    flush();
} else {
    flush();
}

// ─────────────────────────────────────────────────────────────────────────────
// BACKGROUND WORK — runs after user already sees "recorded successfully".
// None of this affects the response the student receives.
// ─────────────────────────────────────────────────────────────────────────────

// ✅ Optional hybrid dual-write to Supabase (file + local chain remain source of truth).
$dualWriteSpan = microtime(true);
hybrid_dual_write('attendance', 'attendance_logs', [
    'timestamp'  => date('c'),
    'name'       => $name,
    'matric'     => $matric,
    'action'     => $action,
    'fingerprint'=> $fingerprint,
    'ip'         => $ip,
    'mac'        => $mac,
    'user_agent' => $userAgent,
    'course'     => $course,
    'reason'     => $logReason,
    'chain_hash' => $block['hash'],
]);
request_timing_span('hybrid_dual_write', $dualWriteSpan);

// Auto-send trigger once a full attendance cycle (checkin + checkout) is complete.
$autoSendEnabled   = !empty($settings['auto_send']['enabled']);
$autoSendRecipient = trim((string)($settings['auto_send']['recipient'] ?? ''));
$autoSendFormat    = strtolower(trim((string)($settings['auto_send']['format'] ?? 'csv')));
if (!in_array($autoSendFormat, ['csv', 'pdf'], true)) {
    $autoSendFormat = 'csv';
}

if ($autoSendEnabled && $autoSendRecipient !== '' && filter_var($autoSendRecipient, FILTER_VALIDATE_EMAIL)) {
    $postWriteLines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $cycleCheckin   = 0;
    $cycleCheckout  = 0;
    foreach ($postWriteLines as $ln) {
        $parts = array_map('trim', explode('|', (string)$ln));
        if (!isset($parts[1], $parts[2])) continue;
        if ($parts[1] !== $matric) continue;
        $lineCourse = isset($parts[8]) ? strtolower(trim((string)$parts[8])) : 'general';
        if ($lineCourse !== $courseNormalized) continue;
        $lineAction = strtolower((string)$parts[2]);
        if ($lineAction === 'checkin')  $cycleCheckin++;
        elseif ($lineAction === 'checkout') $cycleCheckout++;
    }

    if ($cycleCheckin > 0 && $cycleCheckout > 0) {
        $trackerFile = admin_storage_migrate_file('auto_send_tracker_submit.json', app_storage_file('auto_send_tracker_submit.json'));
        if (!file_exists($trackerFile)) {
            @file_put_contents($trackerFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
        $fpTracker = fopen($trackerFile, 'c+');
        if ($fpTracker && flock($fpTracker, LOCK_EX)) {
            rewind($fpTracker);
            $trackerRaw = stream_get_contents($fpTracker);
            $tracker = json_decode($trackerRaw ?: '[]', true);
            if (!is_array($tracker)) $tracker = [];

            $markerKey = $today . '|' . $matric . '|' . $courseNormalized;
            if (!isset($tracker[$markerKey])) {
                $tracker[$markerKey] = [
                    'triggered_at' => date('c'),
                    'date'   => $today,
                    'matric' => $matric,
                    'course' => $course,
                    'status' => 'queued',
                ];
                // Fire auto-send as a detached background process (non-blocking).
                $cmd = escapeshellarg(PHP_BINARY)
                    . ' ' . escapeshellarg(__DIR__ . '/admin/auto_send_logs.php')
                    . ' ' . escapeshellarg($today)
                    . ' --force'
                    . ' --recipient=' . escapeshellarg($autoSendRecipient)
                    . ' --format='    . escapeshellarg($autoSendFormat)
                    . ' > /dev/null 2>&1 &';
                @exec($cmd);
                $tracker[$markerKey]['status'] = 'dispatched';
                request_timing_note('auto_send_triggered', 'dispatched_background');
            } else {
                request_timing_note('auto_send_triggered', 'already_sent');
            }
            rewind($fpTracker);
            ftruncate($fpTracker, 0);
            fwrite($fpTracker, json_encode($tracker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($fpTracker);
            flock($fpTracker, LOCK_UN);
            fclose($fpTracker);
        } elseif (is_resource($fpTracker)) {
            fclose($fpTracker);
        }
    }
}

// ⚡ Self-contained log anchoring — runs in background after response is sent.
// Hashes today's .log file + the latest chain block hash and pushes both to
// Supabase as an immutable external witness record.  No Polygon, no gas fees,
// no RPC latency — just pure SHA-256 verification stored on a separate system.
require_once __DIR__ . '/chain_anchor.php';
$anchorResult = chain_anchor_log($logFile, $today, $course, $block['hash']);
if (!$anchorResult['ok']) {
    error_log('Chain anchor failed: ' . ($anchorResult['error'] ?? 'unknown'));
} else {
    request_timing_note('log_anchor_hash', $anchorResult['hash'] ?? '');
}
