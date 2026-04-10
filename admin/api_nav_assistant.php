<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../src/AiProviderClient.php';
require_once __DIR__ . '/cache_helpers.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !isset($data['query'])) {
    echo json_encode(['ok' => false, 'error' => 'Missing query']);
    exit;
}

$query = trim($data['query']);
if ($query === '') {
    echo json_encode(['ok' => false, 'error' => 'Empty query']);
    exit;
}

// Fetch basic context to pass out
$context = [
    'admin_user' => $_SESSION['admin_user'] ?? 'admin',
    'admin_role' => $_SESSION['admin_role'] ?? 'admin'
];

try {
    $res = AiProviderClient::suggestAdminNavigationHelp($query, $context);
    echo json_encode($res);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
