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

$ip = $_SERVER['REMOTE_ADDR'];
$userAgent = $_SERVER['HTTP_USER_AGENT'];

// Include shared MAC helper
require_once __DIR__ . '/admin/includes/get_mac.php';

$mac = 'UNKNOWN';
$today = date("Y-m-d");
$resolveMac = static function () use (&$mac, $ip) {
    if ($mac !== 'UNKNOWN') {
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

        $rawAccuracy = post_string('geo_accuracy_m');
        $accuracyM = is_numeric($rawAccuracy) ? max(0.0, floatval($rawAccuracy)) : null;
        $accuracyCapM = isset($settings['geo_fence_max_accuracy_m']) && is_numeric($settings['geo_fence_max_accuracy_m'])
            ? max(20.0, floatval($settings['geo_fence_max_accuracy_m']))
            : 250.0;
        $baseAccuracyBufferM = isset($settings['geo_fence_accuracy_buffer_m']) && is_numeric($settings['geo_fence_accuracy_buffer_m'])
            ? max(0.0, floatval($settings['geo_fence_accuracy_buffer_m']))
            : 40.0;
        $accuracyBufferCapM = isset($settings['geo_fence_accuracy_buffer_cap_m']) && is_numeric($settings['geo_fence_accuracy_buffer_cap_m'])
            ? max($baseAccuracyBufferM, floatval($settings['geo_fence_accuracy_buffer_cap_m']))
            : 250.0;

        // Relaxed mode: low-accuracy fixes are tolerated with a wider buffer.
        if ($accuracyM !== null && $accuracyM > $accuracyCapM) {
            request_timing_note('geo_fence_result', 'low_accuracy_relaxed');
            request_timing_note('geo_fence_accuracy_m', round($accuracyM, 2));
            $accuracyM = $accuracyCapM;
        }

        $rawClientTs = post_string('geo_client_ts');
        if (is_numeric($rawClientTs)) {
            $clientTsMs = intval($rawClientTs);
            $nowMs = (int)round(microtime(true) * 1000);
            $maxClientAgeMs = isset($settings['geo_fence_client_max_age_ms']) && is_numeric($settings['geo_fence_client_max_age_ms'])
                ? max(30000, intval($settings['geo_fence_client_max_age_ms']))
                : 180000;
            $ageMs = abs($nowMs - $clientTsMs);
            request_timing_note('geo_fence_client_age_ms', $ageMs);
            if ($ageMs > $maxClientAgeMs) {
                request_timing_note('geo_fence_result', 'stale_location');
                fail('GEOFENCE_STALE_LOCATION', 'Location fix is stale. Refresh location and retry attendance.');
            }
        }

        $accuracyBufferM = $baseAccuracyBufferM;
        if ($accuracyM !== null) {
            $accuracyBufferM = max($baseAccuracyBufferM, min($accuracyBufferCapM, $accuracyM * 1.25));
        }

        $dist = geo_distance_m($gfLat, $gfLng, $postLat, $postLng);
        $allowedRadiusM = $gfRadius + $accuracyBufferM;
        if ($dist > $allowedRadiusM) {
            request_timing_note('geo_fence_result', 'outside');
            request_timing_note('geo_fence_distance_m', round($dist, 2));
            request_timing_note('geo_fence_allowed_radius_m', round($allowedRadiusM, 2));
            fail('GEOFENCE_OUTSIDE', 'You are outside the allowed attendance area. If this is unexpected, disable VPN/proxy and retry with accurate GPS location.');
        }

        // Catch impossible jumps while keeping threshold very high to avoid false positives.
        $geoTrackFile = app_storage_file('logs/geo_track_' . $today . '.json');
        $geoTrack = read_store($geoTrackFile, !empty($settings['encrypt_logs']));
        $geoKey = 'fp:' . hash('sha256', $fingerprint);
        $nowMs = (int)round(microtime(true) * 1000);
        $prev = isset($geoTrack[$geoKey]) && is_array($geoTrack[$geoKey]) ? $geoTrack[$geoKey] : null;
        $maxSpeedMps = isset($settings['geo_fence_max_speed_mps']) && is_numeric($settings['geo_fence_max_speed_mps'])
            ? max(50.0, floatval($settings['geo_fence_max_speed_mps']))
            : 180.0;
        if ($prev && isset($prev['lat'], $prev['lng'], $prev['ts_ms']) && is_numeric($prev['ts_ms'])) {
            $deltaMs = $nowMs - intval($prev['ts_ms']);
            if ($deltaMs >= 30000 && $deltaMs <= 7200000) {
                $travelM = geo_distance_m(floatval($prev['lat']), floatval($prev['lng']), $postLat, $postLng);
                $speedMps = $travelM / max(1.0, ($deltaMs / 1000.0));
                request_timing_note('geo_fence_speed_mps', round($speedMps, 2));
                if ($speedMps > $maxSpeedMps) {
                    request_timing_note('geo_fence_result', 'travel_anomaly');
                    fail('GEOFENCE_TRAVEL_ANOMALY', 'Location movement pattern is not plausible for this time gap. Disable mock location/VPN and retry from your real location.');
                }
            }
        }
        $geoTrack[$geoKey] = ['lat' => $postLat, 'lng' => $postLng, 'ts_ms' => $nowMs];
        write_store($geoTrackFile, $geoTrack, !empty($settings['encrypt_logs']));

        request_timing_note('geo_fence_result', 'inside');
        request_timing_note('geo_fence_distance_m', round($dist, 2));
        request_timing_note('geo_fence_allowed_radius_m', round($allowedRadiusM, 2));
        request_timing_note('geo_fence_accuracy_m', $accuracyM !== null ? round($accuracyM, 2) : 'na');
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

$logFile = $logDir . "/" . $today . ".log";
$failedLog = $logDir . "/" . $today . "_failed_attempts.log";

// ✅ Read log lines
$loadLinesSpan = microtime(true);
$lines = file_exists($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
request_timing_note('existing_log_lines', is_array($lines) ? count($lines) : 0);
request_timing_span('load_attendance_log_lines', $loadLinesSpan);

$normalize_fingerprint_identity = static function ($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    $parts = explode('_', $value, 2);
    return strtolower(trim((string)($parts[0] ?? $value)));
};
$currentFingerprintIdentity = $normalize_fingerprint_identity($fingerprint);

// 🔐 Check for duplicate actions
if (!$loadTestRelaxActive) {
    $dupSpan = microtime(true);
    $hasCheckedInForCourse = false;
    foreach ($lines as $line) {
        $fields = array_map('trim', explode('|', $line));
        // Support old logs (without MAC) and new logs (with MAC).
        if (count($fields) < 7) continue;
        if (count($fields) > 10) continue;

        // Possible formats:
        // Old: Name | Matric | Action | Fingerprint | IP | Timestamp | UserAgent
        // New: Name | Matric | Action | Fingerprint | IP | MAC | Timestamp | UserAgent
        if (count($fields) === 7) {
            list($logName, $logMatric, $logAction, $logFingerprint, $logIp, $logTimestamp, $logUserAgent) = $fields;
            $logMac = 'UNKNOWN';
            $logCourse = 'general';
        } else {
            list($logName, $logMatric, $logAction, $logFingerprint, $logIp, $logMac, $logTimestamp, $logUserAgent) = $fields;
            $logCourse = isset($fields[8]) ? strtolower(trim((string)$fields[8])) : 'general';
        }

        // Ignore malformed rows to avoid false duplicate matches.
        if (!preg_match('/^\d{6,20}$/', (string)$logMatric)) {
            continue;
        }
        $logAction = strtolower(trim((string)$logAction));
        if (!in_array($logAction, ['checkin', 'checkout'], true)) {
            continue;
        }

        if ($action === 'checkout' && $logMatric === $matric && $logAction === 'checkin' && $logCourse === $courseNormalized) {
            $hasCheckedInForCourse = true;
        }
        if ($logMatric === $matric && $logAction === $action && $logCourse === $courseNormalized) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => "This Matric Number has already submitted $action for {$course} today."]);
            exit;
        }

        $logFingerprintIdentity = $normalize_fingerprint_identity($logFingerprint ?? '');
        $sameFingerprint = ($currentFingerprintIdentity !== '' && $logFingerprintIdentity !== '' && $currentFingerprintIdentity === $logFingerprintIdentity && $logAction === $action);
        $sameMac = isset($logMac) && $logMac !== 'UNKNOWN' && $mac !== 'UNKNOWN' && $logMac === $mac && $logAction === $action;
        $sameDevice = ($mac !== 'UNKNOWN') ? $sameMac : $sameFingerprint;

        if ($sameDevice && $logCourse === $courseNormalized) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => "This device has already submitted $action for {$course} today."]);
            exit;
        }
    }
    request_timing_span('duplicate_scan', $dupSpan, ['lines' => count($lines)]);

    // ⛔ Block checkout if no prior check-in (resolved from same scan above)
    if ($action === "checkout" && !$hasCheckedInForCourse) {
        // Standardized failed log format: name | matric | action | fingerprint | ip | mac | timestamp | userAgent | course | reason
        $failedLogEntry = sanitize_log_field($name) . ' | ' . sanitize_log_field($matric) . ' | failed | ' . sanitize_log_field($fingerprint) . ' | ' . sanitize_log_field($ip) . ' | ' . sanitize_log_field($mac) . ' | ' . $today . ' ' . date("H:i:s") . ' | ' . sanitize_log_field($userAgent) . ' | ' . sanitize_log_field($course) . " | NO_CHECKIN\n";
        file_put_contents($failedLog, $failedLogEntry, FILE_APPEND | LOCK_EX);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => "You cannot check out for {$course} without checking in first."]);
        exit;
    }
}
// ✅ Save to .log file (include MAC when available)
$logReason = '-';
$logEntry = sanitize_log_field($name) . ' | ' . sanitize_log_field($matric) . ' | ' . sanitize_log_field($action) . ' | ' . sanitize_log_field($fingerprint) . ' | ' . sanitize_log_field($ip) . ' | ' . sanitize_log_field($mac) . ' | ' . date("Y-m-d H:i:s") . ' | ' . sanitize_log_field($userAgent) . ' | ' . sanitize_log_field($course) . ' | ' . sanitize_log_field($logReason) . "\n";
$logWriteSpan = microtime(true);
file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
request_timing_span('append_attendance_log', $logWriteSpan);

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
