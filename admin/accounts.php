<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
  header('Location: login.php');
  exit;
}

// include header and sidebar provided by index.php layout when loaded via index.php route
// This page can also be accessed directly; include header/footer if not included by index

$accountsFile = __DIR__ . '/accounts.json';
$settingsFile = __DIR__ . '/settings.json';
if (!file_exists($accountsFile)) file_put_contents($accountsFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if (!file_exists($settingsFile)) file_put_contents($settingsFile, json_encode(['prefer_mac' => true, 'max_admins' => 5], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$accounts = json_decode(file_get_contents($accountsFile), true) ?: [];
$settings = json_decode(file_get_contents($settingsFile), true) ?: ['prefer_mac' => true, 'max_admins' => 5];

// CSRF token
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

// determine current user role
$currentRole = $_SESSION['admin_role'] ?? 'admin';

$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // validate CSRF
  $token = $_POST['csrf_token'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'], $token)) {
    $errors[] = 'Invalid CSRF token.';
  }

  $action = $_POST['action'] ?? '';

  // Helper: require superadmin for privileged actions
  $privileged = in_array($action, ['create','delete','set_password'], true);
  if ($privileged && $currentRole !== 'superadmin') {
    $errors[] = 'Only super-admins can perform that action.';
  }

  // Create new admin (superadmin only)
  if ($action === 'create') {
    $username = trim($_POST['username'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $password = $_POST['password'] ?? '';

    // Basic validation
    if ($username === '' || !preg_match('/^[a-zA-Z0-9_\-]{3,30}$/', $username)) {
      $errors[] = 'Username must be 3-30 chars, letters/numbers/_/- only.';
    }
    if ($password === '' || strlen($password) < 6) {
      $errors[] = 'Password must be at least 6 characters.';
    }

    // enforce max admins
    $maxAdmins = intval($settings['max_admins'] ?? 5);
    if (count($accounts) >= $maxAdmins) {
      $errors[] = "Maximum number of admins reached ({$maxAdmins}).";
    }

    if (empty($errors)) {
      if (isset($accounts[$username])) {
        $errors[] = 'Username already exists.';
      } else {
        $accounts[$username] = [
          'password' => password_hash($password, PASSWORD_DEFAULT),
          'name' => $fullname ?: $username,
          'avatar' => null,
          'role' => 'admin'
        ];
        file_put_contents($accountsFile, json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $message = "Admin account '{$username}' created.";
      }
    }

  // Delete admin (superadmin only)
  } elseif ($action === 'delete') {
    $target = trim($_POST['target'] ?? '');
    if ($target === '') {
      $errors[] = 'Invalid target.';
    } elseif (!isset($accounts[$target])) {
      $errors[] = 'Target user does not exist.';
    } else {
      // prevent deleting self
      $currentUser = $_SESSION['admin_user'] ?? '';
      if ($currentUser === $target) {
        $errors[] = 'You cannot delete your own account.';
      } else {
        // prevent removing last superadmin
        $superCount = 0;
        foreach ($accounts as $u => $a) {
          if (($a['role'] ?? 'admin') === 'superadmin') $superCount++;
        }
        if (($accounts[$target]['role'] ?? 'admin') === 'superadmin' && $superCount <= 1) {
          $errors[] = 'Cannot delete the last super-admin.';
        } else {
          unset($accounts[$target]);
          file_put_contents($accountsFile, json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
          $message = "Admin account '{$target}' deleted.";
        }
      }
    }

  // Change own password (any admin)
  } elseif ($action === 'change_self') {
    $currentUser = $_SESSION['admin_user'] ?? '';
    $old = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if ($currentUser === '' || !isset($accounts[$currentUser])) {
      $errors[] = 'Account not found.';
    } else {
      if ($old === '' || $new === '' || $confirm === '') {
        $errors[] = 'All password fields are required.';
      } elseif (!password_verify($old, $accounts[$currentUser]['password'])) {
        $errors[] = 'Current password is incorrect.';
      } elseif ($new !== $confirm) {
        $errors[] = 'New passwords do not match.';
      } elseif (strlen($new) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
      } else {
        $accounts[$currentUser]['password'] = password_hash($new, PASSWORD_DEFAULT);
        file_put_contents($accountsFile, json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $message = 'Your password has been changed.';
      }
    }

  // Superadmin: set password for another user without old password
  } elseif ($action === 'set_password') {
    $target = trim($_POST['target'] ?? '');
    $new = $_POST['new_password'] ?? '';
    if ($target === '' || !isset($accounts[$target])) {
      $errors[] = 'Target user does not exist.';
    } elseif ($new === '' || strlen($new) < 6) {
      $errors[] = 'New password must be at least 6 characters.';
    } else {
      $accounts[$target]['password'] = password_hash($new, PASSWORD_DEFAULT);
      file_put_contents($accountsFile, json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      $message = "Password updated for {$target}.";
    }
  }
}

// Render page (when included via index.php, header/footer/sidebar are already present)
?>

<div class="admin-page" style="padding:20px;background:transparent;">
  <h2>Admin Accounts</h2>
  <?php if ($message): ?>
    <div style="background:#dff0d8;padding:10px;border-radius:6px;margin-bottom:12px;color:#2d6a2d;"><?=htmlspecialchars($message)?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div style="background:#ffe6e6;padding:10px;border-radius:6px;margin-bottom:12px;color:#8a1f1f;"><ul><?php foreach($errors as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?></ul></div>
  <?php endif; ?>

  <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
    <thead><tr style="background:#f4f6f8;"><th style="padding:8px;text-align:left;">Username</th><th style="padding:8px;text-align:left;">Name</th><th style="padding:8px;text-align:left;">Role</th><th style="padding:8px;text-align:right;">Actions</th></tr></thead>
    <tbody>
      <?php foreach ($accounts as $u => $info): ?>
      <tr>
        <td style="padding:8px;border-bottom:1px solid #eee;"><?=htmlspecialchars($u)?></td>
        <td style="padding:8px;border-bottom:1px solid #eee;"><?=htmlspecialchars($info['name']??'')?></td>
        <td style="padding:8px;border-bottom:1px solid #eee;"><?=htmlspecialchars($info['role']??'admin')?></td>
        <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">
            <?php if ($currentRole === 'superadmin'): ?>
              <?php if (($u !== ($_SESSION['admin_user'] ?? ''))): ?>
                <form method="POST" style="display:inline-block;margin:0 6px 0 0;">
                  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="target" value="<?=htmlspecialchars($u)?>">
                  <button type="submit" style="padding:6px 10px;background:#ef4444;color:#fff;border:none;border-radius:4px;">Delete</button>
                </form>
                <form method="POST" style="display:inline-block;margin:0 0 0 6px;">
                  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                  <input type="hidden" name="action" value="set_password">
                  <input type="hidden" name="target" value="<?=htmlspecialchars($u)?>">
                  <input type="password" name="new_password" placeholder="New password" style="padding:6px;border-radius:4px;border:1px solid #ddd;">
                  <button type="submit" style="padding:6px 10px;background:#f59e0b;color:#fff;border:none;border-radius:4px;margin-left:6px;">Set</button>
                </form>
              <?php else: ?>
                <em style="color:#666;">(you)</em>
              <?php endif; ?>
            <?php else: ?>
              <em style="color:#666;">-</em>
            <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($currentRole === 'superadmin'): ?>
  <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <input name="csrf_token" type="hidden" value="<?=htmlspecialchars($csrf)?>">
    <input name="action" type="hidden" value="create">
    <input name="username" placeholder="username" style="padding:8px;min-width:160px;" required>
    <input name="fullname" placeholder="full name" style="padding:8px;min-width:200px;">
    <input name="password" type="password" placeholder="password" style="padding:8px;min-width:160px;" required>
    <button type="submit" style="padding:8px;background:#10b981;color:#fff;border:none;border-radius:6px;">Create Admin</button>
  </form>
  <?php else: ?>
    <div style="color:#6b7280;margin-top:8px;">Only super-admins can create new admin accounts.</div>
  <?php endif; ?>

  <hr style="margin:18px 0;border:none;border-top:1px solid #eee;">

  <h3>Change your password</h3>
  <form method="POST" style="max-width:560px;display:flex;flex-direction:column;gap:8px;">
    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
    <input type="hidden" name="action" value="change_self">
    <input type="password" name="current_password" placeholder="Current password" style="padding:8px;">
    <input type="password" name="new_password" placeholder="New password" style="padding:8px;">
    <input type="password" name="confirm_password" placeholder="Confirm new password" style="padding:8px;">
    <div><button type="submit" style="padding:8px 12px;background:#3b82f6;color:#fff;border:none;border-radius:6px;">Change password</button></div>
  </form>
</div>
