<?php
declare(strict_types=1);

// This script must be invoked by the supervised CLI service only.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
date_default_timezone_set('Africa/Lagos');
require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/../src/AiTicketAutomationEngine.php';
require_once __DIR__ . '/../src/AiSqlJobQueue.php';
app_storage_init();
function ai_worker_log(array $event): void
{
    $event['logged_at'] = date('c');
    @file_put_contents(app_storage_file('logs/ai_ticket_worker.log'),
      json_encode($event, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}
function ai_worker_file_batch(int $limit): array
{
    $path = app_storage_file('logs/ai_ticket_queue.jsonl');
    if (!is_file($path)) return [];
    $fp = fopen($path, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        throw new RuntimeException('AI file queue unavailable');
    }
    try {
        $now = time();
        $rows = array_filter(explode("\n", stream_get_contents($fp) ?: ''));
        $jobs = [];
        foreach ($rows as $line) {
            $row = json_decode($line, true);
            if (!is_array($row) || empty($row['ticket_timestamp'])) continue;
            if ((int)($row['available_at'] ?? 0) > $now) continue;
            $jobs[] = ['timestamp' => (string)$row['ticket_timestamp'],
                'attempts' => (int)($row['attempts'] ?? 0) + 1];
            if (count($jobs) >= $limit) break;
        }
        return $jobs; // No destructive dequeue before processing
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
function ai_worker_file_complete(array $job, bool $ok, string $error = ''): void
{
    $path = app_storage_file('logs/ai_ticket_queue.jsonl');
    $fp = fopen($path, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        throw new RuntimeException('AI queue acknowledgement unavailable');
    }
    try {
        $rows = array_filter(explode("\n", stream_get_contents($fp) ?: ''));
        $updated = [];
        $found = false;
        foreach ($rows as $line) {
            $row = json_decode($line, true);
            if (!$found && is_array($row) && (string)($row['ticket_timestamp'] ?? '') === $job['timestamp']) {
                $found = true;
                if (!$ok && $job['attempts'] < 5) {
                    $row['attempts'] = $job['attempts'];
                    $row['available_at'] = time() + min(300, 5 * (2 ** min($job['attempts'], 6)));
                    $updated[] = json_encode($row, JSON_UNESCAPED_SLASHES);
                } elseif (!$ok) {
                    @file_put_contents(app_storage_file('logs/ai_ticket_deadletter.jsonl'),
                        json_encode(['job' => $row, 'error' => $error, 'at' => date('c')]) . PHP_EOL,
                        FILE_APPEND | LOCK_EX);
                }
                continue;
            }
            $updated[] = $line;
        }
        $json = $updated ? implode("\n", $updated) . "\n" : '';
        rewind($fp);
        if (!ftruncate($fp, 0) || ($json !== '' && fwrite($fp, $json) !== strlen($json)) || !fflush($fp)) {
            throw new RuntimeException('AI queue acknowledgement failed');
        }
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
$mutex = fopen(app_storage_file('logs/ai_ticket_worker.singleton.lock'), 'c');
if (!$mutex || !flock($mutex, LOCK_EX | LOCK_NB)) {
    exit(0); // Another supervised worker is already running
}
$max = 20;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--drain=')) $max = max(1, min(100, (int)substr($arg, 8)));
}
$processed = 0;
$errors = 0;
$engine = new AiTicketAutomationEngine();
try {
    if (bbas_ai_queue_driver() === 'mysql') {
        for ($i = 0; $i < $max; $i++) {
            $job = bbas_ai_claim_mysql();
            if (!$job) break;
            try {
                $result = $engine->processTicketByTimestamp($job['timestamp']);
                $ok = !empty($result['ok']);
                $error = (string)($result['error'] ?? '');
            } catch (Throwable $e) {
                $ok = false;
                $error = $e->getMessage();
            }
            bbas_ai_finish_mysql($job, $ok, $error);
            if ($ok) $processed++; else $errors++;
            ai_worker_log(['job' => $job['id'], 'ok' => $ok, 'error' => $error]);
        }
    } else {
        foreach (ai_worker_file_batch($max) as $job) {
            try {
                $result = $engine->processTicketByTimestamp($job['timestamp']);
                $ok = !empty($result['ok']);
                $error = (string)($result['error'] ?? '');
            } catch (Throwable $e) {
                $ok = false;
                $error = $e->getMessage();
            }
            ai_worker_file_complete($job, $ok, $error);
            if ($ok) $processed++; else $errors++;
            ai_worker_log(['ticket' => $job['timestamp'], 'ok' => $ok, 'error' => $error]);
        }
    }
} catch (Throwable $e) {
    ai_worker_log(['error' => 'worker_unavailable', 'message' => $e->getMessage()]);
    fwrite(STDERR, "AI queue worker unavailable\n");
    exit(1);
} finally {
    flock($mutex, LOCK_UN);
    fclose($mutex);
}
echo json_encode(['ok' => true, 'processed' => $processed, 'errors' => $errors]) . PHP_EOL;
