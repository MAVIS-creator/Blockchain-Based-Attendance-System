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

if (!function_exists('chat_message_id')) {
    function chat_message_id()
    {
        return 'msg_' . bin2hex(random_bytes(8));
    }
}

if (!function_exists('chat_ai_queue_load')) {
    function chat_ai_queue_load($queueFile)
    {
        if (!file_exists($queueFile)) {
            return [];
        }
        $rows = json_decode((string)@file_get_contents($queueFile), true);
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('chat_ai_queue_save')) {
    function chat_ai_queue_save($queueFile, array $rows)
    {
        @file_put_contents($queueFile, json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}

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
    'id' => chat_message_id(),
    'user' => $_SESSION['admin_user'] ?? 'unknown',
    'name' => $_SESSION['admin_name'] ?? ($_SESSION['admin_user'] ?? 'unknown'),
    'time' => date('c'),
    'message' => $msg,
    'deleted' => false,
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
    if (
        strpos($msg, '@ai') !== false
        || strpos($msg, 'sentinel ai') !== false
        || strpos($msg, 'system ai') !== false
        || strpos($msg, 'ai ') !== false
        || strpos($msg, ' ai') !== false
    ) {
        return true;
    }

    if (strpos($msg, '?') !== false) {
        return true;
    }

    if (preg_match('/(status|ticket|support|error|issue|attendance|checkin|checkout|course|logs|diagnostics|verification)/i', $msg)) {
        return true;
    }

    // AI can still join lightly sometimes in normal conversation, but not every message.
    return (mb_strlen($msg) > 28) && (mt_rand(1, 100) <= 22);
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
    $recentAiReply = false;
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        $candidate = $messages[$i] ?? null;
        if (!is_array($candidate)) {
            continue;
        }
        if (($candidate['user'] ?? '') === 'system_ai_operator') {
            $recentTs = strtotime((string)($candidate['time'] ?? ''));
            if ($recentTs !== false && (time() - $recentTs) < 25) {
                $recentAiReply = true;
            }
            break;
        }
    }

    if (!$recentAiReply) {
        $queueFile = admin_chat_ai_queue_file();
        $queue = chat_ai_queue_load($queueFile);
        $queue[] = [
            'id' => 'queue_' . bin2hex(random_bytes(6)),
            'message_id' => (string)$entry['id'],
            'message' => (string)$msg,
            'queued_at' => date('c'),
            'run_after' => date('c', time() + 2),
            'pending_review_count' => ai_pending_review_count_from_diag(),
            'requested_by' => (string)($_SESSION['admin_user'] ?? 'unknown'),
        ];
        if (count($queue) > 80) {
            $queue = array_slice($queue, -80);
        }
        chat_ai_queue_save($queueFile, $queue);
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
