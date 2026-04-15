<?php

/**
 * chain_anchor.php  —  Self-Contained Log Hashing & Tamper-Proof Anchoring
 *
 * How it works (no Polygon / no external blockchain needed):
 *
 *  1. After every attendance submission, the FULL today's .log file is SHA-256
 *     hashed → stored in Supabase `log_anchors` table with a timestamp.
 *  2. That Supabase record is completely separate from your Azure server, so a
 *     tamper attempt must compromise BOTH systems simultaneously.
 *  3. Your local `attendance_chain.json` is also verified: each block's hash is
 *     re-computed and cross-checked against its stored hash and prevHash link.
 *     Any alteration to any log line breaks the chain instantly.
 *  4. The admin can run `admin/verify_integrity.php` at any time to compare
 *     the live local hash against the stored Supabase anchor — mismatch = tamper.
 *
 * Called from submit.php (after fastcgi_finish_request, never blocks the user).
 */

require_once __DIR__ . '/hybrid_dual_write.php';
require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/env_helpers.php';

/**
 * Hash today's attendance .log file and anchor it in Supabase.
 *
 * @param string $logFile      Absolute path to today's .log file.
 * @param string $date         Date string "Y-m-d".
 * @param string $course       Active course name.
 * @param string $chainHash    The latest block hash from attendance_chain.json.
 * @return array               ['ok'=>bool, 'hash'=>string|null, 'anchor_id'=>int|null, 'error'=>string|null]
 */
function chain_anchor_log(string $logFile, string $date, string $course, string $chainHash): array
{
    // 1. Hash the raw .log file (SHA-256 of all log lines = fingerprint of today's attendance)
    if (!file_exists($logFile)) {
        return ['ok' => false, 'hash' => null, 'anchor_id' => null, 'error' => 'log_file_not_found'];
    }

    $logContent = file_get_contents($logFile);
    if ($logContent === false) {
        return ['ok' => false, 'hash' => null, 'anchor_id' => null, 'error' => 'log_file_unreadable'];
    }

    $logHash = hash('sha256', $logContent);

    // 2. Build anchor payload
    $payload = [
        'date'         => $date,
        'course'       => $course,
        'log_hash'     => $logHash,
        'chain_hash'   => $chainHash,           // latest block hash from attendance_chain.json
        'anchored_at'  => date('c'),
        'server_id'    => gethostname() ?: 'unknown',
    ];

    // 3. Push to Supabase log_anchors table (separate system = external witness)
    if (!hybrid_enabled()) {
        // Hybrid mode off — store locally only (still useful for local verification)
        chain_anchor_save_local($date, $payload);
        return ['ok' => true, 'hash' => $logHash, 'anchor_id' => null, 'error' => null];
    }

    $err = null;
    $ok  = hybrid_supabase_insert('log_anchors', $payload, $err);

    if ($ok) {
        return ['ok' => true, 'hash' => $logHash, 'anchor_id' => true, 'error' => null];
    }

    // Supabase write failed — save locally as fallback, outbox will replay later
    chain_anchor_save_local($date, $payload);
    hybrid_outbox_append([
        'at'      => date('c'),
        'entity'  => 'log_anchor',
        'table'   => 'log_anchors',
        'payload' => $payload,
        'error'   => (string)$err,
    ]);

    return ['ok' => false, 'hash' => $logHash, 'anchor_id' => null, 'error' => $err];
}

/**
 * Save anchor record locally as a fallback (JSON lines file).
 * Used when Supabase is unavailable.
 */
function chain_anchor_save_local(string $date, array $payload): void
{
    app_storage_init();
    $dir  = app_storage_file('secure_logs');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/local_anchors.jsonl';
    @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Verify the local attendance_chain.json integrity.
 * Returns ['valid'=>bool, 'blocks'=>int, 'error'=>string|null]
 */
function chain_verify_local(): array
{
    app_storage_init();
    $chainFile = app_storage_file('secure_logs/attendance_chain.json');

    if (!file_exists($chainFile)) {
        return ['valid' => false, 'blocks' => 0, 'error' => 'chain_file_not_found'];
    }

    $chain = json_decode(file_get_contents($chainFile), true);
    if (!is_array($chain) || count($chain) === 0) {
        return ['valid' => false, 'blocks' => 0, 'error' => 'chain_empty_or_invalid'];
    }

    $prevHash = null;
    foreach ($chain as $i => $block) {
        // Re-compute hash from the block data (same logic as submit.php)
        $blockDataForHash = $block;
        unset($blockDataForHash['hash']);
        ksort($blockDataForHash);
        $expectedHash = hash('sha256', json_encode($blockDataForHash, JSON_UNESCAPED_SLASHES) . $prevHash);

        if ($block['hash'] !== $expectedHash) {
            return [
                'valid'  => false,
                'blocks' => count($chain),
                'error'  => "hash_mismatch_at_block_$i",
            ];
        }

        if ($i > 0 && $block['prevHash'] !== $prevHash) {
            return [
                'valid'  => false,
                'blocks' => count($chain),
                'error'  => "prev_hash_broken_at_block_$i",
            ];
        }

        $prevHash = $block['hash'];
    }

    return ['valid' => true, 'blocks' => count($chain), 'error' => null, 'tip_hash' => $prevHash];
}

/**
 * Verify today's log file against the latest Supabase anchor.
 * Returns ['match'=>bool, 'local_hash'=>string, 'anchored_hash'=>string|null, 'error'=>string|null]
 */
function chain_verify_against_supabase(string $logFile, string $date, string $course): array
{
    if (!file_exists($logFile)) {
        return ['match' => false, 'local_hash' => '', 'anchored_hash' => null, 'error' => 'log_not_found'];
    }

    $localHash = hash('sha256', file_get_contents($logFile));

    if (!hybrid_enabled()) {
        return ['match' => null, 'local_hash' => $localHash, 'anchored_hash' => null, 'error' => 'supabase_disabled'];
    }

    $rows = null;
    $err  = null;
    $ok   = hybrid_supabase_select('log_anchors', [
        'select'       => 'log_hash,chain_hash,anchored_at',
        'date'         => 'eq.' . $date,
        'course'       => 'eq.' . $course,
        'order'        => 'anchored_at.desc',
        'limit'        => '1',
    ], $rows, $err);

    if (!$ok || !is_array($rows) || count($rows) === 0) {
        return ['match' => null, 'local_hash' => $localHash, 'anchored_hash' => null, 'error' => $err ?: 'no_anchor_found'];
    }

    $anchor       = $rows[0];
    $anchoredHash = $anchor['log_hash'] ?? null;
    $match        = ($anchoredHash !== null && $anchoredHash === $localHash);

    return [
        'match'         => $match,
        'local_hash'    => $localHash,
        'anchored_hash' => $anchoredHash,
        'anchored_at'   => $anchor['anchored_at'] ?? null,
        'error'         => $match ? null : 'hash_mismatch',
    ];
}
