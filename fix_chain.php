<?php
// Evidence-changing chain repair requires explicit CLI opt-in.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!in_array('--apply', $argv ?? [], true)) {
    fwrite(STDERR, "No changes made. Run php fix_chain.php --apply after verifying the existing chain.\n");
    exit(2);
}
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

$backup = $chainFile . '.pre-repair-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.bak';
if (!@copy($chainFile, $backup)) {
    fwrite(STDERR, "Original chain could not be backed up; aborting.\n");
    exit(1);
}
echo "Original chain preserved in backup.\n";
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
$written = file_put_contents(
    $chainFile,
    json_encode($chain, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    LOCK_EX
);
if ($written === false) {
    fwrite(STDERR, "Chain write failed; original preserved in backup.\n");
    exit(1);
}

echo "🎉 All hashes fixed and chain saved successfully!\n";
