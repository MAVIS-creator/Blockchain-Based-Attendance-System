<?php
// auto_send_logs.php
// Helper script to be scheduled (cron / Task Scheduler) to send logs automatically.

require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/runtime_storage.php';
app_storage_init();
if (session_status() === PHP_SESSION_NONE) session_start();

// CLI options:
//   php auto_send_logs.php [YYYY-MM-DD] [--force] [--dry-run] [--recipient=email] [--format=csv|pdf]
$argList = isset($argv) && is_array($argv) ? array_slice($argv, 1) : [];
$date = date('Y-m-d');
$forceRun = false;
$dryRun = false;
$recipientOverride = '';
$formatOverride = '';

foreach ($argList as $arg) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg)) {
        $date = $arg;
        continue;
    }
    if ($arg === '--force') {
        $forceRun = true;
        continue;
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (strpos($arg, '--recipient=') === 0) {
        $recipientOverride = trim(substr($arg, strlen('--recipient=')));
        continue;
    }
    if (strpos($arg, '--format=') === 0) {
        $formatOverride = strtolower(trim(substr($arg, strlen('--format='))));
        continue;
    }
}

$settingsFile = admin_storage_migrate_file('settings.json');
$keyFile = admin_storage_migrate_file('.settings_key');

function load_settings_file($settingsFile, $keyFile)
{
    if (!file_exists($settingsFile)) return null;
    $raw = file_get_contents($settingsFile);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) return $decoded;
    // try decrypt
    if (!file_exists($keyFile)) return null;
    $key = trim(file_get_contents($keyFile));
    $blob = base64_decode(substr($raw, 4));
    $iv = substr($blob, 0, 16);
    $ct = substr($blob, 16);
    $keyRaw = base64_decode($key);
    $plain = openssl_decrypt($ct, 'AES-256-CBC', $keyRaw, OPENSSL_RAW_DATA, $iv);
    $decoded = json_decode($plain, true);
    return is_array($decoded) ? $decoded : null;
}

$settings = load_settings_file($settingsFile, $keyFile) ?: [];
// require auto_send enabled
if (empty($settings['auto_send']['enabled']) && !$forceRun) exit("Auto-send not enabled in settings (use --force for explicit test runs)\n");
$recipient = $recipientOverride !== '' ? $recipientOverride : ($settings['auto_send']['recipient'] ?? '');
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) exit("No valid recipient configured\n");

$format = $formatOverride !== '' ? $formatOverride : ($settings['auto_send']['format'] ?? 'csv');
if (!in_array($format, ['csv', 'pdf'], true)) {
    $format = 'csv';
}

// Build the groups that should exist for today
// We'll need to scan all courses from logs and select groups for this date
$logsDir = app_storage_file('logs');
$selectedGroups = [];

if (is_dir($logsDir)) {
    $it = new DirectoryIterator($logsDir);
    foreach ($it as $f) {
        if ($f->isFile()) {
            $fn = $f->getFilename();
            if (preg_match('/\.(php|css)$/i', $fn)) continue;
            // Check if this file might contain today's logs
            if (strpos($fn, $date) !== false || preg_match('/(20\d{2}-\d{2}-\d{2})/', $fn, $m) && $m[1] === $date) {
                // Parse the file to find courses
                $lines = @file($logsDir . '/' . $fn, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $courses = [];
                foreach ($lines as $ln) {
                    $parts = array_map('trim', explode('|', $ln));
                    if (isset($parts[8]) && $parts[8] !== '') $courses[$parts[8]] = true;
                }
                // Add group keys for this date + each course
                foreach (array_keys($courses) as $course) {
                    $selectedGroups[] = $date . '|' . $course;
                }
            }
        }
    }
}

// Remove duplicates
$selectedGroups = array_unique($selectedGroups);

if (empty($selectedGroups)) {
    exit("No log groups found for date {$date}\n");
}

if ($dryRun) {
    echo "Auto-send DRY RUN for date {$date}\n";
    echo "Recipient: {$recipient}\n";
    echo "Format: {$format}\n";
    echo "Selected groups: " . implode(', ', $selectedGroups) . "\n";
    exit(0);
}

// Simulate authenticated admin POST with valid CSRF
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_user'] = $_SESSION['admin_user'] ?? 'system_auto_send';
$_SESSION['admin_role'] = $_SESSION['admin_role'] ?? 'superadmin';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once __DIR__ . '/includes/csrf.php';
$csrfToken = csrf_token();

// Simulate POST request to send_logs_email.php
$_POST = [
    'send_logs' => '1',
    'csrf_token' => $csrfToken,
    'recipient' => $recipient,
    'format' => $format,
    'selected_groups' => $selectedGroups,
    'cols' => ['name', 'matric', 'action', 'datetime', 'course']
];

// Capture output from send_logs_email.php
ob_start();
require __DIR__ . '/send_logs_email.php';
$output = ob_get_clean();

$sendSuccess = isset($success) && is_string($success) && trim($success) !== '';
$sendError = isset($error) && is_string($error) ? trim($error) : '';

echo "Auto-send attempted for date {$date}\n";
echo "Selected groups: " . implode(', ', $selectedGroups) . "\n";
if ($sendSuccess) {
    echo "Result: SUCCESS\n";
    echo "Message: {$success}\n";
} else {
    echo "Result: FAILED\n";
    echo "Message: " . ($sendError !== '' ? $sendError : 'Unknown error (inspect rendered output).') . "\n";
}

if (!$sendSuccess) {
    $snippet = trim(strip_tags((string)$output));
    if ($snippet !== '') {
        echo "Rendered output snippet:\n" . mb_substr($snippet, 0, 1200) . "\n";
    }
    exit(1);
}

// Note: schedule this script to run after your class ends. On Windows, use Task Scheduler; on Linux use cron.
// Example cron (run at 23:05 daily): 5 23 * * * /usr/bin/php /path/to/admin/auto_send_logs.php
// Example Windows Task Scheduler: C:\path\to\php.exe C:\path\to\admin\auto_send_logs.php
