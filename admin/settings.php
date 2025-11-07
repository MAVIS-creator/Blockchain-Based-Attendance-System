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
    'backup_retention' => 10,
    'encrypt_logs' => false
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

// CSRF helper
require_once __DIR__ . '/includes/csrf.php';
// ensure a token exists for pages that read it
csrf_token();

// handle POST actions: save settings, templates, apply template
$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // validate CSRF centrally
  if (!csrf_check_request()) {
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
  $settings['encrypt_logs'] = isset($_POST['encrypt_logs']) && $_POST['encrypt_logs'] === '1';

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

  <form method="POST" style="max-width:900px;">
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

    <div style="display:flex;gap:12px;align-items:center;margin-top:12px;">
      <div style="flex:1">
        <button type="submit" name="save_settings" style="padding:8px 12px;background:#3b82f6;color:#fff;border:none;border-radius:6px;">Save Settings</button>
      </div>
      <div style="flex:1">
        <label style="font-weight:600;display:block;margin-bottom:6px;">Re-authenticate (enter password for critical changes)</label>
        <input type="password" name="reauth_password" placeholder="Your password" style="padding:8px;width:100%;">
      </div>
    </div>
  </form>

  <hr style="margin:18px 0;">

  <h3>Templates</h3>
  <form method="POST" style="display:flex;gap:8px;align-items:center;max-width:700px;">
    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
    <input type="text" name="template_name" placeholder="Template name" style="padding:8px;">
    <button type="submit" name="save_template" style="padding:8px;background:#10b981;color:#fff;border:none;border-radius:6px;">Save Template</button>
    <select name="apply_template_name" style="padding:8px;">
      <option value="">-- Apply template --</option>
      <?php $tpls = json_decode(file_get_contents($templatesFile), true) ?: []; foreach ($tpls as $tn => $tv): ?>
        <option value="<?=htmlspecialchars($tn)?>"><?=htmlspecialchars($tn)?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" name="apply_template" style="padding:8px;background:#3b82f6;color:#fff;border:none;border-radius:6px;">Apply</button>
  </form>

  <hr style="margin:18px 0;">

  <h3>Advanced Controls & Utilities</h3>
  <form method="POST" style="max-width:900px;display:flex;flex-direction:column;gap:12px;">
    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
    <fieldset style="padding:12px;border:1px solid #e5e7eb;border-radius:6px;">
      <legend style="font-weight:600;padding:0 6px;">Network & Security</legend>
      <label style="display:block;margin-bottom:8px;"><input type="checkbox" name="encrypted_settings" value="1" <?=($settings['encrypted_settings'] ?? false) ? 'checked' : ''?>> Store settings encrypted on disk</label>
      <label style="display:block;margin-bottom:8px;"><input type="checkbox" name="encrypt_logs" value="1" <?=($settings['encrypt_logs'] ?? false) ? 'checked' : ''?>> Encrypt per-day enforcement/log stores</label>
      <label style="display:block;margin-bottom:8px;">IP whitelist (one per line or comma separated)</label>
      <textarea name="ip_whitelist" style="width:100%;padding:8px;min-height:80px;"><?=htmlspecialchars(implode("\n", $settings['ip_whitelist'] ?? []))?></textarea>
    </fieldset>

    <fieldset style="padding:12px;border:1px solid #e5e7eb;border-radius:6px;">
      <legend style="font-weight:600;padding:0 6px;">Device & Anti-spam</legend>
      <label style="display:block;margin-bottom:8px;">Device cooldown (seconds): <input type="number" name="device_cooldown_seconds" value="<?=htmlspecialchars($settings['device_cooldown_seconds'] ?? 0)?>" style="width:120px;margin-left:8px;padding:6px;"></label>
      <label style="display:block;margin-bottom:8px;"><input type="checkbox" name="user_agent_lock" value="1" <?=($settings['user_agent_lock'] ?? false) ? 'checked' : ''?>> Lock attendance to the first user-agent seen (detect device switching)</label>
    </fieldset>

    <fieldset style="padding:12px;border:1px solid #e5e7eb;border-radius:6px;">
      <legend style="font-weight:600;padding:0 6px;">Geo-fencing</legend>
      <div style="display:flex;gap:8px;align-items:center;"><input type="text" name="geo_lat" placeholder="Latitude" value="<?=htmlspecialchars($settings['geo_fence']['lat'] ?? '')?>" style="padding:8px;width:160px;"><input type="text" name="geo_lng" placeholder="Longitude" value="<?=htmlspecialchars($settings['geo_fence']['lng'] ?? '')?>" style="padding:8px;width:160px;"><input type="number" name="geo_radius_m" placeholder="Radius (m)" value="<?=htmlspecialchars($settings['geo_fence']['radius_m'] ?? 0)?>" style="padding:8px;width:120px;"></div>
    </fieldset>

    <fieldset style="padding:12px;border:1px solid #e5e7eb;border-radius:6px;">
      <legend style="font-weight:600;padding:0 6px;">Backups & retention</legend>
      <label style="display:block;margin-bottom:8px;"><input type="checkbox" name="auto_backup" value="1" <?=($settings['auto_backup'] ?? true) ? 'checked' : ''?>> Keep automatic backups on each save</label>
      <label style="display:block;margin-bottom:8px;">Backup retention (number of backups to keep) <input type="number" name="backup_retention" value="<?=htmlspecialchars($settings['backup_retention'] ?? 10)?>" style="width:120px;margin-left:8px;padding:6px;"></label>
    </fieldset>

    <div style="display:flex;gap:12px;align-items:center;">
      <button type="submit" name="save_settings" style="padding:8px;background:#3b82f6;color:#fff;border:none;border-radius:6px;">Save Advanced</button>
      <div style="flex:1;color:#6b7280;font-size:0.95rem;">Use these controls to protect and manage system-wide behavior. Note: enabling encryption will store settings encrypted on disk (a key file will be created).</div>
    </div>
  </form>

  <hr style="margin:18px 0;">

  <h3>System overview</h3>
  <div style="max-width:900px;background:#fff;padding:12px;border-radius:8px;">
    <?php
      $acct = json_decode(file_get_contents(__DIR__ . '/accounts.json'), true) ?: [];
      $adminCount = count($acct);
      $status = @json_decode(file_get_contents(__DIR__ . '/../status.json'), true) ?: [];
      $lastAudit = file_exists(__DIR__ . '/settings_audit.log') ? array_slice(array_map('trim', file(__DIR__ . '/settings_audit.log')), -10) : [];
      $backups = glob(__DIR__ . '/backups/settings_*.json');
      usort($backups, function($a,$b){ return filemtime($b) - filemtime($a); });
    ?>
    <p><strong>Admins:</strong> <?= $adminCount ?></p>
    <p><strong>Check-in enabled:</strong> <?= (!empty($status['checkin']) && $status['checkin']) ? 'Yes' : 'No' ?> &nbsp; <strong>Check-out enabled:</strong> <?= (!empty($status['checkout']) && $status['checkout']) ? 'Yes' : 'No' ?></p>
    <p><strong>Active countdown ends at:</strong> <?= htmlspecialchars($status['end_time'] ?? 'N/A') ?></p>
    <p><strong>Last backups:</strong></p>
    <ul><?php foreach(array_slice($backups,0,5) as $b){ echo '<li>'.basename($b).' - '.date('c', filemtime($b)).'</li>'; } if(empty($backups)) echo '<li>No backups yet</li>'; ?></ul>
    <p><strong>Recent settings audit entries:</strong></p>
    <pre style="max-height:220px;overflow:auto;background:#f3f4f6;padding:8px;"><?php foreach($lastAudit as $la){ echo htmlspecialchars($la)."\n"; } ?></pre>
  </div>
</div>
