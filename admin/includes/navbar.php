<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<!-- Responsive Desktop Navigation Bar (visible on desktop, hidden on mobile) -->
<nav class="desktop-navbar">
  <div class="navbar-container">
    <!-- Brand/Logo Section -->
    <div class="navbar-brand">
      <img src="../asset/attendance-mark.svg" alt="Attendance Mark" class="navbar-logo">
      <div class="navbar-title">
        <h1>Attendance Admin</h1>
        <span class="navbar-subtitle">Management Console</span>
      </div>
    </div>

    <!-- Main Navigation Items -->
    <ul class="navbar-menu">
      <li><a href="index.php?page=dashboard" class="nav-item <?= $page == 'dashboard' ? 'active' : '' ?>">
          <i class='bx bxs-dashboard'></i><span>Dashboard</span>
        </a></li>

      <li><a href="index.php?page=status" class="nav-item <?= $page == 'status' ? 'active' : '' ?>">
          <i class='bx bx-toggle-left'></i><span>Status</span>
        </a></li>

      <li class="nav-dropdown">
        <button class="nav-item dropdown-toggle" type="button" aria-haspopup="true">
          <i class='bx bx-list-ul'></i><span>Logs</span><i class='bx bx-chevron-down'></i>
        </button>
        <ul class="dropdown-menu">
          <li><a href="index.php?page=logs" class="<?= $page == 'logs' ? 'active' : '' ?>"><i class='bx bx-file'></i>General Logs</a></li>
          <li><a href="index.php?page=chain" class="<?= $page == 'chain' ? 'active' : '' ?>"><i class='bx bx-link-alt'></i>Chain</a></li>
          <li><a href="index.php?page=failed_attempts" class="<?= $page == 'failed_attempts' ? 'active' : '' ?>"><i class='bx bx-error'></i>Failed Logs</a></li>
          <li><a href="index.php?page=clear_logs_ui" class="<?= $page == 'clear_logs_ui' ? 'active' : '' ?>"><i class='bx bx-database'></i>Clear / Backup</a></li>
          <li><a href="index.php?page=clear_tokens_ui" class="<?= $page == 'clear_tokens_ui' ? 'active' : '' ?>"><i class='bx bx-key'></i>Clear Tokens</a></li>
          <li><a href="index.php?page=send_logs_email" class="<?= $page == 'send_logs_email' ? 'active' : '' ?>"><i class='bx bx-mail-send'></i>Email Logs</a></li>
        </ul>
      </li>

      <li class="nav-dropdown">
        <button class="nav-item dropdown-toggle" type="button" aria-haspopup="true">
          <i class='bx bx-book'></i><span>Courses</span><i class='bx bx-chevron-down'></i>
        </button>
        <ul class="dropdown-menu">
          <li><a href="index.php?page=add_course" class="<?= $page == 'add_course' ? 'active' : '' ?>"><i class='bx bx-plus-circle'></i>Add Course</a></li>
          <li><a href="index.php?page=set_active" class="<?= $page == 'set_active' ? 'active' : '' ?>"><i class='bx bx-target-lock'></i>Set Active Course</a></li>
        </ul>
      </li>

      <li><a href="index.php?page=manual_attendance" class="nav-item <?= $page == 'manual_attendance' ? 'active' : '' ?>">
          <i class='bx bx-user-check'></i><span>Manual Attendance</span>
        </a></li>

      <li><a href="index.php?page=announcement" class="nav-item <?= $page == 'announcement' ? 'active' : '' ?>">
          <i class='bx bx-broadcast'></i><span>Announcement</span>
        </a></li>

      <li><a href="index.php?page=unlink_fingerprint" class="nav-item <?= $page == 'unlink_fingerprint' ? 'active' : '' ?>">
          <i class='bx bx-unlink'></i><span>Unlink Fingerprint</span>
        </a></li>

      <li><a href="index.php?page=support_tickets" class="nav-item <?= $page == 'support_tickets' ? 'active' : '' ?>">
          <i class='bx bx-message-dots'></i><span>Support Tickets</span>
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
          if ($ticketCount > 0): ?>
            <span class="nav-badge"><?= $ticketCount ?></span>
          <?php endif; ?>
        </a></li>
    </ul>

    <!-- Right-side User Actions -->
    <div class="navbar-actions">
      <?php
      $isSuperAdmin = isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'superadmin';
      if (!empty($_SESSION['admin_name'])):
        $adminName = htmlspecialchars($_SESSION['admin_name']);
        $adminAvatar = $_SESSION['admin_avatar'] ?? null;
        $initials = trim(array_reduce(explode(' ', $adminName), function ($carry, $part) {
          return $carry . ($part[0] ?? '');
        }, ''));
        $initials = strtoupper(substr($initials, 0, 2));
      ?>
        <div class="navbar-user">
          <button class="user-btn" type="button" aria-haspopup="true" aria-expanded="false" id="navUserToggle">
            <?php if ($adminAvatar): ?>
              <img class="user-avatar" src="<?= htmlspecialchars($adminAvatar) ?>" alt="avatar">
            <?php else: ?>
              <span class="user-initials"><?= $initials ?></span>
            <?php endif; ?>
            <span class="user-name"><?= $adminName ?></span>
            <i class='bx bx-chevron-down'></i>
          </button>

          <div class="user-menu" id="navUserMenu" style="display:none;">
            <a href="index.php?page=profile_settings">
              <i class="bx bx-user"></i>Profile Settings
            </a>
            <?php if ($isSuperAdmin): ?>
              <a href="index.php?page=accounts">
                <i class="bx bx-group"></i>Manage Accounts
              </a>
              <a href="index.php?page=settings">
                <i class="bx bx-cog"></i>System Settings
              </a>
            <?php endif; ?>
            <div class="menu-divider"></div>
            <a href="logout.php" class="menu-logout">
              <i class="bx bx-log-out"></i>Logout
            </a>
          </div>
        </div>
      <?php else: ?>
        <a href="login.php" class="nav-login-link">Login</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<script>
  // Toggle navbar user menu
  document.getElementById('navUserToggle')?.addEventListener('click', function() {
    const menu = document.getElementById('navUserMenu');
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
  });

  // Close menu when clicking outside
  document.addEventListener('click', function(e) {
    const menu = document.getElementById('navUserMenu');
    const btn = document.getElementById('navUserToggle');
    if (menu && btn && !btn.contains(e.target) && !menu.contains(e.target)) {
      menu.style.display = 'none';
    }
  });

  // Handle dropdown toggles in navbar
  document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
    toggle.addEventListener('click', function(e) {
      e.preventDefault();
      const menu = this.nextElementSibling;
      document.querySelectorAll('.dropdown-menu').forEach(m => {
        if (m !== menu) m.style.display = 'none';
      });
      menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
    });
  });

  // Close dropdowns when item is clicked
  document.querySelectorAll('.dropdown-menu a').forEach(link => {
    link.addEventListener('click', function() {
      document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');
    });
  });
</script>
