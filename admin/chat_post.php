<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

// CSRF protection (accepts JSON body token)
$csrfPath = __DIR__ . '/includes/csrf.php';
if (file_exists($csrfPath)) require_once $csrfPath;
if (function_exists('csrf_check_request') && !csrf_check_request()) {
    echo json_encode(['error' => 'csrf_failed']);
    exit;
}

require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/../src/AiProviderClient.php';
require_once __DIR__ . '/state_helpers.php';

// parse and validate
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$msg = trim($data['message'] ?? '');
if ($msg === '') {
    echo json_encode(['error' => 'empty']);
    exit;
}
if (mb_strlen($msg) > 2000) {
    echo json_encode(['error' => 'too_long']);
    exit;
}

$chatFile = admin_storage_migrate_file('chat.json');
if (!file_exists($chatFile)) file_put_contents($chatFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

$entry = [
    'user' => $_SESSION['admin_user'] ?? 'unknown',
    'name' => $_SESSION['admin_name'] ?? ($_SESSION['admin_user'] ?? 'unknown'),
    'time' => date('c'),
    'message' => $msg
];

// safe append with file locking and trimming to last 1000 messages
$maxMessages = 1000;
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

// read existing
rewind($fp);
$raw = stream_get_contents($fp);
$messages = json_decode($raw, true);
if (!is_array($messages)) $messages = [];

$messages[] = $entry;

function should_trigger_ai_chat_reply($msg)
{
    $msg = strtolower(trim((string)$msg));
    if ($msg === '') return false;
    if (strpos($msg, '@ai') !== false || strpos($msg, 'system ai') !== false) {
        return true;
    }

    return (bool)preg_match('/(attendance|ticket|fingerprint|device|checkin|checkout|support|error|issue|failed|blocked|revoked)/i', $msg);
}

function ai_pending_review_count_from_diag()
{
    $diagFile = function_exists('ai_ticket_diagnostics_file')
        ? ai_ticket_diagnostics_file()
        : admin_storage_migrate_file('ai_ticket_diagnostics.json');

    if (!file_exists($diagFile)) return 0;
    $rows = json_decode((string)@file_get_contents($diagFile), true);
    if (!is_array($rows)) return 0;
    $rows = array_slice($rows, 0, 300);
    $count = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $cls = (string)($r['classification'] ?? '');
        if (
            in_array($cls, ['network_ip_rotation', 'new_or_suspicious_device', 'duplicate_or_fraudulent_sequence', 'blocked_revoked_device'], true)
            || empty($r['ticket_resolved'])
        ) {
            $count++;
        }
    }
    return $count;
}

if (should_trigger_ai_chat_reply($msg)) {
    $last = end($messages);
    $recentAiReply = false;
    if (is_array($last) && (($last['user'] ?? '') === 'system_ai_operator')) {
        $recentTs = strtotime((string)($last['time'] ?? ''));
        if ($recentTs !== false && (time() - $recentTs) < 15) {
            $recentAiReply = true;
        }
    }

    if (!$recentAiReply) {
        $aiReply = AiProviderClient::suggestAdminChatReply($msg, [
            'pending_review_count' => ai_pending_review_count_from_diag(),
        ]);

        $aiText = !empty($aiReply['ok']) ? trim((string)($aiReply['suggestion'] ?? '')) : '';
        if ($aiText !== '') {
            $messages[] = [
                'user' => 'system_ai_operator',
                'name' => 'System AI Operator',
                'time' => date('c'),
                'message' => $aiText,
                'auto_replied_by' => 'system_ai_operator',
                'context' => 'admin_chat_assist',
                'ai_provider' => (string)($aiReply['provider'] ?? 'rules'),
                'ai_model' => (string)($aiReply['model'] ?? 'rules-chat-v1'),
                'ai_latency_ms' => (int)($aiReply['latency_ms'] ?? 0),
            ];
        }
    }
}

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

echo json_encode(['ok' => true, 'entry' => $entry]);
