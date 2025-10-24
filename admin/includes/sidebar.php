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
      <li><a href="index.php?page=failed_attempts" class="<?= $page == 'failed_attempts' ? 'active' : '' ?>"><i class='bx bx-error'></i> Failed Logs</a></li>
      <li><a href="index.php?page=add_course" class="<?= $page == 'add_course' ? 'active' : '' ?>"><i class='bx bx-plus-circle'></i> Add Course</a></li>
      <li><a href="index.php?page=set_active" class="<?= $page == 'set_active' ? 'active' : '' ?>"><i class='bx bx-check-circle'></i> Set Active Course</a></li>
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
  <li><a href="index.php?page=settings" class="<?= $page == 'settings' ? 'active' : '' ?>"><i class='bx bx-cog'></i> Settings</a></li>
  <li><a href="index.php?page=chain" class="<?= $page == 'chain' ? 'active' : '' ?>"><i class='bx bx-shield'></i> Chain</a></li>
      <li><a href="logout.php"><i class='bx bx-log-out'></i> Logout</a></li>
    </ul>
  </nav>
</div>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>
