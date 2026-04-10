<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/runtime_storage.php';

$username = $_SESSION['admin_user'] ?? '';
if (!$username) {
    echo json_encode(['ok' => false, 'error' => 'Unknown user']);
    exit;
}

$accountsFile = admin_accounts_file();
if (!file_exists($accountsFile)) {
    echo json_encode(['ok' => false, 'error' => 'File not found']);
    exit;
}

// Write lock to prevent race conditions
$fp = fopen($accountsFile, 'c+');
if ($fp) {
    flock($fp, LOCK_EX);
    $size = filesize($accountsFile) ?: 0;
    $raw = $size > 0 ? fread($fp, $size) : '';
    $accounts = json_decode($raw, true) ?: [];

    if (isset($accounts[$username]) && isset($accounts[$username]['needs_tour'])) {
        unset($accounts[$username]['needs_tour']);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    flock($fp, LOCK_UN);
    fclose($fp);
}

// Clear the session flag so it doesn't trigger on reload
$_SESSION['needs_tour'] = false;

echo json_encode(['ok' => true]);
