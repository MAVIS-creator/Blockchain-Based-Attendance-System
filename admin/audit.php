<?php
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_role'] !== 'superadmin') {
    header('Location: login.php');
    exit;
}

$auditSource = 'local';
$allLogs = [];

// Try Supabase first if hybrid admin read is enabled
$hybridReadFile = __DIR__ . '/includes/hybrid_admin_read.php';
if (file_exists($hybridReadFile)) {
    require_once $hybridReadFile;
    if (function_exists('hybrid_admin_read_enabled') && hybrid_admin_read_enabled()) {
        $rows = null;
        $err = null;
        $ok = hybrid_supabase_select('admin_audit_logs', [
            'select' => 'timestamp,admin_user,admin_role,ip_address,category,action,details',
            'order' => 'timestamp.desc',
            'limit' => '500'
        ], $rows, $err);
        if ($ok && is_array($rows) && count($rows) > 0) {
            foreach ($rows as $row) {
                $allLogs[] = [
                    'timestamp' => str_replace('T', ' ', substr((string)($row['timestamp'] ?? ''), 0, 19)),
                    'admin' => (string)($row['admin_user'] ?? ''),
                    'role' => (string)($row['admin_role'] ?? ''),
                    'ip' => (string)($row['ip_address'] ?? ''),
                    'category' => (string)($row['category'] ?? ''),
                    'action' => (string)($row['action'] ?? ''),
                    'details' => (string)($row['details'] ?? '')
                ];
            }
            $auditSource = 'supabase';
        }
    }
}

// Fallback to local file
if (empty($allLogs)) {
    $auditFile = admin_audit_file();
    $allLogs = file_exists($auditFile) ? json_decode(file_get_contents($auditFile), true) : [];
    if (!is_array($allLogs)) $allLogs = [];
}

// Extract unique categories and admins for filter dropdowns
$categories = array_unique(array_column($allLogs, 'category'));
$admins = array_unique(array_column($allLogs, 'admin'));
sort($categories);
sort($admins);
?>

<div class="content flex-grow-1 p-4 p-md-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Action Audit Log
            <span style="font-size: 0.55rem; vertical-align: middle; padding: 3px 10px; border-radius: 10px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; margin-left: 8px;
                <?= $auditSource === 'supabase' ? 'background: rgba(34,197,94,0.15); color: #16a34a;' : 'background: rgba(59,130,246,0.15); color: #2563eb;' ?>
            "><?= $auditSource === 'supabase' ? '☁ SUPABASE' : '📁 LOCAL' ?></span>
        </h1>
    </div>

    <!-- Filters -->
    <div style="display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 24px;">
        <div style="flex: 1; min-width: 180px;">
            <label style="display:block; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--on-surface-variant); margin-bottom: 6px;">Filter by Admin</label>
            <select id="filterAdmin" onchange="applyFilters()" style="width:100%; padding: 10px 14px; border: 1px solid var(--outline-variant); border-radius: var(--radius-m); background: var(--surface-container-lowest); color: var(--on-surface); font-size: 0.9rem;">
                <option value="">All Admins</option>
                <?php foreach ($admins as $a): ?>
                    <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex: 1; min-width: 180px;">
            <label style="display:block; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--on-surface-variant); margin-bottom: 6px;">Filter by Category</label>
            <select id="filterCategory" onchange="applyFilters()" style="width:100%; padding: 10px 14px; border: 1px solid var(--outline-variant); border-radius: var(--radius-m); background: var(--surface-container-lowest); color: var(--on-surface); font-size: 0.9rem;">
                <option value="">All Categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex: 1; min-width: 180px;">
            <label style="display:block; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--on-surface-variant); margin-bottom: 6px;">Search Details</label>
            <input type="text" id="filterSearch" oninput="applyFilters()" placeholder="Search in actions & details..." style="width:100%; padding: 10px 14px; border: 1px solid var(--outline-variant); border-radius: var(--radius-m); background: var(--surface-container-lowest); color: var(--on-surface); font-size: 0.9rem;">
        </div>
    </div>

    <!-- Results count -->
    <div id="resultsCount" style="font-size: 0.85rem; color: var(--on-surface-variant); margin-bottom: 16px;"></div>

    <?php if (empty($allLogs)): ?>
        <div style="background: var(--surface-container-low); border: 1px solid var(--outline-variant); border-radius: var(--radius-xl); padding: 48px; text-align: center;">
            <span class="material-symbols-outlined" style="font-size: 56px; color: var(--outline); margin-bottom: 16px; display: block;">policy</span>
            <h3 style="font-weight: 700; color: var(--on-surface); margin-bottom: 8px;">No Audit Entries Yet</h3>
            <p style="color: var(--on-surface-variant); font-size: 0.9rem;">Admin actions will be recorded here as they occur across the system.</p>
        </div>
    <?php else: ?>
        <div style="background: var(--surface-container-low); border: 1px solid var(--outline-variant); border-radius: var(--radius-xl); overflow: hidden;">
            <div style="overflow-x: auto;">
                <table id="auditTable" style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
                    <thead>
                        <tr style="background: var(--surface-container); border-bottom: 2px solid var(--outline-variant);">
                            <th style="padding: 14px 16px; text-align: left; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--on-surface-variant);">Timestamp</th>
                            <th style="padding: 14px 16px; text-align: left; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--on-surface-variant);">Admin</th>
                            <th style="padding: 14px 16px; text-align: left; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--on-surface-variant);">Role</th>
                            <th style="padding: 14px 16px; text-align: left; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--on-surface-variant);">Category</th>
                            <th style="padding: 14px 16px; text-align: left; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--on-surface-variant);">Action</th>
                            <th style="padding: 14px 16px; text-align: left; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--on-surface-variant);" class="mobile-hide-col">Details</th>
                            <th style="padding: 14px 16px; text-align: left; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--on-surface-variant);" class="mobile-hide-col">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allLogs as $i => $log): ?>
                            <tr class="audit-row" 
                                data-admin="<?= htmlspecialchars($log['admin'] ?? '') ?>"
                                data-category="<?= htmlspecialchars($log['category'] ?? '') ?>"
                                data-search="<?= htmlspecialchars(strtolower(($log['action'] ?? '') . ' ' . ($log['details'] ?? ''))) ?>"
                                style="border-bottom: 1px solid var(--outline-variant); transition: background 0.15s;">
                                <td style="padding: 12px 16px; white-space: nowrap; color: var(--on-surface-variant); font-size: 0.82rem;"><?= htmlspecialchars($log['timestamp'] ?? '-') ?></td>
                                <td style="padding: 12px 16px; font-weight: 600; color: var(--on-surface);"><?= htmlspecialchars($log['admin'] ?? '-') ?></td>
                                <td style="padding: 12px 16px;">
                                    <?php
                                    $roleStyle = (($log['role'] ?? '') === 'superadmin')
                                        ? 'background: var(--primary-container); color: var(--on-primary-container);'
                                        : 'background: var(--secondary-container); color: var(--on-secondary-container);';
                                    ?>
                                    <span style="display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; <?= $roleStyle ?>"><?= htmlspecialchars($log['role'] ?? '-') ?></span>
                                </td>
                                <td style="padding: 12px 16px;">
                                    <span style="display: inline-block; padding: 4px 12px; border-radius: 8px; font-size: 0.78rem; font-weight: 600;
                                        <?php
                                        $cat = strtolower($log['category'] ?? '');
                                        if ($cat === 'attendance') echo 'background: rgba(34,197,94,0.12); color: #16a34a;';
                                        elseif ($cat === 'accounts') echo 'background: rgba(59,130,246,0.12); color: #2563eb;';
                                        elseif ($cat === 'tokens') echo 'background: rgba(249,115,22,0.12); color: #ea580c;';
                                        elseif ($cat === 'settings') echo 'background: rgba(168,85,247,0.12); color: #9333ea;';
                                        elseif ($cat === 'courses') echo 'background: rgba(236,72,153,0.12); color: #db2777;';
                                        elseif ($cat === 'roles') echo 'background: rgba(20,184,166,0.12); color: #0d9488;';
                                        else echo 'background: var(--surface-container); color: var(--on-surface-variant);';
                                        ?>
                                    "><?= htmlspecialchars($log['category'] ?? '-') ?></span>
                                </td>
                                <td style="padding: 12px 16px; font-weight: 500; color: var(--on-surface);"><?= htmlspecialchars($log['action'] ?? '-') ?></td>
                                <td style="padding: 12px 16px; color: var(--on-surface-variant); max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" class="mobile-hide-col" title="<?= htmlspecialchars($log['details'] ?? '') ?>"><?= htmlspecialchars($log['details'] ?? '-') ?></td>
                                <td style="padding: 12px 16px; color: var(--on-surface-variant); font-family: monospace; font-size: 0.8rem;" class="mobile-hide-col"><?= htmlspecialchars($log['ip'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function applyFilters() {
    const adminFilter = document.getElementById('filterAdmin').value.toLowerCase();
    const categoryFilter = document.getElementById('filterCategory').value.toLowerCase();
    const searchFilter = document.getElementById('filterSearch').value.toLowerCase();
    const rows = document.querySelectorAll('.audit-row');
    let visible = 0;
    
    rows.forEach(row => {
        const admin = (row.dataset.admin || '').toLowerCase();
        const category = (row.dataset.category || '').toLowerCase();
        const search = (row.dataset.search || '').toLowerCase();
        
        let show = true;
        if (adminFilter && admin !== adminFilter) show = false;
        if (categoryFilter && category !== categoryFilter) show = false;
        if (searchFilter && search.indexOf(searchFilter) === -1) show = false;
        
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    
    document.getElementById('resultsCount').textContent = 
        `Showing ${visible} of ${rows.length} entries`;
}

// Add hover effect
document.querySelectorAll('.audit-row').forEach(row => {
    row.addEventListener('mouseenter', () => row.style.background = 'var(--surface-container)');
    row.addEventListener('mouseleave', () => row.style.background = '');
});

// Init count
applyFilters();
</script>
