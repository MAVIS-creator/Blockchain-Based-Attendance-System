<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/state_helpers.php';

if (empty($_SESSION['admin_logged_in'])) {
  header('HTTP/1.1 403 Forbidden');
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'message' => 'Not authorized']);
  exit;
}

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

$file = admin_storage_migrate_file('revoked.json', app_storage_file('revoked.json'));
$data = file_exists($file) ? json_decode((string)file_get_contents($file), true) : ['tokens' => [], 'ips' => [], 'macs' => []];
if (!is_array($data)) {
  $data = ['tokens' => [], 'ips' => [], 'macs' => []];
}

$token = trim((string)($_POST['token'] ?? ''));
$ip = trim((string)($_POST['ip'] ?? ''));
$mac = trim((string)($_POST['mac'] ?? ''));

if (!isset($data['tokens']) || !is_array($data['tokens'])) $data['tokens'] = [];
if (!isset($data['ips']) || !is_array($data['ips'])) $data['ips'] = [];
if (!isset($data['macs']) || !is_array($data['macs'])) $data['macs'] = [];

if ($token === '' && $ip === '' && $mac === '') {
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'message' => 'token, ip, or mac required']);
  exit;
}

$removed = [];
if ($token !== '' && isset($data['tokens'][$token])) {
  unset($data['tokens'][$token]);
  $removed[] = 'token';
}
if ($ip !== '' && isset($data['ips'][$ip])) {
  unset($data['ips'][$ip]);
  $removed[] = 'ip';
}
if ($mac !== '' && isset($data['macs'][$mac])) {
  unset($data['macs'][$mac]);
  $removed[] = 'mac';
}

if (!empty($removed)) {
  file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

  $auditFile = app_storage_file('logs/audit.log');
  $adminUser = $_SESSION['admin_user'] ?? 'unknown';
  $remoteIp = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
  $timeStr = date('Y-m-d H:i:s');
  file_put_contents($auditFile, "$timeStr | clear_revocation | $adminUser | " . implode(',', $removed) . " | from:$remoteIp" . PHP_EOL, FILE_APPEND | LOCK_EX);

  if (function_exists('admin_log_action')) {
    admin_log_action('Tokens', 'Entry Unrevoked', 'Unrevoked: ' . implode(', ', $removed));
  }
}

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'removed' => $removed, 'counts' => ['tokens' => count($data['tokens']), 'ips' => count($data['ips']), 'macs' => count($data['macs'])]]);