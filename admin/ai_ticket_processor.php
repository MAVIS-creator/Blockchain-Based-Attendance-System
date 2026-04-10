<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/../src/AiTicketAutomationEngine.php';

app_storage_init();

$isCli = (PHP_SAPI === 'cli');
if (!$isCli && empty($_SESSION['admin_logged_in'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthenticated']);
    exit;
}

$limit = 200;
if ($isCli) {
    global $argv;
    if (isset($argv[1]) && ctype_digit((string)$argv[1])) {
        $limit = max(1, min(1000, (int)$argv[1]));
    }
} else {
    $reqLimit = $_GET['limit'] ?? $_POST['limit'] ?? 200;
    if (is_numeric($reqLimit)) {
        $limit = max(1, min(1000, (int)$reqLimit));
    }
}

$engine = new AiTicketAutomationEngine();
$result = $engine->processUnresolvedTickets($limit);

if (function_exists('admin_log_action')) {
    require_once __DIR__ . '/state_helpers.php';
    admin_log_action('AI_Operator', 'AI Ticket Processor Run', 'Processed unresolved support tickets via ai_ticket_processor.php');
}

if ($isCli) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result['ok'] ? 0 : 1);
}

header('Content-Type: application/json');
echo json_encode($result, JSON_UNESCAPED_SLASHES);
