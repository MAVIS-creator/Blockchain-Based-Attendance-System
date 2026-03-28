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

// Determine date - default: today (script intended to run at end of class window)
$date = $argv[1] ?? date('Y-m-d');
$format = $settings['auto_send']['format'] ?? 'csv';

// Build the groups that should exist for today
// We'll need to scan all courses from logs and select groups for this date
$logsDir = __DIR__ . '/logs';
$selectedGroups = [];

if (is_dir($logsDir)){
    $it = new DirectoryIterator($logsDir);
    foreach ($it as $f){
        if ($f->isFile()){
            $fn = $f->getFilename();
            if (preg_match('/\.(php|css)$/i',$fn)) continue;
            // Check if this file might contain today's logs
            if (strpos($fn, $date) !== false || preg_match('/(20\d{2}-\d{2}-\d{2})/', $fn, $m) && $m[1] === $date) {
                // Parse the file to find courses
                $lines = @file($logsDir . '/' . $fn, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $courses = [];
                foreach ($lines as $ln){
                    $parts = array_map('trim', explode('|',$ln));
                    if (isset($parts[8]) && $parts[8] !== '') $courses[$parts[8]] = true;
                }
                // Add group keys for this date + each course
                foreach (array_keys($courses) as $course){
                    $selectedGroups[] = $date . '|' . $course;
                }
            }
        }
    }
}

// Remove duplicates
$selectedGroups = array_unique($selectedGroups);

if (empty($selectedGroups)){
    exit("No log groups found for date {$date}\n");
}

// Simulate POST request to send_logs_email.php
$_POST = [
  'send_logs' => '1',
  'recipient' => $recipient,
  'format' => $format,
  'selected_groups' => $selectedGroups,
  'cols' => ['name','matric','action','datetime','course']
];

// Capture output from send_logs_email.php
ob_start();
require __DIR__ . '/send_logs_email.php';
$output = ob_get_clean();

echo "Auto-send attempted for date {$date}\n";
echo "Selected groups: " . implode(', ', $selectedGroups) . "\n";
echo "Output:\n$output\n";

// Note: schedule this script to run after your class ends. On Windows, use Task Scheduler; on Linux use cron.
// Example cron (run at 23:05 daily): 5 23 * * * /usr/bin/php /path/to/admin/auto_send_logs.php
// Example Windows Task Scheduler: C:\path\to\php.exe C:\path\to\admin\auto_send_logs.php

?>
