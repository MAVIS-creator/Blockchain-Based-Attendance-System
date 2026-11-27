<?php
declare(strict_types=1);

/**
 * Atomic append-only JSON blockchain update.
 *
 * Uses a stable sidecar lock (not the replaceable chain inode) and an atomic
 * rename, so concurrent PHP-FPM workers cannot overwrite other blocks.
 * The old chain is preserved if encoding or writing fails.
 */
function attendance_chain_append_atomic(string $path, array $block): array
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('attendance_chain_directory_unavailable');
    }
    $lock = fopen($path . '.lock', 'c');
    if (!$lock) {
        throw new RuntimeException('attendance_chain_lock_unavailable');
    }
    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        throw new RuntimeException('attendance_chain_lock_failed');
    }

    $tmp = null;
    try {
        $chain = [];
        if (is_file($path)) {
            $raw = file_get_contents($path);
            if ($raw === false) {
                throw new RuntimeException('attendance_chain_read_failed');
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || !array_is_list($decoded)) {
                throw new RuntimeException('attendance_chain_invalid_json');
            }
            $chain = $decoded;
        }
        $last = empty($chain) ? null : $chain[count($chain) - 1];
        if ($last !== null && (!is_array($last) || !isset($last['hash']) || !is_string($last['hash']))) {
            throw new RuntimeException('attendance_chain_invalid_tip');
        }
        $prevHash = $last['hash'] ?? null;
        $block['prevHash'] = $prevHash;
        unset($block['hash']);
        ksort($block);
        $block['hash'] = hash('sha256', json_encode($block, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . $prevHash);
        $chain[] = $block;
        $json = json_encode($chain, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp = tempnam($dir, '.chain-');
        if ($tmp === false) {
            throw new RuntimeException('attendance_chain_tempfile_failed');
        }
        @chmod($tmp, 0600);
        $handle = fopen($tmp, 'wb');
        if (!$handle) {
            throw new RuntimeException('attendance_chain_tempfile_open_failed');
        }
        try {
            $len = strlen($json);
            $written = 0;
            while ($written < $len) {
                $n = fwrite($handle, substr($json, $written));
                if ($n === false || $n === 0) {
                    throw new RuntimeException('attendance_chain_write_failed');
                }
                $written += $n;
            }
            if (!fflush($handle)) {
                throw new RuntimeException('attendance_chain_flush_failed');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('attendance_chain_fsync_failed');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($tmp, $path)) {
            throw new RuntimeException('attendance_chain_replace_failed');
        }
        $tmp = null;
        return ['count' => count($chain), 'block' => $block];
    } finally {
        if ($tmp !== null && is_file($tmp)) {
            @unlink($tmp);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
