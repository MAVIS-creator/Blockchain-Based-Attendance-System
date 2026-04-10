<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
  echo json_encode(['error' => 'unauthenticated']);
  exit;
}

require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/state_helpers.php';

$typingFile = admin_chat_typing_file();
$queueFile = admin_chat_ai_queue_file();

if (!function_exists('typing_state_load')) {
  function typing_state_load($file)
  {
    if (!file_exists($file)) {
      return [];
    }
    $rows = json_decode((string)@file_get_contents($file), true);
    return is_array($rows) ? $rows : [];
  }
}

if (!function_exists('typing_state_save')) {
  function typing_state_save($file, array $rows)
  {
    @file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  }
}

if (!function_exists('typing_queue_has_pending_ai')) {
  function typing_queue_has_pending_ai($file)
  {
    if (!file_exists($file)) {
      return false;
    }
    $rows = json_decode((string)@file_get_contents($file), true);
    if (!is_array($rows) || empty($rows)) {
      return false;
    }
    $now = time();
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $runAfter = strtotime((string)($row['run_after'] ?? '')) ?: 0;
      if ($runAfter <= ($now + 8)) {
        return true;
      }
    }
    return false;
  }
}

$now = time();
$rows = typing_state_load($typingFile);
$changed = false;

foreach ($rows as $user => $state) {
  if (!is_array($state)) {
    unset($rows[$user]);
    $changed = true;
    continue;
  }
  $expiry = strtotime((string)($state['expires_at'] ?? '')) ?: 0;
  if ($expiry <= $now) {
    unset($rows[$user]);
    $changed = true;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrfPath = __DIR__ . '/includes/csrf.php';
  if (file_exists($csrfPath)) require_once $csrfPath;
  if (function_exists('csrf_check_request') && !csrf_check_request()) {
    echo json_encode(['error' => 'csrf_failed']);
    exit;
  }

  $data = json_decode((string)file_get_contents('php://input'), true) ?: [];
  $typing = !empty($data['typing']);
  $user = (string)($_SESSION['admin_user'] ?? 'unknown');
  $name = (string)($_SESSION['admin_name'] ?? $user);

  if ($typing) {
    $rows[$user] = [
      'user' => $user,
      'name' => $name,
      'expires_at' => date('c', $now + 6),
      'updated_at' => date('c'),
    ];
  } else {
    unset($rows[$user]);
  }

  typing_state_save($typingFile, $rows);
  echo json_encode(['ok' => true]);
  exit;
}

if ($changed) {
  typing_state_save($typingFile, $rows);
}

$currentUser = (string)($_SESSION['admin_user'] ?? 'unknown');
$typingUsers = [];
foreach ($rows as $state) {
  if (!is_array($state)) continue;
  $user = (string)($state['user'] ?? '');
  if ($user === '' || $user === $currentUser) continue;
  $typingUsers[] = [
    'user' => $user,
    'name' => (string)($state['name'] ?? $user),
  ];
}

echo json_encode([
  'ok' => true,
  'typing' => $typingUsers,
  'ai_typing' => typing_queue_has_pending_ai($queueFile),
]);
