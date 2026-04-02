<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['error'=>'unauthenticated']);
    exit;
}

require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/state_helpers.php';
$chatFile = admin_chat_file();
if (!file_exists($chatFile)) {
    file_put_contents($chatFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}
$messages = admin_cached_json_file('chat_messages', $chatFile, [], 5);
// Return last 200 messages
$slice = array_slice($messages, -200);
echo json_encode($slice);
