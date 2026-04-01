<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) { header('HTTP/1.1 403 Forbidden'); echo json_encode(['ok'=>false,'message'=>'Not authorized']); exit; }
// CSRF protection
$csrfPath = __DIR__ . '/includes/csrf.php';
if (file_exists($csrfPath)) require_once $csrfPath;
if (function_exists('csrf_check_request') && !csrf_check_request()) { header('HTTP/1.1 403 Forbidden'); echo json_encode(['ok'=>false,'message'=>'csrf_failed']); exit; }

require_once __DIR__ . '/../storage_helpers.php';
app_storage_init();
$file = app_storage_migrate_file('revoked.json', __DIR__ . '/revoked.json');
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : ['tokens'=>[], 'ips'=>[], 'macs'=>[]];
if (!is_array($data)) $data = ['tokens'=>[], 'ips'=>[], 'macs'=>[]];

function mask_audit_value($value)
{
	$value = (string)$value;
	$len = strlen($value);
	if ($len <= 6) return str_repeat('*', $len);
	return substr($value, 0, 3) . str_repeat('*', max(4, $len - 6)) . substr($value, -3);
}

$token = trim($_POST['token'] ?? '');
$ip = trim($_POST['ip'] ?? '');
$mac = trim($_POST['mac'] ?? '');
$days = intval($_POST['days'] ?? 7);
if ($days <= 0) $days = 7;
$expiry = time() + ($days * 86400);
$adminUser = $_SESSION['admin_user'] ?? 'unknown';
$added = [];

// Ensure associative structure: map value -> meta
if (!isset($data['tokens']) || !is_array($data['tokens'])) $data['tokens'] = [];
if (!isset($data['ips']) || !is_array($data['ips'])) $data['ips'] = [];
if (!isset($data['macs']) || !is_array($data['macs'])) $data['macs'] = [];

if ($token !== '') {
	$data['tokens'][$token] = ['by'=>$adminUser,'at'=>time(),'expiry'=>$expiry];
	$added[] = 'token';
}
if ($ip !== '') {
	$data['ips'][$ip] = ['by'=>$adminUser,'at'=>time(),'expiry'=>$expiry];
	$added[] = 'ip';
}
if ($mac !== '') {
	$data['macs'][$mac] = ['by'=>$adminUser,'at'=>time(),'expiry'=>$expiry];
	$added[] = 'mac';
}

// write revoked list
file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

// Audit log
$auditFile = app_storage_file('logs/audit.log');
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
$timeStr = date('Y-m-d H:i:s');
foreach ($added as $type) {
	$target = ($type === 'token') ? $token : (($type === 'ip') ? $ip : $mac);
	$maskedTarget = mask_audit_value($target);
	$line = "$timeStr | revoke | $adminUser | $type | $maskedTarget | expiry:" . date('Y-m-d',$expiry) . " | from:$remoteIp" . PHP_EOL;
	file_put_contents($auditFile, $line, FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');
echo json_encode(['ok'=>true,'added'=>$added,'counts'=>['tokens'=>count($data['tokens']),'ips'=>count($data['ips']),'macs'=>count($data['macs'])]]);
