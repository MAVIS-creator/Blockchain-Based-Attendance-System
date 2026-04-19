<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/state_helpers.php';
admin_unregister_session(session_id());
setcookie(ADMIN_SESSION_TRACKER_COOKIE, '', [
	'expires' => time() - 3600,
	'path' => '/',
	'domain' => '',
	'secure' => !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off',
	'httponly' => true,
	'samesite' => 'Lax',
]);
session_unset();
@session_destroy();
header("Location: login.php");
exit;
