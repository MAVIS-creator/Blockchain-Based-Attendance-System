<?php
// Diagnostic test
header('Content-Type: text/plain');

echo "=== PHP Diagnostics ===\n\n";

echo "PHP Version: " . phpversion() . "\n";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'N/A') . "\n";
echo "Root: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
echo "Script: " . __FILE__ . "\n\n";

echo "=== Environment Variables ===\n";
$env_vars = ['APP_ENV', 'APP_DEBUG', 'APP_URL', 'SMTP_HOST', 'BLOCKCHAIN_ENABLED'];
foreach ($env_vars as $var) {
    $val = getenv($var);
    echo "$var: " . ($val ? '✓ ' . substr($val, 0, 50) : 'MISSING') . "\n";
}

echo "\n=== File Checks ===\n";
$files = [
    '/home/site/wwwroot/.env' => '.env file',
    '/home/site/wwwroot/index.php' => 'index.php',
    '/home/site/wwwroot/bootstrap.php' => 'bootstrap.php',
    '/home/site/wwwroot/admin/index.php' => 'admin/index.php'
];

foreach ($files as $path => $desc) {
    echo "$desc: " . (file_exists($path) ? '✓ EXISTS' : '✗ MISSING') . "\n";
}

echo "\n=== Storage Directories ===\n";
$dirs = [
    '/home/site/wwwroot/storage',
    '/home/site/wwwroot/storage/logs',
    '/home/site/wwwroot/storage/sessions',
    '/home/data/attendance_sessions'
];

foreach ($dirs as $dir) {
    if (is_dir($dir)) {
        echo "$dir: ✓ WRITABLE " . (is_writable($dir) ? '✓' : '✗') . "\n";
    } else {
        echo "$dir: ✗ MISSING\n";
    }
}

echo "\n=== Done ===\n";
?>
