<?php if (session_status() === PHP_SESSION_NONE) { if (function_exists('admin_configure_session')) admin_configure_session(); else session_start(); } ?>
<?php $isSuperAdmin = isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'superadmin'; ?>
<?php
require_once __DIR__ . '/../cache_helpers.php';
$ticketCount = admin_support_ticket_count(15);
?>
<!-- Desktop Navigation Bar (visible ≥1024px, hidden on mobile) -->
<nav class="desktop-navbar">
  <div class="navbar-container">
    <!-- Brand -->
    <div class="navbar-brand">
      <img src="../asset/attendance-mark.svg" alt="Attendance Mark" class="navbar-logo" onerror="this.style.display='none'">
      <div class="navbar-title">
        <h1>Attendance Admin</h1>
        <span class="navbar-subtitle">Smart Attendance</span>
      </div>
    </div>

    <!-- Main Nav Items -->
    <ul class="navbar-menu">
      <li><a href="index.php?page=dashboard" class="nav-item <?= $page == 'dashboard' ? 'active' : '' ?>">
          <span class="material-symbols-outlined">dashboard</span><span>Dashboard</span>
        </a></li>

      <li><a href="index.php?page=status" class="nav-item <?= $page == 'status' ? 'active' : '' ?>">
          <span class="material-symbols-outlined">analytics</span><span>Status</span>
        </a></li>

      <?php if ($isSuperAdmin): ?>
        <li><a href="index.php?page=status_debug" class="nav-item <?= $page == 'status_debug' ? 'active' : '' ?>">
            <span class="material-symbols-outlined">troubleshoot</span><span>Diagnostics</span>
          </a></li>
      <?php endif; ?>

      <li class="nav-dropdown">
        <button class="nav-item dropdown-toggle" type="button" aria-haspopup="true">
          <span class="material-symbols-outlined">history_edu</span><span>Logs</span><i class='bx bx-chevron-down' style="font-size:0.8rem;margin-left:2px;"></i>
        </button>
        <ul class="dropdown-menu">
          <li><a href="index.php?page=request_timings" class="<?= $page == 'request_timings' ? 'active' : '' ?>"><span class="material-symbols-outlined" style="font-size:1rem;">timer</span>Request Timings</a></li>
          <li><a href="index.php?page=logs" class="<?= $page == 'logs' ? 'active' : '' ?>"><span class="material-symbols-outlined" style="font-size:1rem;">description</span>General Logs</a></li>
          <li><a href="index.php?page=chain" class="<?= $page == 'chain' ? 'active' : '' ?>"><span class="material-symbols-outlined" style="font-size:1rem;">link</span>Chain</a></li>
          <li><a href="index.php?page=failed_attempts" class="<?= $page == 'failed_attempts' ? 'active' : '' ?>"><span class="material-symbols-outlined" style="font-size:1rem;">error</span>Failed Logs</a></li>
          <li><a href="index.php?page=clear_logs_ui" class="<?= $page == 'clear_logs_ui' ? 'active' : '' ?>"><span class="material-symbols-outlined" style="font-size:1rem;">database</span>Clear / Backup</a></li>
          <li><a href="index.php?page=clear_tokens_ui" class="<?= $page == 'clear_tokens_ui' ? 'active' : '' ?>"><span class="material-symbols-outlined" style="font-size:1rem;">key</span>Clear Tokens</a></li>
          <li><a href="index.php?page=send_logs_email" class="<?= $page == 'send_logs_email' ? 'active' : '' ?>"><span class="material-symbols-outlined" style="font-size:1rem;">mail</span>Email Logs</a></li>
        </ul>
      </li>

      <li class="nav-dropdown">
        <button class="nav-item dropdown-toggle" type="button" aria-haspopup="true">
          <span class="material-symbols-outlined">menu_book</span><span>Courses</span><i class='bx bx-chevron-down' style="font-size:0.8rem;margin-left:2px;"></i>
        </button>
        <ul class="dropdown-menu">
          <li><a href="index.php?page=add_course" class="<?= $page == 'add_course' ? 'active' : '' ?>"><span class="material-symbols-outlined" style="font-size:1rem;">add_circle</span>Add Course</a></li>
          <li><a href="index.php?page=set_active" class="<?= $page == 'set_active' ? 'active' : '' ?>"><span class="material-symbols-outlined" style="font-size:1rem;">gps_fixed</span>Set Active Course</a></li>
        </ul>
      </li>

      <li class="nav-dropdown">
        <button class="nav-item dropdown-toggle" type="button" aria-haspopup="true">
          <span class="material-symbols-outlined">build</span><span>Tools</span><i class='bx bx-chevron-down' style="font-size:0.8rem;margin-left:2px;"></i>
        </button>
        <ul class="dropdown-menu">
          <li><a href="index.php?page=manual_attendance" class="<?= $page == 'manual_attendance' ? 'active' : '' ?>"><span class="material-symbols-outlined" style="font-size:1rem;">touch_app</span>Manual Attendance</a></li>
          <?php if ($isSuperAdmin): ?>
            <li><a href="index.php?page=geofence" class="<?= $page == 'geofence' ? 'active' : '' ?>"><span class="material-symbols-outlined" style="font-size:1rem;">distance</span>Geo-fence</a></li>
          <?php endif; ?>
          <li><a href="index.php?page=announcement" class="<?= $page == 'announcement' ? 'active' : '' ?>"><span class="material-symbols-outlined" style="font-size:1rem;">campaign</span>Announcement</a></li>
          <li><a href="index.php?page=unlink_fingerprint" class="<?= $page == 'unlink_fingerprint' ? 'active' : '' ?>"><span class="material-symbols-outlined" style="font-size:1rem;">link_off</span>Unlink Fingerprint</a></li>
          <li><a href="index.php?page=patcher" class="<?= $page == 'patcher' ? 'active' : '' ?>"><span class="material-symbols-outlined" style="font-size:1rem;">code_blocks</span>Patcher</a></li>
          <li><a href="index.php?page=support_tickets" class="<?= $page == 'support_tickets' ? 'active' : '' ?>"><span class="material-symbols-outlined" style="font-size:1rem;">confirmation_number</span>Support Tickets
              <?php if ($ticketCount > 0): ?>
                <span class="nav-badge" style="margin-left:auto;background:var(--error);color:#fff;border-radius:10px;padding:2px 6px;font-size:0.7rem;font-weight:700;"><?= $ticketCount ?></span>
              <?php endif; ?>
            </a></li>
          <li><a href="index.php?page=ai_suggestions" class="<?= $page == 'ai_suggestions' ? 'active' : '' ?>"><span class="material-symbols-outlined" style="font-size:1rem;">smart_toy</span>AI Suggestions</a></li>
          <li><a href="index.php?page=ai_context_preview" class="<?= $page == 'ai_context_preview' ? 'active' : '' ?>"><span class="material-symbols-outlined" style="font-size:1rem;">visibility</span>AI Context Preview</a></li>
        </ul>
      </li>
    </ul>

    <!-- Right-side User Actions -->
    <div class="navbar-actions">
      <?php
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
            <span class="material-symbols-outlined" style="font-size:1rem;opacity:0.7;">expand_more</span>
          </button>

          <div class="user-menu" id="navUserMenu" style="display:none;">
            <a href="index.php?page=profile_settings">
              <span class="material-symbols-outlined" style="font-size:1.1rem;">person</span>Profile Settings
            </a>
            <?php if ($isSuperAdmin): ?>
              <a href="index.php?page=roles">
                <span class="material-symbols-outlined" style="font-size:1.1rem;">admin_panel_settings</span>Role Privileges
              </a>
              <a href="index.php?page=audit">
                <span class="material-symbols-outlined" style="font-size:1.1rem;">policy</span>Action Audit Log
              </a>
              <a href="index.php?page=accounts">
                <span class="material-symbols-outlined" style="font-size:1.1rem;">group</span>Manage Accounts
              </a>
              <a href="index.php?page=settings">
                <span class="material-symbols-outlined" style="font-size:1.1rem;">settings</span>System Settings
              </a>
            <?php endif; ?>

            <div class="menu-divider"></div>
            <div style="padding: 10px 16px;">
              <div style="font-size:0.75rem;font-weight:700;color:var(--on-surface-variant);text-transform:uppercase;margin-bottom:8px;letter-spacing:0.05em;">Theme Color</div>
              <div style="display:flex;gap:8px;padding-bottom:4px;" id="themeSelectorContainer">
                <button class="theme-btn" data-theme="blue" style="background:#00457b;width:24px;height:24px;border-radius:50%;border:2px solid transparent;cursor:pointer;" title="Blue"></button>
                <button class="theme-btn" data-theme="emerald" style="background:#059669;width:24px;height:24px;border-radius:50%;border:2px solid transparent;cursor:pointer;" title="Emerald"></button>
                <button class="theme-btn" data-theme="crimson" style="background:#dc2626;width:24px;height:24px;border-radius:50%;border:2px solid transparent;cursor:pointer;" title="Crimson"></button>
                <button class="theme-btn" data-theme="violet" style="background:#7c3aed;width:24px;height:24px;border-radius:50%;border:2px solid transparent;cursor:pointer;" title="Violet"></button>
                <button class="theme-btn" data-theme="slate" style="background:#334155;width:24px;height:24px;border-radius:50%;border:2px solid transparent;cursor:pointer;" title="Slate"></button>
              </div>
            </div>
            <div class="menu-divider"></div>
            <a href="logout.php" class="menu-logout">
              <span class="material-symbols-outlined" style="font-size:1.1rem;">logout</span>Logout
            </a>
          </div>
        </div>
      <?php else: ?>
        <a href="login.php" style="color:#fff;font-weight:600;font-size:0.85rem;">Login</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<script>
  // Toggle navbar user menu
  document.getElementById('navUserToggle')?.addEventListener('click', function() {
    const menu = document.getElementById('navUserMenu');
    if (!menu) return;
    const shown = window.getComputedStyle(menu).display !== 'none';
    menu.style.display = shown ? 'none' : 'block';
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
      if (!menu) return;
      document.querySelectorAll('.dropdown-menu').forEach(m => {
        if (m !== menu) m.style.display = 'none';
      });
      const shown = window.getComputedStyle(menu).display !== 'none';
      menu.style.display = shown ? 'none' : 'block';
    });
  });

  // Close dropdowns when item is clicked
  document.querySelectorAll('.dropdown-menu a').forEach(link => {
    link.addEventListener('click', function() {
      document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');
    });
  });

  // Close dropdowns when clicking outside nav items
  document.addEventListener('click', function(e) {
    if (!e.target.closest('.nav-dropdown')) {
      document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');
    }
  });

  // ESC closes all menus
  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');
    const userMenu = document.getElementById('navUserMenu');
    if (userMenu) userMenu.style.display = 'none';
  });

  // Theme Manager
  const themes = {
    blue: {
      p: '#00457b',
      op: '#ffffff',
      pc: '#005699',
      sf: '#002340',
      st: '#003766'
    },
    emerald: {
      p: '#059669',
      op: '#ffffff',
      pc: '#047857',
      sf: '#064e3b',
      st: '#065f46'
    },
    crimson: {
      p: '#dc2626',
      op: '#ffffff',
      pc: '#b91c1c',
      sf: '#450a0a',
      st: '#7f1d1d'
    },
    violet: {
      p: '#7c3aed',
      op: '#ffffff',
      pc: '#6d28d9',
      sf: '#2e1065',
      st: '#4c1d95'
    },
    slate: {
      p: '#334155',
      op: '#ffffff',
      pc: '#1e293b',
      sf: '#0f172a',
      st: '#1e293b'
    }
  };

  function applyTheme(name) {
    if (!themes[name]) return;
    const t = themes[name];
    document.documentElement.style.setProperty('--primary', t.p);
    document.documentElement.style.setProperty('--on-primary', t.op);
    document.documentElement.style.setProperty('--primary-container', t.pc);
    document.documentElement.style.setProperty('--sidebar-from', t.sf);
    document.documentElement.style.setProperty('--sidebar-to', t.st);
    localStorage.setItem('stitch_theme', name);

    document.querySelectorAll('.theme-btn').forEach(b => {
      b.style.borderColor = b.dataset.theme === name ? 'var(--on-surface)' : 'transparent';
    });
  }

  document.querySelectorAll('.theme-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation(); // keep menu open
      applyTheme(e.target.dataset.theme);
    });
  });

  const savedTheme = localStorage.getItem('stitch_theme');
  if (savedTheme) applyTheme(savedTheme);
</script>
