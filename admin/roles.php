<?php
if (!isset($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? 'admin') !== 'superadmin') {
  header('Location: login.php');
  exit;
}

$permissionsFile = admin_permissions_file();
$settingsFile = admin_settings_file();
$permissions = admin_load_permissions_cached(0); // load fresh
if (!is_array($permissions)) {
  $permissions = ['admin' => []];
}
if (!isset($permissions['admin']) || !is_array($permissions['admin'])) {
  $permissions['admin'] = [];
}

$assignablePages = admin_assignable_pages();
$assignableKeys = array_fill_keys(array_keys($assignablePages), true);

$settings = admin_load_settings_cached(0);
if (!is_array($settings)) {
  $settings = [];
}
$roleMemberLimits = [];
if (isset($settings['role_member_limits']) && is_array($settings['role_member_limits'])) {
  foreach ($settings['role_member_limits'] as $k => $v) {
    $key = strtolower(trim((string)$k));
    if ($key === '') continue;
    $roleMemberLimits[$key] = max(0, (int)$v);
  }
}

$accounts = admin_load_accounts_cached(0);
if (!is_array($accounts)) {
  $accounts = [];
}

$roleUsageCounts = [];
foreach ($accounts as $acct) {
  if (!is_array($acct)) continue;
  $acctRole = strtolower(trim((string)($acct['role'] ?? 'admin')));
  if ($acctRole === '') $acctRole = 'admin';
  $roleUsageCounts[$acctRole] = (int)($roleUsageCounts[$acctRole] ?? 0) + 1;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = trim((string)($_POST['role_action'] ?? ''));

  if ($action === 'create_role') {
    $rawRole = trim((string)($_POST['new_role'] ?? ''));
    $role = strtolower($rawRole);

    if (!preg_match('/^[a-z][a-z0-9_\-]{2,31}$/', $role)) {
      $error = 'Role name must be 3-32 chars, start with a letter, and use letters/numbers/_/- only.';
    } elseif (in_array($role, ['superadmin', 'unauthorized'], true)) {
      $error = 'That role name is reserved and cannot be created.';
    } elseif (isset($permissions[$role])) {
      $error = 'Role already exists.';
    } elseif (count($permissions) >= 12) { // 10 custom roles + 2 built-in
      $error = 'Maximum limit of 10 custom roles reached. Please delete an old role first.';
    } else {
      $permissions[$role] = ['dashboard', 'status', 'profile_settings'];
      if (@file_put_contents($permissionsFile, json_encode($permissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
        if (!array_key_exists($role, $roleMemberLimits)) {
          $roleMemberLimits[$role] = 0;
          $settings['role_member_limits'] = $roleMemberLimits;
          @file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
        admin_log_action('Roles', 'Role Created', "Created role '{$role}' with default permissions.");
        $message = "Role '{$role}' created successfully.";
      } else {
        $error = 'Failed to persist new role.';
      }
    }
  }

  if ($action === 'delete_role') {
    $role = strtolower(trim((string)($_POST['target_role'] ?? '')));

    if ($role === '' || !isset($permissions[$role])) {
      $error = 'Role not found.';
    } elseif (in_array($role, ['admin', 'superadmin'], true)) {
      $error = 'Built-in roles cannot be deleted.';
    } else {
      $accounts = admin_load_accounts_cached(0);
      $inUseBy = [];
      foreach ($accounts as $username => $acct) {
        if (($acct['role'] ?? 'admin') === $role) {
          $inUseBy[] = (string)$username;
        }
      }
      if (!empty($inUseBy)) {
        $error = "Cannot delete role '{$role}' because it is assigned to: " . implode(', ', array_slice($inUseBy, 0, 5)) . (count($inUseBy) > 5 ? '...' : '');
      } else {
        unset($permissions[$role]);
        if (@file_put_contents($permissionsFile, json_encode($permissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
          if (isset($roleMemberLimits[$role])) {
            unset($roleMemberLimits[$role]);
            $settings['role_member_limits'] = $roleMemberLimits;
            @file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
          }
          admin_log_action('Roles', 'Role Deleted', "Deleted role '{$role}'.");
          $message = "Role '{$role}' deleted successfully.";
        } else {
          $error = 'Failed to delete role from storage.';
        }
      }
    }
  }

  if ($action === 'save_role_limits') {
    $submitted = $_POST['role_member_limits'] ?? [];
    $newLimits = [];
    $allRoles = array_keys($permissions);
    if (!in_array('superadmin', $allRoles, true)) {
      $allRoles[] = 'superadmin';
    }
    if (!in_array('admin', $allRoles, true)) {
      $allRoles[] = 'admin';
    }

    foreach ($allRoles as $roleName) {
      $rk = strtolower(trim((string)$roleName));
      if ($rk === '') continue;

      $raw = '';
      if (is_array($submitted) && array_key_exists($rk, $submitted)) {
        $raw = trim((string)$submitted[$rk]);
      }

      $limit = 0;
      if ($raw !== '') {
        if (!preg_match('/^\d+$/', $raw)) {
          $error = "Limit for role '{$rk}' must be a whole number (0 or higher).";
          break;
        }
        $limit = max(0, (int)$raw);
      }

      $currentCount = (int)($roleUsageCounts[$rk] ?? 0);
      if ($limit > 0 && $currentCount > $limit) {
        $error = "Cannot set limit for '{$rk}' below current assigned count ({$currentCount}).";
        break;
      }

      $newLimits[$rk] = $limit;
    }

    if ($error === '') {
      $settings['role_member_limits'] = $newLimits;
      if (@file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
        $roleMemberLimits = $newLimits;
        admin_log_action('Roles', 'Role Limits Updated', 'Updated per-role member limits including superadmin/admin limits.');
        $message = 'Role member limits saved successfully.';
      } else {
        $error = 'Failed to save role member limits.';
      }
    }
  }

  if ($action === 'save_permissions') {
    $role = strtolower(trim((string)($_POST['target_role'] ?? '')));
    $newAllowed = $_POST['allowed_modules'] ?? [];

    if ($role === '' || $role === 'superadmin') {
      $error = 'Invalid role selected.';
    } else {
      $sanitized = [];
      if (is_array($newAllowed)) {
        foreach ($newAllowed as $mod) {
          $mod = (string)$mod;
          if (isset($assignableKeys[$mod])) {
            $sanitized[$mod] = true;
          }
        }
      }
      $permissions[$role] = array_keys($sanitized);

      if (@file_put_contents($permissionsFile, json_encode($permissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
        admin_log_action('Roles', 'Permissions Updated', "Updated allowed modules for role '{$role}'.");
        $message = "Permissions updated for role '{$role}'.";
      } else {
        $error = 'Failed to write permissions configuration.';
      }
    }
  }

  // reload after any mutation
  $permissions = admin_load_permissions_cached(0);
  if (!is_array($permissions)) $permissions = ['admin' => []];
  $settings = admin_load_settings_cached(0);
  if (!is_array($settings)) {
    $settings = [];
  }
  $roleMemberLimits = [];
  if (isset($settings['role_member_limits']) && is_array($settings['role_member_limits'])) {
    foreach ($settings['role_member_limits'] as $k => $v) {
      $rk = strtolower(trim((string)$k));
      if ($rk === '') continue;
      $roleMemberLimits[$rk] = max(0, (int)$v);
    }
  }
  $accounts = admin_load_accounts_cached(0);
  if (!is_array($accounts)) $accounts = [];
  $roleUsageCounts = [];
  foreach ($accounts as $acct) {
    if (!is_array($acct)) continue;
    $acctRole = strtolower(trim((string)($acct['role'] ?? 'admin')));
    if ($acctRole === '') $acctRole = 'admin';
    $roleUsageCounts[$acctRole] = (int)($roleUsageCounts[$acctRole] ?? 0) + 1;
  }
}

$roles = array_keys($permissions);
usort($roles, function ($a, $b) {
  $order = ['superadmin' => 0, 'admin' => 1];
  $ai = $order[$a] ?? 10;
  $bi = $order[$b] ?? 10;
  if ($ai !== $bi) return $ai <=> $bi;
  return strcmp($a, $b);
});

if (!in_array('superadmin', $roles, true)) {
  array_unshift($roles, 'superadmin');
}

$customRoleCount = max(0, count($roles) - 2);
$totalAssignableModules = count($assignablePages);
$totalRoleAssignments = 0;
foreach ($roleUsageCounts as $cnt) {
  $totalRoleAssignments += (int)$cnt;
}
?>
<div class="content flex-grow-1 p-4 p-md-5 roles-page-wrap">
  <div class="roles-hero">
    <div>
      <h1 class="roles-title">
        <span class="material-symbols-outlined">shield_person</span>
        Role Privileges Configuration
      </h1>
      <p class="roles-subtitle">Define who can access what, set role capacity limits, and manage your permission matrix with confidence.</p>
    </div>
    <div class="roles-chip">Superadmin Control Center</div>
  </div>

  <div class="roles-stat-grid">
    <div class="roles-stat-card">
      <p class="roles-stat-label">Total Roles</p>
      <p class="roles-stat-value"><?= count($roles) ?></p>
    </div>
    <div class="roles-stat-card">
      <p class="roles-stat-label">Custom Roles</p>
      <p class="roles-stat-value"><?= $customRoleCount ?></p>
    </div>
    <div class="roles-stat-card">
      <p class="roles-stat-label">Assignable Modules</p>
      <p class="roles-stat-value"><?= $totalAssignableModules ?></p>
    </div>
    <div class="roles-stat-card">
      <p class="roles-stat-label">Role Assignments</p>
      <p class="roles-stat-value"><?= $totalRoleAssignments ?></p>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="roles-banner roles-banner-success">
      <span class="material-symbols-outlined" style="vertical-align: middle; margin-right: 8px;">check_circle</span>
      <?= htmlspecialchars($message) ?>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="roles-banner roles-banner-error">
      <span class="material-symbols-outlined" style="vertical-align: middle; margin-right: 8px;">error</span>
      <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>

  <div class="roles-panel">
    <h3 class="roles-panel-title">
      <span class="material-symbols-outlined">add_circle</span>
      Create New Role
    </h3>
    <p class="roles-panel-subtitle">Create a custom role (e.g. manager, helpdesk, registrar). Then configure its page-level permissions below.</p>

    <form method="POST" action="index.php?page=roles" class="roles-form-inline">
      <input type="hidden" name="role_action" value="create_role">
      <input
        class="roles-input"
        type="text"
        name="new_role"
        placeholder="new role (e.g. manager)"
        required
        pattern="[a-zA-Z][a-zA-Z0-9_\-]{2,31}">
      <button type="submit" class="roles-btn roles-btn-primary">
        <span class="material-symbols-outlined">add</span>
        Create Role
      </button>
    </form>
  </div>

  <?php
  $limitRoles = $roles;
  if (!in_array('admin', $limitRoles, true)) {
    $limitRoles[] = 'admin';
  }
  if (!in_array('superadmin', $limitRoles, true)) {
    array_unshift($limitRoles, 'superadmin');
  }
  usort($limitRoles, function ($a, $b) {
    $order = ['superadmin' => 0, 'admin' => 1];
    $ai = $order[$a] ?? 10;
    $bi = $order[$b] ?? 10;
    if ($ai !== $bi) return $ai <=> $bi;
    return strcmp($a, $b);
  });
  ?>
  <div class="roles-panel">
    <h3 class="roles-panel-title">
      <span class="material-symbols-outlined">groups</span>
      Role Member Limits
    </h3>
    <p class="roles-panel-subtitle">Set max account slots per role (including <strong>superadmin</strong>). Use <strong>0</strong> for unlimited.</p>
    <form method="POST" action="index.php?page=roles">
      <input type="hidden" name="role_action" value="save_role_limits">
      <div class="roles-limit-grid">
        <?php foreach ($limitRoles as $limitRole): ?>
          <?php
          $limitKey = strtolower((string)$limitRole);
          $currentCount = (int)($roleUsageCounts[$limitKey] ?? 0);
          $currentLimit = (int)($roleMemberLimits[$limitKey] ?? 0);
          $limitReached = $currentLimit > 0 && $currentCount >= $currentLimit;
          ?>
          <label class="roles-limit-card <?= $limitReached ? 'roles-limit-card-alert' : '' ?>">
            <div class="roles-limit-head">
              <span class="roles-limit-name"><?= htmlspecialchars($limitRole) ?></span>
              <span class="roles-limit-badge"><?= $currentCount ?> used</span>
            </div>
            <input
              class="roles-input"
              type="number"
              min="0"
              step="1"
              name="role_member_limits[<?= htmlspecialchars($limitKey) ?>]"
              value="<?= $currentLimit ?>">
          </label>
        <?php endforeach; ?>
      </div>
      <div class="roles-actions-end">
        <button type="submit" class="roles-btn roles-btn-primary">
          <span class="material-symbols-outlined">rule_settings</span>
          Save Role Limits
        </button>
      </div>
    </form>
  </div>

  <div class="roles-panel" style="margin-bottom: 14px;">
    <div class="roles-search-wrap">
      <span class="material-symbols-outlined">search</span>
      <input id="roleSearchInput" class="roles-input" type="text" placeholder="Search role cards (e.g. admin, manager)..." autocomplete="off">
    </div>
  </div>

  <div class="roles-cards-grid" id="rolesCardsGrid">
    <?php foreach ($roles as $role): ?>
      <?php
      $isSuper = $role === 'superadmin';
      $isBuiltIn = in_array($role, ['admin', 'superadmin'], true);
      $allowedForRole = is_array($permissions[$role] ?? null) ? $permissions[$role] : [];
      $roleLimit = (int)($roleMemberLimits[$role] ?? 0);
      $roleCount = (int)($roleUsageCounts[$role] ?? 0);
      $limitText = $roleLimit > 0 ? ($roleCount . '/' . $roleLimit . ' slots') : ($roleCount . ' used · unlimited');
      ?>
      <div class="roles-role-card" data-role="<?= htmlspecialchars(strtolower($role)) ?>">
        <div class="roles-role-head">
          <div>
            <h3 class="roles-role-title"><?= htmlspecialchars($role) ?></h3>
            <p class="roles-role-subtitle">
              <?= $isSuper
                ? 'Full system access. Not editable here.'
                : 'Pick which pages this role can access.' ?>
            </p>
            <span class="roles-role-meta"><?= htmlspecialchars($limitText) ?></span>
          </div>

          <?php if (!$isBuiltIn): ?>
            <form method="POST" action="index.php?page=roles" onsubmit="return confirm('Delete role <?= htmlspecialchars($role) ?>?');" style="margin:0;">
              <input type="hidden" name="role_action" value="delete_role">
              <input type="hidden" name="target_role" value="<?= htmlspecialchars($role) ?>">
              <button type="submit" class="roles-btn roles-btn-danger roles-btn-sm">
                <span class="material-symbols-outlined">delete</span>
                Delete Role
              </button>
            </form>
          <?php endif; ?>
        </div>

        <?php if ($isSuper): ?>
          <div class="roles-super-note">
            Superadmin pages are intentionally not governed by role permissions.
          </div>
        <?php else: ?>
          <form method="POST" action="index.php?page=roles">
            <input type="hidden" name="role_action" value="save_permissions">
            <input type="hidden" name="target_role" value="<?= htmlspecialchars($role) ?>">

            <div class="roles-module-grid">
              <?php foreach ($assignablePages as $pageKey => $meta): ?>
                <label class="roles-module-pill">
                  <input type="checkbox" name="allowed_modules[]" value="<?= htmlspecialchars($pageKey) ?>" <?= in_array($pageKey, $allowedForRole, true) ? 'checked' : '' ?>>
                  <span><?= htmlspecialchars((string)($meta['label'] ?? $pageKey)) ?></span>
                </label>
              <?php endforeach; ?>
            </div>

            <div class="roles-actions-end">
              <button type="submit" class="roles-btn roles-btn-primary">
                <span class="material-symbols-outlined">save</span>
                Save <?= htmlspecialchars(ucfirst($role)) ?> Permissions
              </button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<style>
  .roles-page-wrap {
    max-width: 1240px;
    margin: 0 auto;
  }

  .roles-hero {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 18px;
  }

  .roles-title {
    margin: 0;
    font-size: 1.62rem;
    font-weight: 900;
    letter-spacing: -0.02em;
    color: var(--on-surface);
    display: inline-flex;
    align-items: center;
    gap: 10px;
  }

  .roles-subtitle {
    margin: 6px 0 0;
    color: var(--on-surface-variant);
    font-size: 0.92rem;
    max-width: 700px;
  }

  .roles-chip {
    padding: 8px 14px;
    border-radius: 999px;
    border: 1px solid var(--outline-variant);
    background: var(--surface-container-low);
    color: var(--on-surface);
    font-weight: 700;
    font-size: 0.8rem;
    white-space: nowrap;
  }

  .roles-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 18px;
  }

  .roles-stat-card {
    border: 1px solid var(--outline-variant);
    background: var(--surface-container-lowest);
    border-radius: 14px;
    padding: 14px;
  }

  .roles-stat-label {
    margin: 0 0 6px;
    color: var(--on-surface-variant);
    text-transform: uppercase;
    letter-spacing: 0.07em;
    font-size: 0.7rem;
    font-weight: 800;
  }

  .roles-stat-value {
    margin: 0;
    color: var(--on-surface);
    font-size: 1.28rem;
    font-weight: 900;
  }

  .roles-banner {
    padding: 14px 16px;
    border-radius: 12px;
    margin-bottom: 16px;
    font-weight: 600;
    border: 1px solid transparent;
  }

  .roles-banner-success {
    background: var(--primary-container);
    color: var(--on-primary-container);
    border-color: color-mix(in srgb, var(--primary) 24%, transparent);
  }

  .roles-banner-error {
    background: rgba(239, 68, 68, 0.12);
    color: #991b1b;
    border-color: rgba(239, 68, 68, 0.25);
  }

  .roles-panel {
    background: var(--surface-container-low);
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-xl);
    padding: 20px;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.03);
    margin-bottom: 16px;
  }

  .roles-panel-title {
    margin: 0;
    color: var(--on-surface);
    font-size: 1.05rem;
    font-weight: 850;
    display: inline-flex;
    align-items: center;
    gap: 8px;
  }

  .roles-panel-subtitle {
    margin: 8px 0 14px;
    color: var(--on-surface-variant);
    font-size: 0.9rem;
  }

  .roles-form-inline {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
  }

  .roles-input {
    width: 100%;
    min-width: 220px;
    padding: 10px 12px;
    border: 1px solid var(--outline-variant);
    border-radius: 10px;
    background: var(--surface-container-lowest);
    color: var(--on-surface);
    transition: border-color .15s ease, box-shadow .15s ease;
  }

  .roles-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary) 18%, transparent);
  }

  .roles-btn {
    border: none;
    border-radius: 999px;
    font-weight: 800;
    font-size: 0.88rem;
    padding: 10px 16px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: transform .14s ease, opacity .14s ease;
  }

  .roles-btn:hover {
    transform: translateY(-1px);
  }

  .roles-btn .material-symbols-outlined {
    font-size: 17px;
  }

  .roles-btn-primary {
    background: var(--primary);
    color: var(--on-primary);
  }

  .roles-btn-danger {
    background: rgba(239, 68, 68, 0.12);
    color: #b91c1c;
    border: 1px solid rgba(239, 68, 68, 0.28);
  }

  .roles-btn-sm {
    font-size: 0.8rem;
    padding: 8px 12px;
  }

  .roles-limit-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 10px;
    margin-bottom: 14px;
  }

  .roles-limit-card {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 12px;
    background: var(--surface-container-lowest);
    border: 1px solid var(--outline-variant);
    border-radius: 10px;
  }

  .roles-limit-card-alert {
    border-color: rgba(245, 158, 11, 0.48);
    background: #fffdf4;
  }

  .roles-limit-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
  }

  .roles-limit-name {
    text-transform: capitalize;
    font-weight: 800;
    color: var(--on-surface);
    font-size: 0.88rem;
  }

  .roles-limit-badge {
    font-size: 0.74rem;
    color: var(--on-surface-variant);
    padding: 3px 8px;
    border-radius: 999px;
    border: 1px solid var(--outline-variant);
    background: var(--surface-container-low);
  }

  .roles-search-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .roles-search-wrap .material-symbols-outlined {
    color: var(--on-surface-variant);
  }

  .roles-cards-grid {
    display: grid;
    gap: 16px;
  }

  .roles-role-card {
    background: var(--surface-container-low);
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-xl);
    padding: 20px;
    box-shadow: 0 5px 14px rgba(0, 0, 0, 0.03);
  }

  .roles-role-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 14px;
    flex-wrap: wrap;
  }

  .roles-role-title {
    margin: 0;
    color: var(--on-surface);
    text-transform: capitalize;
    font-size: 1.05rem;
    font-weight: 900;
    letter-spacing: -0.01em;
  }

  .roles-role-subtitle {
    margin: 4px 0 6px;
    color: var(--on-surface-variant);
    font-size: 0.86rem;
  }

  .roles-role-meta {
    display: inline-flex;
    border-radius: 999px;
    padding: 4px 10px;
    border: 1px solid var(--outline-variant);
    background: var(--surface-container-lowest);
    color: var(--on-surface-variant);
    font-size: 0.75rem;
    font-weight: 700;
  }

  .roles-super-note {
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px dashed var(--outline-variant);
    background: var(--surface-container-lowest);
    color: var(--on-surface-variant);
    font-size: 0.9rem;
  }

  .roles-module-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 10px;
    margin-bottom: 14px;
  }

  .roles-module-pill {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    background: var(--surface-container-lowest);
    border: 1px solid var(--outline-variant);
    border-radius: 10px;
    cursor: pointer;
    transition: border-color .14s ease, background .14s ease;
  }

  .roles-module-pill:hover {
    border-color: var(--primary);
    background: var(--surface-container);
  }

  .roles-module-pill input {
    width: 17px;
    height: 17px;
    accent-color: var(--primary);
  }

  .roles-module-pill span {
    color: var(--on-surface);
    font-size: 0.9rem;
    font-weight: 560;
  }

  .roles-actions-end {
    display: flex;
    justify-content: flex-end;
  }

  @media (max-width: 1024px) {
    .roles-stat-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width: 740px) {
    .roles-hero {
      flex-direction: column;
      align-items: stretch;
    }

    .roles-stat-grid {
      grid-template-columns: 1fr;
    }

    .roles-actions-end {
      justify-content: stretch;
    }

    .roles-actions-end .roles-btn {
      width: 100%;
      justify-content: center;
    }

    .roles-form-inline .roles-btn {
      width: 100%;
      justify-content: center;
    }
  }
</style>
<script>
  (function() {
    var searchInput = document.getElementById('roleSearchInput');
    var grid = document.getElementById('rolesCardsGrid');
    if (!searchInput || !grid) return;

    searchInput.addEventListener('input', function() {
      var q = String(searchInput.value || '').toLowerCase().trim();
      var cards = grid.querySelectorAll('.roles-role-card');
      cards.forEach(function(card) {
        var role = String(card.getAttribute('data-role') || '').toLowerCase();
        card.style.display = (q === '' || role.indexOf(q) !== -1) ? '' : 'none';
      });
    });
  })();
</script>
