<?php
declare(strict_types=1);

/** Atomic fixed-window IP counter; callers retain higher-level policy. */
function request_guard_tick(string $file, array $defaults, int $now, int $window): array
{
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('request_guard_storage_unavailable');
    }
    $fp = fopen($file, 'c+');
    if (!$fp) {
        throw new RuntimeException('request_guard_storage_unavailable');
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('request_guard_lock_failed');
        }
        $data = json_decode(stream_get_contents($fp) ?: '', true);
        $state = array_merge($defaults, is_array($data) ? $data : []);
        if ($now - (int)($state['window_started'] ?? 0) >= $window) {
            $state['window_started'] = $now;
            $state['count'] = 0;
        }
        $state['count'] = (int)($state['count'] ?? 0) + 1;
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        rewind($fp);
        if (!ftruncate($fp, 0) || fwrite($fp, $json) !== strlen($json) || !fflush($fp)) {
            throw new RuntimeException('request_guard_write_failed');
        }
        return $state;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
function request_guard_block(string $file, int $until): void
{
    $fp = fopen($file, 'c+');
    if (!$fp) {
        throw new RuntimeException('request_guard_storage_unavailable');
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('request_guard_lock_failed');
        }
        $state = json_decode(stream_get_contents($fp) ?: '', true);
        $state = is_array($state) ? $state : [];
        $state['blocked_until'] = max((int)($state['blocked_until'] ?? 0), $until);
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        rewind($fp);
        if (!ftruncate($fp, 0) || fwrite($fp, $json) !== strlen($json) || !fflush($fp)) {
            throw new RuntimeException('request_guard_write_failed');
        }
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
