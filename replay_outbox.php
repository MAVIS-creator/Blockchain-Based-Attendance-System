<?php
// This maintenance worker is CLI-only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/hybrid_dual_write.php';

$max = 200;
if (isset($argv[1]) && is_numeric($argv[1])) {
  $max = max(1, (int)$argv[1]);
}

$result = hybrid_replay_outbox($max);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(($result['ok'] ?? false) ? 0 : 1);
