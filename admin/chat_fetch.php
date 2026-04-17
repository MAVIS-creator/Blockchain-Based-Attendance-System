<?php
require_once __DIR__ . '/session_bootstrap.php';
header('Content-Type: application/json');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/cache_helpers.php';
require_once __DIR__ . '/../request_timing.php';
require_once __DIR__ . '/../src/AiProviderClient.php';
request_timing_start('admin/chat_fetch.php');
$chatFile = admin_chat_file();
if (!file_exists($chatFile)) {
    file_put_contents($chatFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

$cacheSpan = microtime(true);
$queueFile = admin_chat_ai_queue_file();
$sessionsFile = admin_sessions_file();
$chatMtime = @filemtime($chatFile) ?: 0;
$queueMtime = @filemtime($queueFile) ?: 0;
$sessionsMtime = @filemtime($sessionsFile) ?: 0;
$cacheKey = 'admin_chat_fetch_payload:' . md5($chatMtime . '|' . $queueMtime . '|' . $sessionsMtime . '|' . (string)($_SESSION['admin_user'] ?? 'admin'));
$cacheEligible = true;
if (file_exists($queueFile)) {
    $queueRows = json_decode((string)@file_get_contents($queueFile), true);
    if (is_array($queueRows)) {
        $nowTs = time();
        foreach ($queueRows as $row) {
            if (!is_array($row)) continue;
            $runAfter = strtotime((string)($row['run_after'] ?? '')) ?: 0;
            if ($runAfter > 0 && $runAfter <= $nowTs) {
                $cacheEligible = false;
                break;
            }
        }
    }
}
if ($cacheEligible && admin_cache_enabled() && function_exists('apcu_fetch')) {
    $hit = false;
    $cachedPayload = @apcu_fetch($cacheKey, $hit);
    if ($hit && is_string($cachedPayload) && $cachedPayload !== '') {
        request_timing_span('chat_fetch_cache_lookup', $cacheSpan, ['hit' => true]);
        echo $cachedPayload;
        exit;
    }
}
request_timing_span('chat_fetch_cache_lookup', $cacheSpan, ['hit' => false]);

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
        $personalityMode = 'balanced';
        try {
            $settings = admin_load_settings_cached(15);
            $candidate = strtolower(trim((string)($settings['sentinel_personality_mode'] ?? 'balanced')));
            if (in_array($candidate, ['serious', 'balanced', 'playful'], true)) {
                $personalityMode = $candidate;
            }
        } catch (\Throwable $e) {
            $personalityMode = 'balanced';
        }

        $latestHuman = '';
        $lastIdleMessage = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $m = $messages[$i] ?? null;
            if (!is_array($m) || !empty($m['deleted'])) continue;
            if ((string)($m['user'] ?? '') === 'system_ai_operator' && (string)($m['context'] ?? '') === 'idle_proactive' && $lastIdleMessage === '') {
                $lastIdleMessage = trim((string)($m['message'] ?? ''));
            }
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
            if (preg_match('/(hello|hi|hey|yo|sup|how are you)/i', $latestHuman)) {
                $smallTalkPool = [
                    'Sentinel AI waving 👋: I can keep you company with a 20-second ops summary, a tiny joke, or a mini dashboard tour. Your pick?',
                    'Sentinel AI here 😎: Want a quick laugh, quick game, or quick system pulse-check while things are calm?',
                    'Sentinel AI check-in 🎯: We can do a fast page tour, a one-liner joke, or a support queue snapshot.'
                ];
                return $smallTalkPool[array_rand($smallTalkPool)];
            }
        }

        $pool = [
            'Sentinel AI online 🤖: I can help triage support tickets, validate attendance flow, or point you to any admin page instantly.',
            'Sentinel AI here 😄: Quiet room detected. Want a quick ops summary before the next ticket drops?',
            'Sentinel AI check-in ✅: I can jump in with fast diagnostics whenever you drop a message.',
            'Sentinel AI pit stop 🛠️: Want me to run a rapid health rundown (status, pending tickets, and suspicious attempts)?',
            'Sentinel AI mini-game 🎮: Choose one — 10-second joke, one admin tip, or lightning tour of useful pages.',
            'Sentinel AI lounge mode ☕: I can share a quick joke, surface urgent tasks, or prep your next ticket response.',
            'Sentinel AI nudge 📌: Need a super quick walkthrough of Dashboard → Status → Support Tickets?',
            'Sentinel AI vibe check 😄: Calm shift right now — should I drop a joke or a short ops snapshot?',
            'Sentinel AI alert-lite 🚦: I can do a 3-point summary: attendance flow, review queue, and security events.'
        ];

        $seriousPool = [
            'Sentinel AI status: I can provide a concise operations summary (attendance flow, review queue, security alerts).',
            'Sentinel AI standing by: Request a quick diagnostic snapshot when ready.',
            'Sentinel AI available: I can prioritize pending support tickets and suggest next actions.'
        ];

        $playfulPool = [
            'Sentinel AI on snack break 🍿: want a quick joke, a mini tour, or an ops speed-run?',
            'Sentinel AI mood boost 😄: I can drop one tiny joke, then we blitz through your priority queue.',
            'Sentinel AI arcade mode 🎮: pick one — fun fact, admin tip, or 30-second dashboard tour.'
        ];

        if ($personalityMode === 'serious') {
            $pool = $seriousPool;
        } elseif ($personalityMode === 'playful') {
            $pool = array_merge($pool, $playfulPool);
        }

        if (!empty($_SESSION['needs_tour'])) {
            $pool[] = 'Sentinel AI onboarder 🧭: Since this is still a fresh session, I can guide a quick tour of your key pages.';
        }

        if ($lastIdleMessage !== '') {
            $filtered = array_values(array_filter($pool, function ($line) use ($lastIdleMessage) {
                return trim((string)$line) !== $lastIdleMessage;
            }));
            if (!empty($filtered)) {
                $pool = $filtered;
            }
        }

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
    $persistSpan = microtime(true);
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    request_timing_span('chat_fetch_persist_mutation', $persistSpan);
}

flock($fp, LOCK_UN);
fclose($fp);

$payload = json_encode(array_slice($messages, -200));
if ($cacheEligible && is_string($payload) && $payload !== '' && admin_cache_enabled() && function_exists('apcu_store')) {
    @apcu_store($cacheKey, $payload, 2);
}
request_timing_span('chat_fetch_total', $cacheSpan, ['mutated' => $mutated, 'queue_mutated' => $queueMutated]);
echo $payload;
