<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../src/PostSubmitQueue.php';
require_once __DIR__ . '/../src/PostSubmitProcessor.php';
app_storage_init();

$mutex = fopen(app_storage_file('logs/post_submit_worker.lock'), 'c');
if (!$mutex || !flock($mutex, LOCK_EX | LOCK_NB)) {
    exit(0);
}
function post_submit_batch(int $limit): array
{
    $file = bbas_post_submit_queue_path();
    if (!is_file($file)) return [];
    $fp = fopen($file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        throw new RuntimeException('Post-submit queue inaccessible');
    }
    try {
        $rows = array_filter(explode("\n", stream_get_contents($fp) ?: ''));
        $jobs = [];
        foreach ($rows as $line) {
            $job = json_decode($line, true);
            if (!is_array($job) || empty($job['id'])) continue;
            if ((int)($job['available_at'] ?? 0) > time()) continue;
            $jobs[] = $job;
            if (count($jobs) >= $limit) break;
        }
        return $jobs;
    } finally {
        flock($fp, LOCK_UN); fclose($fp);
    }
}
function post_submit_ack(array $job, bool $ok, string $error): void
{
    $file = bbas_post_submit_queue_path();
    $fp = fopen($file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        throw new RuntimeException('Post-submit queue acknowledgement inaccessible');
    }
    try {
        $rows = array_filter(explode("\n", stream_get_contents($fp) ?: ''));
        $revised = [];
        $found = false;
        foreach ($rows as $line) {
            $item = json_decode($line, true);
            if (!$found && is_array($item) && ($item['id'] ?? '') === $job['id']) {
                $found = true;
                if (!$ok && (int)($item['attempts'] ?? 0) < 4) {
                    $item['attempts'] = (int)($item['attempts'] ?? 0) + 1;
                    $item['available_at'] = time() + 10 * (2 ** min($item['attempts'], 5));
                    $revised[] = json_encode($item, JSON_UNESCAPED_SLASHES);
                } elseif (!$ok) {
                    $dead = app_storage_file('logs/post_submit_deadletter.jsonl');
                    @file_put_contents($dead, json_encode(['job' => $item,
                        'error' => $error, 'at' => date('c')]) . PHP_EOL, FILE_APPEND | LOCK_EX);
                }
                continue;
            }
            $revised[] = $line;
        }
        $payload = $revised ? implode("\n", $revised) . "\n" : '';
        rewind($fp);
        if (!ftruncate($fp, 0) || ($payload !== '' && fwrite($fp, $payload) !== strlen($payload)) || !fflush($fp)) {
            throw new RuntimeException('Post-submit queue acknowledgement write failure');
        }
    } finally {
        flock($fp, LOCK_UN); fclose($fp);
    }
}
$drain = 20;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--drain=')) $drain = max(1, min(100, (int)substr($arg, 8)));
}
try {
    $done = 0; $errors = 0;
    foreach (post_submit_batch($drain) as $job) {
        try {
            bbas_process_post_submit($job);
            $ok = true; $message = '';
        } catch (Throwable $e) {
            $ok = false; $message = $e->getMessage();
            error_log('Post-submit job failed: ' . $message);
        }
        post_submit_ack($job, $ok, $message);
        if ($ok) $done++; else $errors++;
    }
    echo json_encode(['ok' => true, 'processed' => $done, 'errors' => $errors]) . PHP_EOL;
} finally {
    flock($mutex, LOCK_UN); fclose($mutex);
}
