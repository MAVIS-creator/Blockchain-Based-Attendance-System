<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'message' => 'Not authorized']);
  exit;
}

if (($_SESSION['admin_role'] ?? 'admin') !== 'superadmin') {
  http_response_code(403);
  echo json_encode(['ok' => false, 'message' => 'Super-admin access required']);
  exit;
}

require_once __DIR__ . '/includes/csrf.php';
if (!csrf_check_request()) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'message' => 'Invalid CSRF token']);
  exit;
}

$settingsFile = __DIR__ . '/settings.json';
$keyFile = __DIR__ . '/.settings_key';

function load_settings_maybe_decrypt($settingsFile, $keyFile)
{
  if (!file_exists($settingsFile)) return [];
  $raw = file_get_contents($settingsFile);
  $decoded = json_decode($raw, true);
  if (is_array($decoded)) return $decoded;

  if (strpos($raw, 'ENC:') === 0 && file_exists($keyFile)) {
    $key = trim(file_get_contents($keyFile));
    $blob = base64_decode(substr($raw, 4));
    $iv = substr($blob, 0, 16);
    $ct = substr($blob, 16);
    $plain = openssl_decrypt($ct, 'AES-256-CBC', base64_decode($key), OPENSSL_RAW_DATA, $iv);
    $decoded2 = json_decode($plain, true);
    if (is_array($decoded2)) return $decoded2;
  }

  return [];
}

$settings = load_settings_maybe_decrypt($settingsFile, $keyFile);
$enabled = !empty($settings['geo_fence_enabled']);
$geo = is_array($settings['geo_fence'] ?? null) ? $settings['geo_fence'] : [];
$centerLat = isset($geo['lat']) ? floatval($geo['lat']) : null;
$centerLng = isset($geo['lng']) ? floatval($geo['lng']) : null;
$radiusM = isset($geo['radius_m']) ? intval($geo['radius_m']) : 0;

$testLatRaw = trim($_POST['test_lat'] ?? '');
$testLngRaw = trim($_POST['test_lng'] ?? '');

if ($testLatRaw === '' || $testLngRaw === '' || !is_numeric($testLatRaw) || !is_numeric($testLngRaw)) {
  echo json_encode(['ok' => false, 'message' => 'Provide valid test latitude and longitude.']);
  exit;
}

$testLat = floatval($testLatRaw);
$testLng = floatval($testLngRaw);

if (!$enabled) {
  echo json_encode([
    'ok' => true,
    'enforced' => false,
    'message' => 'Geo-fence disabled in settings.'
  ]);
  exit;
}

if ($centerLat === null || $centerLng === null || $radiusM <= 0) {
  echo json_encode([
    'ok' => false,
    'enforced' => true,
    'message' => 'Geo-fence is enabled but center/radius is not configured correctly.'
  ]);
  exit;
}

$earthRadius = 6371000; // meters
$dLat = deg2rad($testLat - $centerLat);
$dLon = deg2rad($testLng - $centerLng);
$a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($centerLat)) * cos(deg2rad($testLat)) * sin($dLon / 2) * sin($dLon / 2);
$c = 2 * atan2(sqrt($a), sqrt(1 - $a));
$distance = $earthRadius * $c;
$inside = $distance <= $radiusM;

echo json_encode([
  'ok' => true,
  'enforced' => true,
  'inside' => $inside,
  'distance_m' => round($distance, 2),
  'radius_m' => $radiusM,
  'center' => ['lat' => $centerLat, 'lng' => $centerLng],
  'tested' => ['lat' => $testLat, 'lng' => $testLng]
]);
