<?php
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_role'] !== 'superadmin') {
    header('Location: login.php');
    exit;
}

$permissionsFile = admin_permissions_file();
$permissions = admin_load_permissions_cached(0); // Load fresh
$adminAllowed = $permissions['admin'] ?? [];

$availableModules = [
    'dashboard' => 'Dashboard',
    'status' => 'Status Monitor',
    'request_timings' => 'Request Timings',
    'logs' => 'General Logs',
    'chain' => 'Blockchain Ledger',
    'failed_attempts' => 'Failed Verification Logs',
    'clear_logs_ui' => 'Clear / Backup Logs',
    'clear_tokens_ui' => 'Access Tokens Management',
    'send_logs_email' => 'Email Reports',
    'add_course' => 'Add Course',
    'set_active' => 'Set Active Course',
    'manual_attendance' => 'Manual Attendance Tool',
    'announcement' => 'Broadcast Announcements',
    'unlink_fingerprint' => 'Unlink Fingerprints',
    'patcher' => 'Patcher Studio',
    'support_tickets' => 'Support Tickets',
    'profile_settings' => 'Profile Settings'
];

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_roles'])) {
    $newAllowed = $_POST['allowed_modules'] ?? [];
    $sanitized = [];
    foreach ($newAllowed as $mod) {
        if (isset($availableModules[$mod])) {
            $sanitized[] = $mod;
        }
    }
    $permissions['admin'] = $sanitized;
    if (file_put_contents($permissionsFile, json_encode($permissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)) {
        admin_log_action('Roles', 'Permissions Updated', 'Superadmin updated allowed modules for the Admin role.');
        // Unset APCu cache if possible so changes reflect immediately
        if (function_exists('apcu_delete')) {
            // Find key? We can just let time update it or force clear
            apcu_clear_cache(); 
        }
        $adminAllowed = $sanitized;
        $message = "Role permissions updated successfully!";
    } else {
        $message = "Failed to write permissions configuration.";
    }
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

    <div style="background: var(--surface-container-low); border: 1px solid var(--outline-variant); border-radius: var(--radius-xl); padding: 32px; box-shadow: 0 4px 12px rgba(0,0,0,0.02);">
        
        <div style="margin-bottom: 24px; border-bottom: 1px solid var(--outline-variant); padding-bottom: 16px;">
            <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: var(--on-surface);">Standard Admin Role</h3>
            <p style="margin: 4px 0 0 0; color: var(--on-surface-variant); font-size: 0.9rem;">Select which system modules standard administrators are allowed to access.</p>
        </div>

        <form method="POST" action="index.php?page=roles">
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-bottom: 32px;">
                <?php foreach ($availableModules as $key => $label): ?>
                    <label style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: var(--surface-container-lowest); border: 1px solid var(--outline-variant); border-radius: var(--radius-m); cursor: pointer; transition: all 0.2s;">
                        <input type="checkbox" name="allowed_modules[]" value="<?= htmlspecialchars($key) ?>" <?= in_array($key, $adminAllowed) ? 'checked' : '' ?> style="width: 18px; height: 18px; accent-color: var(--primary);">
                        <span style="font-weight: 500; font-size: 0.95rem; color: var(--on-surface);"><?= htmlspecialchars($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div style="display: flex; justify-content: flex-end;">
                <button type="submit" name="update_roles" style="background: var(--primary); color: var(--on-primary); padding: 12px 32px; border: none; border-radius: var(--radius-full); font-weight: 600; font-size: 1rem; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: opacity 0.2s;">
                    <span class="material-symbols-outlined" style="font-size: 20px;">save</span>
                    Save Restrictions
                </button>
            </div>
        </form>
    </div>
</div>
<style>
    label:hover {
        border-color: var(--primary) !important;
        background: var(--surface-container) !important;
    }
</style>
