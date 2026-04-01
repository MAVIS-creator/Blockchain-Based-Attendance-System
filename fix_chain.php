<?php
// ✅ Set timezone
date_default_timezone_set('Africa/Lagos');

// Path to chain file
require_once __DIR__ . '/storage_helpers.php';
app_storage_init();
$chainFile = app_storage_migrate_file('secure_logs/attendance_chain.json', __DIR__ . '/secure_logs/attendance_chain.json');

if (!file_exists($chainFile)) {
    die("❌ Chain file not found.\n");
}

$chain = json_decode(file_get_contents($chainFile), true);

if (!is_array($chain) || empty($chain)) {
    die("❌ Chain file is empty or invalid.\n");
}

echo "🔧 Fixing chain hashes...\n";

// Initialize previous hash
$prevHash = null;

foreach ($chain as $i => &$block) {
    // Update prevHash field for current block
    $block['prevHash'] = $prevHash;

    // Prepare block data for hashing
    $blockDataForHash = $block;
    unset($blockDataForHash['hash']);
    ksort($blockDataForHash);

    // Generate hash exactly like submit.php
    $block['hash'] = hash(
        'sha256',
        json_encode($blockDataForHash, JSON_UNESCAPED_SLASHES) . $prevHash
    );

    // Set prevHash for the next block
    $prevHash = $block['hash'];

    echo "✅ Block #$i fixed (new hash: {$block['hash']})\n";
}

// Save updated chain
file_put_contents(
    $chainFile,
    json_encode($chain, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    LOCK_EX
);

echo "🎉 All hashes fixed and chain saved successfully!\n";
