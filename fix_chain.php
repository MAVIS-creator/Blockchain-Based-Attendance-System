<?php
// ✅ Set timezone
date_default_timezone_set('Africa/Lagos');

// Path to chain file
$chainFile = __DIR__ . '/secure_logs/attendance_chain.json';

if (!file_exists($chainFile)) {
    die("❌ Chain file not found.\n");
}

$chain = json_decode(file_get_contents($chainFile), true);

if (!is_array($chain) || empty($chain)) {
    die("❌ Chain file is empty or invalid.\n");
}

echo "🔧 Fixing chain hashes...\n";

// Initialize
$prevHash = null;

foreach ($chain as $i => &$block) {
    // Remove old hash
    unset($block['hash']);

    // 💥 Sort keys globally
    ksort($block);

    // Compute new hash
    $block['hash'] = hash('sha256', json_encode($block, JSON_UNESCAPED_SLASHES) . $prevHash);

    // Save this hash as prevHash for next block
    $prevHash = $block['hash'];

    echo "✅ Block #$i fixed (new hash: {$block['hash']})\n";
}

// Save updated chain
file_put_contents($chainFile, json_encode($chain, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);

echo "🎉 All hashes fixed and chain saved successfully!\n";
?>
