<?php

require_once __DIR__ . '/hybrid_dual_write.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json');
}

$max = 200;
if (isset($argv[1]) && is_numeric($argv[1])) {
    $max = max(1, (int)$argv[1]);
} else if (isset($_GET['max']) && is_numeric($_GET['max'])) {
    $max = max(1, (int)$_GET['max']);
}

$result = hybrid_replay_outbox($max);

if (PHP_SAPI === 'cli') {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($result['ok'] ?? false) ? 0 : 1);
}

echo json_encode($result);
