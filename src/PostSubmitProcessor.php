<?php
declare(strict_types=1);

require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/../hybrid_dual_write.php';
require_once __DIR__ . '/../admin/runtime_storage.php';
require_once __DIR__ . '/../request_timing.php';

function bbas_process_post_submit(array $job): void
{
    $today = (string)($job['day'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
        throw new RuntimeException('invalid_background_day');
    }
    $name = (string)($job['name'] ?? '');
    $matric = (string)($job['matric'] ?? '');
    $action = (string)($job['action'] ?? '');
    $fingerprint = (string)($job['fingerprint'] ?? '');
    $ip = (string)($job['ip'] ?? '');
    $mac = (string)($job['mac'] ?? 'UNKNOWN');
    $userAgent = (string)($job['user_agent'] ?? '');
    $course = (string)($job['course'] ?? 'General');
    $courseNormalized = strtolower(trim($course));
    $logReason = (string)($job['reason'] ?? '-');
    $block = ['hash' => (string)($job['chain_hash'] ?? '')];
    $settings = ['auto_send' => (array)($job['auto_send'] ?? [])];
    $logFile = app_storage_file('logs/' . $today . '.log');
    if ($block['hash'] === '') {
        throw new RuntimeException('missing_committed_chain_hash');
    }

// ✅ Optional hybrid dual-write to Supabase (file + local chain remain source of truth).
$dualWriteSpan = microtime(true);
hybrid_dual_write('attendance', 'attendance_logs', [
    'timestamp'  => date('c'),
    'name'       => $name,
    'matric'     => $matric,
    'action'     => $action,
    'fingerprint'=> $fingerprint,
    'ip'         => $ip,
    'mac'        => $mac,
    'user_agent' => $userAgent,
    'course'     => $course,
    'reason'     => $logReason,
    'chain_hash' => $block['hash'],
]);
request_timing_span('hybrid_dual_write', $dualWriteSpan);

// Auto-send trigger once a full attendance cycle (checkin + checkout) is complete.
$autoSendEnabled   = !empty($settings['auto_send']['enabled']);
$autoSendRecipient = trim((string)($settings['auto_send']['recipient'] ?? ''));
$autoSendFormat    = strtolower(trim((string)($settings['auto_send']['format'] ?? 'csv')));
if (!in_array($autoSendFormat, ['csv', 'pdf'], true)) {
    $autoSendFormat = 'csv';
}

if ($autoSendEnabled && $autoSendRecipient !== '' && filter_var($autoSendRecipient, FILTER_VALIDATE_EMAIL)) {
    $postWriteLines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $cycleCheckin   = 0;
    $cycleCheckout  = 0;
    foreach ($postWriteLines as $ln) {
        $parts = array_map('trim', explode('|', (string)$ln));
        if (!isset($parts[1], $parts[2])) continue;
        if ($parts[1] !== $matric) continue;
        $lineCourse = isset($parts[8]) ? strtolower(trim((string)$parts[8])) : 'general';
        if ($lineCourse !== $courseNormalized) continue;
        $lineAction = strtolower((string)$parts[2]);
        if ($lineAction === 'checkin')  $cycleCheckin++;
        elseif ($lineAction === 'checkout') $cycleCheckout++;
    }

    if ($cycleCheckin > 0 && $cycleCheckout > 0) {
        $trackerFile = admin_storage_migrate_file('auto_send_tracker_submit.json', app_storage_file('auto_send_tracker_submit.json'));
        if (!file_exists($trackerFile)) {
            @file_put_contents($trackerFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
        $fpTracker = fopen($trackerFile, 'c+');
        if ($fpTracker && flock($fpTracker, LOCK_EX)) {
            rewind($fpTracker);
            $trackerRaw = stream_get_contents($fpTracker);
            $tracker = json_decode($trackerRaw ?: '[]', true);
            if (!is_array($tracker)) $tracker = [];

            $markerKey = $today . '|' . $matric . '|' . $courseNormalized;
            if (!isset($tracker[$markerKey])) {
                $tracker[$markerKey] = [
                    'triggered_at' => date('c'),
                    'date'   => $today,
                    'matric' => $matric,
                    'course' => $course,
                    'status' => 'queued',
                ];
                // Fire auto-send as a detached background process (non-blocking).
                $cmd = escapeshellarg(PHP_BINARY)
                    . ' ' . escapeshellarg(__DIR__ . '/admin/auto_send_logs.php')
                    . ' ' . escapeshellarg($today)
                    . ' --force'
                    . ' --recipient=' . escapeshellarg($autoSendRecipient)
                    . ' --format='    . escapeshellarg($autoSendFormat)
                    . ' > /dev/null 2>&1 &';
                @exec($cmd);
                $tracker[$markerKey]['status'] = 'dispatched';
                request_timing_note('auto_send_triggered', 'dispatched_background');
            } else {
                request_timing_note('auto_send_triggered', 'already_sent');
            }
            rewind($fpTracker);
            ftruncate($fpTracker, 0);
            fwrite($fpTracker, json_encode($tracker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($fpTracker);
            flock($fpTracker, LOCK_UN);
            fclose($fpTracker);
        } elseif (is_resource($fpTracker)) {
            fclose($fpTracker);
        }
    }
}

// ⚡ Self-contained log anchoring — runs in background after response is sent.
// Hashes today's .log file + the latest chain block hash and pushes both to
// Supabase as an immutable external witness record.  No Polygon, no gas fees,
// no RPC latency — just pure SHA-256 verification stored on a separate system.
require_once __DIR__ . '/chain_anchor.php';
$anchorResult = chain_anchor_log($logFile, $today, $course, $block['hash']);
if (!$anchorResult['ok']) {
    error_log('Chain anchor failed: ' . ($anchorResult['error'] ?? 'unknown'));
} else {
    request_timing_note('log_anchor_hash', $anchorResult['hash'] ?? '');
}

}
