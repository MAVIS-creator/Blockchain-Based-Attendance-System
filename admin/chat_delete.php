<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
// only superadmin may delete messages
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['error'=>'unauthenticated']); exit;
}
if (($_SESSION['admin_role'] ?? 'admin') !== 'superadmin') {
    echo json_encode(['error'=>'forbidden']); exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$time = $data['time'] ?? null;
if (!$time) { echo json_encode(['error'=>'missing_time']); exit; }

$chatFile = __DIR__ . '/chat.json';
if (!file_exists($chatFile)) { echo json_encode(['error'=>'not_found']); exit; }

$fp = fopen($chatFile, 'c+');
if (!$fp) { echo json_encode(['error'=>'file_open']); exit; }
if (!flock($fp, LOCK_EX)) { fclose($fp); echo json_encode(['error'=>'lock']); exit; }

rewind($fp);
$raw = stream_get_contents($fp);
$messages = json_decode($raw, true);
if (!is_array($messages)) $messages = [];

$new = [];
$deleted = 0;
foreach ($messages as $m) {
    if (($m['time'] ?? '') === $time) { $deleted++; continue; }
    $new[] = $m;
}

if ($deleted > 0) {
    rewind($fp); ftruncate($fp,0); fwrite($fp, json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); fflush($fp);
}
flock($fp, LOCK_UN); fclose($fp);

echo json_encode(['ok'=>true,'deleted'=>$deleted]);
