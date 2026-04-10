<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/../src/AiProviderClient.php';
$chatFile = admin_chat_file();
if (!file_exists($chatFile)) {
    file_put_contents($chatFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

if (!function_exists('chat_message_id')) {
    function chat_message_id()
    {
        return 'msg_' . bin2hex(random_bytes(8));
    }
}

if (!function_exists('active_admin_users_count')) {
    function active_admin_users_count()
    {
        $sessionsFile = admin_sessions_file();
        if (!file_exists($sessionsFile)) {
            return 0;
        }
        $rows = json_decode((string)@file_get_contents($sessionsFile), true);
        if (!is_array($rows)) {
            return 0;
        }
        $now = time();
        $users = [];
        foreach ($rows as $sess) {
            if (!is_array($sess)) continue;
            $user = (string)($sess['user'] ?? '');
            $last = (int)($sess['last_activity'] ?? 0);
            if ($user !== '' && $last > 0 && ($now - $last) <= 180) {
                $users[$user] = true;
            }
        }
        return count($users);
    }
}

if (!function_exists('build_idle_sentinel_message')) {
    function build_idle_sentinel_message(array $messages)
    {
        $latestHuman = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $m = $messages[$i] ?? null;
            if (!is_array($m) || !empty($m['deleted'])) continue;
            if ((string)($m['user'] ?? '') === 'system_ai_operator') continue;
            $latestHuman = strtolower(trim((string)($m['message'] ?? '')));
            if ($latestHuman !== '') break;
        }

        if ($latestHuman !== '') {
            if (preg_match('/(ticket|support|issue|error|failed|revoked|blocked)/i', $latestHuman)) {
                return 'Sentinel AI checking in 👀: I can summarize unresolved tickets and suggest the fastest safe actions if you want.';
            }
            if (preg_match('/(checkin|checkout|attendance|course)/i', $latestHuman)) {
                return 'Sentinel AI ping 🚀: Need a quick attendance sanity-check? I can help verify check-in/out flow in one shot.';
            }
        }

        $pool = [
            'Sentinel AI online 🤖: I can help triage support tickets, validate attendance flow, or point you to any admin page instantly.',
            'Sentinel AI here 😄: Quiet room detected. Want a quick ops summary before the next ticket drops?',
            'Sentinel AI check-in ✅: I can jump in with fast diagnostics whenever you drop a message.'
        ];
        return $pool[array_rand($pool)];
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

$fp = fopen($chatFile, 'c+');
if (!$fp) {
    echo json_encode([]);
    exit;
}
if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    echo json_encode([]);
    exit;
}

rewind($fp);
$raw = stream_get_contents($fp);
$messages = json_decode($raw ?: '[]', true);
if (!is_array($messages)) {
    $messages = [];
}

// Normalize legacy rows so frontend actions can reliably target by id and deletion state.
$mutated = false;
foreach ($messages as $i => $m) {
    if (!is_array($m)) {
        $messages[$i] = [];
        $mutated = true;
        continue;
    }
    if (empty($m['id'])) {
        $messages[$i]['id'] = chat_message_id();
        $mutated = true;
    }
    if (!array_key_exists('deleted', $m)) {
        $messages[$i]['deleted'] = false;
        $mutated = true;
    }
}

// Process one due AI queue item at a time so human messages land first, then AI follows naturally.
$queueFile = admin_chat_ai_queue_file();
$queue = chat_ai_queue_load($queueFile);
$queueMutated = false;
$now = time();

if (!empty($queue)) {
    $dueIdx = -1;
    foreach ($queue as $idx => $job) {
        if (!is_array($job)) {
            continue;
        }
        $runAfter = strtotime((string)($job['run_after'] ?? '')) ?: 0;
        if ($runAfter <= $now) {
            $dueIdx = (int)$idx;
            break;
        }
    }

    if ($dueIdx >= 0 && isset($queue[$dueIdx]) && is_array($queue[$dueIdx])) {
        $job = $queue[$dueIdx];

        $recentAiReply = false;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $candidate = $messages[$i] ?? null;
            if (!is_array($candidate)) continue;
            if ((string)($candidate['user'] ?? '') !== 'system_ai_operator') continue;
            $recentTs = strtotime((string)($candidate['time'] ?? '')) ?: 0;
            if ($recentTs > 0 && ($now - $recentTs) < 12) {
                $recentAiReply = true;
            }
            break;
        }

        if (!$recentAiReply) {
            $recentMessages = [];
            for ($i = count($messages) - 1; $i >= 0 && count($recentMessages) < 6; $i--) {
                $candidate = $messages[$i] ?? null;
                if (!is_array($candidate) || !empty($candidate['deleted'])) {
                    continue;
                }
                $recentMessages[] = [
                    'name' => (string)($candidate['name'] ?? 'Unknown'),
                    'user' => (string)($candidate['user'] ?? 'unknown'),
                    'message' => (string)($candidate['message'] ?? ''),
                ];
            }
            $recentMessages = array_reverse($recentMessages);

            $adminMessage = trim((string)($job['message'] ?? ''));
            if ($adminMessage !== '') {
                $aiReply = AiProviderClient::suggestAdminChatReply($adminMessage, [
                    'pending_review_count' => (int)($job['pending_review_count'] ?? 0),
                    'recent_messages' => $recentMessages,
                    'allow_humor' => true,
                    'assistant_name' => 'Sentinel AI',
                ]);

                $aiText = !empty($aiReply['ok']) ? trim((string)($aiReply['suggestion'] ?? '')) : '';
                if ($aiText !== '') {
                    $messages[] = [
                        'id' => chat_message_id(),
                        'user' => 'system_ai_operator',
                        'name' => 'Sentinel AI',
                        'time' => date('c'),
                        'message' => $aiText,
                        'auto_replied_by' => 'system_ai_operator',
                        'context' => 'admin_chat_assist',
                        'ai_provider' => (string)($aiReply['provider'] ?? 'rules'),
                        'ai_model' => (string)($aiReply['model'] ?? 'rules-chat-v1'),
                        'ai_latency_ms' => (int)($aiReply['latency_ms'] ?? 0),
                        'deleted' => false,
                    ];
                    $mutated = true;
                }
            }
        }

        array_splice($queue, $dueIdx, 1);
        $queueMutated = true;
    }
}

if ($queueMutated) {
    chat_ai_queue_save($queueFile, $queue);
}
$now = time();
$activeCount = active_admin_users_count();
$lastHumanTs = 0;
$lastIdleAiTs = 0;
for ($i = count($messages) - 1; $i >= 0; $i--) {
    $m = $messages[$i] ?? null;
    if (!is_array($m)) continue;
    $ts = strtotime((string)($m['time'] ?? '')) ?: 0;
    if (($m['user'] ?? '') !== 'system_ai_operator' && empty($m['deleted']) && $lastHumanTs === 0) {
        $lastHumanTs = $ts;
    }
    if (($m['user'] ?? '') === 'system_ai_operator' && (string)($m['context'] ?? '') === 'idle_proactive' && $lastIdleAiTs === 0) {
        $lastIdleAiTs = $ts;
    }
    if ($lastHumanTs > 0 && $lastIdleAiTs > 0) {
        break;
    }
}

$shouldProactive =
    $activeCount > 0
    && ($lastHumanTs === 0 || ($now - $lastHumanTs) >= (600 + (abs(crc32((string)$lastHumanTs . '|' . (string)$activeCount)) % 301)))
    && ($lastIdleAiTs === 0 || ($now - $lastIdleAiTs) >= 600);

if ($shouldProactive) {
    $messages[] = [
        'id' => chat_message_id(),
        'user' => 'system_ai_operator',
        'name' => 'Sentinel AI',
        'time' => date('c'),
        'message' => build_idle_sentinel_message($messages),
        'auto_replied_by' => 'system_ai_operator',
        'context' => 'idle_proactive',
        'deleted' => false,
    ];
    $mutated = true;
}

if (count($messages) > 1000) {
    $messages = array_slice($messages, -1000);
    $mutated = true;
}

if ($mutated) {
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fp);
}

flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(array_slice($messages, -200));
