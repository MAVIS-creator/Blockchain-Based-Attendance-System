<?php
require_once __DIR__ . '/../src/AttendanceChainStore.php';
$path = getenv('BBAS_TEST_CHAIN_FILE');
if (!$path || !str_starts_with($path, sys_get_temp_dir() . DIRECTORY_SEPARATOR)) { exit(2); }
$pid = (string)getmypid();
for ($i = 0; $i < 30; $i++) {
    attendance_chain_append_atomic($path, [
        'timestamp' => '2026-11-18 13:00:00',
        'name' => 'test', 'matric' => $pid . '-' . $i,
        'action' => 'checkin', 'course' => 'Test'
    ]);
}
