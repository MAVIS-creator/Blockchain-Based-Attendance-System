<?php
require_once __DIR__ . '/../src/AtomicWindowLimiter.php';
$file = sys_get_temp_dir() . '/bbas-limit-' . bin2hex(random_bytes(8)) . '.json';
try {
  for ($i=0;$i<550;$i++) {
    $s=request_guard_tick($file, ['window_started'=>100,'count'=>0,'blocked_until'=>0], 101, 60);
  }
  if ($s['count'] !== 550) { throw new RuntimeException('shared-IP count incorrect'); }
  request_guard_block($file, 300);
  $s=request_guard_tick($file, ['window_started'=>100,'count'=>0,'blocked_until'=>0], 102, 60);
  if ($s['count'] !== 551 || $s['blocked_until'] !== 300) { throw new RuntimeException('block state incorrect'); }
  echo "Rate window atomic regression passed\n";
} finally { @unlink($file); }
