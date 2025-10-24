<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['error'=>'unauthenticated']);
    exit;
}

$chatFile = __DIR__ . '/chat.json';
if (!file_exists($chatFile)) {
    file_put_contents($chatFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
$messages = json_decode(file_get_contents($chatFile), true) ?: [];
// Return last 200 messages
$slice = array_slice($messages, -200);
echo json_encode($slice);
