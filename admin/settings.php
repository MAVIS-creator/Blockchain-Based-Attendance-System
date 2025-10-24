<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
  header('Location: login.php');
  exit;
}

$settingsFile = __DIR__ . '/settings.json';
if (!file_exists($settingsFile)) file_put_contents($settingsFile, json_encode([
    'prefer_mac' => true,
    'max_admins' => 5,
    'require_fingerprint_match' => false,
    'require_reason_keywords' => false,
    'reason_keywords' => '',
    'checkin_time_start' => '',
    'checkin_time_end' => '',
    'enforce_one_device_per_day' => false
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$settings = json_decode(file_get_contents($settingsFile), true) ?: ['prefer_mac'=>true,'max_admins'=>5];

// determine current user role
$currentRole = $_SESSION['admin_role'] ?? 'admin';

// CSRF token
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = 'Invalid CSRF token.';
    }

  $preferMac = isset($_POST['prefer_mac']) && $_POST['prefer_mac'] === '1';
  $maxAdmins = intval($_POST['max_admins'] ?? $settings['max_admins']);
  $requireFingerprint = isset($_POST['require_fingerprint_match']) && $_POST['require_fingerprint_match'] === '1';
  $requireReasonKeywords = isset($_POST['require_reason_keywords']) && $_POST['require_reason_keywords'] === '1';
  $reasonKeywords = trim($_POST['reason_keywords'] ?? '');
  $checkinStart = trim($_POST['checkin_time_start'] ?? '');
  $checkinEnd = trim($_POST['checkin_time_end'] ?? '');
  $enforceOneDevice = isset($_POST['enforce_one_device_per_day']) && $_POST['enforce_one_device_per_day'] === '1';

  if ($maxAdmins < 1 || $maxAdmins > 50) $errors[] = 'Max admins must be between 1 and 50.';
  if ($requireReasonKeywords && $reasonKeywords === '') $errors[] = 'Provide at least one reason keyword when requiring reason keywords.';
  // validate time format HH:MM optional
  if ($checkinStart !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $checkinStart)) $errors[] = 'Check-in start must be in HH:MM format.';
  if ($checkinEnd !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $checkinEnd)) $errors[] = 'Check-in end must be in HH:MM format.';

  if (empty($errors)) {
    $settings['prefer_mac'] = $preferMac;
    // only superadmin can change max_admins
    if ($currentRole === 'superadmin') {
      $settings['max_admins'] = $maxAdmins;
    }
    $settings['require_fingerprint_match'] = $requireFingerprint;
    $settings['require_reason_keywords'] = $requireReasonKeywords;
    $settings['reason_keywords'] = $reasonKeywords;
    $settings['checkin_time_start'] = $checkinStart;
    $settings['checkin_time_end'] = $checkinEnd;
    $settings['enforce_one_device_per_day'] = $enforceOneDevice;
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $message = 'Settings saved.';
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
