<?php
$chainFile = __DIR__ . '/secure_logs/attendance_chain.json';

if (!file_exists($chainFile)) {
    die('❌ Chain file not found.');
}

$chain = json_decode(file_get_contents($chainFile), true);
if (!is_array($chain) || count($chain) === 0) {
    die('❌ Chain is empty or invalid.');
}

$valid = true;
$prevHash = null;
foreach ($chain as $i => $block) {
    $blockDataForHash = $block;
    unset($blockDataForHash['hash']);
    $expectedHash = hash('sha256', json_encode($blockDataForHash) . $prevHash);
    if ($block['hash'] !== $expectedHash) {
        echo "❌ Tampering detected at block #$i (hash mismatch)\n";
        $valid = false;
        break;
    }
    if ($i > 0 && $block['prevHash'] !== $prevHash) {
        echo "❌ Tampering detected at block #$i (prevHash mismatch)\n";
        $valid = false;
        break;
    }
    $prevHash = $block['hash'];
}

if ($valid) {
    echo "✅ Chain is valid. All blocks are intact (" . count($chain) . " blocks checked).\n";
} 