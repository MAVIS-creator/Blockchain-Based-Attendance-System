<?php
declare(strict_types=1);
$root = sys_get_temp_dir() . '/bbas-ai-attendance-' . bin2hex(random_bytes(7));
mkdir($root, 0700, true);
putenv('STORAGE_PATH=' . $root);
date_default_timezone_set('Africa/Lagos');
require_once __DIR__ . '/../admin/includes/ticket_helpers.php';
try {
  $f = 'dummy-device';
  if (!ticket_append_attendance_log('Student A','1234567890','checkin','Verified session issue','CSC 101',$f)) exit(1);
  if (ticket_append_attendance_log('Student A','1234567890','checkin','duplicate','CSC 101',$f)) exit(2);
  if (!ticket_append_attendance_log('Student A','1234567890','checkout','Verified session issue','CSC 101',$f)) exit(3);
  if (ticket_append_attendance_log('Student A','1234567890','checkout','duplicate','CSC 101',$f)) exit(4);
  if (ticket_append_attendance_log('Student B','1234567891','checkout','missing checkin','CSC 101','another')) exit(5);
  $chain=json_decode(file_get_contents(app_storage_file('secure_logs/attendance_chain.json')),true);
  if (!is_array($chain) || count($chain)!==2 || $chain[1]['prevHash']!==$chain[0]['hash']) exit(6);
  echo "Guarded AI attendance write and chain regression passed\n";
} finally {
  foreach (['logs','secure_logs'] as $dir) {
    foreach (glob($root.'/'.$dir.'/*') ?: [] as $entry) @unlink($entry);
    @rmdir($root.'/'.$dir);
  }
  @rmdir($root);
}
