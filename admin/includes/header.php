<?php require_once __DIR__ . '/../session_bootstrap.php'; ?>
<?php
require_once __DIR__ . '/../../env_helpers.php';
$adminLocalMode = app_local_mode_enabled(__DIR__ . '/../../.env');
?>
<!-- Mobile Page Header (visible <1024px only, hidden on desktop) -->
<header class="page-header">
  <div style="display:flex;align-items:center;gap:12px;">
    <button class="mobile-menu-btn" onclick="toggleSidebar()" aria-label="Open menu">
      <span class="material-symbols-outlined">menu</span>
    </button>
    <img src="../asset/lautech_logo.png" alt="logo" style="height:32px;width:auto;border-radius:6px;" onerror="this.style.display='none'">
    <h1>Attendance Admin</h1>
    <?php if ($adminLocalMode): ?>
      <span style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:rgba(78,222,163,0.14);color:#059669;font-size:0.72rem;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;">
        <span style="width:8px;height:8px;border-radius:50%;background:#4edea3;box-shadow:0 0 12px rgba(78,222,163,0.7);"></span>
        Local Mode
      </span>
    <?php endif; ?>
  </div>

  <div style="display:flex;align-items:center;gap:10px;">
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
      <div style="position:relative;">
        <button class="avatar-btn" aria-haspopup="true" aria-expanded="false" id="avatarToggle">
          <?php if ($adminAvatar): ?>
            <img class="admin-avatar" src="<?= htmlspecialchars($adminAvatar) ?>" alt="avatar">
          <?php else: ?>
            <span class="admin-initials"><?= $initials ?></span>
          <?php endif; ?>
        </button>

        <div id="avatarMenu" class="avatar-menu" style="display:none;">
          <a href="index.php?page=profile_settings" onclick="openProfileModal(); return false;">
            <span class="material-symbols-outlined" style="font-size:1.1rem;">person</span>
            Profile Settings
          </a>
          <?php if ($isSuperAdmin): ?>
            <a href="index.php?page=accounts">
              <span class="material-symbols-outlined" style="font-size:1.1rem;">group</span>
              Manage Accounts
            </a>
            <a href="index.php?page=settings">
              <span class="material-symbols-outlined" style="font-size:1.1rem;">settings</span>
              System Settings
            </a>
          <?php endif; ?>
          <div class="menu-divider"></div>
          <a href="logout.php" class="menu-danger">
            <span class="material-symbols-outlined" style="font-size:1.1rem;">logout</span>
            Logout
          </a>
        </div>
      </div>
    <?php else: ?>
      <a href="login.php" style="color:var(--on-surface);font-weight:600;">Login</a>
    <?php endif; ?>
  </div>
</header>
<?php
// compute admin root path to include local admin assets reliably
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$posAdmin = strpos($scriptPath, '/admin');
$adminRoot = ($posAdmin !== false) ? substr($scriptPath, 0, $posAdmin + 6) : '/admin';
?>
<!-- SweetAlert2 (CDN) and local theme -->
<link rel="stylesheet" href="<?= htmlspecialchars($adminRoot) ?>/swal-theme.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="<?= htmlspecialchars($adminRoot) ?>/admin-theme.css">
<?php
// expose CSRF token to JS by including the helper
$csrfToken = '';
$csrfPath = __DIR__ . '/csrf.php';
if (file_exists($csrfPath)) {
  require_once $csrfPath;
  if (function_exists('csrf_token')) $csrfToken = csrf_token();
}
?>
<script>
  // expose admin root to client scripts so relative redirects are safe
  var ADMIN_ROOT = <?= json_encode($adminRoot) ?>;
  window.openProfileModal = function() {
    var menu = document.getElementById('avatarMenu');
    var toggle = document.getElementById('avatarToggle');
    if (menu) menu.style.display = 'none';
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
    window.location.href = 'index.php?page=profile_settings';
  };
  // make CSRF token available to admin scripts
  window.ADMIN_CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
  (function() {
    function csrfTokenValue() {
      return (typeof window.ADMIN_CSRF_TOKEN === 'string') ? window.ADMIN_CSRF_TOKEN : '';
    }

    function isUnsafeMethod(method) {
      var m = String(method || 'GET').toUpperCase();
      return !(m === 'GET' || m === 'HEAD' || m === 'OPTIONS');
    }

    function isSameOriginUrl(inputUrl) {
      try {
        var url = new URL(inputUrl, window.location.href);
        return url.origin === window.location.origin;
      } catch (e) {
        return true;
      }
    }

    if (typeof window.fetch === 'function') {
      var originalFetch = window.fetch.bind(window);
      window.fetch = function(resource, init) {
        var token = csrfTokenValue();
        if (!token) {
          return originalFetch(resource, init);
        }

        var method = 'GET';
        var urlForCheck = '';
        if (typeof resource === 'string' || resource instanceof URL) {
          urlForCheck = String(resource);
          if (init && init.method) method = init.method;
        } else if (resource && typeof resource === 'object') {
          urlForCheck = resource.url || '';
          method = (init && init.method) || resource.method || 'GET';
        }

        if (!isUnsafeMethod(method) || !isSameOriginUrl(urlForCheck)) {
          return originalFetch(resource, init);
        }

        var nextInit = init ? Object.assign({}, init) : {};
        var headers = new Headers(nextInit.headers || (resource && resource.headers) || undefined);
        if (!headers.has('X-CSRF-Token')) {
          headers.set('X-CSRF-Token', token);
        }
        nextInit.headers = headers;

        return originalFetch(resource, nextInit);
      };
    }

    function attachCsrfToPostForms() {
      var token = csrfTokenValue();
      if (!token) return;

      var forms = document.querySelectorAll('form');
      for (var i = 0; i < forms.length; i++) {
        var form = forms[i];
        var method = String(form.getAttribute('method') || 'GET').toUpperCase();
        if (!isUnsafeMethod(method)) continue;

        var existing = form.querySelector('input[name="csrf_token"]');
        if (existing) continue;

        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = token;
        form.appendChild(input);
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', attachCsrfToPostForms);
    } else {
      attachCsrfToPostForms();
    }
  })();
  // Lightweight helpers so pages can replace native alert/confirm easily
  window.adminAlert = function(title, text, icon) {
    icon = icon || 'info';
    return Swal.fire({
      title: title || '',
      text: text || '',
      icon: icon,
      confirmButtonText: 'OK'
    });
  };
  window.adminConfirm = function(title, text) {
    return Swal.fire({
      title: title || 'Confirm',
      text: text || '',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes',
      cancelButtonText: 'Cancel'
    }).then(function(res) {
      return !!res.isConfirmed;
    });
  };
</script>
<!-- Page load timeout: redirect to timeout.php if the page hasn't completed loading within 60 seconds -->
<script>
  (function() {
    var timeoutMs = 60 * 1000; // 1 minute
    var warningMs = 45 * 1000; // show banner at 45s
    var timer = null;
    var warningTimer = null;

    function showWarning() {
      try {
        if (document.getElementById('admin-loading-warning')) return;
        var el = document.createElement('div');
        el.id = 'admin-loading-warning';
        el.className = 'loading-warning';
        el.innerHTML = '<div class="loading-warning-inner"><strong>Still loading…</strong> The page is taking longer than expected. If this continues, you will be redirected.</div>';
        document.body.appendChild(el);
      } catch (e) {}
    }

    function doRedirect() {
      try {
        var from = encodeURIComponent(window.location.pathname + window.location.search || '');
        var target = (typeof ADMIN_ROOT === 'string' && ADMIN_ROOT ? ADMIN_ROOT + '/timeout.php' : 'timeout.php');
        window.location.replace(target + (from ? ('?from=' + from) : ''));
      } catch (e) {
        window.location.replace('timeout.php');
      }
    }

    function startTimers() {
      clearTimers();
      warningTimer = setTimeout(showWarning, warningMs);
      timer = setTimeout(doRedirect, timeoutMs);
    }

    function clearTimers() {
      if (timer) {
        clearTimeout(timer);
        timer = null;
      }
      if (warningTimer) {
        clearTimeout(warningTimer);
        warningTimer = null;
      }
      var w = document.getElementById('admin-loading-warning');
      if (w) w.parentNode.removeChild(w);
    }

    // start as early as possible
    startTimers();

    // When page fully loads, cancel the timeout and warning
    window.addEventListener('load', clearTimers, {
      passive: true
    });
    // If user navigates away before load completes, cancel the timers to avoid spurious redirects
    window.addEventListener('beforeunload', clearTimers, {
      passive: true
    });

    // Expose cancel function for pages that perform heavy dynamic loads and want to cancel the timeout
    window.__cancelPageLoadTimeout = clearTimers;

    // avatar dropdown toggle
    document.addEventListener('click', function(e) {
      var toggle = document.getElementById('avatarToggle');
      var menu = document.getElementById('avatarMenu');
      if (!toggle || !menu) return;
      if (toggle.contains(e.target)) {
        var shown = menu.style.display === 'block';
        menu.style.display = shown ? 'none' : 'block';
        toggle.setAttribute('aria-expanded', (!shown).toString());
        return;
      }
      if (!menu.contains(e.target)) {
        menu.style.display = 'none';
        toggle.setAttribute('aria-expanded', 'false');
      }
    });

  })();
</script>
<script>
  // detect whether icon fonts loaded; if not, enable fallback CSS
  (function() {
    try {
      var span = document.createElement('span');
      span.className = 'material-symbols-outlined';
      span.style.display = 'none';
      document.body.appendChild(span);
      var ff = window.getComputedStyle(span).getPropertyValue('font-family') || '';
      document.body.removeChild(span);
      ff = ff.toLowerCase();
      if (ff.indexOf('material') === -1 && ff.indexOf('boxicons') === -1) {
        document.body.classList.add('icons-fallback');
      }
    } catch (e) {
      document.body.classList.add('icons-fallback');
    }
  })();
</script>
<script>
  (function() {
    var currentPage = '<?= htmlspecialchars($page ?? '') ?>';
    var lastKnown = {};

    function safeFetch(url) {
      return fetch(url, {
        cache: 'no-store'
      }).then(function(r) {
        if (!r.ok) throw new Error('Network');
        return r.json();
      });
    }

    function checkUpdates() {
      if (document.hidden) {
        return;
      }
      safeFetch('_last_updates.php').then(function(data) {
        if (!lastKnown.accounts) {
          lastKnown = data;
          return;
        }
        var changed = false;
        var keys = ['accounts', 'settings', 'chain', 'tickets', 'fingerprints', 'courses', 'active_course', 'status', 'view_tickets_page', 'unlink_page', 'add_course_page', 'chat'];
        keys.forEach(function(k) {
          if ((data[k] || 0) !== (lastKnown[k] || 0)) {
            lastKnown[k] = data[k];
            changed = true;
            if (currentPage === 'accounts' && k === 'accounts') refreshCurrent();
            if (currentPage === 'settings' && k === 'settings') refreshCurrent();
            if (currentPage === 'chain' && k === 'chain') refreshCurrent();
            if (currentPage === 'logs' && (k === 'tickets' || k === 'view_tickets_page')) refreshCurrent();
            if (currentPage === 'failed_attempts' && k === 'fingerprints') refreshCurrent();
            if (currentPage === 'add_course' && k === 'add_course_page') refreshCurrent();
            if (currentPage === 'set_active' && (k === 'active_course' || k === 'courses')) refreshCurrent();
            if (currentPage === 'status' && k === 'status') refreshCurrent();
            if (k === 'chat') fetchChat();
          }
        });
        if (changed && currentPage === '') {
          location.reload();
        }
      }).catch(function() {
        /* ignore network errors silently */
      });
    }

    function refreshCurrent() {
      var container = document.querySelector('.content-wrapper');
      if (!container) return;
      // IMPORTANT: Fetch via index.php?page= so session and auth checks run correctly.
      // Fetching raw .php files directly bypasses session_bootstrap and causes auth failures.
      fetch('index.php?page=' + (currentPage || 'dashboard'), {
          cache: 'no-store'
        })
        .then(function(r) {
          if (!r.ok) throw new Error('Network');
          return r.text();
        })
        .then(function(html) {
          // Extract just the content-wrapper div from the full page HTML
          var parser = new DOMParser();
          var doc = parser.parseFromString(html, 'text/html');
          var newContent = doc.querySelector('.content-wrapper');
          if (newContent) {
            container.innerHTML = newContent.innerHTML;
          } else {
            // Fallback: replace entire container
            container.innerHTML = html;
          }
          console.info('Refreshed ' + currentPage);
        })
        .catch(function() {
          /* ignore */
        });
    }
    // Chat: fetch and render
    function renderChat(messages) {
      var w = document.getElementById('admin-chat-window');
      if (!w) return;
      var html = '';
      messages.forEach(function(m) {
        var time = new Date(m.time).toLocaleTimeString();
        html += '<div class="chat-msg"><strong>' + escapeHtml(m.name) + "</strong> <span class='muted'>" + time + "</span><div>" + escapeHtml(m.message) + "</div></div>";
      });
      w.innerHTML = html;
      w.scrollTop = w.scrollHeight;
    }

    function fetchChat() {
      return fetch('chat_fetch.php', {
        cache: 'no-store'
      }).then(function(r) {
        if (!r.ok) throw new Error('Network');
        return r.json();
      }).then(function(data) {
        if (data.error) return [];
        return data;
      }).catch(function() {
        return [];
      });
    }

    function postChat(msg) {
      var payload = {
        message: msg
      };
      if (window.ADMIN_CSRF_TOKEN) payload.csrf_token = window.ADMIN_CSRF_TOKEN;
      return fetch('chat_post.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
      }).then(function(r) {
        if (!r.ok) throw new Error('Network');
        return r.json();
      }).catch(function() {
        return {
          ok: false
        };
      });
    }

    function escapeHtml(s) {
      return String(s).replace(/[&<>"]/g, function(c) {
        return {
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '"': '&quot;'
        } [c];
      });
    }
    window.fetchChat = fetchChat;
    window.postChat = postChat;
    window.escapeHtml = escapeHtml;
    checkUpdates();
    setInterval(checkUpdates, 20000);
  })();
</script>
<!-- Chat UI -->
<?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true): ?>
  <style>
    .chat_button {
      position: fixed;
      right: 18px;
      bottom: 18px;
      height: 54px;
      width: 54px;
      border-radius: 50%;
      background: linear-gradient(135deg, #0f4c88, #2368ab);
      border: none;
      box-shadow: 0 14px 26px rgba(15, 76, 136, 0.32);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 10001;
      transition: transform 0.2s, box-shadow 0.2s;
    }

    .chat_button:hover {
      transform: translateY(-3px);
      box-shadow: 0 18px 34px rgba(15, 76, 136, 0.36);
    }

    .chat_button .material-symbols-outlined {
      color: #fff;
      font-size: 22px;
    }

    #chatbar.chat_box {
      position: fixed;
      right: 18px;
      bottom: 84px;
      width: min(360px, calc(100vw - 28px));
      height: min(500px, calc(100vh - 140px));
      background: #ffffff;
      border-radius: 14px;
      box-shadow: 0 24px 48px rgba(24, 39, 75, 0.16);
      overflow: hidden;
      z-index: 10000;
      display: none;
      flex-direction: column;
      border: 1px solid rgba(194, 199, 209, 0.45);
    }

    .chat_box_header {
      padding: 11px 12px;
      background: linear-gradient(135deg, #0f4c88, #2368ab);
      color: #fff;
      font-weight: 700;
      font-size: 0.86rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
    }

    .chat_header_identity {
      display: inline-flex;
      align-items: center;
      gap: 9px;
      min-width: 0;
    }

    .chat_header_avatar {
      position: relative;
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.2);
      border: 1px solid rgba(255, 255, 255, 0.35);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .chat_header_avatar .material-symbols-outlined {
      color: #fff;
      font-size: 19px;
    }

    .chat_header_status_dot {
      position: absolute;
      right: -1px;
      bottom: -1px;
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: #16a34a;
      border: 2px solid #0f4c88;
    }

    .chat_header_text {
      min-width: 0;
      display: flex;
      flex-direction: column;
      line-height: 1.1;
    }

    .chat_header_title {
      font-weight: 800;
      letter-spacing: 0.02em;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .chat_header_subtitle {
      color: rgba(232, 242, 255, 0.84);
      font-size: 0.62rem;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      font-weight: 700;
    }

    .chat_header_actions {
      display: inline-flex;
      gap: 2px;
    }

    .chat_header_btn {
      width: 28px;
      height: 28px;
      border-radius: 8px;
      border: none;
      background: rgba(255, 255, 255, 0.14);
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: background 0.18s ease;
      padding: 0;
    }

    .chat_header_btn:hover {
      background: rgba(255, 255, 255, 0.25);
    }

    .chat_header_btn .material-symbols-outlined {
      font-size: 18px;
    }

    .chat_box_body {
      padding: 14px;
      flex: 1;
      overflow-y: auto;
      background: linear-gradient(180deg, #f8fbff, #f1f5fb);
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .chat_box_body .msg {
      position: relative;
      max-width: 88%;
      padding: 10px 12px;
      border-radius: 12px;
      font-size: 0.86rem;
      line-height: 1.45;
      box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
    }

    .chat_box_body .msg.self {
      align-self: flex-end;
      background: linear-gradient(135deg, #0f4c88, #2467a8);
      color: #fff;
      border-bottom-right-radius: 6px;
    }

    .chat_box_body .msg.other {
      align-self: flex-start;
      background: #ffffff;
      color: var(--on-surface);
      border: 1px solid rgba(194, 199, 209, 0.55);
      border-bottom-left-radius: 6px;
    }

    .chat_message_name {
      font-size: 0.8rem;
      font-weight: 800;
      margin-bottom: 3px;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }

    .chat_message_time {
      font-size: 0.68rem;
      opacity: 0.78;
      font-weight: 600;
      float: right;
      margin-left: 8px;
    }

    .chat_meta_deleted {
      font-style: italic;
      opacity: 0.82;
      font-size: 0.8rem;
    }

    .chat_typing_row {
      align-self: flex-start;
      max-width: 92%;
      display: none;
      align-items: center;
      gap: 6px;
      background: #eef3fb;
      border: 1px solid rgba(194, 199, 209, 0.7);
      color: #334155;
      padding: 8px 10px;
      border-radius: 12px;
      font-size: 0.76rem;
      font-weight: 600;
    }

    .chat_typing_row.show {
      display: inline-flex;
    }

    .chat_typing_dots {
      display: inline-flex;
      gap: 4px;
      margin-left: 2px;
    }

    .chat_typing_dot {
      width: 5px;
      height: 5px;
      border-radius: 50%;
      background: #475569;
      opacity: 0.35;
      animation: chatTypingPulse 1.2s infinite ease-in-out;
    }

    .chat_typing_dot:nth-child(2) {
      animation-delay: 0.15s;
    }

    .chat_typing_dot:nth-child(3) {
      animation-delay: 0.3s;
    }

    @keyframes chatTypingPulse {

      0%,
      80%,
      100% {
        opacity: 0.35;
        transform: translateY(0);
      }

      40% {
        opacity: 1;
        transform: translateY(-2px);
      }
    }

    .chat_box_footer {
      padding: 10px 10px 8px;
      border-top: 1px solid rgba(194, 199, 209, 0.38);
      background: #ffffff;
    }

    .chat_input_row {
      display: flex;
      align-items: center;
      gap: 7px;
    }

    .chat_box_footer input {
      flex: 1;
      padding: 10px 12px;
      border-radius: 10px;
      border: 1px solid var(--outline-variant);
      font-family: inherit;
      background: #f8fafc;
      font-size: 0.86rem;
    }

    .chat_send_btn {
      background: linear-gradient(135deg, #0f4c88, #2368ab);
      color: #fff;
      border: none;
      width: 38px;
      height: 38px;
      border-radius: 10px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .chat_action_row {
      display: flex;
      align-items: center;
      gap: 5px;
      margin-top: 7px;
      padding-left: 2px;
    }

    .chat_icon_btn {
      border: none;
      background: transparent;
      color: var(--on-surface-variant);
      border-radius: 8px;
      width: 30px;
      height: 30px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: background 0.18s ease, color 0.18s ease;
      padding: 0;
    }

    .chat_icon_btn:hover {
      background: #eef3fb;
      color: #0f4c88;
    }

    .chat_icon_btn .material-symbols-outlined {
      font-size: 18px;
    }

    .chat_emoji_panel {
      margin-top: 6px;
      display: none;
      flex-wrap: wrap;
      gap: 4px;
      background: #f8fafc;
      border: 1px solid var(--outline-variant);
      border-radius: 10px;
      padding: 6px;
      max-height: 96px;
      overflow-y: auto;
    }

    .chat_emoji_panel.open {
      display: flex;
    }

    .chat_emoji_item {
      border: none;
      background: transparent;
      width: 26px;
      height: 26px;
      border-radius: 7px;
      cursor: pointer;
      font-size: 16px;
      line-height: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .chat_emoji_item:hover {
      background: #e8eff9;
    }

    @media (max-width:480px) {
      #chatbar.chat_box {
        right: 10px;
        left: 10px;
        bottom: 78px;
        width: auto;
        height: min(470px, calc(100vh - 112px));
      }

      .chat_button {
        right: 12px;
        bottom: 12px;
      }
    }
  </style>

  <div id="chatPage" class="chat_page">
    <button id="chatToggle" class="chat_button" aria-label="Open chat">
      <span id="chatOpen" class="material-symbols-outlined">chat</span>
      <span id="chatBadge" style="display:none;position:absolute;top:-6px;right:-6px;background:var(--error);color:#fff;border-radius:999px;padding:2px 6px;font-size:0.7rem;font-weight:700;">0</span>
    </button>

    <div id="chatbar" class="chat_box">
      <div class="chat_box_header">
        <div class="chat_header_identity">
          <span class="chat_header_avatar">
            <span class="material-symbols-outlined">smart_toy</span>
            <span class="chat_header_status_dot"></span>
          </span>
          <span class="chat_header_text">
            <span class="chat_header_title">Sentinel AI</span>
            <span class="chat_header_subtitle">active assistant</span>
          </span>
        </div>
        <div class="chat_header_actions">
          <button id="chatMinBtn" class="chat_header_btn" aria-label="Minimize chat"><span class="material-symbols-outlined">remove</span></button>
          <button id="chatCloseBtn" class="chat_header_btn" aria-label="Close chat"><span class="material-symbols-outlined">close</span></button>
        </div>
      </div>
      <div id="chatBody" class="chat_box_body"></div>
      <div class="chat_box_footer">
        <div class="chat_input_row">
          <input type="text" id="MsgInput" placeholder="Describe your issue...">
          <button id="MsgSend" class="chat_send_btn" aria-label="Send message"><span class="material-symbols-outlined" style="font-size:18px;">send</span></button>
        </div>
        <div class="chat_action_row">
          <button id="MsgAttach" class="chat_icon_btn" aria-label="Attach file"><span class="material-symbols-outlined">attach_file</span></button>
          <button id="MsgEmoji" class="chat_icon_btn" aria-label="Insert emoji"><span class="material-symbols-outlined">mood</span></button>
          <button id="MsgGallery" class="chat_icon_btn" aria-label="Choose image"><span class="material-symbols-outlined">image</span></button>
        </div>
        <div id="chatEmojiPanel" class="chat_emoji_panel" aria-label="Emoji picker"></div>
        <input type="file" id="MsgGalleryInput" accept="image/*" style="display:none;">
        <input type="file" id="MsgAttachInput" style="display:none;">
      </div>
    </div>
  </div>

  <script>
    (function() {
      var isOpen = false;
      var toggle = document.getElementById('chatToggle');
      var chatbar = document.getElementById('chatbar');
      var icon = document.getElementById('chatOpen');
      var msgInput = document.getElementById('MsgInput');
      var msgSend = document.getElementById('MsgSend');
      var msgEmoji = document.getElementById('MsgEmoji');
      var msgGallery = document.getElementById('MsgGallery');
      var msgAttach = document.getElementById('MsgAttach');
      var msgGalleryInput = document.getElementById('MsgGalleryInput');
      var msgAttachInput = document.getElementById('MsgAttachInput');
      var chatEmojiPanel = document.getElementById('chatEmojiPanel');
      var chatMinBtn = document.getElementById('chatMinBtn');
      var chatCloseBtn = document.getElementById('chatCloseBtn');
      var chatBody = document.getElementById('chatBody');
      var badge = document.getElementById('chatBadge');
      var currentUser = <?= json_encode($_SESSION['admin_user'] ?? '') ?>;
      var previousMessageIds = {};
      var hasBootstrapped = false;
      var unseenIncomingCount = 0;
      var forceScrollToBottom = false;
      var typingTimer = null;
      var typingHeartbeatAt = 0;
      var typingActive = false;
      var emojiList = ['😀', '😁', '😂', '😊', '😍', '👍', '🙏', '🔥', '✅', '🎉', '🤖', '💡', '📌', '📎', '📷', '⚠️', '🚀', '👀'];

      function isNearBottom(el, thresholdPx) {
        if (!el) return true;
        var threshold = typeof thresholdPx === 'number' ? thresholdPx : 64;
        return (el.scrollHeight - (el.scrollTop + el.clientHeight)) <= threshold;
      }

      function closeEmojiPanel() {
        if (chatEmojiPanel) {
          chatEmojiPanel.classList.remove('open');
        }
      }

      function appendToInput(text) {
        if (!msgInput) return;
        msgInput.value = (msgInput.value + text).trim();
        msgInput.focus();
      }

      function bootstrapEmojiPanel() {
        if (!chatEmojiPanel) return;
        chatEmojiPanel.innerHTML = '';
        emojiList.forEach(function(em) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'chat_emoji_item';
          btn.textContent = em;
          btn.addEventListener('click', function() {
            appendToInput(' ' + em + ' ');
            closeEmojiPanel();
          });
          chatEmojiPanel.appendChild(btn);
        });
      }

      bootstrapEmojiPanel();

      function buildChatLink(url, label) {
        var safeUrl = String(url || '').trim();
        if (/^admin\//i.test(safeUrl)) {
          safeUrl = safeUrl.replace(/^admin\//i, '');
        }
        if (/^\/admin\//i.test(safeUrl)) {
          safeUrl = safeUrl.replace(/^\/admin\//i, '');
        }

        var allowed = /^https?:\/\//i.test(safeUrl) || /^index\.php\?page=[\w_-]+/i.test(safeUrl) || /^\.\/index\.php\?page=[\w_-]+/i.test(safeUrl) || /^\/?index\.php\?page=[\w_-]+/i.test(safeUrl);
        if (!allowed) {
          return escapeHtml(String(label || safeUrl || 'link'));
        }
        var safeLabel = String(label || safeUrl);
        var isExternal = /^https?:\/\//i.test(safeUrl);
        var target = isExternal ? ' target="_blank" rel="noopener noreferrer"' : '';
        return '<a href="' + escapeHtml(safeUrl) + '"' + target + ' style="color:inherit;text-decoration:underline;text-underline-offset:2px;word-break:break-all;pointer-events:auto;">' + escapeHtml(safeLabel) + '</a>';
      }

      function renderMessageText(raw) {
        var text = String(raw || '');
        var tokenMap = [];
        text = text.replace(/\[([^\]]+)\]\(([^\s)]+)\)/gi, function(_, label, url) {
          var token = '@@LINK_' + tokenMap.length + '@@';
          tokenMap.push({
            token: token,
            html: buildChatLink(url, label)
          });
          return token;
        });

        var escaped = escapeHtml(text);
        escaped = escaped.replace(/(https?:\/\/[^\s<]+|(?:\/?admin\/index\.php\?page=[\w_-]+)|(?:\/?index\.php\?page=[\w_-]+))/gi, function(url) {
          return buildChatLink(url, url);
        });

        escaped = escaped.replace(/(<\/a>)([\.,!?;:]+)/gi, '$2$1');

        tokenMap.forEach(function(t) {
          escaped = escaped.replace(t.token, t.html);
        });
        return escaped;
      }

      function showUnreadPopup(count) {
        if (!count || count < 1 || typeof Swal === 'undefined') return;
        Swal.fire({
          toast: true,
          position: 'top-end',
          timer: 2600,
          timerProgressBar: true,
          showConfirmButton: false,
          icon: 'info',
          title: count === 1 ? 'New chat message' : (count + ' new chat messages'),
          text: 'Tap chat to view the latest updates.'
        });
      }

      function toPreviewText(raw) {
        var text = String(raw || '');
        text = text.replace(/\[([^\]]+)\]\([^\)]+\)/g, '$1');
        text = text.replace(/\s+/g, ' ').trim();
        if (text.length > 140) {
          text = text.slice(0, 137) + '...';
        }
        return text;
      }

      function showAiPopup(newAiMessages) {
        if (!Array.isArray(newAiMessages) || !newAiMessages.length || typeof Swal === 'undefined') return;
        var latest = newAiMessages[newAiMessages.length - 1] || {};
        var preview = toPreviewText(latest.message || 'New message from Sentinel AI');
        var suffix = newAiMessages.length > 1 ? (' +' + (newAiMessages.length - 1) + ' more') : '';
        Swal.fire({
          toast: true,
          position: 'top-end',
          timer: 4200,
          timerProgressBar: true,
          showConfirmButton: false,
          icon: 'info',
          title: 'Sentinel AI' + suffix,
          text: preview
        });
      }

      function openChatBox() {
        if (!isOpen) {
          chatbar.style.display = 'flex';
          isOpen = true;
          icon.textContent = 'close';
          forceScrollToBottom = true;
          unseenIncomingCount = 0;
          badge.style.display = 'none';
          closeEmojiPanel();
          fetchAndRender();
          restartPolling();
        } else {
          chatbar.style.display = 'none';
          isOpen = false;
          icon.textContent = 'chat';
          closeEmojiPanel();
          stopTypingHeartbeat();
          restartPolling();
        }
      }

      toggle.addEventListener('click', openChatBox);

      function renderMessages(messages) {
        if (!chatBody) return;
        var shouldAutoStick = forceScrollToBottom || isNearBottom(chatBody, 72);
        chatBody.innerHTML = '';
        messages.forEach(function(m) {
          var div = document.createElement('div');
          var cls = (m.user === '<?= addslashes($_SESSION['admin_user'] ?? '') ?>') ? 'msg self' : 'msg other';
          div.className = cls;
          var d = new Date(m.time);
          var rel = d.toLocaleTimeString();
          var title = d.toString();
          var content = '<div><span class="chat_message_name">' + escapeHtml(m.name) + '</span><span class="chat_message_time" title="' + escapeHtml(title) + '">' + escapeHtml(rel) + '</span></div>';
          if (m.deleted) {
            var deletedBy = String(m.deleted_by || '');
            var deletedByName = String(m.deleted_by_name || m.name || 'An admin');
            var deletedText = (deletedBy === currentUser) ? 'You deleted this message' : (deletedByName + ' deleted this message');
            content += '<div class="chat_meta_deleted">' + escapeHtml(deletedText) + '</div>';
          } else {
            content += '<div style="margin-bottom:4px;line-height:1.4;word-break:break-word;">' + renderMessageText(m.message) + '</div>';
          }
          if (!m.deleted && String(m.user || '') === String(currentUser)) {
            content += '<div style="text-align:right;margin-top:4px;"><button data-id="' + escapeHtml(m.id || '') + '" data-time="' + escapeHtml(m.time || '') + '" class="delete-msg" style="background:none;border:none;cursor:pointer;font-size:0.75rem;padding:0;text-decoration:none;font-weight:600;' + (cls.indexOf('self') > -1 ? 'color:#ffcfcf;' : 'color:var(--error);') + '">Delete</button></div>';
          }
          div.innerHTML = content;
          chatBody.appendChild(div);
        });
        var typingNode = document.createElement('div');
        typingNode.id = 'chatTypingRow';
        typingNode.className = 'chat_typing_row';
        typingNode.innerHTML = '<span id="chatTypingLabel">Typing</span><span class="chat_typing_dots"><span class="chat_typing_dot"></span><span class="chat_typing_dot"></span><span class="chat_typing_dot"></span></span>';
        chatBody.appendChild(typingNode);
        if (shouldAutoStick) {
          chatBody.scrollTop = chatBody.scrollHeight;
        }
        forceScrollToBottom = false;
      }

      function renderTypingState(data) {
        var row = document.getElementById('chatTypingRow');
        var label = document.getElementById('chatTypingLabel');
        if (!row || !label) return;

        data = data || {};
        var names = [];
        if (Array.isArray(data.typing)) {
          data.typing.forEach(function(t) {
            var n = String((t && t.name) || '').trim();
            if (n) names.push(n);
          });
        }
        if (data.ai_typing) {
          names.push('Sentinel AI');
        }

        if (!names.length) {
          row.classList.remove('show');
          return;
        }

        var shown = names.slice(0, 2).join(', ');
        if (names.length > 2) {
          shown += ' +' + (names.length - 2) + ' more';
        }
        label.textContent = shown + ' typing';
        row.classList.add('show');
        if (isNearBottom(chatBody, 72)) {
          chatBody.scrollTop = chatBody.scrollHeight;
        }
      }

      function fetchTypingState() {
        return fetch('chat_typing.php', {
          cache: 'no-store'
        }).then(function(r) {
          if (!r.ok) throw new Error('Network');
          return r.json();
        }).then(function(data) {
          renderTypingState(data || {});
        }).catch(function() {
          renderTypingState({});
        });
      }

      function postTypingState(isTyping) {
        var payload = {
          typing: !!isTyping
        };
        if (window.ADMIN_CSRF_TOKEN) payload.csrf_token = window.ADMIN_CSRF_TOKEN;
        return fetch('chat_typing.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload)
        }).catch(function() {});
      }

      function stopTypingHeartbeat() {
        if (typingTimer) {
          clearInterval(typingTimer);
          typingTimer = null;
        }
        if (typingActive) {
          typingActive = false;
          postTypingState(false);
        }
      }

      function ensureTypingHeartbeat() {
        var now = Date.now();
        if (!typingActive || (now - typingHeartbeatAt) > 1800) {
          typingActive = true;
          typingHeartbeatAt = now;
          postTypingState(true);
        }
        if (!typingTimer) {
          typingTimer = setInterval(function() {
            if (!isOpen || !msgInput || !msgInput.value.trim()) {
              stopTypingHeartbeat();
              return;
            }
            typingHeartbeatAt = Date.now();
            postTypingState(true);
          }, 2000);
        }
      }

      function fetchAndRender() {
        window.fetchChat().then(function(messages) {
          messages = messages || [];
          var newIncoming = 0;
          var newAiMessages = [];
          var nextIds = {};
          messages.forEach(function(m) {
            var id = String(m.id || m.time || '');
            if (id) {
              nextIds[id] = true;
              if (hasBootstrapped && !previousMessageIds[id] && String(m.user || '') !== String(currentUser) && !m.deleted) {
                newIncoming++;
                if (String(m.user || '') === 'system_ai_operator') {
                  newAiMessages.push(m);
                }
              }
            }
          });
          previousMessageIds = nextIds;

          if (!isOpen && hasBootstrapped && newIncoming > 0) {
            unseenIncomingCount += newIncoming;
            badge.style.display = 'inline-block';
            badge.textContent = String(unseenIncomingCount);
            showUnreadPopup(newIncoming);
          } else {
            badge.style.display = 'none';
            unseenIncomingCount = 0;
          }

          if (hasBootstrapped && newAiMessages.length > 0) {
            showAiPopup(newAiMessages);
          }

          hasBootstrapped = true;
          renderMessages(messages);
          Array.from(document.getElementsByClassName('delete-msg')).forEach(function(btn) {
            btn.addEventListener('click', function() {
              var t = this.getAttribute('data-time');
              var id = this.getAttribute('data-id');
              if (!t && !id) return;
              window.adminConfirm('Delete message', 'Delete this message?').then(function(ok) {
                if (!ok) return;
                var payload = {
                  id: id,
                  time: t
                };
                if (window.ADMIN_CSRF_TOKEN) payload.csrf_token = window.ADMIN_CSRF_TOKEN;
                fetch('chat_delete.php', {
                    method: 'POST',
                    headers: {
                      'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                  })
                  .then(function(r) {
                    return r.json();
                  })
                  .then(function(r) {
                    if (r && r.ok) fetchAndRender();
                    else window.adminAlert('Delete failed', (r && r.error) ? r.error : JSON.stringify(r), 'error');
                  })
                  .catch(function() {
                    window.adminAlert('Delete failed', 'Network or server error', 'error');
                  });
              });
            });
          });
          if (isOpen) {
            fetchTypingState();
          }
        });
      }

      function send() {
        var v = msgInput.value.trim();
        if (!v) return;
        stopTypingHeartbeat();
        forceScrollToBottom = true;
        msgInput.value = '';
        window.postChat(v).then(function(res) {
          if (res && res.ok) {
            fetchAndRender();
          }
        });
      }

      msgSend.addEventListener('click', send);
      if (msgEmoji) {
        msgEmoji.addEventListener('click', function() {
          if (!chatEmojiPanel) return;
          chatEmojiPanel.classList.toggle('open');
        });
      }

      if (msgGallery && msgGalleryInput) {
        msgGallery.addEventListener('click', function() {
          closeEmojiPanel();
          msgGalleryInput.click();
        });
        msgGalleryInput.addEventListener('change', function() {
          var file = this.files && this.files[0] ? this.files[0] : null;
          if (!file) return;
          appendToInput(' 📷 image: ' + file.name + ' ');
          this.value = '';
        });
      }

      if (msgAttach && msgAttachInput) {
        msgAttach.addEventListener('click', function() {
          closeEmojiPanel();
          msgAttachInput.click();
        });
        msgAttachInput.addEventListener('change', function() {
          var file = this.files && this.files[0] ? this.files[0] : null;
          if (!file) return;
          appendToInput(' 📎 file: ' + file.name + ' ');
          this.value = '';
        });
      }

      if (chatMinBtn) {
        chatMinBtn.addEventListener('click', function(e) {
          e.preventDefault();
          if (isOpen) openChatBox();
        });
      }

      if (chatCloseBtn) {
        chatCloseBtn.addEventListener('click', function(e) {
          e.preventDefault();
          if (isOpen) openChatBox();
        });
      }

      document.addEventListener('click', function(e) {
        if (!chatEmojiPanel || !msgEmoji) return;
        if (!chatEmojiPanel.contains(e.target) && e.target !== msgEmoji && !msgEmoji.contains(e.target)) {
          closeEmojiPanel();
        }
      });

      msgInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          send();
        }
      });

      msgInput.addEventListener('input', function() {
        if (!isOpen) return;
        if (msgInput.value.trim()) {
          ensureTypingHeartbeat();
        } else {
          stopTypingHeartbeat();
        }
      });

      msgInput.addEventListener('blur', function() {
        if (!msgInput.value.trim()) {
          stopTypingHeartbeat();
        }
      });

      if (chatBody) {
        chatBody.addEventListener('wheel', function() {
          if (!isNearBottom(chatBody, 72)) {
            forceScrollToBottom = false;
          }
        }, {
          passive: true
        });

        chatBody.addEventListener('touchmove', function() {
          if (!isNearBottom(chatBody, 72)) {
            forceScrollToBottom = false;
          }
        }, {
          passive: true
        });
      }

      var pollTimer = null;
      var pollIntervalMs = 20000;

      function resolvePollInterval() {
        if (document.hidden) {
          return 45000;
        }
        return isOpen ? 5000 : 20000;
      }

      function restartPolling() {
        var nextMs = resolvePollInterval();
        if (pollTimer && pollIntervalMs === nextMs) {
          return;
        }
        if (pollTimer) {
          clearInterval(pollTimer);
          pollTimer = null;
        }
        pollIntervalMs = nextMs;
        startPolling();
      }

      function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(fetchAndRender, pollIntervalMs);
      }

      function stopPolling() {
        if (!pollTimer) return;
        clearInterval(pollTimer);
        pollTimer = null;
        stopTypingHeartbeat();
      }

      window.openChatBox = openChatBox;
      window.sendChatMessage = send;
      document.addEventListener('visibilitychange', restartPolling);
      fetchAndRender();
      restartPolling();

    })();
  </script>
<?php endif; ?>

<?php
$h_permissions = admin_load_permissions_cached();
$h_isSuper = isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'superadmin';
$h_allowed = $h_isSuper ? [] : ($h_permissions[$_SESSION['admin_role'] ?? 'admin'] ?? []);
function h_can_view($pageId)
{
  global $h_isSuper, $h_allowed;
  return $h_isSuper || in_array($pageId, $h_allowed, true);
}

$h_tour_catalog = [
  [
    'id' => 'dashboard',
    'page' => 'dashboard',
    'title' => 'Live Dashboard',
    'text' => 'Your command center for attendance activity and operational health.',
    'selector' => 'a[href="index.php?page=dashboard"]',
    'position' => 'right',
  ],
  [
    'id' => 'status',
    'page' => 'status',
    'title' => 'Attendance Status',
    'text' => 'Enable or pause check-in and check-out modes from this control point.',
    'selector' => 'a[href="index.php?page=status"]',
    'position' => 'right',
  ],
  [
    'id' => 'set_active',
    'page' => 'set_active',
    'title' => 'Active Course',
    'text' => 'Set the active course before opening attendance windows.',
    'selector' => 'a[href="index.php?page=set_active"]',
    'position' => 'right',
  ],
  [
    'id' => 'manual_attendance',
    'page' => 'manual_attendance',
    'title' => 'Manual Attendance',
    'text' => 'Use controlled manual override for valid attendance exceptions.',
    'selector' => 'a[href="index.php?page=manual_attendance"]',
    'position' => 'right',
  ],
  [
    'id' => 'support_tickets',
    'page' => 'support_tickets',
    'title' => 'Support Tickets',
    'text' => 'Review student complaints and resolution workflow from one queue.',
    'selector' => 'a[href="index.php?page=support_tickets"]',
    'position' => 'right',
  ],
  [
    'id' => 'ai_suggestions',
    'page' => 'ai_suggestions',
    'title' => 'AI Suggestions',
    'text' => 'Inspect AI recommendations, confidence, and required manual-review actions.',
    'selector' => 'a[href="index.php?page=ai_suggestions"]',
    'position' => 'right',
  ],
  [
    'id' => 'request_timings',
    'page' => 'request_timings',
    'title' => 'Request Timings',
    'text' => 'Track latency and identify slow admin routes quickly.',
    'selector' => 'a[href="index.php?page=request_timings"]',
    'position' => 'right',
  ],
  [
    'id' => 'logs',
    'page' => 'logs',
    'title' => 'General Logs',
    'text' => 'Browse attendance activity and operational records.',
    'selector' => 'a[href="index.php?page=logs"]',
    'position' => 'right',
  ],
  [
    'id' => 'failed_attempts',
    'page' => 'failed_attempts',
    'title' => 'Failed Attempts',
    'text' => 'Review blocked and suspicious attempts for security monitoring.',
    'selector' => 'a[href="index.php?page=failed_attempts"]',
    'position' => 'right',
  ],
  [
    'id' => 'announcement',
    'page' => 'announcement',
    'title' => 'Announcements',
    'text' => 'Broadcast important updates to students instantly.',
    'selector' => 'a[href="index.php?page=announcement"]',
    'position' => 'right',
  ],
  [
    'id' => 'unlink_fingerprint',
    'page' => 'unlink_fingerprint',
    'title' => 'Unlink Fingerprint',
    'text' => 'Safely unlink biometric mapping when remediation is required.',
    'selector' => 'a[href="index.php?page=unlink_fingerprint"]',
    'position' => 'right',
  ],
  [
    'id' => 'status_debug',
    'page' => 'status_debug',
    'title' => 'Status Diagnostics',
    'text' => 'Deep-dive debug panel for advanced operational diagnostics.',
    'selector' => 'a[href="index.php?page=status_debug"]',
    'position' => 'right',
  ],
  [
    'id' => 'roles',
    'page' => 'roles',
    'title' => 'Role Privileges',
    'text' => 'Manage role permissions and governance boundaries.',
    'selector' => 'a[href="index.php?page=roles"]',
    'position' => 'right',
  ],
  [
    'id' => 'accounts',
    'page' => 'accounts',
    'title' => 'Manage Accounts',
    'text' => 'Create, update, and secure admin accounts by role.',
    'selector' => 'a[href="index.php?page=accounts"]',
    'position' => 'right',
  ],
  [
    'id' => 'audit',
    'page' => 'audit',
    'title' => 'Action Audit Log',
    'text' => 'Track privileged changes for accountability and compliance.',
    'selector' => 'a[href="index.php?page=audit"]',
    'position' => 'right',
  ],
  [
    'id' => 'settings',
    'page' => 'settings',
    'title' => 'System Settings',
    'text' => 'Configure system-level behavior, automation, and platform defaults.',
    'selector' => 'a[href="index.php?page=settings"]',
    'position' => 'right',
  ],
];

$h_tour_steps_for_role = [];
foreach ($h_tour_catalog as $tourStep) {
  $tourPage = (string)($tourStep['page'] ?? '');
  if ($tourPage !== '' && h_can_view($tourPage)) {
    $h_tour_steps_for_role[] = $tourStep;
  }
}

$h_currentRole = strtolower((string)($_SESSION['admin_role'] ?? 'admin'));
$h_opsPages = ['dashboard', 'status', 'set_active', 'manual_attendance', 'support_tickets', 'ai_suggestions', 'announcement', 'logs', 'request_timings', 'failed_attempts', 'unlink_fingerprint'];
$h_govPages = ['roles', 'accounts', 'audit', 'settings', 'status_debug'];

$h_roleProfiles = [
  'superadmin' => 'governance-first',
  'admin' => 'operations-first',
  'operator' => 'operations-first',
  'operations' => 'operations-first',
  'ops' => 'operations-first',
  'reviewer' => 'operations-first',
  'support' => 'operations-first',
  'auditor' => 'governance-first',
  'governance' => 'governance-first',
  'compliance' => 'governance-first',
];

$h_profile = $h_roleProfiles[$h_currentRole] ?? null;
if ($h_profile === null) {
  $h_sourcePages = $h_isSuper
    ? array_map(static function ($row) {
      return (string)($row['page'] ?? '');
    }, $h_tour_catalog)
    : (is_array($h_allowed) ? array_map('strval', $h_allowed) : []);

  $h_opsCount = count(array_intersect($h_sourcePages, $h_opsPages));
  $h_govCount = count(array_intersect($h_sourcePages, $h_govPages));

  $h_profile = ($h_govCount > 0 && $h_govCount >= $h_opsCount)
    ? 'governance-first'
    : 'operations-first';
}

$h_profileOrder = [
  'operations-first' => [
    'dashboard',
    'status',
    'set_active',
    'manual_attendance',
    'support_tickets',
    'ai_suggestions',
    'announcement',
    'request_timings',
    'logs',
    'failed_attempts',
    'unlink_fingerprint',
    'roles',
    'accounts',
    'audit',
    'settings',
    'status_debug',
  ],
  'governance-first' => [
    'roles',
    'accounts',
    'audit',
    'settings',
    'status_debug',
    'dashboard',
    'status',
    'support_tickets',
    'ai_suggestions',
    'request_timings',
    'logs',
    'failed_attempts',
    'set_active',
    'manual_attendance',
    'announcement',
    'unlink_fingerprint',
  ],
];

$h_selectedOrder = $h_profileOrder[$h_profile] ?? $h_profileOrder['operations-first'];
$h_rank = [];
foreach ($h_selectedOrder as $idx => $pageId) {
  $h_rank[(string)$pageId] = (int)$idx;
}

foreach ($h_tour_steps_for_role as $idx => $step) {
  $h_tour_steps_for_role[$idx]['_orig_idx'] = $idx;
}

usort($h_tour_steps_for_role, static function ($a, $b) use ($h_rank) {
  $aPage = (string)($a['page'] ?? '');
  $bPage = (string)($b['page'] ?? '');
  $aRank = $h_rank[$aPage] ?? 10000;
  $bRank = $h_rank[$bPage] ?? 10000;
  if ($aRank === $bRank) {
    return ((int)($a['_orig_idx'] ?? 0)) <=> ((int)($b['_orig_idx'] ?? 0));
  }
  return $aRank <=> $bRank;
});

foreach ($h_tour_steps_for_role as $idx => $step) {
  unset($h_tour_steps_for_role[$idx]['_orig_idx']);
}
?>
<script src="https://cdn.jsdelivr.net/npm/shepherd.js@11.0.1/dist/js/shepherd.min.js"></script>
<style>
  .shepherd-element {
    z-index: 1000000;
    background: var(--surface-container-lowest);
    color: var(--on-surface);
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-elevated);
    max-width: min(420px, calc(100vw - 28px));
  }

  .shepherd-title {
    font-weight: 700;
    color: var(--primary);
  }

  .shepherd-header {
    position: relative;
    padding: 14px 16px 8px;
    padding-right: 46px;
  }

  .shepherd-content {
    border-radius: inherit;
  }

  .shepherd-text {
    padding: 0 16px 2px;
  }

  .shepherd-footer {
    padding: 12px 16px 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }

  .shepherd-button {
    background: var(--primary);
    color: #fff;
    border-radius: var(--radius-md);
    font-weight: 600;
    padding: 6px 12px;
    cursor: pointer;
    border: none;
  }

  .shepherd-button-secondary {
    background: var(--surface-container-highest);
    color: var(--on-surface);
    border: 1px solid var(--outline-variant);
    cursor: pointer;
  }

  .shepherd-text {
    font-size: 0.95rem;
    line-height: 1.5;
    color: var(--on-surface-variant);
  }

  .shepherd-cancel-icon {
    position: absolute !important;
    top: 10px;
    right: 10px;
    left: auto !important;
    margin: 0 !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--on-surface-variant);
    font-size: 1.15rem;
    width: 30px;
    height: 30px;
    border: 1px solid var(--outline-variant);
    background: var(--surface-container-lowest);
    border-radius: 8px;
    transition: background 0.18s ease, color 0.18s ease;
  }

  .shepherd-cancel-icon:hover {
    background: var(--surface-container-high);
    color: var(--on-surface);
  }

  .shepherd-modal-overlay-container {
    background: rgba(8, 15, 28, 0.58) !important;
  }

  .shepherd-target.shepherd-enabled,
  .shepherd-enabled.shepherd-target {
    position: relative;
    z-index: 1000000;
  }

  @media (max-width: 768px) {
    .shepherd-element {
      max-width: calc(100vw - 24px) !important;
      border-radius: 16px;
      border-color: color-mix(in srgb, var(--outline-variant) 78%, #ffffff 22%);
      box-shadow: 0 14px 30px rgba(9, 23, 48, 0.26);
    }

    .shepherd-header {
      padding: 12px 12px 6px;
      padding-right: 42px;
    }

    .shepherd-cancel-icon {
      top: 8px;
      right: 8px;
      width: 28px;
      height: 28px;
    }

    .shepherd-text {
      padding: 0 12px 2px;
      font-size: 0.92rem;
      line-height: 1.45;
    }

    .shepherd-footer {
      padding: 10px 12px 12px;
      gap: 6px;
    }

    .shepherd-button {
      padding: 8px 12px;
      font-size: 0.86rem;
    }
  }
</style>
<script>
  document.addEventListener("DOMContentLoaded", function() {
    const steps = [];
    const rolePageTourSteps = <?= json_encode($h_tour_steps_for_role, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const navButtons = [{
      text: 'Back',
      action: function() {
        window.tour.back();
      },
      secondary: true,
      classes: 'shepherd-button-secondary'
    }, {
      text: 'Next',
      action: function() {
        window.tour.next();
      },
      classes: 'shepherd-button'
    }];

    function pushRoleStep(stepConfig) {
      if (!stepConfig || !stepConfig.id || !stepConfig.selector) return;
      steps.push({
        id: stepConfig.id,
        title: stepConfig.title || 'Module',
        text: stepConfig.text || 'Important workspace module.',
        attachTo: {
          element: stepConfig.selector,
          on: stepConfig.position || 'right'
        },
        buttons: navButtons
      });
    }

    steps.push({
      id: 'intro',
      title: 'Welcome to the Platform!',
      text: 'Let\'s take a quick tour of your day-to-day administrative tools. You can exit this at any time.',
      attachTo: {
        element: 'h1',
        on: 'bottom'
      },
      buttons: [{
        text: 'Skip',
        action: function() {
          window.tour.cancel();
        },
        secondary: true,
        classes: 'shepherd-button-secondary'
      }, {
        text: 'Next',
        action: function() {
          window.tour.next();
        },
        classes: 'shepherd-button'
      }]
    });
    rolePageTourSteps.forEach(pushRoleStep);
    steps.push({
      id: 'ai_copilot',
      title: 'AI Navigation & Chat',
      text: 'Pro Tip: Press Cmd+K anywhere to Search! Click the sidebar chat bubble for help.',
      attachTo: {
        element: '#chatToggle',
        on: 'top-left'
      },
      buttons: [{
        text: 'Finish',
        action: function() {
          window.tour.complete();
        },
        classes: 'shepherd-button'
      }]
    });

    function resolveVisibleElement(selector) {
      const matches = Array.from(document.querySelectorAll(selector));
      if (!matches.length) return null;
      const visible = matches.find((el) => {
        if (!(el instanceof HTMLElement)) return false;
        const style = window.getComputedStyle(el);
        return style.display !== 'none' && style.visibility !== 'hidden' && el.getClientRects().length > 0;
      });
      return visible || matches[0];
    }

    function waitFor(ms) {
      return new Promise((resolve) => setTimeout(resolve, ms));
    }

    function ensureSidebarGroupsOpen(target) {
      if (!(target instanceof Element)) return false;
      let changed = false;
      const groups = [];
      let current = target.closest('details.sidebar-group');
      while (current) {
        groups.push(current);
        current = current.parentElement ? current.parentElement.closest('details.sidebar-group') : null;
      }
      groups.reverse().forEach((group) => {
        if (!group.open) {
          group.open = true;
          changed = true;
        }
      });
      return changed;
    }

    function resolveTourTarget(selector) {
      if (typeof selector !== 'string') return selector;
      const isMobile = window.matchMedia('(max-width: 1024px)').matches;
      if (!isMobile) {
        return resolveVisibleElement(selector);
      }

      const hrefMatch = selector.match(/a\[href=['\"]([^'\"]+)['\"]\]/i);
      if (hrefMatch && hrefMatch[1]) {
        const sidebarTarget = resolveVisibleElement(`.sidebar a[href="${hrefMatch[1]}"]`);
        if (sidebarTarget) return sidebarTarget;
      }
      return resolveVisibleElement(selector);
    }

    steps.forEach((step) => {
      if (!step.attachTo || !step.attachTo.element) return;
      step._selector = typeof step.attachTo.element === 'string' ? step.attachTo.element : null;
      const target = resolveTourTarget(step.attachTo.element);
      if (target) {
        step.attachTo.element = target;
      }

      const existingBeforeShow = step.beforeShowPromise;
      step.beforeShowPromise = function() {
        return Promise.resolve(
            typeof existingBeforeShow === 'function' ? existingBeforeShow.call(this) : undefined
          )
          .then(async () => {
            const isMobile = window.matchMedia('(max-width: 1024px)').matches;
            const selector = step._selector;
            const attached = resolveTourTarget(selector || (step.attachTo && step.attachTo.element));
            if (attached instanceof Element) {
              if (isMobile && attached.closest('.sidebar') && !document.body.classList.contains('sidebar-open') && typeof window.toggleSidebar === 'function') {
                window.toggleSidebar();
                await waitFor(260);
              }

              if (ensureSidebarGroupsOpen(attached)) {
                await waitFor(140);
              }

              step.attachTo.element = attached;
              attached.scrollIntoView({
                behavior: 'smooth',
                block: 'center',
                inline: 'nearest'
              });
            }
          });
      };
    });

    var needsTour = <?= json_encode(!empty($_SESSION['needs_tour'])) ?>;
    var tourUser = <?= json_encode((string)($_SESSION['admin_user'] ?? '')) ?>;
    var tourDismissKey = 'admin_tour_completed_' + (tourUser || 'session');
    var localTourCompleted = false;
    try {
      localTourCompleted = window.localStorage && window.localStorage.getItem(tourDismissKey) === '1';
    } catch (e) {
      localTourCompleted = false;
    }

    function markTourLocallyDone() {
      try {
        if (window.localStorage) {
          window.localStorage.setItem(tourDismissKey, '1');
        }
      } catch (e) {}
    }

    function completeBackendTour() {
      markTourLocallyDone();
      var endpoint = 'api_tour_complete.php';
      try {
        if (navigator.sendBeacon) {
          var beacon = new Blob(['{}'], {
            type: 'application/json'
          });
          var queued = navigator.sendBeacon(endpoint, beacon);
          if (queued) {
            return;
          }
        }
      } catch (e) {}

      fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        keepalive: true,
        headers: {
          'Content-Type': 'application/json'
        },
        body: '{}'
      }).catch(function() {});
    }

    if (needsTour && localTourCompleted) {
      completeBackendTour();
      needsTour = false;
    }

    if (typeof Shepherd !== 'undefined' && needsTour) {
      window.tour = new Shepherd.Tour({
        useModalOverlay: true,
        defaultStepOptions: {
          cancelIcon: {
            enabled: true
          },
          scrollTo: {
            behavior: 'smooth',
            block: 'center'
          }
        }
      });
      steps.forEach(s => window.tour.addStep(s));

      window.tour.on('complete', completeBackendTour);
      window.tour.on('cancel', completeBackendTour);

      setTimeout(() => {
        window.tour.start();
      }, 1500);
    }
  });
</script>

<!-- Cmd+K Command Palette UI -->
<style>
  .palette-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(8px);
    z-index: 999999;
    display: none;
    align-items: flex-start;
    justify-content: center;
    padding-top: 10vh;
    opacity: 0;
    transition: opacity 0.2s ease;
  }

  .palette-overlay.active {
    display: flex;
    opacity: 1;
  }

  .palette-box {
    background: var(--surface);
    width: 90%;
    max-width: 600px;
    border-radius: var(--radius-xl);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
    border: 1px solid var(--outline-variant);
    overflow: hidden;
    transform: scale(0.95);
    transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    display: flex;
    flex-direction: column;
  }

  .palette-overlay.active .palette-box {
    transform: scale(1);
  }

  .palette-search-wrapper {
    display: flex;
    align-items: center;
    padding: 16px 24px;
    border-bottom: 1px solid var(--outline-variant);
    background: var(--surface-container-low);
  }

  .palette-search-wrapper span {
    color: var(--on-surface-variant);
    font-size: 1.5rem;
    margin-right: 12px;
  }

  .palette-input {
    border: none;
    background: transparent;
    width: 100%;
    font-size: 1.2rem;
    color: var(--on-surface);
    outline: none;
    font-family: 'Inter', sans-serif;
  }

  .palette-input::placeholder {
    color: var(--on-surface-variant);
    opacity: 0.7;
  }

  .palette-results {
    max-height: 400px;
    overflow-y: auto;
    padding: 8px 0;
  }

  .palette-item {
    padding: 12px 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    color: var(--on-surface);
    transition: background 0.1s ease;
    text-decoration: none;
    border-left: 3px solid transparent;
  }

  .palette-item:hover,
  .palette-item.active-item {
    background: var(--surface-container-high);
    border-left-color: var(--primary);
  }

  .palette-item-icon {
    color: var(--on-surface-variant);
    font-size: 1.2rem;
  }

  .palette-item-title {
    font-weight: 600;
    font-size: 0.95rem;
  }

  .palette-item-desc {
    font-size: 0.8rem;
    color: var(--on-surface-variant);
    flex: 1;
    text-align: right;
  }

  #ai-palette-loading {
    padding: 16px;
    text-align: center;
    color: var(--on-surface-variant);
    font-size: 0.9rem;
    display: none;
  }
</style>

<div class="palette-overlay" id="cmdKOverlay">
  <div class="palette-box">
    <div class="palette-search-wrapper">
      <span class="material-symbols-outlined">search</span>
      <input type="text" class="palette-input" id="cmdKInput" placeholder="Navigate or ask AI (e.g., 'how to add course?')" autocomplete="off" spellcheck="false" />
      <span style="font-size: 0.7rem; color: var(--on-surface-variant); background: var(--surface-container-high); padding: 4px 8px; border-radius: 4px; font-weight: bold;">ESC</span>
    </div>
    <div id="ai-palette-loading"><span class="material-symbols-outlined" style="animation: spin 1s linear infinite; vertical-align: middle;">sync</span> AI Processing...</div>
    <div class="palette-results" id="cmdKResults"></div>
  </div>
</div>

<script>
  const staticRoutes = [{
      id: 'dashboard',
      title: 'Dashboard',
      desc: 'Overview metrics',
      url: 'index.php?page=dashboard',
      icon: 'dashboard'
    },
    {
      id: 'status',
      title: 'Status Control',
      desc: 'Checkin/checkout mode',
      url: 'index.php?page=status',
      icon: 'analytics'
    },
    {
      id: 'settings',
      title: 'Settings',
      desc: 'System configuration',
      url: 'index.php?page=settings',
      icon: 'settings'
    },
    {
      id: 'roles',
      title: 'Roles',
      desc: 'Manage permissions',
      url: 'index.php?page=roles',
      icon: 'admin_panel_settings'
    },
    {
      id: 'accounts',
      title: 'Accounts',
      desc: 'Manage admins',
      url: 'index.php?page=accounts',
      icon: 'group'
    },
    {
      id: 'logs',
      title: 'Logs',
      desc: 'View general logs',
      url: 'index.php?page=logs',
      icon: 'history_edu'
    },
    {
      id: 'support_tickets',
      title: 'Support Tickets',
      desc: 'Resolve complaints',
      url: 'index.php?page=support_tickets',
      icon: 'support_agent'
    }
  ];

  const overlay = document.getElementById('cmdKOverlay');
  const input = document.getElementById('cmdKInput');
  const results = document.getElementById('cmdKResults');
  const loading = document.getElementById('ai-palette-loading');

  let debounceTimer;

  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
      e.preventDefault();
      openPalette();
    }
    if (e.key === 'Escape' && overlay.classList.contains('active')) {
      closePalette();
    }
  });

  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) closePalette();
  });

  function openPalette() {
    overlay.classList.add('active');
    input.value = '';
    renderResults(staticRoutes);
    setTimeout(() => input.focus(), 50);
  }

  function closePalette() {
    overlay.classList.remove('active');
    input.blur();
  }

  input.addEventListener('input', (e) => {
    const q = e.target.value.trim().toLowerCase();
    if (!q) {
      renderResults(staticRoutes);
      return;
    }
    const localMatches = staticRoutes.filter(r => r.title.toLowerCase().includes(q) || r.desc.toLowerCase().includes(q));
    renderResults(localMatches);

    clearTimeout(debounceTimer);
    if (q.length > 5 && q.includes(' ')) {
      debounceTimer = setTimeout(() => {
        queryAIPalette(q);
      }, 600);
    }
  });

  function queryAIPalette(text) {
    if (!text) return;
    loading.style.display = 'block';
    fetch('api_nav_assistant.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          query: text
        })
      })
      .then(res => res.json())
      .then(data => {
        loading.style.display = 'none';
        if (data.ok && data.results && data.results.length > 0) {
          renderResults(data.results.map(r => ({
            title: r.title,
            desc: 'AI Nav Suggestion \u2728',
            url: r.url,
            icon: 'auto_awesome'
          })));
        }
      })
      .catch(() => loading.style.display = 'none');
  }

  function renderResults(list) {
    results.innerHTML = '';
    if (list.length === 0) {
      results.innerHTML = '<div style="padding:24px;text-align:center;color:var(--on-surface-variant);">No rapid jumps found... press enter to let AI process this!</div>';
      return;
    }
    list.forEach((item, index) => {
      const div = document.createElement('a');
      div.className = 'palette-item' + (index === 0 ? ' active-item' : '');
      div.href = item.url;
      const icon = document.createElement('span');
      icon.className = 'material-symbols-outlined palette-item-icon';
      icon.textContent = item.icon || 'arrow_forward';

      const title = document.createElement('span');
      title.className = 'palette-item-title';
      title.textContent = item.title || 'Untitled';

      const desc = document.createElement('span');
      desc.className = 'palette-item-desc';
      desc.textContent = item.desc || '';

      div.appendChild(icon);
      div.appendChild(title);
      div.appendChild(desc);
      results.appendChild(div);
    });
  }

  input.addEventListener('keydown', (e) => {
    const items = Array.from(results.querySelectorAll('.palette-item'));
    const activeIdx = items.findIndex(i => i.classList.contains('active-item'));

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (activeIdx < items.length - 1) {
        if (activeIdx >= 0) items[activeIdx].classList.remove('active-item');
        items[activeIdx + 1].classList.add('active-item');
        items[activeIdx + 1].scrollIntoView({
          block: 'nearest'
        });
      }
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (activeIdx > 0) {
        items[activeIdx].classList.remove('active-item');
        items[activeIdx - 1].classList.add('active-item');
        items[activeIdx - 1].scrollIntoView({
          block: 'nearest'
        });
      }
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (activeIdx >= 0) {
        window.location.href = items[activeIdx].href;
      } else {
        queryAIPalette(input.value.trim());
      }
    }
  });
</script>
