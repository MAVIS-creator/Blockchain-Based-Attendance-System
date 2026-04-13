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
$permissions = admin_load_permissions_cached(15);

$roleMemberLimits = [];
if (isset($settings['role_member_limits']) && is_array($settings['role_member_limits'])) {
  foreach ($settings['role_member_limits'] as $rk => $rv) {
    $roleKey = strtolower(trim((string)$rk));
    if ($roleKey === '') continue;
    $roleMemberLimits[$roleKey] = max(0, (int)$rv);
  }
}

$countRoleMembers = static function (array $rows, string $role): int {
  $needle = strtolower(trim($role));
  if ($needle === '') return 0;
  $count = 0;
  foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $rowRole = strtolower(trim((string)($row['role'] ?? 'admin')));
    if ($rowRole === '') $rowRole = 'admin';
    if ($rowRole === $needle) $count++;
  }
  return $count;
};

$availableRoles = ['superadmin', 'admin'];
if (is_array($permissions)) {
  foreach (array_keys($permissions) as $roleKey) {
    if (is_string($roleKey) && $roleKey !== '') {
      $availableRoles[] = $roleKey;
    }
  }
}
$availableRoles = array_values(array_unique($availableRoles));
usort($availableRoles, function ($a, $b) {
  $order = ['superadmin' => 0, 'admin' => 1];
  $ai = $order[$a] ?? 10;
  $bi = $order[$b] ?? 10;
  if ($ai !== $bi) return $ai <=> $bi;
  return strcmp($a, $b);
});

require_once __DIR__ . '/includes/csrf.php';
csrf_token();

$currentRole = $_SESSION['admin_role'] ?? 'admin';

$message = '';
$errors = [];

$accountsPage = isset($_GET['accounts_pg']) && ctype_digit((string)$_GET['accounts_pg'])
  ? max(1, (int)$_GET['accounts_pg'])
  : 1;
$accountsPerPage = 12;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check_request()) {
    $errors[] = 'Invalid CSRF token.';
  }

  $action = $_POST['action'] ?? '';
  $privileged = in_array($action, ['create', 'delete', 'set_password', 'update_role'], true);
  if ($privileged && $currentRole !== 'superadmin') {
    $errors[] = 'Only super-admins can perform that action.';
  }

  if ($action === 'create') {
    $username = trim($_POST['username'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = strtolower(trim((string)($_POST['role'] ?? 'admin')));

    if ($username === '' || !preg_match('/^[a-zA-Z0-9_\-]{3,30}$/', $username)) {
      $errors[] = 'Username must be 3-30 chars, letters/numbers/_/- only.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Invalid email address.';
    }
    if ($password === '' || strlen($password) < 6) {
      $errors[] = 'Password must be at least 6 characters.';
    }
    if (!in_array($role, $availableRoles, true)) {
      $errors[] = 'Selected role is invalid.';
    }

    $roleLimit = (int)($roleMemberLimits[$role] ?? 0);
    if ($roleLimit > 0) {
      $currentForRole = $countRoleMembers($accounts, $role);
      if ($currentForRole >= $roleLimit) {
        $errors[] = "Maximum number of '{$role}' accounts reached ({$roleLimit}).";
      }
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
          'role' => $role,
          'needs_tour' => true
        ];
        file_put_contents($accountsFile, json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if (function_exists('admin_log_action')) {
          admin_log_action('Accounts', 'Account Created', "Created admin account: {$username} (role: {$role})");
        }

        $emailSent = false;
        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
          require_once __DIR__ . '/../env_helpers.php';
          $ENV = app_load_env_layers(__DIR__ . '/../.env');

          $loginLink = app_public_url('/admin/login.php');

          $subject = "Your Admin Account Details";
          $headers = "MIME-Version: 1.0\r\n";
          $headers .= "Content-type: text/html; charset=UTF-8\r\n";
          $headers .= "From: Admin System <noreply@" . $host . ">\r\n";

          $htmlMsg = "
            <html>
            <body style='font-family: Arial, sans-serif; background-color: #f3f4f6; margin: 0; padding: 20px;'>
              <div style='max-width: 500px; margin: 0 auto; background-color: #ffffff; padding: 30px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);'>
                <h2 style='color: #111827; margin-top: 0;'>Welcome to the Admin Portal</h2>
                <p style='color: #4b5563; font-size: 16px;'>Hello <strong>" . htmlspecialchars($fullname ?: $username) . "</strong>,</p>
                <p style='color: #4b5563; font-size: 16px;'>An administrator account has been provisioned for you.</p>
                <div style='background-color: #f9fafb; padding: 15px; border-radius: 8px; margin: 20px 0; border: 1px dashed #d1d5db;'>
                  <p style='margin: 5px 0; color: #111827;'><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                  <p style='margin: 5px 0; color: #111827;'><strong>Temporary Password:</strong> " . htmlspecialchars($password) . "</p>
                  <p style='margin: 5px 0; color: #111827;'><strong>Role Level:</strong> " . htmlspecialchars($role) . "</p>
                </div>
                <div style='text-align: center; margin: 30px 0;'>
                  <a href='{$loginLink}' style='background-color: #2563eb; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;'>Access Dashboard</a>
                </div>
                <p style='color: #dc2626; font-size: 14px; font-weight: bold; background-color: #fef2f2; padding: 10px; border-radius: 6px; border: 1px solid #fca5a5;'>
                  🚨 SECURITY ACTION REQUIRED: Please change your temporary password immediately upon logging in via the Profile Settings page.
                </p>
              </div>
            </body>
            </html>
            ";
          // $ENV loaded above
          $smtpHost = trim((string)($ENV['SMTP_HOST'] ?? ''));
          $smtpUser = trim((string)($ENV['SMTP_USER'] ?? ''));
          $smtpPass = trim((string)($ENV['SMTP_PASS'] ?? ''));
          $smtpConfigured = ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '');

          if ($smtpConfigured && file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
              try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $smtpHost;
                $mail->Port = intval($ENV['SMTP_PORT'] ?? 587);
                $secure = $ENV['SMTP_SECURE'] ?? '';
                if ($secure) $mail->SMTPSecure = $secure;
                $mail->SMTPAuth = true;
                $mail->Username = $smtpUser;
                $mail->Password = $smtpPass;

                $fromEmail = $ENV['FROM_EMAIL'] ?? 'no-reply@example.com';
                $fromName = $ENV['FROM_NAME'] ?? 'Attendance System';
                $mail->setFrom($fromEmail, $fromName);
                $mail->addAddress($email);
                $mail->Subject = $subject;
                $mail->isHTML(true);
                $mail->Body = trim($htmlMsg);
                $mail->send();
                $emailSent = true;
              } catch (\Exception $e) {
                $emailSent = false;
              }
            }
          } else {
            $emailSent = @mail($email, $subject, trim($htmlMsg), $headers);
          }
        }

        $message = "Admin account '{$username}' created with role '{$role}'." . ($emailSent ? " Email delivery dispatched." : ($email ? " Email delivery failed (check mail config)." : ""));
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
          if (function_exists('admin_log_action')) {
            admin_log_action('Accounts', 'Account Deleted', "Deleted admin account: {$target}");
          }
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
        if (function_exists('admin_log_action')) {
          admin_log_action('Accounts', 'Password Changed', "Admin changed their own password.");
        }
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
      if (function_exists('admin_log_action')) {
        admin_log_action('Accounts', 'Password Reset', "Password reset for account: {$target}");
      }
      $message = "Password updated for {$target}.";
    }
  } elseif ($action === 'update_role') {
    $target = trim($_POST['target'] ?? '');
    $newRole = strtolower(trim((string)($_POST['new_role'] ?? 'admin')));

    if ($target === '' || !isset($accounts[$target])) {
      $errors[] = 'Target user does not exist.';
    } elseif (!in_array($newRole, $availableRoles, true)) {
      $errors[] = 'Selected role is invalid.';
    } else {
      $oldRole = (string)($accounts[$target]['role'] ?? 'admin');
      $oldRoleNorm = strtolower(trim($oldRole));
      if ($oldRoleNorm === '') $oldRoleNorm = 'admin';
      $superCount = 0;
      foreach ($accounts as $a) {
        if (($a['role'] ?? 'admin') === 'superadmin') $superCount++;
      }

      if ($oldRole === 'superadmin' && $newRole !== 'superadmin' && $superCount <= 1) {
        $errors[] = 'Cannot demote the last super-admin.';
      } else {
        if ($newRole !== $oldRoleNorm) {
          $targetRoleLimit = (int)($roleMemberLimits[$newRole] ?? 0);
          if ($targetRoleLimit > 0) {
            $targetCurrentCount = $countRoleMembers($accounts, $newRole);
            if ($targetCurrentCount >= $targetRoleLimit) {
              $errors[] = "Maximum number of '{$newRole}' accounts reached ({$targetRoleLimit}).";
            }
          }
        }
      }

      if (empty($errors)) {
        $accounts[$target]['role'] = $newRole;
        file_put_contents($accountsFile, json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if (function_exists('admin_log_action')) {
          admin_log_action('Accounts', 'Role Updated', "Updated role for {$target}: {$oldRole} -> {$newRole}");
        }
        $message = "Role updated for {$target}.";
      }
    }
  }
}
?>

<style>
  .accounts-page {
    max-width: 1120px;
    margin: 0 auto;
  }

  .accounts-hero {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
  }

  .accounts-title {
    margin: 0;
    font-size: 1.55rem;
    font-weight: 900;
    letter-spacing: -0.02em;
    color: var(--on-surface);
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .accounts-subtitle {
    margin: 6px 0 0;
    color: var(--on-surface-variant);
    font-size: 0.92rem;
  }

  .accounts-stat-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 16px;
  }

  .accounts-stat-card {
    background: var(--surface-container-lowest);
    border: 1px solid var(--outline-variant);
    border-radius: 14px;
    padding: 14px;
    box-shadow: var(--shadow-ambient);
  }

  .accounts-stat-label {
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--on-surface-variant);
    font-weight: 800;
    margin-bottom: 4px;
  }

  .accounts-stat-value {
    font-size: 1.35rem;
    font-weight: 900;
    color: var(--on-surface);
  }

  .accounts-table-wrap {
    overflow-x: auto;
  }

  .accounts-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
  }

  .accounts-self-chip {
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--on-surface-variant);
    background: var(--surface-container-high);
    padding: 4px 10px;
    border-radius: 999px;
  }

  .accounts-reset-form {
    display: inline-flex;
    gap: 6px;
    align-items: center;
  }

  .accounts-role-form {
    display: inline-flex;
    gap: 6px;
    align-items: center;
  }

  .accounts-role-select {
    min-width: 120px;
    padding: 6px 8px;
    border-radius: 8px;
    border: 1px solid var(--outline-variant);
    background: var(--surface-container-lowest);
    color: var(--on-surface);
    font-size: 0.82rem;
  }

  .accounts-reset-input {
    width: 132px;
    min-width: 132px;
  }

  .admin-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(7, 18, 31, 0.56);
    backdrop-filter: blur(4px);
    z-index: 21000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
  }

  .admin-modal-backdrop.open {
    display: flex;
  }

  .admin-modal {
    width: min(680px, 100%);
    background: var(--surface-container-lowest);
    border: 1px solid var(--outline-variant);
    border-radius: 18px;
    box-shadow: var(--shadow-elevated);
    overflow: hidden;
  }

  .admin-modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 18px;
    background: linear-gradient(135deg, var(--primary-fixed), var(--surface-container-low));
    border-bottom: 1px solid var(--outline-variant);
  }

  .admin-modal-title {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--on-surface);
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .admin-modal-body {
    padding: 18px;
  }

  .admin-modal-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
  }

  .admin-modal-grid .full {
    grid-column: span 2;
  }

  .admin-modal-close {
    background: transparent;
    border: none;
    color: var(--on-surface-variant);
    cursor: pointer;
    border-radius: 8px;
    padding: 6px;
  }

  .admin-modal-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .admin-modal-input,
  .admin-modal-select {
    width: 100%;
    border: 1px solid var(--outline-variant);
    background: var(--surface-container-low);
    color: var(--on-surface);
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 0.92rem;
    line-height: 1.35;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
    outline: none;
    box-sizing: border-box;
  }

  .admin-modal-input::placeholder {
    color: var(--on-surface-variant);
    opacity: 0.85;
  }

  .admin-modal-input:focus,
  .admin-modal-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary) 18%, transparent);
    background: var(--surface-container-lowest);
  }

  @media (max-width: 860px) {
    .accounts-stat-grid {
      grid-template-columns: 1fr;
    }

    .accounts-hero {
      flex-direction: column;
      align-items: stretch;
    }
  }

  @media (max-width: 720px) {
    .admin-modal-grid {
      grid-template-columns: 1fr;
    }

    .admin-modal-grid .full {
      grid-column: span 1;
    }

    .accounts-actions {
      justify-content: flex-start;
    }

    .accounts-reset-input {
      width: 100%;
      min-width: 0;
    }
  }
</style>

<div class="accounts-page">
  <div class="accounts-hero">
    <div>
      <h2 class="accounts-title"><span class="material-symbols-outlined">admin_panel_settings</span>Manage Accounts</h2>
      <p class="accounts-subtitle">Modern account controls for your admin team. Max allowed: <?= intval($settings['max_admins'] ?? 5) ?> accounts.</p>
    </div>
    <?php if ($currentRole === 'superadmin'): ?>
      <button type="button" class="st-btn st-btn-primary" id="openCreateAdminModalBtn">
        <span class="material-symbols-outlined" style="font-size:1rem;">person_add</span>
        Create Admin
      </button>
    <?php endif; ?>
  </div>

  <?php
  $totalAdmins = count($accounts);
  $superAdmins = 0;
  foreach ($accounts as $acct) {
    if (($acct['role'] ?? 'admin') === 'superadmin') $superAdmins++;
  }

  $accountRows = array_values($accounts);
  $accountUsernames = array_keys($accounts);
  $accountsTotal = count($accountRows);
  $accountsTotalPages = max(1, (int)ceil($accountsTotal / $accountsPerPage));
  $accountsPage = min($accountsPage, $accountsTotalPages);
  $accountsOffset = ($accountsPage - 1) * $accountsPerPage;
  $pagedAccountRows = array_slice($accountRows, $accountsOffset, $accountsPerPage);
  $pagedAccountUsernames = array_slice($accountUsernames, $accountsOffset, $accountsPerPage);
  ?>

  <div class="accounts-stat-grid">
    <div class="accounts-stat-card">
      <div class="accounts-stat-label">Total Admins</div>
      <div class="accounts-stat-value"><?= intval($totalAdmins) ?></div>
    </div>
    <div class="accounts-stat-card">
      <div class="accounts-stat-label">Super Admins</div>
      <div class="accounts-stat-value"><?= intval($superAdmins) ?></div>
    </div>
    <div class="accounts-stat-card">
      <div class="accounts-stat-label">Remaining Slots</div>
      <div class="accounts-stat-value"><?= max(0, intval($settings['max_admins'] ?? 5) - $totalAdmins) ?></div>
    </div>
  </div>

  <div class="st-card accounts-table-wrap" style="padding:0;">
    <table class="st-table" style="width:100%;">
      <thead>
        <tr>
          <th>Admin</th>
          <th class="mobile-hide-col">Email</th>
          <th>Role</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pagedAccountRows as $idx => $info): ?>
          <?php $u = (string)($pagedAccountUsernames[$idx] ?? ''); ?>
          <?php $isSelf = $u === ($_SESSION['admin_user'] ?? ''); ?>
          <tr>
            <td>
              <div style="display:flex;flex-direction:column;gap:2px;">
                <strong><?= htmlspecialchars($u) ?></strong>
                <span style="color:var(--on-surface-variant);font-size:0.82rem;"><?= htmlspecialchars($info['name'] ?? $u) ?></span>
              </div>
            </td>
            <td class="mobile-hide-col">
              <?php if (!empty($info['email'])): ?>
                <a href="mailto:<?= htmlspecialchars($info['email']) ?>" style="color:var(--primary);font-weight:600;"><?= htmlspecialchars($info['email']) ?></a>
              <?php else: ?>
                <span style="color:var(--outline);">No email</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="st-chip <?= ($info['role'] ?? 'admin') === 'superadmin' ? 'st-chip-info' : 'st-chip-neutral' ?>">
                <?= htmlspecialchars($info['role'] ?? 'admin') ?>
              </span>
            </td>
            <td style="text-align:right;">
              <?php if ($currentRole === 'superadmin'): ?>
                <?php if ($isSelf): ?>
                  <span class="accounts-self-chip">YOU</span>
                <?php else: ?>
                  <div class="accounts-actions">
                    <form method="POST" class="accounts-role-form" style="margin:0;">
                      <?php csrf_field(); ?>
                      <input type="hidden" name="action" value="update_role">
                      <input type="hidden" name="target" value="<?= htmlspecialchars($u) ?>">
                      <select name="new_role" class="accounts-role-select">
                        <?php foreach ($availableRoles as $roleOption): ?>
                          <option value="<?= htmlspecialchars($roleOption) ?>" <?= (($info['role'] ?? 'admin') === $roleOption) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($roleOption) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" class="st-btn st-btn-sm" style="background:#2563eb;color:#fff;">Role</button>
                    </form>
                    <form method="POST" class="accounts-reset-form" style="margin:0;">
                      <?php csrf_field(); ?>
                      <input type="hidden" name="action" value="set_password">
                      <input type="hidden" name="target" value="<?= htmlspecialchars($u) ?>">
                      <input type="password" name="new_password" class="accounts-reset-input" placeholder="New password" required minlength="6">
                      <button type="submit" class="st-btn st-btn-sm" style="background:#f59e0b;color:#fff;">Set</button>
                    </form>
                    <form method="POST" class="admin-delete-form" style="margin:0;">
                      <?php csrf_field(); ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="target" value="<?= htmlspecialchars($u) ?>">
                      <button type="submit" class="st-btn st-btn-danger st-btn-sm">
                        <span class="material-symbols-outlined" style="font-size:0.9rem;">delete</span>
                        Remove
                      </button>
                    </form>
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:var(--outline);">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($accountsTotal > 0): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 16px;border-top:1px solid var(--outline-variant);">
        <div style="font-size:0.82rem;color:var(--on-surface-variant);">
          Showing <?= (int)($accountsOffset + 1) ?>-<?= (int)min($accountsOffset + $accountsPerPage, $accountsTotal) ?> of <?= (int)$accountsTotal ?> accounts
        </div>
        <?php if ($accountsTotalPages > 1): ?>
          <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
            <?php for ($i = 1; $i <= $accountsTotalPages; $i++): ?>
              <a
                href="?page=accounts&accounts_pg=<?= (int)$i ?>"
                style="display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;border-radius:8px;padding:0 8px;text-decoration:none;font-size:0.82rem;font-weight:700;<?= $i === $accountsPage ? 'background:var(--primary);color:#fff;' : 'background:var(--surface-container-low);color:var(--on-surface);border:1px solid var(--outline-variant);' ?>"
              ><?= (int)$i ?></a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($currentRole !== 'superadmin'): ?>
    <p style="margin-top:12px;color:var(--outline);font-style:italic;">Only super-admins can create or modify admin accounts.</p>
  <?php endif; ?>
</div>

<?php if ($currentRole === 'superadmin'): ?>
  <div class="admin-modal-backdrop" id="createAdminModal" aria-hidden="true">
    <div class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="createAdminModalTitle">
      <div class="admin-modal-head">
        <p class="admin-modal-title" id="createAdminModalTitle"><span class="material-symbols-outlined">person_add</span>Create Admin</p>
        <button type="button" class="admin-modal-close" id="closeCreateAdminModalBtn" aria-label="Close create admin modal">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
      <div class="admin-modal-body">
        <form method="POST">
          <?php csrf_field(); ?>
          <input name="action" type="hidden" value="create">
          <div class="admin-modal-grid">
            <div class="admin-modal-field">
              <label class="st-label" style="margin-bottom:6px;display:block;">Username</label>
              <input class="admin-modal-input" name="username" placeholder="username" required pattern="[a-zA-Z0-9_\-]{3,30}">
            </div>
            <div class="admin-modal-field">
              <label class="st-label" style="margin-bottom:6px;display:block;">Full Name</label>
              <input class="admin-modal-input" name="fullname" placeholder="Full Name">
            </div>
            <div class="full admin-modal-field">
              <label class="st-label" style="margin-bottom:6px;display:block;">Email</label>
              <input class="admin-modal-input" name="email" type="email" placeholder="admin@domain.com">
            </div>
            <div class="full admin-modal-field">
              <label class="st-label" style="margin-bottom:6px;display:block;">Temporary Password</label>
              <input class="admin-modal-input" name="password" type="password" placeholder="Minimum 6 characters" required minlength="6">
            </div>
            <div class="full admin-modal-field">
              <label class="st-label" style="margin-bottom:6px;display:block;">Role</label>
              <select class="admin-modal-select" name="role" required>
                <?php foreach ($availableRoles as $roleOption): ?>
                  <option value="<?= htmlspecialchars($roleOption) ?>" <?= $roleOption === 'admin' ? 'selected' : '' ?>><?= htmlspecialchars($roleOption) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
            <button type="button" class="st-btn st-btn-ghost" id="cancelCreateAdminModalBtn">Cancel</button>
            <button type="submit" class="st-btn st-btn-success">
              <span class="material-symbols-outlined" style="font-size:1rem;">check</span>
              Create Admin
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
  document.querySelectorAll('.admin-delete-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      window.adminConfirm('Delete admin account', 'This account will be removed permanently. Continue?').then(function(ok) {
        if (ok) form.submit();
      });
    });
  });

  (function() {
    var modal = document.getElementById('createAdminModal');
    if (!modal) return;
    var openBtn = document.getElementById('openCreateAdminModalBtn');
    var closeBtn = document.getElementById('closeCreateAdminModalBtn');
    var cancelBtn = document.getElementById('cancelCreateAdminModalBtn');

    function openModal() {
      modal.classList.add('open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    }

    function closeModal() {
      modal.classList.remove('open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    }

    if (openBtn) openBtn.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    modal.addEventListener('click', function(e) {
      if (e.target === modal) closeModal();
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });
  })();

  <?php if ($message !== ''): ?>
    window.adminAlert('Success', <?= json_encode($message) ?>, 'success');
  <?php elseif (!empty($errors)): ?>
    window.adminAlert('Action failed', <?= json_encode(implode("\n", $errors)) ?>, 'error');
  <?php endif; ?>
</script>
