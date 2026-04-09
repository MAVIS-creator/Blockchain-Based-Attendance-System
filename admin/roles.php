<?php
if (!isset($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? 'admin') !== 'superadmin') {
    header('Location: login.php');
    exit;
}

$permissionsFile = admin_permissions_file();
$permissions = admin_load_permissions_cached(0); // load fresh
if (!is_array($permissions)) {
    $permissions = ['admin' => []];
}
if (!isset($permissions['admin']) || !is_array($permissions['admin'])) {
    $permissions['admin'] = [];
}

$assignablePages = admin_assignable_pages();
$assignableKeys = array_fill_keys(array_keys($assignablePages), true);

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
        } else {
            $permissions[$role] = ['dashboard', 'status', 'profile_settings'];
            if (@file_put_contents($permissionsFile, json_encode($permissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
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
                    admin_log_action('Roles', 'Role Deleted', "Deleted role '{$role}'.");
                    $message = "Role '{$role}' deleted successfully.";
                } else {
                    $error = 'Failed to delete role from storage.';
                }
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
?>
<div class="content flex-grow-1 p-4 p-md-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Role Privileges Configuration</h1>
    </div>

    <?php if ($message): ?>
        <div style="background: var(--primary-container); color: var(--on-primary-container); padding: 16px; border-radius: var(--radius-m); margin-bottom: 24px; font-weight: 500;">
            <span class="material-symbols-outlined" style="vertical-align: middle; margin-right: 8px;">check_circle</span>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div style="background: rgba(239,68,68,0.12); color: #991b1b; padding: 16px; border-radius: var(--radius-m); margin-bottom: 24px; font-weight: 500; border: 1px solid rgba(239,68,68,0.25);">
            <span class="material-symbols-outlined" style="vertical-align: middle; margin-right: 8px;">error</span>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div style="background: var(--surface-container-low); border: 1px solid var(--outline-variant); border-radius: var(--radius-xl); padding: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); margin-bottom: 20px;">
        <h3 style="margin: 0 0 6px 0; font-size: 1.1rem; font-weight: 700; color: var(--on-surface);">Create New Role</h3>
        <p style="margin: 0 0 14px 0; color: var(--on-surface-variant); font-size: 0.9rem;">Add custom roles (e.g. manager, helpdesk, registrar). You can configure each role below.</p>

        <form method="POST" action="index.php?page=roles" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="role_action" value="create_role">
            <input
                type="text"
                name="new_role"
                placeholder="new role (e.g. manager)"
                required
                pattern="[a-zA-Z][a-zA-Z0-9_\-]{2,31}"
                style="min-width:280px; padding: 10px 12px; border: 1px solid var(--outline-variant); border-radius: var(--radius-m); background: var(--surface-container-lowest); color: var(--on-surface);"
            >
            <button type="submit" style="background: var(--primary); color: var(--on-primary); padding: 10px 18px; border: none; border-radius: var(--radius-full); font-weight: 600; font-size: 0.95rem; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                <span class="material-symbols-outlined" style="font-size: 18px;">add</span>
                Add Role
            </button>
        </form>
    </div>

    <div style="display:grid; gap:20px;">
        <?php foreach ($roles as $role): ?>
            <?php
                $isSuper = $role === 'superadmin';
                $isBuiltIn = in_array($role, ['admin', 'superadmin'], true);
                $allowedForRole = is_array($permissions[$role] ?? null) ? $permissions[$role] : [];
            ?>
            <div style="background: var(--surface-container-low); border: 1px solid var(--outline-variant); border-radius: var(--radius-xl); padding: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.02);">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
                    <div>
                        <h3 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--on-surface); text-transform: capitalize;"><?= htmlspecialchars($role) ?></h3>
                        <p style="margin: 4px 0 0 0; color: var(--on-surface-variant); font-size: 0.85rem;">
                            <?= $isSuper
                                ? 'Full system access. Not editable here.'
                                : 'Pick which pages this role can access.' ?>
                        </p>
                    </div>

                    <?php if (!$isBuiltIn): ?>
                        <form method="POST" action="index.php?page=roles" onsubmit="return confirm('Delete role <?= htmlspecialchars($role) ?>?');" style="margin:0;">
                            <input type="hidden" name="role_action" value="delete_role">
                            <input type="hidden" name="target_role" value="<?= htmlspecialchars($role) ?>">
                            <button type="submit" style="background: rgba(239,68,68,0.12); color: #b91c1c; padding: 8px 14px; border: 1px solid rgba(239,68,68,0.28); border-radius: var(--radius-full); font-weight: 700; font-size: 0.85rem; cursor: pointer; display:inline-flex; align-items:center; gap:6px;">
                                <span class="material-symbols-outlined" style="font-size:16px;">delete</span>
                                Delete Role
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if ($isSuper): ?>
                    <div style="padding: 12px 14px; border-radius: var(--radius-m); background: var(--surface-container-lowest); border: 1px dashed var(--outline-variant); color: var(--on-surface-variant); font-size: 0.9rem;">
                        Superadmin pages are intentionally not governed by role permissions.
                    </div>
                <?php else: ?>
                    <form method="POST" action="index.php?page=roles">
                        <input type="hidden" name="role_action" value="save_permissions">
                        <input type="hidden" name="target_role" value="<?= htmlspecialchars($role) ?>">

                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; margin-bottom: 18px;">
                            <?php foreach ($assignablePages as $pageKey => $meta): ?>
                                <label style="display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--surface-container-lowest); border: 1px solid var(--outline-variant); border-radius: var(--radius-m); cursor: pointer;">
                                    <input type="checkbox" name="allowed_modules[]" value="<?= htmlspecialchars($pageKey) ?>" <?= in_array($pageKey, $allowedForRole, true) ? 'checked' : '' ?> style="width: 17px; height: 17px; accent-color: var(--primary);">
                                    <span style="font-weight: 500; font-size: 0.92rem; color: var(--on-surface);"><?= htmlspecialchars((string)($meta['label'] ?? $pageKey)) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div style="display: flex; justify-content: flex-end;">
                            <button type="submit" style="background: var(--primary); color: var(--on-primary); padding: 10px 20px; border: none; border-radius: var(--radius-full); font-weight: 700; font-size: 0.92rem; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                                <span class="material-symbols-outlined" style="font-size: 18px;">save</span>
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
    label:hover {
        border-color: var(--primary) !important;
        background: var(--surface-container) !important;
    }
</style>
