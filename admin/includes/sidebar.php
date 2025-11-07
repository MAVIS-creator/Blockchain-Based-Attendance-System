<?php
$ticketCount = 0;
$ticketsFile = __DIR__ . '/../support_tickets.json';
if (file_exists($ticketsFile)) {
    $tickets = json_decode(file_get_contents($ticketsFile), true);
    foreach ($tickets as $t) {
        if (!($t['resolved'] ?? false)) {
            $ticketCount++;
        }
    }
}
?>

<div class="sidebar <?= isset($_COOKIE['sidebar_collapsed']) && $_COOKIE['sidebar_collapsed'] == 'true' ? 'collapsed' : '' ?>">
  <div class="sidebar-header">
    <span class="toggle-btn" onclick="toggleSidebar()"><i class='bx bx-menu'></i></span>
    <h2>Admin Panel</h2>
  </div>
  <nav class="sidebar-nav">
    <ul>
      <li><a href="index.php?page=dashboard" class="<?= $page == 'dashboard' ? 'active' : '' ?>"><i class='bx bxs-dashboard'></i> Dashboard</a></li>
      <li><a href="index.php?page=status" class="<?= $page == 'status' ? 'active' : '' ?>"><i class='bx bx-toggle-left'></i> Status</a></li>
      <li><a href="index.php?page=logs" class="<?= $page == 'logs' ? 'active' : '' ?>"><i class='bx bx-list-ul'></i> Logs</a></li>
  <li><a href="index.php?page=clear_logs_ui" class="<?= $page == 'clear_logs_ui' ? 'active' : '' ?>"><i class='bx bx-trash'></i> Clear / Backup Logs</a></li>
  <li><a href="index.php?page=clear_tokens_ui" class="<?= $page == 'clear_tokens_ui' ? 'active' : '' ?>"><i class='bx bx-key'></i> Clear Attendance Tokens</a></li>
      <li><a href="index.php?page=failed_attempts" class="<?= $page == 'failed_attempts' ? 'active' : '' ?>"><i class='bx bx-error'></i> Failed Logs</a></li>
        <li>
          <details class="sidebar-group" <?= in_array($page, ['logs','chain','failed_attempts','clear_logs_ui','clear_tokens_ui','send_logs_email']) ? 'open' : '' ?> >
            <summary><i class='bx bx-list-ul'></i> Logs</summary>
            <ul>
              <li><a href="index.php?page=logs" class="<?= $page == 'logs' ? 'active' : '' ?>">General Logs</a></li>
              <li><a href="index.php?page=chain" class="<?= $page == 'chain' ? 'active' : '' ?>">Chain</a></li>
              <li><a href="index.php?page=failed_attempts" class="<?= $page == 'failed_attempts' ? 'active' : '' ?>">Failed Logs</a></li>
              <li><a href="index.php?page=clear_logs_ui" class="<?= $page == 'clear_logs_ui' ? 'active' : '' ?>">Clear / Backup Logs</a></li>
              <li><a href="index.php?page=clear_tokens_ui" class="<?= $page == 'clear_tokens_ui' ? 'active' : '' ?>">Clear Attendance Tokens</a></li>
              <li><a href="index.php?page=send_logs_email" class="<?= $page == 'send_logs_email' ? 'active' : '' ?>">Send Logs via Email</a></li>
            </ul>
          </details>
        </li>
        <li>
          <details class="sidebar-group" <?= in_array($page, ['add_course','set_active']) ? 'open' : '' ?> >
            <summary><i class='bx bx-book'></i> Courses</summary>
            <ul>
              <li><a href="index.php?page=add_course" class="<?= $page == 'add_course' ? 'active' : '' ?>">Add Course</a></li>
              <li><a href="index.php?page=set_active" class="<?= $page == 'set_active' ? 'active' : '' ?>">Set Active Course</a></li>
            </ul>
          </details>
        </li>
      <li><a href="index.php?page=manual_attendance" class="<?= $page == 'manual_attendance' ? 'active' : '' ?>"><i class='bx bx-user-check'></i> Manual Attendance</a></li>
      <li>
        <a href="index.php?page=support_tickets" class="<?= $page == 'support_tickets' ? 'active' : '' ?>">
          <i class='bx bx-message-dots'></i> Support Tickets
          <?php if ($ticketCount > 0): ?>
            <span style="background:#dc3545;color:#fff;padding:2px 8px;border-radius:12px;font-size:0.75em;margin-left:5px;"><?= $ticketCount ?></span>
          <?php endif; ?>
        </a>
      </li>
      <li><a href="index.php?page=announcement" class="<?= $page == 'announcement' ? 'active' : '' ?>"><i class='bx bx-broadcast'></i> Announcement</a></li>
      <li><a href="index.php?page=unlink_fingerprint" class="<?= $page == 'unlink_fingerprint' ? 'active' : '' ?>"><i class='bx bx-unlink'></i> Unlink Fingerprint</a></li>
  <li><a href="index.php?page=accounts" class="<?= $page == 'accounts' ? 'active' : '' ?>"><i class='bx bx-user-circle'></i> Accounts</a></li>
  <?php if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'superadmin'): ?>
    <li><a href="index.php?page=settings" class="<?= $page == 'settings' ? 'active' : '' ?>"><i class='bx bx-cog'></i> Settings</a></li>
  <?php endif; ?>
  <li><a href="index.php?page=chain" class="<?= $page == 'chain' ? 'active' : '' ?>"><i class='bx bx-shield'></i> Chain</a></li>
  <li><a href="index.php?page=accounts" class="<?= $page == 'accounts' ? 'active' : '' ?>"><i class='bx bx-user-circle'></i> Accounts</a></li>
  <li style="margin-top:8px;">&nbsp;</li>
    </ul>
  </nav>
</div>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>
