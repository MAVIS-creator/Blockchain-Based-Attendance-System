<?php
session_start();

// Log file or DB logic — here we'll use a simple file for example
$logFile = __DIR__ . '/logs/inactivity_log.txt';

// Get reason (just in case you have multiple triggers)
$reason = $_POST['reason'] ?? 'Unknown reason';

// Save timestamp and IP for audit
$entry = date('Y-m-d H:i:s') . " | IP: " . $_SERVER['REMOTE_ADDR'] . " | Reason: " . $reason . PHP_EOL;

// Append to log file
file_put_contents($logFile, $entry, FILE_APPEND);

// Optional: you can destroy session or mark in DB here
session_destroy();

// Respond
echo 'Logged';
