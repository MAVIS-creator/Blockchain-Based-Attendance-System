<?php
// auto_send_logs.php
// Helper script to be scheduled (cron / Task Scheduler) to send logs automatically.
if (session_status() === PHP_SESSION_NONE) session_start();

$settingsFile = __DIR__ . '/settings.json';
$keyFile = __DIR__ . '/.settings_key';

function load_settings_file($settingsFile,$keyFile){
  if (!file_exists($settingsFile)) return null;
  $raw = file_get_contents($settingsFile);
  $decoded = json_decode($raw,true);
  if (is_array($decoded)) return $decoded;
  // try decrypt
  if (!file_exists($keyFile)) return null;
  $key = trim(file_get_contents($keyFile));
  $blob = base64_decode(substr($raw,4));
  $iv = substr($blob,0,16);
  $ct = substr($blob,16);
  $keyRaw = base64_decode($key);
  $plain = openssl_decrypt($ct,'AES-256-CBC',$keyRaw,OPENSSL_RAW_DATA,$iv);
  $decoded = json_decode($plain,true);
  return is_array($decoded)?$decoded:null;
}

$settings = load_settings_file($settingsFile,$keyFile) ?: [];
// require auto_send enabled
if (empty($settings['auto_send']['enabled'])) exit("Auto-send not enabled\n");
$recipient = $settings['auto_send']['recipient'] ?? '';
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) exit("No valid recipient configured\n");

// Determine date window - default: today (script intended to run at end of class window)
$date = $argv[1] ?? date('Y-m-d');
$format = $settings['auto_send']['format'] ?? 'csv';

// determine time window from settings if available
$time_from = $settings['checkin_time_start'] ?? ($settings['checkin_time'] ?? '');
$time_to = $settings['checkin_time_end'] ?? ($settings['checkin_end'] ?? '');

// Reuse send_logs_email logic by making an internal POST-like call
$_POST = [
  'email' => $recipient,
  'format' => $format,
  'date_from' => $date,
  'time_from' => $time_from ?? '',
  'date_to' => $date,
  'time_to' => $time_to ?? '',
  'course' => '',
  'cols' => ['name','matric','action','datetime','course']
];

// include the send script but avoid sending HTML; it will run and echo messages
require __DIR__ . '/send_logs_email.php';

// Note: schedule this script to run after your class ends. On Windows, use Task Scheduler; on Linux use cron.
// Example cron (run at 23:05 daily): 5 23 * * * /usr/bin/php /path/to/admin/auto_send_logs.php

echo "Auto-send attempted for date {$date}\n";

?>
