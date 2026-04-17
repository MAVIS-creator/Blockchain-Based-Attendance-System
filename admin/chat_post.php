<?php
require_once __DIR__ . '/session_bootstrap.php';
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

if (!function_exists('chat_parse_role_override_command')) {
    function chat_parse_role_override_command($msg)
    {
        $text = trim((string)$msg);
        if ($text === '') {
            return ['ok' => false];
        }

        $lower = strtolower($text);
        $mentionsAi = (strpos($lower, '@ai') !== false || strpos($lower, 'sentinel ai') !== false || strpos($lower, 'system ai') !== false);
        $looksRoleIntent = (
            strpos($lower, 'role') !== false
            || strpos($lower, 'page') !== false
            || strpos($lower, 'permission') !== false
            || strpos($lower, 'access') !== false
            || strpos($lower, 'compulsory') !== false
            || strpos($lower, 'mandatory') !== false
            || strpos($lower, 'locked') !== false
            || strpos($lower, 'greyed') !== false
            || strpos($lower, 'grayed') !== false
        );
        if (!$mentionsAi && !$looksRoleIntent) {
            return ['ok' => false];
        }

        // Mode detection from strict commands and natural language.
        $mode = '';
        if (
            preg_match('/\b(unlock|remove|ungray|ungrey|unblock|uncheck)\b/i', $text)
            || preg_match('/\b(should\s+not\s+see|shouldn\'?t\s+see|must\s+not\s+see|do\s+not\s+allow|don\'?t\s+allow)\b/i', $text)
            || preg_match('/\b(not\s+compulsory|not\s+required|not\s+mandatory|not\s+supposed\s+to\s+see)\b/i', $text)
        ) {
            $mode = 'unlock';
        } elseif (
            preg_match('/\b(lock|force|must|mandatory|required|compulsory|grey|gray|check)\b/i', $text)
            || preg_match('/\b(should\s+see|must\s+see|needs\s+to\s+see|need\s+to\s+see|allow\s+access)\b/i', $text)
        ) {
            $mode = 'lock';
        }

        if ($mode === '') {
            return ['ok' => false];
        }

        $role = '';
        $page = '';

        $permissions = admin_load_permissions_cached(0);
        if (!is_array($permissions)) {
            $permissions = ['admin' => []];
        }
        $knownRoles = array_values(array_keys($permissions));
        if (!in_array('admin', $knownRoles, true)) {
            $knownRoles[] = 'admin';
        }

        $assignable = admin_assignable_pages();
        if (!is_array($assignable)) {
            $assignable = [];
        }

        if (preg_match('/\brole\s*[=:]\s*([a-zA-Z0-9_\-]+)/i', $text, $m)) {
            $role = strtolower(trim((string)$m[1]));
        } elseif (preg_match('/\brole\s+([a-zA-Z0-9_\-]+)/i', $text, $m)) {
            $role = strtolower(trim((string)$m[1]));
        } elseif (preg_match('/\b([a-zA-Z0-9_\-]+)\s+role\b/i', $text, $m)) {
            $role = strtolower(trim((string)$m[1]));
        } else {
            foreach ($knownRoles as $candidateRole) {
                $rk = strtolower(trim((string)$candidateRole));
                if ($rk === '') continue;
                if (preg_match('/\b' . preg_quote($rk, '/') . '\b/i', $lower)) {
                    $role = $rk;
                    break;
                }
            }
        }

        if (preg_match('/\bpage\s*[=:]\s*([a-zA-Z0-9_\-\s]+)/i', $text, $m)) {
            $page = trim((string)$m[1]);
        } elseif (preg_match('/\bpage\s+([a-zA-Z0-9_\-\s]+)/i', $text, $m)) {
            $page = trim((string)$m[1]);
        } elseif (preg_match('/\b([a-zA-Z0-9_\-\s]+)\s+page\b/i', $text, $m)) {
            $page = trim((string)$m[1]);
        } else {
            $bestPage = '';
            $bestScore = 0;
            $normLower = preg_replace('/[^a-z0-9]+/', ' ', $lower);
            foreach ($assignable as $pageId => $meta) {
                $id = strtolower((string)$pageId);
                $label = strtolower(trim((string)($meta['label'] ?? '')));
                $idSpaced = str_replace('_', ' ', $id);
                $score = 0;

                if ($id !== '' && preg_match('/\b' . preg_quote($id, '/') . '\b/i', $lower)) {
                    $score += 5;
                }
                if ($idSpaced !== '' && strpos($normLower, $idSpaced) !== false) {
                    $score += 4;
                }
                if ($label !== '' && strpos($normLower, $label) !== false) {
                    $score += 5;
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestPage = (string)$pageId;
                }
            }

            if ($bestScore > 0) {
                $page = $bestPage;
            }
        }

        $page = preg_replace('/\s+/', '_', strtolower(trim((string)$page)));

        if ($role === '' || $page === '') {
            return ['ok' => false, 'needs_help' => true];
        }

        return [
            'ok' => true,
            'mode' => $mode,
            'role' => $role,
            'page' => $page,
        ];
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

$overrideReply = '';
$overrideParsed = chat_parse_role_override_command($msg);
if (!empty($overrideParsed['ok'])) {
    $isSuperAdmin = (($_SESSION['admin_role'] ?? 'admin') === 'superadmin');
    if (!$isSuperAdmin) {
        $overrideReply = 'Sentinel AI: role override denied. Only superadmin can lock/unlock compulsory role pages.';
    } else {
        $result = admin_apply_role_compulsory_override(
            (string)$overrideParsed['role'],
            (string)$overrideParsed['page'],
            (string)$overrideParsed['mode']
        );

        if (!empty($result['ok'])) {
            $verb = $overrideParsed['mode'] === 'unlock' ? 'unlocked (no longer compulsory)' : 'locked as compulsory';
            $overrideReply = sprintf(
                'Sentinel AI: done ✅ page "%s" is now %s for role "%s". Changes are saved.',
                (string)$result['page'],
                $verb,
                (string)$result['role']
            );
            if (function_exists('admin_log_action')) {
                admin_log_action('Roles', 'Chat Override', sprintf(
                    'Chat override by %s: %s page %s for role %s',
                    (string)($_SESSION['admin_user'] ?? 'unknown'),
                    (string)$overrideParsed['mode'],
                    (string)$result['page'],
                    (string)$result['role']
                ));
            }
        } else {
            $error = (string)($result['error'] ?? 'unknown_error');
            if ($error === 'default_compulsory_page') {
                $overrideReply = 'Sentinel AI: cannot unlock that page because it is part of the global compulsory safety baseline.';
            } elseif ($error === 'invalid_page') {
                $overrideReply = 'Sentinel AI: page not recognized. Use a valid page key or label (for example: announcement, support_tickets, ai_suggestions).';
            } elseif ($error === 'invalid_role') {
                $overrideReply = 'Sentinel AI: role not recognized for override. Check the role name and try again.';
            } else {
                $overrideReply = 'Sentinel AI: override failed to save. Please retry or use Roles page directly.';
            }
        }
    }
} elseif (!empty($overrideParsed['needs_help'])) {
    $overrideReply = 'Sentinel AI: I understand normal English too. Try: "@ai manager should not see announcement page" or "please lock support_tickets for helpdesk role". Strict format also works: @ai role override <lock|unlock> role=<role_name> page=<page_key>.';
}

if ($overrideReply !== '') {
    $messages[] = [
        'id' => chat_message_id(),
        'user' => 'system_ai_operator',
        'name' => 'Sentinel AI',
        'time' => date('c'),
        'message' => $overrideReply,
        'auto_replied_by' => 'system_ai_operator',
        'context' => 'role_override_command',
        'deleted' => false,
    ];
}

function should_trigger_ai_chat_reply($msg)
{
    $msg = strtolower(trim((string)$msg));
    if ($msg === '') return false;

    // Jovial join-in phrases (keeps existing trigger behavior intact)
    if (preg_match('/\b(hi|hey)\s+guys\b|\bwhats\s+up\s+guys\b|\bwhat\'?s\s+up\s+guys\b|\bwhat\s+is\s+up\s+guys\b|\bsup\s+guys\b/i', $msg)) {
        return true;
    }

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
            in_array($cls, ['network_ip_rotation', 'new_or_suspicious_device', 'duplicate_or_fraudulent_sequence', 'blocked_revoked_device', 'policy_device_sharing_risk'], true)
            || empty($r['ticket_resolved'])
        ) {
            $count++;
        }
    }
    return $count;
}

if ($overrideReply === '' && should_trigger_ai_chat_reply($msg)) {
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
