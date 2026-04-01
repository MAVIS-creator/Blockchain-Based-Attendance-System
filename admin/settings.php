<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
  header('Location: login.php');
  exit;
}

require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/runtime_storage.php';
app_storage_init();

$settingsFile = admin_storage_migrate_file('settings.json');
$keyFile = admin_storage_migrate_file('.settings_key');
$backupDir = app_storage_file('backups');
if (!is_dir($backupDir)) @mkdir($backupDir, 0700, true);

// helper: generate/return encryption key
function get_settings_key($keyFile)
{
  if (file_exists($keyFile)) return trim(file_get_contents($keyFile));
  $k = base64_encode(random_bytes(32));
  @file_put_contents($keyFile, $k);
  @chmod($keyFile, 0600);
  return $k;
}

// encryption helpers (AES-256-CBC)
function encrypt_payload($plaintext, $key)
{
  $keyRaw = base64_decode($key);
  $iv = random_bytes(16);
  $ct = openssl_encrypt($plaintext, 'AES-256-CBC', $keyRaw, OPENSSL_RAW_DATA, $iv);
  return 'ENC:' . base64_encode($iv . $ct);
}
function decrypt_payload($payload, $key)
{
  if (strpos($payload, 'ENC:') !== 0) return $payload;
  $blob = base64_decode(substr($payload, 4));
  $iv = substr($blob, 0, 16);
  $ct = substr($blob, 16);
  $keyRaw = base64_decode($key);
  return openssl_decrypt($ct, 'AES-256-CBC', $keyRaw, OPENSSL_RAW_DATA, $iv);
}

// load settings (with optional decryption)
function load_settings($settingsFile, $keyFile)
{
  if (!file_exists($settingsFile)) return null;
  $raw = file_get_contents($settingsFile);
  // try parse as JSON first
  $decoded = json_decode($raw, true);
  if (is_array($decoded)) return $decoded;
  // otherwise try decrypt
  $key = get_settings_key($keyFile);
  $plain = @decrypt_payload($raw, $key);
  $decoded = json_decode($plain, true);
  return is_array($decoded) ? $decoded : null;
}

// save settings with optional encryption flag
function save_settings($settingsFile, $keyFile, $settings, $encrypt = false)
{
  $payload = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  // create backup
  $backupDir = app_storage_file('backups');
  if (!is_dir($backupDir)) @mkdir($backupDir, 0700, true);
  $timestamp = date('Y-m-d_His');
  @file_put_contents($backupDir . "/settings_{$timestamp}.json", $payload);
  if ($encrypt) {
    $key = get_settings_key($keyFile);
    $payload = encrypt_payload($payload, $key);
  }
  file_put_contents($settingsFile, $payload, LOCK_EX);
}

// audit
function audit_settings_change($adminUser, $changes)
{
  $log = admin_storage_migrate_file('settings_audit.log');
  $entry = [
    'time' => date('c'),
    'user' => $adminUser,
    'changes' => $changes
  ];
  file_put_contents($log, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

// templates file
$templatesFile = admin_storage_migrate_file('settings_templates.json');
if (!file_exists($templatesFile)) file_put_contents($templatesFile, json_encode(new stdClass(), JSON_PRETTY_PRINT));

// Load .env (simple parser – avoids extra dependency)
function load_env_array($path)
{
  $env = [];
  if (file_exists($path)) {
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $l) {
      $t = trim($l);
      if ($t === '' || strpos($t, '#') === 0) continue;
      if (strpos($t, '=') === false) continue;
      list($k, $v) = explode('=', $t, 2);
      $env[trim($k)] = trim(trim($v), "\"'");
    }
  }
  return $env;
}

function load_env_lines($path)
{
  if (!file_exists($path)) return [];
  return file($path, FILE_IGNORE_NEW_LINES);
}

function update_env_values($path, $updates)
{
  $lines = load_env_lines($path);
  $seen = [];

  foreach ($lines as $i => $line) {
    $trim = trim($line);
    if ($trim === '' || strpos($trim, '#') === 0 || strpos($trim, '=') === false) continue;

    list($k,) = explode('=', $line, 2);
    $key = trim($k);
    if (array_key_exists($key, $updates)) {
      $val = str_replace(["\r", "\n"], '', (string)$updates[$key]);
      $lines[$i] = $key . '=' . $val;
      $seen[$key] = true;
    }
  }

  foreach ($updates as $k => $v) {
    if (!isset($seen[$k])) {
      $val = str_replace(["\r", "\n"], '', (string)$v);
      $lines[] = $k . '=' . $val;
    }
  }

  $payload = implode("\n", $lines);
  if ($payload !== '' && substr($payload, -1) !== "\n") $payload .= "\n";
  return file_put_contents($path, $payload, LOCK_EX) !== false;
}

function mask_secret_value($value)
{
  $value = (string)$value;
  $len = strlen($value);
  if ($len <= 6) return str_repeat('*', $len);
  return substr($value, 0, 3) . str_repeat('*', max(4, $len - 6)) . substr($value, -3);
}

function test_supabase_connection_env($env)
{
  $url = rtrim(trim($env['SUPABASE_URL'] ?? ''), '/');
  $key = trim($env['SUPABASE_SERVICE_ROLE_KEY'] ?? '');
  if ($url === '' || $key === '') {
    return ['ok' => false, 'message' => 'SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY are required.'];
  }

  $endpoint = $url . '/rest/v1/attendance_logs?select=id&limit=1';
  $ch = curl_init($endpoint);
  if ($ch === false) {
    return ['ok' => false, 'message' => 'Unable to initialize cURL.'];
  }

  curl_setopt_array($ch, [
    CURLOPT_HTTPGET => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_HTTPHEADER => [
      'apikey: ' . $key,
      'Authorization: Bearer ' . $key,
    ],
  ]);

  $resp = curl_exec($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($resp === false || $err) {
    return ['ok' => false, 'message' => 'Connection failed: ' . $err];
  }
  if ($http < 200 || $http >= 300) {
    return ['ok' => false, 'message' => 'Supabase responded with HTTP ' . $http . '.'];
  }

  return ['ok' => true, 'message' => 'Supabase connection successful (HTTP ' . $http . ').'];
}

function test_supabase_write_env($env)
{
  $url = rtrim(trim($env['SUPABASE_URL'] ?? ''), '/');
  $key = trim($env['SUPABASE_SERVICE_ROLE_KEY'] ?? '');
  if ($url === '' || $key === '') {
    return ['ok' => false, 'message' => 'SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY are required.'];
  }

  $probeMatric = 'SYS-' . date('YmdHis');
  $probeHash = 'probe-' . bin2hex(random_bytes(8));
  $payload = [[
    'timestamp' => date('c'),
    'name' => 'SYSTEM PROBE',
    'matric' => $probeMatric,
    'action' => 'checkin',
    'fingerprint' => 'probe',
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    'mac' => 'PROBE',
    'user_agent' => 'Admin Settings Probe',
    'course' => 'System',
    'reason' => 'probe',
    'chain_hash' => $probeHash,
  ]];

  $endpoint = $url . '/rest/v1/attendance_logs';
  $ch = curl_init($endpoint);
  if ($ch === false) {
    return ['ok' => false, 'message' => 'Unable to initialize cURL.'];
  }

  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_HTTPHEADER => [
      'apikey: ' . $key,
      'Authorization: Bearer ' . $key,
      'Content-Type: application/json',
      'Prefer: return=representation',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
  ]);

  $resp = curl_exec($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($resp === false || $err) {
    return ['ok' => false, 'message' => 'Write probe failed: ' . $err];
  }
  if ($http < 200 || $http >= 300) {
    return ['ok' => false, 'message' => 'Write probe failed. Supabase HTTP ' . $http . '.'];
  }

  $rows = json_decode((string)$resp, true);
  if (!is_array($rows) || empty($rows[0])) {
    return ['ok' => true, 'message' => 'Write probe sent (HTTP ' . $http . '), but response payload was empty.'];
  }

  $inserted = $rows[0];
  $id = $inserted['id'] ?? 'n/a';
  return ['ok' => true, 'message' => 'Supabase write probe succeeded. Inserted row id: ' . $id . ' (matric: ' . $probeMatric . ').'];
}

function test_smtp_connection_env($env, $recipient = '')
{
  $host = trim($env['SMTP_HOST'] ?? '');
  $user = trim($env['SMTP_USER'] ?? '');
  $pass = trim($env['SMTP_PASS'] ?? '');
  $port = intval($env['SMTP_PORT'] ?? 587);
  $secure = strtolower(trim($env['SMTP_SECURE'] ?? 'tls'));

  if ($host === '' || $user === '' || $pass === '' || $port <= 0) {
    return ['ok' => false, 'message' => 'SMTP_HOST, SMTP_PORT, SMTP_USER and SMTP_PASS are required.'];
  }

  $autoload = __DIR__ . '/../vendor/autoload.php';
  if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    if (!file_exists($autoload)) {
      return ['ok' => false, 'message' => 'Composer autoload file not found.'];
    }
    require_once $autoload;
  }

  try {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = $port;
    $mail->SMTPAuth = true;
    $mail->Username = $user;
    $mail->Password = $pass;
    $mail->Timeout = 8;
    $mail->SMTPAutoTLS = true;

    if ($secure === 'ssl') {
      $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } else {
      $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }

    if (!$mail->smtpConnect()) {
      return ['ok' => false, 'message' => 'SMTP connect failed: ' . ($mail->ErrorInfo ?: 'Unknown SMTP error')];
    }

    $to = trim((string)$recipient);
    if ($to === '') {
      $to = trim((string)($env['SMTP_USER'] ?? ''));
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
      $mail->smtpClose();
      return ['ok' => false, 'message' => 'SMTP test recipient is invalid.'];
    }

    $fromEmail = trim((string)($env['FROM_EMAIL'] ?? ''));
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
      $fromEmail = $user;
    }
    $fromName = trim((string)($env['FROM_NAME'] ?? 'Attendance System'));

    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($to);
    $mail->Subject = 'SMTP Test - Attendance System';
    $mail->Body = "SMTP test successful.\n\nTime: " . date('c') . "\nServer: " . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $mail->AltBody = $mail->Body;

    if (!$mail->send()) {
      $mail->smtpClose();
      return ['ok' => false, 'message' => 'SMTP connected but test mail failed: ' . ($mail->ErrorInfo ?: 'Unknown send error')];
    }

    $mail->smtpClose();
    return ['ok' => true, 'message' => 'SMTP connection successful and test email sent to ' . $to . '.'];
  } catch (\Throwable $e) {
    return ['ok' => false, 'message' => 'SMTP test failed: ' . $e->getMessage()];
  }
}
// Resolve env once for this page
$envPath = __DIR__ . '/../.env';
$ENV = load_env_array($envPath);

// default settings
if (!file_exists($settingsFile)) {
  $default = [
    'prefer_mac' => true,
    'max_admins' => 5,
    'require_fingerprint_match' => false,
    'checkin_time_start' => '',
    'checkin_time_end' => '',
    'enforce_one_device_per_day' => false,
    // new keys
    'ip_whitelist' => [],
    'encrypted_settings' => false,
    'device_cooldown_seconds' => 0,
    'geo_fence_enabled' => false,
    'geo_fence' => ['lat' => null, 'lng' => null, 'radius_m' => 0],
    'user_agent_lock' => false,
    'auto_backup' => true,
    'backup_retention' => 10,
    'encrypt_logs' => false,
    'blocked_tokens_retention_days' => 30,
    // SMTP & auto-send defaults
    'smtp' => [
      'host' => '',
      'port' => 587,
      'user' => '',
      'pass' => '',
      'secure' => 'tls',
      'from_email' => 'no-reply@example.com',
      'from_name' => 'Attendance System'
    ],
    'auto_send' => [
      'enabled' => false,
      'recipient' => '',
      'format' => 'csv'
    ]
  ];
  save_settings($settingsFile, $keyFile, $default, false);
}

$settings = load_settings($settingsFile, $keyFile) ?: ['prefer_mac' => true, 'max_admins' => 5];

// Ensure missing keys from older settings files are populated safely.
$settings = array_replace([
  'prefer_mac' => true,
  'max_admins' => 5,
  'require_fingerprint_match' => false,
  'checkin_time_start' => '',
  'checkin_time_end' => '',
  'enforce_one_device_per_day' => false,
  'ip_whitelist' => [],
  'encrypted_settings' => false,
  'device_cooldown_seconds' => 0,
  'geo_fence_enabled' => false,
  'geo_fence' => ['lat' => null, 'lng' => null, 'radius_m' => 0],
  'user_agent_lock' => false,
  'auto_backup' => true,
  'backup_retention' => 10,
  'encrypt_logs' => false,
  'blocked_tokens_retention_days' => 30,
  'smtp' => [
    'host' => '',
    'port' => 587,
    'user' => '',
    'pass' => '',
    'secure' => 'tls',
    'from_email' => 'no-reply@example.com',
    'from_name' => 'Attendance System'
  ],
  'auto_send' => [
    'enabled' => false,
    'recipient' => '',
    'format' => 'csv'
  ]
], is_array($settings) ? $settings : []);

// determine current user role
$currentRole = $_SESSION['admin_role'] ?? 'admin';

// Restrict entire settings page to superadmin only
if ($currentRole !== 'superadmin') {
  echo '<div style="padding:20px;"><h2>Access denied</h2><p>You do not have permission to view this page.</p></div>';
  return;
}

// CSRF helper
require_once __DIR__ . '/includes/csrf.php';
// ensure a token exists for pages that read it
csrf_token();

// handle POST actions: save settings, templates, apply template
$message = '';
$errors = [];
$envTestResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // validate CSRF centrally
  if (!csrf_check_request()) {
    $errors[] = 'Invalid CSRF token.';
  }

  // load accounts for re-auth
  $accountsFile = admin_storage_migrate_file('accounts.json');
  $accounts = @json_decode(file_get_contents($accountsFile), true) ?: [];
  $currentUser = $_SESSION['admin_user'] ?? '';

  // helper: require re-auth for critical changes
  $requireReauth = false;
  $reauthPassword = $_POST['reauth_password'] ?? '';
  $reauthOk = false;
  if ($reauthPassword !== '' && isset($accounts[$currentUser])) {
    $reauthOk = password_verify($reauthPassword, $accounts[$currentUser]['password']);
  }

  // template operations
  if (isset($_POST['save_template']) && trim($_POST['template_name'] ?? '') !== '') {
    $tplName = trim($_POST['template_name']);
    $templates = json_decode(file_get_contents($templatesFile), true) ?: [];
    $templates[$tplName] = $settings; // save current settings as template
    file_put_contents($templatesFile, json_encode($templates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $message = "Template saved: {$tplName}";
  }

  if (isset($_POST['apply_template']) && trim($_POST['apply_template_name'] ?? '') !== '') {
    $applyName = trim($_POST['apply_template_name']);
    $templates = json_decode(file_get_contents($templatesFile), true) ?: [];
    if (isset($templates[$applyName])) {
      $old = $settings;
      $settings = $templates[$applyName];
      save_settings($settingsFile, $keyFile, $settings, ($settings['encrypted_settings'] ?? false));
      audit_settings_change($currentUser, ['apply_template' => $applyName]);
      $message = "Template applied: {$applyName}";
    } else {
      $errors[] = 'Template not found.';
    }
  }

  // Save settings flow
  if (isset($_POST['save_settings'])) {
    $preferMac = isset($_POST['prefer_mac']) && $_POST['prefer_mac'] === '1';
    $maxAdmins = intval($_POST['max_admins'] ?? $settings['max_admins']);
    $requireFingerprint = isset($_POST['require_fingerprint_match']) && $_POST['require_fingerprint_match'] === '1';
    $checkinStart = trim($_POST['checkin_time_start'] ?? '');
    $checkinEnd = trim($_POST['checkin_time_end'] ?? '');
    $enforceOneDevice = isset($_POST['enforce_one_device_per_day']) && $_POST['enforce_one_device_per_day'] === '1';

    // new fields
    $ipWhitelistRaw = trim($_POST['ip_whitelist'] ?? '');
    $ipWhitelist = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $ipWhitelistRaw))));
    $encryptedSettings = isset($_POST['encrypted_settings']) && $_POST['encrypted_settings'] === '1';
    $deviceCooldown = intval($_POST['device_cooldown_seconds'] ?? 0);
    $geoFenceEnabled = isset($_POST['geo_fence_enabled']) && $_POST['geo_fence_enabled'] === '1';
    $geoLat = $_POST['geo_lat'] ?? null;
    $geoLng = $_POST['geo_lng'] ?? null;
    $geoRadius = intval($_POST['geo_radius_m'] ?? 0);
    $userAgentLock = isset($_POST['user_agent_lock']) && $_POST['user_agent_lock'] === '1';
    $autoBackup = isset($_POST['auto_backup']) && $_POST['auto_backup'] === '1';
    $backupRetention = intval($_POST['backup_retention'] ?? 10);
    $blockedTokensRetention = intval($_POST['blocked_tokens_retention_days'] ?? ($settings['blocked_tokens_retention_days'] ?? 30));

    if ($maxAdmins < 1 || $maxAdmins > 50) $errors[] = 'Max admins must be between 1 and 50.';
    // validate time format HH:MM optional
    if ($checkinStart !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $checkinStart)) $errors[] = 'Check-in start must be in HH:MM format.';
    if ($checkinEnd !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $checkinEnd)) $errors[] = 'Check-in end must be in HH:MM format.';
    if ($backupRetention < 1) $backupRetention = 1;
    if ($blockedTokensRetention < 1) $blockedTokensRetention = 1;
    if ($blockedTokensRetention > 3650) $blockedTokensRetention = 3650;

    if ($geoFenceEnabled) {
      if ($geoLat === null || $geoLat === '' || !is_numeric($geoLat)) $errors[] = 'Geo-fence latitude must be a valid number when geo-fencing is enabled.';
      if ($geoLng === null || $geoLng === '' || !is_numeric($geoLng)) $errors[] = 'Geo-fence longitude must be a valid number when geo-fencing is enabled.';
      if ($geoRadius <= 0) $errors[] = 'Geo-fence radius must be greater than 0 when geo-fencing is enabled.';
    }

    // if critical fields are changing and encryption toggle or max_admins changed, require reauth
    $critical = false;
    if (($settings['max_admins'] ?? 0) !== $maxAdmins) $critical = true;
    if (($settings['encrypted_settings'] ?? false) !== $encryptedSettings) $critical = true;
    if ($critical && !$reauthOk) $errors[] = 'Re-authentication required for critical changes. Please enter your password.';

    if (empty($errors)) {
      $old = $settings;
      $settings['prefer_mac'] = $preferMac;
      if ($currentRole === 'superadmin') $settings['max_admins'] = $maxAdmins;
      $settings['require_fingerprint_match'] = $requireFingerprint;
      $settings['checkin_time_start'] = $checkinStart;
      $settings['checkin_time_end'] = $checkinEnd;
      $settings['enforce_one_device_per_day'] = $enforceOneDevice;
      $settings['ip_whitelist'] = $ipWhitelist;
      $settings['encrypted_settings'] = $encryptedSettings;
      $settings['device_cooldown_seconds'] = $deviceCooldown;
      $settings['geo_fence_enabled'] = $geoFenceEnabled;
      $settings['geo_fence'] = ['lat' => $geoLat, 'lng' => $geoLng, 'radius_m' => $geoRadius];
      $settings['user_agent_lock'] = $userAgentLock;
      $settings['auto_backup'] = $autoBackup;
      $settings['backup_retention'] = $backupRetention;
      $settings['encrypt_logs'] = isset($_POST['encrypt_logs']) && $_POST['encrypt_logs'] === '1';
      $settings['blocked_tokens_retention_days'] = $blockedTokensRetention;

      // SMTP & auto-send
      // Policy: SMTP connection details come from .env; only 'from_name' and auto-send recipient/format are changeable here.
      $settings['smtp'] = $settings['smtp'] ?? [];
      $settings['smtp']['host'] = $ENV['SMTP_HOST'] ?? ($settings['smtp']['host'] ?? '');
      $settings['smtp']['port'] = isset($ENV['SMTP_PORT']) ? intval($ENV['SMTP_PORT']) : ($settings['smtp']['port'] ?? 587);
      $settings['smtp']['user'] = $ENV['SMTP_USER'] ?? ($settings['smtp']['user'] ?? '');
      $settings['smtp']['pass'] = $ENV['SMTP_PASS'] ?? ($settings['smtp']['pass'] ?? '');
      $settings['smtp']['secure'] = strtolower($ENV['SMTP_SECURE'] ?? ($settings['smtp']['secure'] ?? 'tls'));
      $settings['smtp']['from_email'] = $ENV['FROM_EMAIL'] ?? ($settings['smtp']['from_email'] ?? 'no-reply@example.com');
      // Editable field
      $settings['smtp']['from_name'] = trim($_POST['smtp_from_name'] ?? ($settings['smtp']['from_name'] ?? 'Attendance System'));

      $settings['auto_send'] = $settings['auto_send'] ?? [];
      $settings['auto_send']['enabled'] = isset($_POST['auto_send_enabled']) && $_POST['auto_send_enabled'] === '1';
      $settings['auto_send']['recipient'] = trim($_POST['auto_send_recipient'] ?? ($settings['auto_send']['recipient'] ?? ($ENV['AUTO_SEND_RECIPIENT'] ?? '')));
      $settings['auto_send']['format'] = trim($_POST['auto_send_format'] ?? $settings['auto_send']['format'] ?? 'csv');

      // save (respect encryption flag)
      save_settings($settingsFile, $keyFile, $settings, $settings['encrypted_settings'] ?? false);

      // retention cleanup
      if ($settings['auto_backup']) {
        $backups = glob(app_storage_file('backups') . '/settings_*.json');
        if (count($backups) > ($settings['backup_retention'] ?? 10)) {
          usort($backups, function ($a, $b) {
            return filemtime($a) - filemtime($b);
          });
          while (count($backups) > ($settings['backup_retention'] ?? 10)) {
            @unlink(array_shift($backups));
          }
        }
      }

      // audit diff
      $changes = [];
      foreach ($settings as $k => $v) {
        $oldV = $old[$k] ?? null;
        if ($oldV !== $v) $changes[$k] = ['old' => $oldV, 'new' => $v];
      }
      audit_settings_change($currentUser, $changes);

      $message = 'Settings saved.';
    }
  }

  if (isset($_POST['test_supabase_connection'])) {
    $envTestResult = test_supabase_connection_env($ENV);
    if ($envTestResult['ok']) $message = $envTestResult['message'];
    else $errors[] = $envTestResult['message'];
  }

  if (isset($_POST['test_supabase_write'])) {
    $envTestResult = test_supabase_write_env($ENV);
    if ($envTestResult['ok']) $message = $envTestResult['message'];
    else $errors[] = $envTestResult['message'];
  }

  if (isset($_POST['test_smtp_connection'])) {
    $smtpTestRecipient = trim($_POST['smtp_test_recipient'] ?? '');
    $envTestResult = test_smtp_connection_env($ENV, $smtpTestRecipient);
    if ($envTestResult['ok']) $message = $envTestResult['message'];
    else $errors[] = $envTestResult['message'];
  }

  // Save environment key/value flow
  if (isset($_POST['save_env_settings'])) {
    if (!$reauthOk) {
      $errors[] = 'Re-authentication required to rotate environment keys. Please enter your password.';
    } else {
      $hybridMode = trim($_POST['env_hybrid_mode'] ?? ($ENV['HYBRID_MODE'] ?? 'off'));
      if (!in_array($hybridMode, ['off', 'dual_write'], true)) {
        $hybridMode = 'off';
      }

      $hybridAdminRead = isset($_POST['env_hybrid_admin_read']) && $_POST['env_hybrid_admin_read'] === '1' ? 'true' : 'false';

      $updates = [
        'SUPABASE_URL' => trim($_POST['env_supabase_url'] ?? ($ENV['SUPABASE_URL'] ?? '')),
        'SUPABASE_SERVICE_ROLE_KEY' => trim($_POST['env_supabase_service_role_key'] ?? ($ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '')),
        'HYBRID_MODE' => $hybridMode,
        'HYBRID_ADMIN_READ' => $hybridAdminRead,
        'STORAGE_PATH' => trim($_POST['env_storage_path'] ?? ($ENV['STORAGE_PATH'] ?? '')),
        'POLYGON_PRIVATE_KEY' => trim($_POST['env_polygon_private_key'] ?? ($ENV['POLYGON_PRIVATE_KEY'] ?? '')),
        'SMTP_USER' => trim($_POST['env_smtp_user'] ?? ($ENV['SMTP_USER'] ?? '')),
        'SMTP_PASS' => trim($_POST['env_smtp_pass'] ?? ($ENV['SMTP_PASS'] ?? '')),
      ];

      if ($updates['SUPABASE_URL'] !== '' && !preg_match('#^https?://#i', $updates['SUPABASE_URL'])) {
        $errors[] = 'SUPABASE_URL must start with http:// or https://';
      }

      if (empty($errors)) {
        $saved = update_env_values($envPath, $updates);
        if ($saved) {
          $maskedAudit = [];
          foreach ($updates as $k => $v) {
            $oldV = $ENV[$k] ?? '';
            if ($oldV !== $v) {
              $isSensitive = in_array($k, ['SUPABASE_SERVICE_ROLE_KEY', 'POLYGON_PRIVATE_KEY', 'SMTP_PASS'], true);
              $maskedAudit[$k] = [
                'old' => $isSensitive ? mask_secret_value($oldV) : $oldV,
                'new' => $isSensitive ? mask_secret_value($v) : $v,
              ];
            }
          }
          audit_settings_change($currentUser, ['env_rotation' => $maskedAudit]);
          $ENV = load_env_array($envPath);
          $message = 'Environment settings updated successfully.';
        } else {
          $errors[] = 'Unable to write .env file.';
        }
      }
    }
  }
}

?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script>
  tailwind.config = {
    darkMode: "class",
    theme: {
      extend: {
        colors: {
          "surface": "#f6faff",
          "surface-container-lowest": "#ffffff",
          "surface-container-low": "#f0f4f9",
          "surface-container": "#eaeef3",
          "surface-container-high": "#e4e9ed",
          "surface-container-highest": "#dfe3e8",
          "on-surface": "#171c20",
          "on-surface-variant": "#424750",
          "outline-variant": "#c2c7d1",
          "primary": "#00457b",
          "primary-container": "#1f5d99",
          "secondary-fixed": "#cfe5ff",
          "error": "#ba1a1a",
          "error-container": "#ffdad6"
        },
        fontFamily: {
          body: ["Inter", "sans-serif"]
        }
      }
    }
  }
</script>

<div class="font-body text-on-surface bg-surface p-6 rounded-xl">
  <div class="mb-8">
    <h1 class="text-[1.75rem] font-bold tracking-tight">System Settings</h1>
    <p class="text-on-surface-variant mt-1">Exact Stitch screen structure mapped to live settings endpoints.</p>
  </div>

  <?php if ($message): ?>
    <div class="mb-4 rounded-lg px-4 py-3 bg-green-100 text-green-800 border border-green-200"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="mb-4 rounded-lg px-4 py-3 bg-error-container text-error border border-red-200">
      <ul class="list-disc list-inside"><?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?></ul>
    </div>
  <?php endif; ?>

  <div class="bg-surface-container-low p-1.5 rounded-xl flex gap-1 mb-8 overflow-x-auto whitespace-nowrap">
    <button type="button" class="st-tab px-6 py-2.5 rounded-lg text-sm font-semibold transition-all bg-white text-primary shadow-sm" onclick="openTab(event, 'tab-general')">General</button>
    <button type="button" class="st-tab px-6 py-2.5 rounded-lg text-sm font-medium transition-all text-on-surface-variant hover:bg-surface-container-high" onclick="openTab(event, 'tab-templates')">Templates</button>
    <button type="button" class="st-tab px-6 py-2.5 rounded-lg text-sm font-medium transition-all text-on-surface-variant hover:bg-surface-container-high" onclick="openTab(event, 'tab-advanced')">Advanced &amp; Security</button>
    <button type="button" class="st-tab px-6 py-2.5 rounded-lg text-sm font-medium transition-all text-on-surface-variant hover:bg-surface-container-high" onclick="openTab(event, 'tab-email')">Email &amp; Auto-send</button>
    <button type="button" class="st-tab px-6 py-2.5 rounded-lg text-sm font-medium transition-all text-on-surface-variant hover:bg-surface-container-high" onclick="openTab(event, 'tab-envkeys')">Env &amp; Key Rotation</button>
    <button type="button" class="st-tab px-6 py-2.5 rounded-lg text-sm font-medium transition-all text-on-surface-variant hover:bg-surface-container-high" onclick="openTab(event, 'tab-overview')">System Overview</button>
  </div>

  <div id="tab-general" class="st-tab-content grid grid-cols-1 lg:grid-cols-12 gap-8">
    <div class="lg:col-span-8 space-y-8">
      <form method="POST" class="bg-surface-container-lowest p-8 rounded-xl shadow-sm border border-outline-variant/20 space-y-6">
        <?php csrf_field(); ?>
        <div class="flex items-center gap-3 mb-2"><span class="material-symbols-outlined text-primary">verified_user</span>
          <h3 class="text-lg font-bold">Attendance Policy</h3>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Device Preference</label>
            <div class="flex gap-4 p-1 bg-surface-container-low rounded-lg mt-2">
              <label class="flex-1 flex items-center justify-center py-2 px-4 rounded-md cursor-pointer <?= ($settings['prefer_mac'] ?? true) ? 'bg-white text-primary shadow-sm' : 'text-on-surface-variant' ?>">
                <input class="hidden" type="radio" name="prefer_mac" value="1" <?= ($settings['prefer_mac'] ?? true) ? 'checked' : '' ?>><span class="text-sm font-semibold">Prefer MAC</span>
              </label>
              <label class="flex-1 flex items-center justify-center py-2 px-4 rounded-md cursor-pointer <?= !($settings['prefer_mac'] ?? true) ? 'bg-white text-primary shadow-sm' : 'text-on-surface-variant' ?>">
                <input class="hidden" type="radio" name="prefer_mac" value="0" <?= !($settings['prefer_mac'] ?? true) ? 'checked' : '' ?>><span class="text-sm font-semibold">Prefer IP</span>
              </label>
            </div>
          </div>
          <div>
            <label class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Max Admins</label>
            <input class="mt-2 w-full bg-surface-container-low border-none rounded-lg text-sm py-2.5" type="number" name="max_admins" min="1" max="50" value="<?= htmlspecialchars($settings['max_admins'] ?? 5) ?>" <?= ($currentRole !== 'superadmin') ? 'disabled' : '' ?>>
          </div>
        </div>

        <div class="space-y-4 pt-4 border-t border-outline-variant/20">
          <label class="flex items-center gap-3"><input class="w-5 h-5 rounded border-outline-variant text-primary" type="checkbox" name="require_fingerprint_match" value="1" <?= ($settings['require_fingerprint_match'] ?? false) ? 'checked' : '' ?>><span class="text-sm">Require biometric fingerprint verification</span></label>
          <label class="flex items-center gap-3"><input class="w-5 h-5 rounded border-outline-variant text-primary" type="checkbox" name="enforce_one_device_per_day" value="1" <?= ($settings['enforce_one_device_per_day'] ?? false) ? 'checked' : '' ?>><span class="text-sm">Enforce single device association per day</span></label>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div><label class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Check-in Start</label><input class="mt-2 w-full bg-surface-container-low border-none rounded-lg text-sm py-2.5" type="time" name="checkin_time_start" value="<?= htmlspecialchars($settings['checkin_time_start'] ?? '') ?>"></div>
          <div><label class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Check-in End</label><input class="mt-2 w-full bg-surface-container-low border-none rounded-lg text-sm py-2.5" type="time" name="checkin_time_end" value="<?= htmlspecialchars($settings['checkin_time_end'] ?? '') ?>"></div>
        </div>

        <div class="bg-surface-container-low p-6 rounded-xl border border-outline-variant/20">
          <div class="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-4 items-end">
            <div>
              <label class="text-sm font-semibold">Confirm critical changes</label>
              <input class="mt-2 w-full bg-white border-none rounded-lg text-sm py-2.5" type="password" name="reauth_password" placeholder="Confirm password">
            </div>
            <button class="bg-primary hover:bg-primary-container text-white px-8 py-2.5 rounded-lg font-bold text-sm" type="submit" name="save_settings">Save General Settings</button>
          </div>
        </div>
      </form>
    </div>
    <div class="lg:col-span-4 space-y-6">
      <div class="bg-white p-6 rounded-xl shadow-sm border border-outline-variant/20">
        <?php $accountsFile = admin_storage_migrate_file('accounts.json'); $acct = json_decode(file_get_contents($accountsFile), true) ?: []; ?>
        <p class="text-xs text-on-surface-variant">Admin Accounts</p>
        <p class="font-bold text-2xl"><?= count($acct) ?> / <?= htmlspecialchars($settings['max_admins'] ?? 5) ?></p>
      </div>
    </div>
  </div>

  <div id="tab-templates" class="st-tab-content hidden">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
      <section class="lg:col-span-4 bg-surface-container-lowest rounded-xl p-8 shadow-sm border border-outline-variant/20">
        <h3 class="text-lg font-semibold mb-4">Create Snapshot</h3>
        <form method="POST" class="space-y-4">
          <?php csrf_field(); ?>
          <input class="w-full bg-surface-container-low rounded-lg border border-outline-variant/20 px-4 py-3" type="text" name="template_name" placeholder="Template name">
          <button class="w-full bg-primary text-white font-semibold py-3 rounded-lg" type="submit" name="save_template">Save Template Snapshot</button>
        </form>
      </section>
      <section class="lg:col-span-8 bg-surface-container-lowest rounded-xl p-8 shadow-sm border border-outline-variant/20">
        <h3 class="text-lg font-semibold mb-4">Apply Template</h3>
        <form method="POST" class="flex gap-3 flex-wrap items-center">
          <?php csrf_field(); ?>
          <select class="bg-surface-container-low rounded-lg border border-outline-variant/20 px-4 py-3 min-w-[260px]" name="apply_template_name">
            <option value="">Select template...</option>
            <?php $tpls = json_decode(file_get_contents($templatesFile), true) ?: [];
            foreach ($tpls as $tn => $tv): ?>
              <option value="<?= htmlspecialchars($tn) ?>"><?= htmlspecialchars($tn) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="bg-primary hover:bg-primary-container text-white px-6 py-3 rounded-lg font-semibold" type="submit" name="apply_template">Apply Template</button>
        </form>
      </section>
    </div>
  </div>

  <div id="tab-advanced" class="st-tab-content hidden overflow-x-hidden">
    <form method="POST" class="grid grid-cols-1 xl:grid-cols-12 gap-6 max-w-full">
      <?php csrf_field(); ?>
      <section class="xl:col-span-8 min-w-0 bg-surface-container-lowest p-6 rounded-xl shadow-sm space-y-5 border border-outline-variant/20">
        <h3 class="text-lg font-semibold">Network &amp; Security</h3>
        <label class="flex items-center gap-3"><input class="w-5 h-5" type="checkbox" name="encrypted_settings" value="1" <?= ($settings['encrypted_settings'] ?? false) ? 'checked' : '' ?>> Encrypted settings</label>
        <label class="flex items-center gap-3"><input class="w-5 h-5" type="checkbox" name="encrypt_logs" value="1" <?= ($settings['encrypt_logs'] ?? false) ? 'checked' : '' ?>> Encrypt logs</label>
        <div>
          <label class="text-xs font-bold uppercase tracking-wider text-on-surface-variant">IP whitelist</label>
          <textarea class="mt-2 w-full bg-surface-container-lowest border border-outline-variant/20 rounded-lg p-3 text-sm" name="ip_whitelist" rows="4"><?= htmlspecialchars(implode("\n", $settings['ip_whitelist'] ?? [])) ?></textarea>
        </div>
      </section>

      <section class="xl:col-span-4 min-w-0 bg-surface-container-low p-6 rounded-xl space-y-4">
        <h3 class="text-lg font-semibold">Device &amp; Anti-spam</h3>
        <div>
          <label class="text-xs font-bold uppercase tracking-wider text-on-surface-variant">Device cooldown (seconds)</label>
          <input class="mt-2 w-full bg-surface-container-lowest border border-outline-variant/20 rounded-lg p-3 text-sm" type="number" name="device_cooldown_seconds" value="<?= htmlspecialchars($settings['device_cooldown_seconds'] ?? 0) ?>">
        </div>
        <label class="flex items-center gap-3"><input class="w-5 h-5" type="checkbox" name="user_agent_lock" value="1" <?= ($settings['user_agent_lock'] ?? false) ? 'checked' : '' ?>> Lock by user agent</label>
      </section>

      <section class="xl:col-span-7 min-w-0 bg-surface-container-lowest p-6 rounded-xl shadow-sm space-y-4 border border-outline-variant/20">
        <h3 class="text-lg font-semibold">Geo-fencing</h3>
        <label class="flex items-center gap-3"><input class="w-5 h-5" type="checkbox" name="geo_fence_enabled" value="1" <?= ($settings['geo_fence_enabled'] ?? false) ? 'checked' : '' ?>> Enable geo-fence</label>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <input class="bg-surface border border-outline-variant/20 rounded-lg p-3 text-sm" type="text" name="geo_lat" placeholder="Latitude" value="<?= htmlspecialchars($settings['geo_fence']['lat'] ?? '') ?>">
          <input class="bg-surface border border-outline-variant/20 rounded-lg p-3 text-sm" type="text" name="geo_lng" placeholder="Longitude" value="<?= htmlspecialchars($settings['geo_fence']['lng'] ?? '') ?>">
        </div>
        <div class="flex items-end gap-4">
          <div class="flex-1"><input class="w-full bg-surface border border-outline-variant/20 rounded-lg p-3 text-sm" type="number" name="geo_radius_m" placeholder="Radius (meters)" value="<?= htmlspecialchars($settings['geo_fence']['radius_m'] ?? 0) ?>"></div>
          <button id="geo_test_btn" type="button" class="bg-primary hover:bg-primary-container text-white h-[46px] px-6 rounded-lg font-semibold text-sm">Test Geofence</button>
        </div>
        <div id="geo_test_result" class="text-sm text-on-surface-variant"></div>
      </section>

      <section class="xl:col-span-5 min-w-0 bg-surface-container-low p-6 rounded-xl space-y-4">
        <h3 class="text-lg font-semibold">Backups &amp; Retention</h3>
        <label class="flex items-center gap-3"><input class="w-5 h-5" type="checkbox" name="auto_backup" value="1" <?= ($settings['auto_backup'] ?? true) ? 'checked' : '' ?>> Enable auto backup</label>
        <div><label class="text-xs font-bold uppercase tracking-wider text-on-surface-variant">Backup retention</label><input class="mt-2 w-full bg-surface-container-lowest border border-outline-variant/20 rounded-lg p-3 text-sm" type="number" name="backup_retention" value="<?= htmlspecialchars($settings['backup_retention'] ?? 10) ?>"></div>
        <div><label class="text-xs font-bold uppercase tracking-wider text-on-surface-variant">Blocked token retention (days)</label><input class="mt-2 w-full bg-surface-container-lowest border border-outline-variant/20 rounded-lg p-3 text-sm" type="number" min="1" max="3650" name="blocked_tokens_retention_days" value="<?= htmlspecialchars($settings['blocked_tokens_retention_days'] ?? 30) ?>"></div>
      </section>

      <div class="xl:col-span-12 flex justify-end gap-4 border-t border-outline-variant/20 pt-4">
        <button class="px-8 py-2.5 rounded-lg bg-gradient-to-br from-primary to-primary-container text-white font-semibold text-sm" type="submit" name="save_settings">Commit to Chain</button>
      </div>
    </form>
  </div>

  <div id="tab-email" class="st-tab-content hidden">
    <form method="POST" class="grid grid-cols-1 lg:grid-cols-12 gap-8">
      <?php csrf_field(); ?>
      <div class="lg:col-span-7 space-y-8">
        <section class="bg-surface-container-lowest rounded-xl p-8 shadow-sm">
          <h2 class="text-lg font-semibold mb-4">Email Identity</h2>
          <label class="block text-sm font-medium text-on-surface-variant mb-2">smtp_from_name</label>
          <input class="w-full px-4 py-3 rounded-lg bg-surface-container-low border-0" type="text" name="smtp_from_name" value="<?= htmlspecialchars($settings['smtp']['from_name'] ?? ($ENV['FROM_NAME'] ?? 'Attendance System')) ?>">
        </section>
        <section class="bg-surface-container-lowest rounded-xl p-8 shadow-sm space-y-5">
          <h2 class="text-lg font-semibold">Auto-send Configuration</h2>
          <label class="flex items-center justify-between p-4 bg-surface-container rounded-lg"><span class="font-medium">auto_send_enabled</span><input class="w-5 h-5" type="checkbox" name="auto_send_enabled" value="1" <?= ($settings['auto_send']['enabled'] ?? false) ? 'checked' : '' ?>></label>
          <div>
            <label class="block text-sm font-medium text-on-surface-variant mb-2">auto_send_recipient</label>
            <input class="w-full px-4 py-3 rounded-lg bg-surface-container-low border-0" type="email" name="auto_send_recipient" value="<?= htmlspecialchars($settings['auto_send']['recipient'] ?? ($ENV['AUTO_SEND_RECIPIENT'] ?? '')) ?>">
          </div>
          <div>
            <label class="block text-sm font-medium text-on-surface-variant mb-2">auto_send_format</label>
            <select class="w-full px-4 py-3 rounded-lg bg-surface-container-low border-0" name="auto_send_format">
              <option value="pdf" <?= ($settings['auto_send']['format'] ?? '') === 'pdf' ? 'selected' : '' ?>>PDF (Verified Ledger Layout)</option>
              <option value="csv" <?= ($settings['auto_send']['format'] ?? 'csv') === 'csv' ? 'selected' : '' ?>>CSV (Raw Immutable Data)</option>
            </select>
          </div>
        </section>
      </div>
      <div class="lg:col-span-5 space-y-8">
        <section class="bg-gradient-to-br from-primary to-primary-container text-white rounded-xl p-8 shadow-lg">
          <h3 class="text-xl font-bold mb-2">SMTP Environment</h3>
          <p class="text-sm opacity-90">SMTP transport credentials are managed strictly via <code>.env</code>.</p>
        </section>
        <div class="flex gap-4">
          <button class="flex-1 bg-gradient-to-br from-primary to-primary-container text-white font-semibold py-4 rounded-xl" type="submit" name="save_settings">Save Configuration</button>
        </div>
      </div>
    </form>
  </div>

  <div id="tab-envkeys" class="st-tab-content hidden">
    <form method="POST" class="space-y-6">
      <?php csrf_field(); ?>

      <section class="rounded-2xl p-6 md:p-8 border border-indigo-200 bg-gradient-to-br from-indigo-50 via-white to-cyan-50 shadow-sm">
        <div class="flex items-start justify-between gap-4 flex-wrap">
          <div>
            <h2 class="text-xl font-extrabold tracking-tight text-indigo-900">Environment &amp; Key Rotation</h2>
            <p class="text-sm text-indigo-700 mt-1">Rotate critical <code>.env</code> values safely from one panel. Changes apply on next request lifecycle.</p>
          </div>
          <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-800 border border-indigo-200">
            <span class="material-symbols-outlined" style="font-size:16px;">shield_lock</span>
            Superadmin only
          </span>
        </div>
      </section>

      <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <section class="lg:col-span-7 bg-white rounded-xl border border-outline-variant/30 p-6 shadow-sm space-y-5">
          <h3 class="text-base font-bold text-on-surface">Hybrid / Storage</h3>

          <?php if ($envTestResult !== null): ?>
            <div class="rounded-lg p-3 text-sm border <?= !empty($envTestResult['ok']) ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' ?>">
              <?= htmlspecialchars($envTestResult['message'] ?? '') ?>
            </div>
          <?php endif; ?>

          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant mb-2">SUPABASE_URL</label>
            <input class="w-full rounded-lg border border-outline-variant/40 bg-surface-container-low px-4 py-3 text-sm" type="text" name="env_supabase_url" value="<?= htmlspecialchars($ENV['SUPABASE_URL'] ?? '') ?>" placeholder="https://your-project.supabase.co">
          </div>

          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant mb-2">SUPABASE_SERVICE_ROLE_KEY</label>
            <input class="w-full rounded-lg border border-outline-variant/40 bg-surface-container-low px-4 py-3 text-sm" type="password" name="env_supabase_service_role_key" value="<?= htmlspecialchars($ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '') ?>" placeholder="service role key">
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant mb-2">HYBRID_MODE</label>
              <select class="w-full rounded-lg border border-outline-variant/40 bg-surface-container-low px-4 py-3 text-sm" name="env_hybrid_mode">
                <option value="off" <?= (($ENV['HYBRID_MODE'] ?? 'off') === 'off') ? 'selected' : '' ?>>off</option>
                <option value="dual_write" <?= (($ENV['HYBRID_MODE'] ?? '') === 'dual_write') ? 'selected' : '' ?>>dual_write</option>
              </select>
            </div>
            <div class="flex items-end">
              <label class="flex items-center gap-3 rounded-lg border border-outline-variant/40 bg-surface-container-low px-4 py-3 w-full">
                <input class="w-5 h-5" type="checkbox" name="env_hybrid_admin_read" value="1" <?= (strtolower($ENV['HYBRID_ADMIN_READ'] ?? 'true') === 'true') ? 'checked' : '' ?>>
                <span class="text-sm font-medium">HYBRID_ADMIN_READ</span>
              </label>
            </div>
          </div>

          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant mb-2">STORAGE_PATH</label>
            <input class="w-full rounded-lg border border-outline-variant/40 bg-surface-container-low px-4 py-3 text-sm" type="text" name="env_storage_path" value="<?= htmlspecialchars($ENV['STORAGE_PATH'] ?? '') ?>" placeholder="/home/data">
          </div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-3 pt-2">
            <button class="w-full rounded-lg border border-indigo-300 bg-indigo-50 hover:bg-indigo-100 text-indigo-800 font-semibold py-2.5" type="submit" name="test_supabase_connection" value="1">
              Test Supabase Connection
            </button>
            <button class="w-full rounded-lg border border-emerald-300 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 font-semibold py-2.5" type="submit" name="test_supabase_write" value="1">
              Test Supabase Write
            </button>
            <button class="w-full rounded-lg border border-sky-300 bg-sky-50 hover:bg-sky-100 text-sky-800 font-semibold py-2.5" type="submit" name="test_smtp_connection" value="1">
              Test SMTP
            </button>
          </div>

          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant mb-2">SMTP test recipient (optional)</label>
            <input class="w-full rounded-lg border border-outline-variant/40 bg-surface-container-low px-4 py-3 text-sm" type="email" name="smtp_test_recipient" placeholder="defaults to SMTP_USER if empty">
          </div>
        </section>

        <section class="lg:col-span-5 bg-white rounded-xl border border-outline-variant/30 p-6 shadow-sm space-y-5">
          <h3 class="text-base font-bold text-on-surface">Secrets Rotation</h3>

          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant mb-2">POLYGON_PRIVATE_KEY</label>
            <input class="w-full rounded-lg border border-outline-variant/40 bg-surface-container-low px-4 py-3 text-sm" type="password" name="env_polygon_private_key" value="<?= htmlspecialchars($ENV['POLYGON_PRIVATE_KEY'] ?? '') ?>">
          </div>

          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant mb-2">SMTP_USER</label>
            <input class="w-full rounded-lg border border-outline-variant/40 bg-surface-container-low px-4 py-3 text-sm" type="text" name="env_smtp_user" value="<?= htmlspecialchars($ENV['SMTP_USER'] ?? '') ?>">
          </div>

          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant mb-2">SMTP_PASS</label>
            <input class="w-full rounded-lg border border-outline-variant/40 bg-surface-container-low px-4 py-3 text-sm" type="password" name="env_smtp_pass" value="<?= htmlspecialchars($ENV['SMTP_PASS'] ?? '') ?>">
          </div>

          <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-800">
            <strong>Security note:</strong> Use this for key rotation/revocation workflows. Audit logs mask sensitive values.
          </div>

          <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant mb-2">Confirm Password</label>
            <input class="w-full rounded-lg border border-outline-variant/40 bg-surface-container-low px-4 py-3 text-sm" type="password" name="reauth_password" placeholder="Required for env changes">
          </div>

          <button class="w-full bg-gradient-to-r from-indigo-600 to-cyan-600 hover:from-indigo-700 hover:to-cyan-700 text-white font-bold py-3 rounded-lg" type="submit" name="save_env_settings">
            Rotate &amp; Save Environment
          </button>
        </section>
      </div>
    </form>
  </div>

  <div id="tab-overview" class="st-tab-content hidden">
    <?php
    $accountsFile = admin_storage_migrate_file('accounts.json');
    $acct = json_decode(file_get_contents($accountsFile), true) ?: [];
    $adminCount = count($acct);
    $statusFile = app_storage_migrate_file('status.json', __DIR__ . '/../status.json');
    $status = @json_decode(file_get_contents($statusFile), true) ?: [];
    $settingsAuditFile = admin_storage_migrate_file('settings_audit.log');
    $lastAudit = file_exists($settingsAuditFile) ? array_slice(array_map('trim', file($settingsAuditFile)), -10) : [];
    $backups = glob(app_storage_file('backups') . '/settings_*.json');
    usort($backups, function ($a, $b) {
      return filemtime($b) - filemtime($a);
    });
    ?>
    <div class="grid grid-cols-1 md:grid-cols-12 gap-8">
      <div class="md:col-span-8 bg-surface-container-low rounded-xl p-8">
        <h3 class="text-sm font-medium text-on-surface-variant uppercase mb-4">Terminal Heartbeat</h3>
        <p><strong>Check-in enabled:</strong> <?= (!empty($status['checkin']) && $status['checkin']) ? 'Yes' : 'No' ?></p>
        <p><strong>Check-out enabled:</strong> <?= (!empty($status['checkout']) && $status['checkout']) ? 'Yes' : 'No' ?></p>
        <p><strong>Active countdown ends at:</strong> <?= htmlspecialchars($status['end_time'] ?? 'N/A') ?></p>
      </div>
      <div class="md:col-span-4 bg-gradient-to-br from-primary to-primary-container rounded-xl p-8 text-white">
        <h3 class="text-sm uppercase opacity-80">Admin Accounts</h3>
        <div class="text-[2.2rem] font-extrabold"><?= $adminCount ?></div>
      </div>
      <div class="md:col-span-12 bg-surface-container-low rounded-xl p-8">
        <h3 class="text-lg font-bold mb-4">Recent Backups</h3>
        <ul class="list-disc list-inside text-sm mb-6">
          <?php foreach (array_slice($backups, 0, 5) as $b) echo '<li>' . htmlspecialchars(basename($b)) . ' - ' . date('c', filemtime($b)) . '</li>'; ?>
          <?php if (empty($backups)) echo '<li>No backups yet</li>'; ?>
        </ul>
        <h3 class="text-lg font-bold mb-3">Settings Audit Log</h3>
        <pre class="max-h-72 overflow-auto bg-surface-container-lowest p-4 rounded-lg border border-outline-variant/20 text-xs"><?php foreach ($lastAudit as $la) echo htmlspecialchars($la) . "\n"; ?></pre>
      </div>
    </div>
  </div>
</div>
<script>
  function openTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName('st-tab-content');
    for (i = 0; i < tabcontent.length; i++) {
      tabcontent[i].classList.remove('active');
      tabcontent[i].classList.add('hidden');
      tabcontent[i].style.display = 'none';
    }
    tablinks = document.getElementsByClassName('st-tab');
    for (i = 0; i < tablinks.length; i++) {
      tablinks[i].classList.remove('active');
      tablinks[i].classList.remove('bg-white', 'text-primary', 'shadow-sm', 'font-semibold');
      tablinks[i].classList.add('text-on-surface-variant', 'font-medium');
    }
    var activeTab = document.getElementById(tabName);
    if (activeTab) {
      activeTab.classList.add('active');
      activeTab.classList.remove('hidden');
      activeTab.style.display = activeTab.classList.contains('grid') ? 'grid' : 'block';
    }
    if (evt && evt.currentTarget) {
      evt.currentTarget.classList.add('active');
      evt.currentTarget.classList.add('bg-white', 'text-primary', 'shadow-sm', 'font-semibold');
      evt.currentTarget.classList.remove('text-on-surface-variant', 'font-medium');
    } else {
      var targetBtn = document.querySelector('button[onclick*="' + tabName + '"]');
      if (targetBtn) {
        targetBtn.classList.add('active');
        targetBtn.classList.add('bg-white', 'text-primary', 'shadow-sm', 'font-semibold');
        targetBtn.classList.remove('text-on-surface-variant', 'font-medium');
      }
    }
    history.replaceState(null, null, '#' + tabName);
  }

  document.addEventListener('DOMContentLoaded', function() {
    var hash = window.location.hash.substring(1);
    if (hash && document.getElementById(hash)) {
      openTab(null, hash);
    } else {
      openTab(null, 'tab-general');
    }
  });
</script>
<script>
  (function() {
    var btn = document.getElementById('geo_test_btn');
    var out = document.getElementById('geo_test_result');
    var testLatInput = document.getElementById('geo_test_lat');
    var testLngInput = document.getElementById('geo_test_lng');
    if (!btn || !out || !testLatInput || !testLngInput) return;

    function read(name) {
      var el = document.querySelector('[name="' + name + '"]');
      return el ? el.value : '';
    }

    if (!testLatInput.value) testLatInput.value = read('geo_lat');
    if (!testLngInput.value) testLngInput.value = read('geo_lng');

    btn.addEventListener('click', function() {
      var csrf = read('csrf_token');
      var body = new URLSearchParams();
      body.append('csrf_token', csrf || '');
      body.append('test_lat', testLatInput.value.trim());
      body.append('test_lng', testLngInput.value.trim());

      out.textContent = 'Running geofence test...';
      fetch('geofence_test.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
          },
          body: body.toString()
        })
        .then(function(r) {
          return r.json();
        })
        .then(function(data) {
          if (!data || !data.ok) {
            out.style.color = '#b91c1c';
            out.textContent = 'Test failed: ' + ((data && data.message) ? data.message : 'Unknown error');
            return;
          }

          if (data.enforced === false) {
            out.style.color = '#0369a1';
            out.textContent = 'Geo-fence is currently disabled, so attendance would not be blocked by location.';
            return;
          }

          if (data.inside) {
            out.style.color = '#166534';
            out.textContent = 'Inside geofence. Distance: ' + data.distance_m + 'm (radius: ' + data.radius_m + 'm).';
          } else {
            out.style.color = '#b91c1c';
            out.textContent = 'Outside geofence. Distance: ' + data.distance_m + 'm (radius: ' + data.radius_m + 'm).';
          }
        })
        .catch(function() {
          out.style.color = '#b91c1c';
          out.textContent = 'Request error while testing geofence.';
        });
    });
  })();
</script>
