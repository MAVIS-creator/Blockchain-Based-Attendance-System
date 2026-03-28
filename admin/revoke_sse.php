<?php
// Server-Sent Events endpoint to push revocation updates to connected clients.
// Long-running script: polls revoked.json filemtime and pushes updates when changed.
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', 0);
set_time_limit(0);
ob_implicit_flush(true);

$file = __DIR__ . '/revoked.json';
$lastMtime = file_exists($file) ? filemtime($file) : 0;

// send an initial comment
echo ": connected\n\n";
while (true) {
    clearstatcache(false, $file);
    $mtime = file_exists($file) ? filemtime($file) : 0;
    if ($mtime > $lastMtime) {
        $lastMtime = $mtime;
        $raw = @file_get_contents($file);
        $data = @json_decode($raw, true) ?: ['tokens'=>[], 'ips'=>[], 'macs'=>[]];
        // filter expired same as revoked_tokens.php
        $now = time();
        $resp = ['tokens'=>[], 'ips'=>[], 'macs'=>[]];
        foreach (['tokens','ips','macs'] as $k) {
            if (!isset($data[$k]) || !is_array($data[$k])) continue;
            foreach ($data[$k] as $key => $meta) {
                $expiry = intval($meta['expiry'] ?? 0);
                if ($expiry !==0 && $expiry < $now) continue;
                $resp[$k][$key] = $meta;
            }
        }
        $payload = json_encode(['ok'=>true,'revoked'=>$resp]);
        echo "event: revoked\n";
        // send data in lines and double newline
        echo "data: " . str_replace("\n","\\n", $payload) . "\n\n";
    }
    // flush and sleep
    @ob_flush(); @flush();
    // keepalive comment every 25s
    sleep(2);
    // small heartbeat
    echo ": heartbeat\n\n";
    @ob_flush(); @flush();
    // prevent runaway loop issues; continue
}

?>
