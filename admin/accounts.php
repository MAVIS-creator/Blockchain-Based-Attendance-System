<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
  header('Location: login.php');
  exit;
}

require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/state_helpers.php';

$accountsFile = admin_accounts_file();
$settingsFile = admin_settings_file();
if (!file_exists($accountsFile)) file_put_contents($accountsFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if (!file_exists($settingsFile)) file_put_contents($settingsFile, json_encode(['prefer_mac' => true, 'max_admins' => 5], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$accounts = admin_load_accounts_cached(15);
$settings = admin_load_settings_cached(15) ?: ['prefer_mac' => true, 'max_admins' => 5];

require_once __DIR__ . '/includes/csrf.php';
csrf_token();

$currentRole = $_SESSION['admin_role'] ?? 'admin';

$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check_request()) {
    $errors[] = 'Invalid CSRF token.';
  }

  $action = $_POST['action'] ?? '';
  $privileged = in_array($action, ['create', 'delete', 'set_password'], true);
  if ($privileged && $currentRole !== 'superadmin') {
    $errors[] = 'Only super-admins can perform that action.';
  }

  if ($action === 'create') {
    $username = trim($_POST['username'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || !preg_match('/^[a-zA-Z0-9_\-]{3,30}$/', $username)) {
      $errors[] = 'Username must be 3-30 chars, letters/numbers/_/- only.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Invalid email address.';
    }
    if ($password === '' || strlen($password) < 6) {
      $errors[] = 'Password must be at least 6 characters.';
    }

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
          'email' => $email,
          'avatar' => null,
          'role' => 'admin'
        ];
        file_put_contents($accountsFile, json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $message = "Admin account '{$username}' created.";
      }
    }
  } elseif ($action === 'delete') {
    $target = trim($_POST['target'] ?? '');
    if ($target === '') {
      $errors[] = 'Invalid target.';
    } elseif (!isset($accounts[$target])) {
      $errors[] = 'Target user does not exist.';
    } else {
      $currentUser = $_SESSION['admin_user'] ?? '';
      if ($currentUser === $target) {
        $errors[] = 'You cannot delete your own account.';
      } else {
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
?>

<!-- Admin Accounts — Stitch UI -->
<div style="max-width:900px;margin:0 auto;">
  <div style="margin-bottom:24px;">
    <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;">
      <span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;">group</span>Admin Accounts
    </h2>
    <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">Manage admin users. Max: <?= intval($settings['max_admins'] ?? 5) ?> accounts.</p>
  </div>

  <!-- Accounts Table -->
  <div class="st-card" style="padding:0;margin-bottom:20px;overflow-x:auto;">
    <table class="st-table" style="width:100%;">
      <thead>
        <tr>
          <th>Username</th>
          <th>Name</th>
          <th>Email</th>
          <th>Role</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($accounts as $u => $info): ?>
          <tr>
            <td style="font-weight:600;"><?= htmlspecialchars($u) ?></td>
            <td><?= htmlspecialchars($info['name'] ?? '') ?></td>
            <td>
              <?php if (!empty($info['email'])): ?>
                <a href="mailto:<?= htmlspecialchars($info['email']) ?>?subject=Smart Attendance System Invitation&body=You have been invited as an admin. Username: <?= htmlspecialchars($u) ?>" style="color:var(--primary);text-decoration:none;"><?= htmlspecialchars($info['email']) ?></a>
              <?php else: ?>
                <span style="color:var(--outline);">—</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="st-chip <?= ($info['role'] ?? 'admin') === 'superadmin' ? 'st-chip-info' : 'st-chip-neutral' ?>">
                <?= htmlspecialchars($info['role'] ?? 'admin') ?>
              </span>
            </td>
            <td style="text-align:right;">
              <?php if ($currentRole === 'superadmin'): ?>
                <?php if ($u !== ($_SESSION['admin_user'] ?? '')): ?>
                  <div style="display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
                    <form method="POST" style="display:inline;margin:0;">
                      <?php csrf_field(); ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="target" value="<?= htmlspecialchars($u) ?>">
                      <button type="submit" class="st-btn st-btn-danger st-btn-sm">
                        <span class="material-symbols-outlined" style="font-size:0.9rem;">delete</span>
                      </button>
                    </form>
                    <form method="POST" style="display:inline-flex;gap:4px;margin:0;align-items:center;">
                      <?php csrf_field(); ?>
                      <input type="hidden" name="action" value="set_password">
                      <input type="hidden" name="target" value="<?= htmlspecialchars($u) ?>">
                      <input type="password" name="new_password" placeholder="New pwd" style="width:120px;padding:6px 8px;font-size:0.85rem;">
                      <button type="submit" class="st-btn st-btn-sm" style="background:#f59e0b;color:#fff;">Set</button>
                    </form>
                  </div>
                <?php else: ?>
                  <span class="st-chip st-chip-neutral">(you)</span>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:var(--outline);">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Create Admin -->
  <?php if ($currentRole === 'superadmin'): ?>
    <div class="st-card" style="margin-bottom:20px;">
      <p style="font-weight:700;color:var(--on-surface);margin:0 0 16px;display:flex;align-items:center;gap:8px;">
        <span class="material-symbols-outlined" style="font-size:1.1rem;">person_add</span> Create Admin
      </p>
      <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
        <?php csrf_field(); ?>
        <input name="action" type="hidden" value="create">
        <div>
          <label style="display:block;font-weight:600;margin-bottom:4px;color:var(--on-surface-variant);font-size:0.8rem;">Username</label>
          <input name="username" placeholder="username" required>
        </div>
        <div>
          <label style="display:block;font-weight:600;margin-bottom:4px;color:var(--on-surface-variant);font-size:0.8rem;">Full Name</label>
          <input name="fullname" placeholder="Full Name">
        </div>
        <div>
          <label style="display:block;font-weight:600;margin-bottom:4px;color:var(--on-surface-variant);font-size:0.8rem;">Email</label>
          <input name="email" type="email" placeholder="admin@domain.com">
        </div>
        <div>
          <label style="display:block;font-weight:600;margin-bottom:4px;color:var(--on-surface-variant);font-size:0.8rem;">Password</label>
          <input name="password" type="password" placeholder="password" required>
        </div>
        <button type="submit" class="st-btn st-btn-success">
          <span class="material-symbols-outlined" style="font-size:1rem;">add</span> Create
        </button>
      </form>
    </div>
  <?php else: ?>
    <p style="color:var(--outline);font-style:italic;">Only super-admins can create new admin accounts.</p>
  <?php endif; ?>

  <!-- Change Own Password -->
  <div class="st-card">
    <p style="font-weight:700;color:var(--on-surface);margin:0 0 16px;display:flex;align-items:center;gap:8px;">
      <span class="material-symbols-outlined" style="font-size:1.1rem;">lock</span> Change Your Password
    </p>
    <form method="POST" style="max-width:400px;display:flex;flex-direction:column;gap:10px;">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="change_self">
      <input type="password" name="current_password" placeholder="Current password">
      <input type="password" name="new_password" placeholder="New password">
      <input type="password" name="confirm_password" placeholder="Confirm new password">
      <button type="submit" class="st-btn st-btn-primary">
        <span class="material-symbols-outlined" style="font-size:1rem;">lock_reset</span> Change Password
      </button>
    </form>
  </div>
</div>

<script>
  document.querySelectorAll('form input[name="action"][value="delete"]').forEach(function(input) {
    var form = input.closest('form');
    if (!form) return;
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      window.adminConfirm('Delete admin account', 'This account will be removed permanently. Continue?').then(function(ok) {
        if (ok) form.submit();
      });
    });
  });

  <?php if ($message !== ''): ?>
  window.adminAlert('Success', <?= json_encode($message) ?>, 'success');
  <?php elseif (!empty($errors)): ?>
  window.adminAlert('Action failed', <?= json_encode(implode("\n", $errors)) ?>, 'error');
  <?php endif; ?>
</script>
