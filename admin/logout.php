<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/state_helpers.php';
$logoutSid = trim((string)session_id());
if ($logoutSid === '') {
	$logoutSid = trim((string)($_COOKIE[ADMIN_SESSION_TRACKER_COOKIE] ?? ''));
}
admin_unregister_session($logoutSid);
$httpsForwarded = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$httpsNative = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
setcookie(ADMIN_SESSION_TRACKER_COOKIE, '', [
	'expires' => time() - 3600,
	'path' => '/',
	'domain' => '',
	'secure' => ($httpsForwarded || $httpsNative),
	'httponly' => true,
	'samesite' => 'Lax',
]);
session_unset();
@session_destroy();
header("Location: login.php");
exit;
