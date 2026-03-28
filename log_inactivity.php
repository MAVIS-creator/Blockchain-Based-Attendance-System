<?php
session_start();

function load_admin_settings_for_retention()
{
	$settingsFile = __DIR__ . '/admin/settings.json';
	$keyFile = __DIR__ . '/admin/.settings_key';
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

// Log file or DB logic — here we'll use a simple file for example
$logDir = __DIR__ . '/admin/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$inactivityLog = __DIR__ . '/logs/inactivity_log.txt';
$blockedLog = $logDir . '/blocked_tokens.log';

// Get reason (just in case you have multiple triggers)
$reason = $_POST['reason'] ?? 'Unknown reason';

// Try to capture token sent by client
$token = trim($_POST['token'] ?? '');

// Try to capture fingerprint if provided
$fingerprint = trim($_POST['fingerprint'] ?? '');

// IP and userAgent
$ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Attempt to resolve MAC using helper if available
if (file_exists(__DIR__ . '/admin/includes/get_mac.php')) {
	require_once __DIR__ . '/admin/includes/get_mac.php';
	$mac = get_mac_from_ip($ip);
} else {
	$mac = 'UNKNOWN';
}

// Append to plain inactivity log for backward compatibility
$entry = date('Y-m-d H:i:s') . " | IP: " . $ip . " | Reason: " . $reason . PHP_EOL;
file_put_contents($inactivityLog, $entry, FILE_APPEND | LOCK_EX);

// If token present, log to blocked_tokens.log with more detail
if ($token !== '') {
	// Format: timestamp | token | fingerprint | ip | mac | userAgent | reason
	$safeUA = str_replace("\n", ' ', $ua);
	$line = date('Y-m-d H:i:s') . " | " . $token . " | " . $fingerprint . " | " . $ip . " | " . $mac . " | " . $safeUA . " | " . $reason . PHP_EOL;
	file_put_contents($blockedLog, $line, FILE_APPEND | LOCK_EX);
	// Rotate blocked_tokens.log if it grows too large (e.g. 5MB) and keep backups for retention period
	$maxSize = 5 * 1024 * 1024; // 5 MB
	$backupDir = __DIR__ . '/admin/backups';
	if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);
	if (file_exists($blockedLog) && filesize($blockedLog) > $maxSize) {
		$ts = date('Ymd_His');
		$bak = $backupDir . "/blocked_tokens_{$ts}.log";
		@rename($blockedLog, $bak);
		// optional: create an empty new blocked log
		@file_put_contents($blockedLog, "", LOCK_EX);
	}
	// Prune backups older than retention days from admin settings (default 30)
	$adminSettings = load_admin_settings_for_retention();
	$retentionDays = intval($adminSettings['blocked_tokens_retention_days'] ?? 30);
	if ($retentionDays <= 0) $retentionDays = 30;

	// Prune rotated backup logs
	foreach (glob($backupDir . '/blocked_tokens_*.log') as $f) {
		if (filemtime($f) < time() - ($retentionDays * 86400)) @unlink($f);
	}

	// Prune old lines in active blocked_tokens.log as well.
	if (file_exists($blockedLog)) {
		$lines = @file($blockedLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
		if (!empty($lines)) {
			$cutoff = time() - ($retentionDays * 86400);
			$kept = [];
			foreach ($lines as $ln) {
				$parts = array_map('trim', explode('|', $ln));
				$dt = $parts[0] ?? '';
				$ts = strtotime($dt);
				if ($ts === false || $ts >= $cutoff) $kept[] = $ln;
			}
			if (count($kept) !== count($lines)) {
				file_put_contents($blockedLog, implode(PHP_EOL, $kept) . (empty($kept) ? '' : PHP_EOL), LOCK_EX);
			}
		}
	}
}

// Optional: you can destroy session or mark in DB here
session_destroy();

// Respond
header('Content-Type: application/json');
echo json_encode(['ok' => true]);
