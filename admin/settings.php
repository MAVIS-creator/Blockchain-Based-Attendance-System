<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
  header('Location: login.php');
  exit;
}

$settingsFile = __DIR__ . '/settings.json';
$keyFile = __DIR__ . '/.settings_key';
$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) @mkdir($backupDir, 0700, true);

// helper: generate/return encryption key
function get_settings_key($keyFile){
  if (file_exists($keyFile)) return trim(file_get_contents($keyFile));
  $k = base64_encode(random_bytes(32));
  @file_put_contents($keyFile, $k);
  @chmod($keyFile, 0600);
  return $k;
}

// encryption helpers (AES-256-CBC)
function encrypt_payload($plaintext, $key){
  $keyRaw = base64_decode($key);
  $iv = random_bytes(16);
  $ct = openssl_encrypt($plaintext, 'AES-256-CBC', $keyRaw, OPENSSL_RAW_DATA, $iv);
  return 'ENC:' . base64_encode($iv . $ct);
}
function decrypt_payload($payload, $key){
  if (strpos($payload, 'ENC:') !== 0) return $payload;
  $blob = base64_decode(substr($payload,4));
  $iv = substr($blob,0,16);
  $ct = substr($blob,16);
  $keyRaw = base64_decode($key);
  return openssl_decrypt($ct, 'AES-256-CBC', $keyRaw, OPENSSL_RAW_DATA, $iv);
}

// load settings (with optional decryption)
function load_settings($settingsFile, $keyFile){
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
function save_settings($settingsFile, $keyFile, $settings, $encrypt=false){
  $payload = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  // create backup
  $backupDir = dirname($settingsFile) . '/backups';
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
function audit_settings_change($adminUser, $changes){
  $log = __DIR__ . '/settings_audit.log';
  $entry = [
    'time' => date('c'),
    'user' => $adminUser,
    'changes' => $changes
  ];
  file_put_contents($log, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

// templates file
$templatesFile = __DIR__ . '/settings_templates.json';
if (!file_exists($templatesFile)) file_put_contents($templatesFile, json_encode(new stdClass(), JSON_PRETTY_PRINT));

// default settings
if (!file_exists($settingsFile)) {
  $default = [
    'prefer_mac' => true,
    'max_admins' => 5,
    'require_fingerprint_match' => false,
    'require_reason_keywords' => false,
    'reason_keywords' => '',
    'checkin_time_start' => '',
    'checkin_time_end' => '',
    'enforce_one_device_per_day' => false,
    // new keys
    'ip_whitelist' => [],
    'encrypted_settings' => false,
    'device_cooldown_seconds' => 0,
    'geo_fence' => ['lat'=>null,'lng'=>null,'radius_m'=>0],
    'user_agent_lock' => false,
    'auto_backup' => true,
    'backup_retention' => 10
  ];
  save_settings($settingsFile, $keyFile, $default, false);
}

$settings = load_settings($settingsFile, $keyFile) ?: ['prefer_mac'=>true,'max_admins'=>5];

// determine current user role
$currentRole = $_SESSION['admin_role'] ?? 'admin';

// Restrict entire settings page to superadmin only
if ($currentRole !== 'superadmin') {
  echo '<div style="padding:20px;"><h2>Access denied</h2><p>You do not have permission to view this page.</p></div>';
  return;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

// handle POST actions: save settings, templates, apply template
$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = 'Invalid CSRF token.';
    }

    // load accounts for re-auth
    $accounts = @json_decode(file_get_contents(__DIR__ . '/accounts.json'), true) ?: [];
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
      $requireReasonKeywords = isset($_POST['require_reason_keywords']) && $_POST['require_reason_keywords'] === '1';
      $reasonKeywords = trim($_POST['reason_keywords'] ?? '');
      $checkinStart = trim($_POST['checkin_time_start'] ?? '');
      $checkinEnd = trim($_POST['checkin_time_end'] ?? '');
      $enforceOneDevice = isset($_POST['enforce_one_device_per_day']) && $_POST['enforce_one_device_per_day'] === '1';

      // new fields
      $ipWhitelistRaw = trim($_POST['ip_whitelist'] ?? '');
      $ipWhitelist = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $ipWhitelistRaw))));
      $encryptedSettings = isset($_POST['encrypted_settings']) && $_POST['encrypted_settings'] === '1';
      $deviceCooldown = intval($_POST['device_cooldown_seconds'] ?? 0);
      $geoLat = $_POST['geo_lat'] ?? null;
      $geoLng = $_POST['geo_lng'] ?? null;
      $geoRadius = intval($_POST['geo_radius_m'] ?? 0);
      $userAgentLock = isset($_POST['user_agent_lock']) && $_POST['user_agent_lock'] === '1';
      $autoBackup = isset($_POST['auto_backup']) && $_POST['auto_backup'] === '1';
      $backupRetention = intval($_POST['backup_retention'] ?? 10);

      if ($maxAdmins < 1 || $maxAdmins > 50) $errors[] = 'Max admins must be between 1 and 50.';
      if ($requireReasonKeywords && $reasonKeywords === '') $errors[] = 'Provide at least one reason keyword when requiring reason keywords.';
      // validate time format HH:MM optional
      if ($checkinStart !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $checkinStart)) $errors[] = 'Check-in start must be in HH:MM format.';
      if ($checkinEnd !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $checkinEnd)) $errors[] = 'Check-in end must be in HH:MM format.';
      if ($backupRetention < 1) $backupRetention = 1;

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
        $settings['require_reason_keywords'] = $requireReasonKeywords;
        $settings['reason_keywords'] = $reasonKeywords;
        $settings['checkin_time_start'] = $checkinStart;
        $settings['checkin_time_end'] = $checkinEnd;
        $settings['enforce_one_device_per_day'] = $enforceOneDevice;
        $settings['ip_whitelist'] = $ipWhitelist;
        $settings['encrypted_settings'] = $encryptedSettings;
        $settings['device_cooldown_seconds'] = $deviceCooldown;
        $settings['geo_fence'] = ['lat'=>$geoLat,'lng'=>$geoLng,'radius_m'=>$geoRadius];
        $settings['user_agent_lock'] = $userAgentLock;
        $settings['auto_backup'] = $autoBackup;
        $settings['backup_retention'] = $backupRetention;

        // save (respect encryption flag)
        save_settings($settingsFile, $keyFile, $settings, $settings['encrypted_settings'] ?? false);

        // retention cleanup
        if ($settings['auto_backup']) {
          $backups = glob(__DIR__ . '/backups/settings_*.json');
          if (count($backups) > ($settings['backup_retention'] ?? 10)) {
            usort($backups, function($a,$b){ return filemtime($a) - filemtime($b); });
            while (count($backups) > ($settings['backup_retention'] ?? 10)) { @unlink(array_shift($backups)); }
          }
        }

        // audit diff
        $changes = [];
        foreach ($settings as $k => $v) {
          $oldV = $old[$k] ?? null;
          if ($oldV !== $v) $changes[$k] = ['old'=>$oldV,'new'=>$v];
        }
        audit_settings_change($currentUser, $changes);

        $message = 'Settings saved.';
      }
    }
}

?>

<div style="padding:20px;">
  <h2>Settings</h2>
  <?php if ($message): ?><div style="background:#dff0d8;padding:10px;border-radius:6px;margin-bottom:12px;color:#2d6a2d;"><?=htmlspecialchars($message)?></div><?php endif; ?>
  <?php if ($errors): ?><div style="background:#ffe6e6;padding:10px;border-radius:6px;margin-bottom:12px;color:#8a1f1f;"><ul><?php foreach($errors as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?></ul></div><?php endif; ?>

  <form method="POST" style="max-width:700px;">
    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
    <div style="margin-bottom:12px;">
      <label style="display:block;font-weight:600;margin-bottom:6px;">Device matching preference</label>
      <label style="display:block;margin-bottom:6px;"><input type="radio" name="prefer_mac" value="1" <?=($settings['prefer_mac'] ?? true) ? 'checked' : ''?>> Prefer MAC (if available)</label>
      <label style="display:block;margin-bottom:6px;"><input type="radio" name="prefer_mac" value="0" <?=!($settings['prefer_mac'] ?? true) ? 'checked' : ''?>> Prefer IP</label>
    </div>

    <div style="margin-bottom:12px;">
      <label style="display:block;font-weight:600;margin-bottom:6px;">Max admins</label>
      <input type="number" name="max_admins" value="<?=htmlspecialchars($settings['max_admins'] ?? 5)?>" min="1" max="50" style="padding:8px;width:120px;" <?=($currentRole !== 'superadmin') ? 'disabled' : ''?>>
      <p style="color:#6b7280;font-size:0.9rem;margin-top:6px;">Limit how many admin accounts can be created. (Only super-admin can change this)</p>
    </div>

    <fieldset style="margin-bottom:12px;padding:12px;border:1px solid #e5e7eb;border-radius:6px;">
      <legend style="font-weight:600;padding:0 6px;">Attendance enforcement</legend>
      <label style="display:block;margin-bottom:8px;"><input type="checkbox" name="require_fingerprint_match" value="1" <?=($settings['require_fingerprint_match'] ?? false) ? 'checked' : ''?>> Require fingerprint match for a valid attendance</label>
      <label style="display:block;margin-bottom:8px;"><input type="checkbox" name="require_reason_keywords" value="1" <?=($settings['require_reason_keywords'] ?? false) ? 'checked' : ''?>> Require reason to contain specific keywords</label>
      <div style="margin-bottom:8px;"><input type="text" name="reason_keywords" placeholder="comma separated keywords" value="<?=htmlspecialchars($settings['reason_keywords'] ?? '')?>" style="padding:8px;width:100%;"></div>
      <label style="display:block;margin-bottom:8px;">Check-in window (optional)</label>
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;"><input type="time" name="checkin_time_start" value="<?=htmlspecialchars($settings['checkin_time_start'] ?? '')?>"> <span style="color:#6b7280">to</span> <input type="time" name="checkin_time_end" value="<?=htmlspecialchars($settings['checkin_time_end'] ?? '')?>"></div>
      <label style="display:block;margin-bottom:8px;"><input type="checkbox" name="enforce_one_device_per_day" value="1" <?=($settings['enforce_one_device_per_day'] ?? false) ? 'checked' : ''?>> Enforce one device per student per day</label>
    </fieldset>

    <div><button type="submit" style="padding:8px 12px;background:#3b82f6;color:#fff;border:none;border-radius:6px;">Save Settings</button></div>
  </form>
</div>
