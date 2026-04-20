<?php

declare(strict_types=1);

date_default_timezone_set('Africa/Lagos');

require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/../src/AiTicketAutomationEngine.php';

app_storage_init();

function ai_worker_queue_file(): string
{
    return app_storage_file('logs/ai_ticket_queue.jsonl');
}

function ai_worker_log_file(): string
{
    return app_storage_file('logs/ai_ticket_worker.log');
}

function ai_worker_take_batch(int $maxItems): array
{
    $maxItems = max(1, min(200, $maxItems));
    $file = ai_worker_queue_file();
    if (!file_exists($file)) {
        return [];
    }

    $fp = fopen($file, 'c+');
    if (!$fp) {
        return [];
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return [];
    }

    rewind($fp);
    $raw = stream_get_contents($fp);
    $lines = preg_split('/\r\n|\n|\r/', (string)$raw);
    $lines = array_values(array_filter($lines, static function ($line) {
        return trim((string)$line) !== '';
    }));

    $batchLines = array_slice($lines, 0, $maxItems);
    $remaining = array_slice($lines, count($batchLines));

    rewind($fp);
    ftruncate($fp, 0);
    if (!empty($remaining)) {
        fwrite($fp, implode("\n", $remaining) . "\n");
    }
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $batch = [];
    foreach ($batchLines as $line) {
        $entry = json_decode((string)$line, true);
        if (!is_array($entry)) {
            continue;
        }
        $ts = trim((string)($entry['ticket_timestamp'] ?? ''));
        if ($ts === '') {
            continue;
        }
        $batch[] = $ts;
    }

    return $batch;
}

function ai_worker_log(array $entry): void
{
    $entry['logged_at'] = date('c');
    @file_put_contents(
        ai_worker_log_file(),
        json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

$drain = 20;
foreach (($argv ?? []) as $arg) {
    if (strpos((string)$arg, '--drain=') === 0) {
        $drain = (int)substr((string)$arg, 8);
    }
}
$drain = max(1, min(200, $drain));

$queue = ai_worker_take_batch($drain);
if (empty($queue)) {
    echo json_encode(['ok' => true, 'processed' => 0, 'message' => 'queue_empty'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$engine = new AiTicketAutomationEngine();
$processed = 0;
$errors = 0;

foreach ($queue as $ticketTimestamp) {
    try {
        $result = $engine->processTicketByTimestamp($ticketTimestamp);
        $ok = !empty($result['ok']);
        if ($ok) {
            $processed++;
        } else {
            $errors++;
        }
        ai_worker_log([
            'ticket_timestamp' => $ticketTimestamp,
            'ok' => $ok,
            'error' => (string)($result['error'] ?? ''),
            'processed' => (int)($result['processed'] ?? 0)
        ]);
    } catch (Throwable $e) {
        $errors++;
        ai_worker_log([
            'ticket_timestamp' => $ticketTimestamp,
            'ok' => false,
            'error' => 'worker_exception',
            'message' => $e->getMessage()
        ]);
    }
}

echo json_encode([
    'ok' => true,
    'processed' => $processed,
    'errors' => $errors,
    'requested' => count($queue)
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
