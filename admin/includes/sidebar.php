<?php
$ticketCount = 0;
$ticketsFile = __DIR__ . '/../support_tickets.json';
if (file_exists($ticketsFile)) {
    $tickets = json_decode(file_get_contents($ticketsFile), true);
    if (is_array($tickets)) {
        foreach ($tickets as $t) {
            if (!($t['resolved'] ?? false)) {
                $ticketCount++;
            }
        }
    }
}
$isSuperAdmin = isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'superadmin';
?>

<!-- Mobile Sidebar Overlay -->
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<!-- Mobile Sidebar Drawer -->
<div class="sidebar <?= isset($_COOKIE['sidebar_collapsed']) && $_COOKIE['sidebar_collapsed'] == 'true' ? '' : '' ?>">
  <div class="sidebar-header">
    <div style="display:flex;align-items:center;">
      <div class="sidebar-brand-icon">
        <span class="material-symbols-outlined">shield</span>
      </div>
      <div>
        <h2>Attendance Admin</h2>
        <span style="font-size:0.6rem;text-transform:uppercase;letter-spacing:0.08em;color:rgba(178,200,233,0.6);font-weight:600;">Smart Attendance</span>
      </div>
    </div>
    <button class="sidebar-close-btn" onclick="toggleSidebar()">
      <span class="material-symbols-outlined">close</span>
    </button>
  </div>

  <nav class="sidebar-nav">
    <ul>
      <li><a href="index.php?page=dashboard" class="<?= $page == 'dashboard' ? 'active' : '' ?>">
        <span class="material-symbols-outlined">dashboard</span><span class="label-text">Dashboard</span>
      </a></li>

      <li><a href="index.php?page=status" class="<?= $page == 'status' ? 'active' : '' ?>">
        <span class="material-symbols-outlined">analytics</span><span class="label-text">Status</span>
      </a></li>

      <li>
        <details class="sidebar-group" <?= in_array($page, ['logs','chain','failed_attempts','clear_logs_ui','clear_tokens_ui','send_logs_email']) ? 'open' : '' ?>>
          <summary><span class="material-symbols-outlined">history_edu</span><span class="label-text">Logs</span></summary>
          <ul>
            <li><a href="index.php?page=logs" class="<?= $page == 'logs' ? 'active' : '' ?>"><span class="material-symbols-outlined">description</span><span class="label-text">General Logs</span></a></li>
            <li><a href="index.php?page=chain" class="<?= $page == 'chain' ? 'active' : '' ?>"><span class="material-symbols-outlined">link</span><span class="label-text">Chain</span></a></li>
            <li><a href="index.php?page=failed_attempts" class="<?= $page == 'failed_attempts' ? 'active' : '' ?>"><span class="material-symbols-outlined">error</span><span class="label-text">Failed Logs</span></a></li>
            <li><a href="index.php?page=clear_logs_ui" class="<?= $page == 'clear_logs_ui' ? 'active' : '' ?>"><span class="material-symbols-outlined">database</span><span class="label-text">Clear / Backup</span></a></li>
            <li><a href="index.php?page=clear_tokens_ui" class="<?= $page == 'clear_tokens_ui' ? 'active' : '' ?>"><span class="material-symbols-outlined">key</span><span class="label-text">Clear Tokens</span></a></li>
            <li><a href="index.php?page=send_logs_email" class="<?= $page == 'send_logs_email' ? 'active' : '' ?>"><span class="material-symbols-outlined">mail</span><span class="label-text">Email Logs</span></a></li>
          </ul>
        </details>
      </li>

      <li>
        <details class="sidebar-group" <?= in_array($page, ['add_course','set_active']) ? 'open' : '' ?>>
          <summary><span class="material-symbols-outlined">menu_book</span><span class="label-text">Courses</span></summary>
          <ul>
            <li><a href="index.php?page=add_course" class="<?= $page == 'add_course' ? 'active' : '' ?>"><span class="material-symbols-outlined">add_circle</span><span class="label-text">Add Course</span></a></li>
            <li><a href="index.php?page=set_active" class="<?= $page == 'set_active' ? 'active' : '' ?>"><span class="material-symbols-outlined">gps_fixed</span><span class="label-text">Set Active Course</span></a></li>
          </ul>
        </details>
      </li>

      <li><a href="index.php?page=manual_attendance" class="<?= $page == 'manual_attendance' ? 'active' : '' ?>">
        <span class="material-symbols-outlined">touch_app</span><span class="label-text">Manual Attendance</span>
      </a></li>

      <li><a href="index.php?page=announcement" class="<?= $page == 'announcement' ? 'active' : '' ?>">
        <span class="material-symbols-outlined">campaign</span><span class="label-text">Announcement</span>
      </a></li>

      <li><a href="index.php?page=unlink_fingerprint" class="<?= $page == 'unlink_fingerprint' ? 'active' : '' ?>">
        <span class="material-symbols-outlined">link_off</span><span class="label-text">Unlink Fingerprint</span>
      </a></li>

      <li><a href="index.php?page=support_tickets" class="<?= $page == 'support_tickets' ? 'active' : '' ?>">
        <span class="material-symbols-outlined">confirmation_number</span><span class="label-text">Support Tickets</span>
        <?php if ($ticketCount > 0): ?>
          <span class="badge badge-danger"><?= $ticketCount ?></span>
        <?php endif; ?>
      </a></li>

      <?php if ($isSuperAdmin): ?>
      <li><a href="index.php?page=accounts" class="<?= $page == 'accounts' ? 'active' : '' ?>">
        <span class="material-symbols-outlined">group</span><span class="label-text">Manage Accounts</span>
      </a></li>

      <li><a href="index.php?page=settings" class="<?= $page == 'settings' ? 'active' : '' ?>">
        <span class="material-symbols-outlined">settings</span><span class="label-text">System Settings</span>
      </a></li>
      <?php endif; ?>
    </ul>
  </nav>

  <div class="sidebar-bottom">
    <ul style="list-style:none;padding:0;margin:0;">
      <li><a href="index.php?page=profile_settings" style="display:flex;align-items:center;gap:12px;padding:12px 16px;color:rgba(178,200,233,0.8);border-radius:8px;font-size:0.9rem;transition:all 0.2s;">
        <span class="material-symbols-outlined">person</span><span>Profile Settings</span>
      </a></li>
      <li><a href="logout.php" style="display:flex;align-items:center;gap:12px;padding:12px 16px;color:#fca5a5;border-radius:8px;font-size:0.9rem;transition:all 0.2s;">
        <span class="material-symbols-outlined">logout</span><span>Logout</span>
      </a></li>
    </ul>
  </div>
</div>
