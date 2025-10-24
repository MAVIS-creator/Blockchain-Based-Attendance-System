<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['error'=>'unauthenticated']);
    exit;
}

// parse and validate
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$msg = trim($data['message'] ?? '');
if ($msg === '') { echo json_encode(['error'=>'empty']); exit; }
if (mb_strlen($msg) > 2000) { echo json_encode(['error'=>'too_long']); exit; }

$chatFile = __DIR__ . '/chat.json';
if (!file_exists($chatFile)) file_put_contents($chatFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$entry = [
    'user' => $_SESSION['admin_user'] ?? 'unknown',
    'name' => $_SESSION['admin_name'] ?? ($_SESSION['admin_user'] ?? 'unknown'),
    'time' => date('c'),
    'message' => $msg
];

// safe append with file locking and trimming to last 1000 messages
$maxMessages = 1000;
$fp = fopen($chatFile, 'c+');
if (!$fp) { echo json_encode(['error'=>'file_open']); exit; }
if (!flock($fp, LOCK_EX)) { fclose($fp); echo json_encode(['error'=>'lock']); exit; }

// read existing
rewind($fp);
$raw = stream_get_contents($fp);
$messages = json_decode($raw, true);
if (!is_array($messages)) $messages = [];

$messages[] = $entry;
// trim
if (count($messages) > $maxMessages) {
    $messages = array_slice($messages, -$maxMessages);
}

// write back
rewind($fp);
ftruncate($fp, 0);
fwrite($fp, json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(['ok'=>true,'entry'=>$entry]);
