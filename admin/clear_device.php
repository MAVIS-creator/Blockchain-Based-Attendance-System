<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/state_helpers.php';

if (empty($_SESSION['admin_logged_in'])) {
  $restoreReason = null;
  $restoreSid = trim((string)session_id());
  if ($restoreSid === '') {
    $restoreSid = trim((string)($_COOKIE[ADMIN_SESSION_TRACKER_COOKIE] ?? ''));
  }
  if ($restoreSid !== '' && function_exists('admin_restore_session_from_tracker')) {
    admin_restore_session_from_tracker($restoreSid, $restoreReason);
  }
}

if (empty($_SESSION['admin_logged_in'])) {
  header('HTTP/1.1 403 Forbidden');
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'message' => 'Not authorized']);
  exit;
}
// CSRF protection
$csrfPath = __DIR__ . '/includes/csrf.php';
if (file_exists($csrfPath)) require_once $csrfPath;
if (function_exists('csrf_check_request') && !csrf_check_request()) {
  header('HTTP/1.1 403 Forbidden');
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'message' => 'csrf_failed']);
  exit;
}

require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/runtime_storage.php';
app_storage_init();

// Accept fingerprint or matric to clear device-blocking state for today
$fingerprint = trim($_POST['fingerprint'] ?? '');
$matric = trim($_POST['matric'] ?? '');
$token = trim($_POST['token'] ?? '');
$ip = trim($_POST['ip'] ?? '');
$mac = trim($_POST['mac'] ?? '');
$today = date('Y-m-d');
$adminLogs = app_storage_file('logs');
$responses = ['cleared' => [], 'skipped' => [], 'errors' => []];

if ($fingerprint === '' && $matric === '' && $token === '' && $ip === '' && $mac === '') {
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'message' => 'fingerprint, matric, token, ip, or mac required']);
  exit;
}

// Helper to read/write JSON stores (supports ENC: encrypted files)
function read_json($file)
{
  if (!file_exists($file)) return [];
  $c = @file_get_contents($file);
  if (!is_string($c) || $c === '') return [];

  if (strpos($c, 'ENC:') === 0) {
    $keyFile = admin_storage_migrate_file('.settings_key');
    if (!file_exists($keyFile)) return [];
    $key = trim((string)@file_get_contents($keyFile));
    $blob = base64_decode(substr($c, 4));
    if ($blob === false) return [];
    $iv = substr($blob, 0, 16);
    $ct = substr($blob, 16);
    $plain = openssl_decrypt($ct, 'AES-256-CBC', base64_decode($key), OPENSSL_RAW_DATA, $iv);
    $d = json_decode((string)$plain, true);
    return is_array($d) ? $d : [];
  }

  $d = json_decode($c, true);
  return is_array($d) ? $d : [];
}

function write_json($file, $data)
{
  $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  $current = file_exists($file) ? (string)@file_get_contents($file) : '';
  $shouldEncrypt = (strpos($current, 'ENC:') === 0);

  if (!$shouldEncrypt) {
    @file_put_contents($file, $payload, LOCK_EX);
    return;
  }

  $keyFile = admin_storage_migrate_file('.settings_key');
  if (!file_exists($keyFile)) {
    @file_put_contents($file, $payload, LOCK_EX);
    return;
  }
  $key = trim((string)@file_get_contents($keyFile));
  $iv = random_bytes(16);
  $ct = openssl_encrypt($payload, 'AES-256-CBC', base64_decode($key), OPENSSL_RAW_DATA, $iv);
  @file_put_contents($file, 'ENC:' . base64_encode($iv . $ct), LOCK_EX);
}

function mask_audit_value($value)
{
  $value = (string)$value;
  $len = strlen($value);
  if ($len <= 6) return str_repeat('*', $len);
  return substr($value, 0, 3) . str_repeat('*', max(4, $len - 6)) . substr($value, -3);
}

// Target files for clearing
$targets = [
  $adminLogs . "/fp_useragent_{$today}.json",
  $adminLogs . "/device_cooldowns_{$today}.json",
  $adminLogs . "/fp_devices_{$today}.json",
];

foreach ($targets as $file) {
  if (!file_exists($file)) continue;
  $data = read_json($file);
  $changed = false;
  if ($fingerprint !== '') {
    if (isset($data[$fingerprint])) {
      unset($data[$fingerprint]);
      $changed = true;
    }
    // some files may use concatenated keys
    foreach ($data as $k => $v) {
      if (strpos($k, $fingerprint) !== false) {
        unset($data[$k]);
        $changed = true;
      }
    }
  }
  if ($token !== '') {
    foreach ($data as $k => $v) {
      if (strpos($k, $token) !== false) {
        unset($data[$k]);
        $changed = true;
      }
    }
  }
  if ($ip !== '') {
    foreach ($data as $k => $v) {
      if (strpos($k, $ip) !== false) {
        unset($data[$k]);
        $changed = true;
      }
    }
  }
  if ($mac !== '') {
    foreach ($data as $k => $v) {
      if (strpos($k, $mac) !== false) {
        unset($data[$k]);
        $changed = true;
      }
    }
  }
  if ($matric !== '') {
    // fingerprints.json maps matric -> hashedFingerprint
    $fpFile = admin_storage_migrate_file('fingerprints.json', app_storage_file('fingerprints.json'));
    if (file_exists($fpFile)) {
      $fps = read_json($fpFile);
      if (isset($fps[$matric])) {
        unset($fps[$matric]);
        write_json($fpFile, $fps);
        $responses['cleared'][] = "fingerprints.json: $matric";
      }
    }
    // also remove any entries where matric appears in keys
    foreach ($data as $k => $v) {
      if (strpos($k, $matric) !== false) {
        unset($data[$k]);
        $changed = true;
      }
    }
  }
  if ($changed) {
    write_json($file, $data);
    $responses['cleared'][] = $file;
  }
}

// Also remove matching entries from blocked_tokens.log
$blockedLog = $adminLogs . '/blocked_tokens.log';
if (file_exists($blockedLog)) {
  $lines = @file($blockedLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
  $kept = [];
  $removed = 0;
  foreach ($lines as $ln) {
    $parts = array_map('trim', explode('|', $ln));
    $lineToken = $parts[1] ?? '';
    $lineFingerprint = $parts[2] ?? '';
    $lineIp = $parts[3] ?? '';
    $lineMac = $parts[4] ?? '';

    $match = false;
    if ($token !== '' && $lineToken !== '' && $lineToken === $token) $match = true;
    if ($fingerprint !== '' && $lineFingerprint !== '' && $lineFingerprint === $fingerprint) $match = true;
    if ($ip !== '' && $lineIp !== '' && $lineIp === $ip) $match = true;
    if ($mac !== '' && $lineMac !== '' && $lineMac === $mac) $match = true;

    if ($match) {
      $removed++;
      continue;
    }
    $kept[] = $ln;
  }
  if ($removed > 0) {
    @file_put_contents($blockedLog, implode(PHP_EOL, $kept) . (empty($kept) ? '' : PHP_EOL), LOCK_EX);
    $responses['cleared'][] = "blocked_tokens.log: {$removed} line(s) removed";
  }
}

// Also remove cooldown entries that use composite keys
$cdFile = $adminLogs . "/device_cooldowns_{$today}.json";
if (file_exists($cdFile)) {
  $cd = read_json($cdFile);
  $changed = false;
  foreach ($cd as $k => $v) {
    if ($fingerprint !== '' && strpos($k, $fingerprint) !== false) {
      unset($cd[$k]);
      $changed = true;
    }
    if ($matric !== '' && strpos($k, $matric) !== false) {
      unset($cd[$k]);
      $changed = true;
    }
  }
  if ($changed) {
    write_json($cdFile, $cd);
    $responses['cleared'][] = $cdFile;
  }
}

// Audit: record who cleared and what
$auditFile = app_storage_file('logs/audit.log');
$adminUser = $_SESSION['admin_user'] ?? 'unknown';
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
if (!empty($responses['cleared'])) {
  $timeStr = date('Y-m-d H:i:s');
  foreach ($responses['cleared'] as $f) {
    $rawKey = ($fingerprint ?: ($matric ?: ($token ?: ($ip ?: $mac))));
    $maskedKey = mask_audit_value($rawKey);
    $line = "$timeStr | clear_device | $adminUser | file:$f | key:" . $maskedKey . " | from:$remoteIp" . PHP_EOL;
    file_put_contents($auditFile, $line, FILE_APPEND | LOCK_EX);
  }

  if (function_exists('admin_log_action')) {
    $parts = [];
    if ($fingerprint !== '') $parts[] = 'fingerprint';
    if ($matric !== '') $parts[] = 'matric';
    if ($token !== '') $parts[] = 'token';
    if ($ip !== '') $parts[] = 'ip';
    if ($mac !== '') $parts[] = 'mac';
    $details = 'Cleared device/token blocks using keys: ' . implode(', ', $parts) . '; affected=' . count($responses['cleared']);
    admin_log_action('Logs', 'Device State Cleared', $details);
  }
}

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'result' => $responses]);
