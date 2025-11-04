<?php
session_start();

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
	// Prune backups older than retention days (default 30)
	$retentionDays = 30;
	foreach (glob($backupDir . '/blocked_tokens_*.log') as $f) {
		if (filemtime($f) < time() - ($retentionDays * 86400)) @unlink($f);
	}
}

// Optional: you can destroy session or mark in DB here
session_destroy();

// Respond
header('Content-Type: application/json');
echo json_encode(['ok' => true]);

