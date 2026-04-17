<?php
require_once __DIR__ . '/session_bootstrap.php';
header('Content-Type: application/json');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

// CSRF protection
$csrfPath = __DIR__ . '/includes/csrf.php';
if (file_exists($csrfPath)) require_once $csrfPath;
if (function_exists('csrf_check_request') && !csrf_check_request()) {
    echo json_encode(['error' => 'csrf_failed']);
    exit;
}

require_once __DIR__ . '/runtime_storage.php';

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$id = trim((string)($data['id'] ?? ''));
$time = $data['time'] ?? null;
if ($id === '' && !$time) {
    echo json_encode(['error' => 'missing_identifier']);
    exit;
}

$currentUser = (string)($_SESSION['admin_user'] ?? 'unknown');
$currentName = (string)($_SESSION['admin_name'] ?? $currentUser);

$chatFile = admin_storage_migrate_file('chat.json');
if (!file_exists($chatFile)) {
    echo json_encode(['error' => 'not_found']);
    exit;
}

$fp = fopen($chatFile, 'c+');
if (!$fp) {
    echo json_encode(['error' => 'file_open']);
    exit;
}
if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    echo json_encode(['error' => 'lock']);
    exit;
}

rewind($fp);
$raw = stream_get_contents($fp);
$messages = json_decode($raw, true);
if (!is_array($messages)) $messages = [];

$targetIndex = -1;
foreach ($messages as $idx => $m) {
    if (!is_array($m)) continue;
    if ($id !== '' && (string)($m['id'] ?? '') === $id) {
        $targetIndex = (int)$idx;
        break;
    }
    if ($id === '' && $time && (string)($m['time'] ?? '') === (string)$time) {
        $targetIndex = (int)$idx;
        break;
    }
}

if ($targetIndex < 0 || !isset($messages[$targetIndex]) || !is_array($messages[$targetIndex])) {
    flock($fp, LOCK_UN);
    fclose($fp);
    echo json_encode(['error' => 'not_found']);
    exit;
}

$target = $messages[$targetIndex];
if ((string)($target['user'] ?? '') !== $currentUser) {
    flock($fp, LOCK_UN);
    fclose($fp);
    echo json_encode(['error' => 'forbidden_owner']);
    exit;
}

if (!empty($target['deleted'])) {
    flock($fp, LOCK_UN);
    fclose($fp);
    echo json_encode(['ok' => true, 'deleted' => 0, 'already_deleted' => true]);
    exit;
}

$messages[$targetIndex]['deleted'] = true;
$messages[$targetIndex]['deleted_by'] = $currentUser;
$messages[$targetIndex]['deleted_by_name'] = $currentName;
$messages[$targetIndex]['deleted_at'] = date('c');
$messages[$targetIndex]['message'] = '';

rewind($fp);
ftruncate($fp, 0);
fwrite($fp, json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fflush($fp);

flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(['ok' => true, 'deleted' => 1]);
