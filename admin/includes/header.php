<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
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
        /* ignore network errors silently */ });
    }

    function refreshCurrent() {
      var container = document.querySelector('.content-wrapper');
      if (!container) return;
      fetch((currentPage || 'dashboard') + '.php', {
          cache: 'no-store'
        })
        .then(function(r) {
          if (!r.ok) throw new Error('Network');
          return r.text();
        })
        .then(function(html) {
          container.innerHTML = html;
          console.info('Refreshed ' + currentPage);
        })
        .catch(function() {
          /* ignore */ });
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
    setInterval(checkUpdates, 5000);
  })();
</script>
<!-- Chat UI -->
<?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true): ?>
  <style>
    .chat_button {
      position: fixed;
      right: 24px;
      bottom: 24px;
      height: 56px;
      width: 56px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--primary), var(--primary-container));
      border: none;
      box-shadow: 0 6px 20px rgba(0, 69, 123, 0.2);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 10001;
      transition: transform 0.2s, box-shadow 0.2s;
    }

    .chat_button:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 30px rgba(0, 69, 123, 0.3);
    }

    .chat_button .material-symbols-outlined {
      color: #fff;
      font-size: 22px;
    }

    #chatbar.chat_box {
      position: fixed;
      right: 24px;
      bottom: 92px;
      width: 360px;
      max-height: 520px;
      background: var(--surface-container-lowest);
      border-radius: var(--radius-xl);
      box-shadow: var(--shadow-elevated);
      overflow: hidden;
      z-index: 10000;
      display: none;
      flex-direction: column;
      border: 1px solid var(--outline-variant);
    }

    .chat_box_header {
      padding: 14px 16px;
      background: var(--primary);
      color: #fff;
      font-weight: 700;
      letter-spacing: 0.05em;
      font-size: 0.85rem;
    }

    .chat_box_body {
      padding: 16px;
      max-height: 360px;
      overflow-y: auto;
      background: var(--surface-container-low);
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .chat_box_body .msg {
      position: relative;
      max-width: 85%;
      padding: 10px 14px;
      border-radius: 12px;
      font-size: 0.9rem;
    }

    .chat_box_body .msg.self {
      align-self: flex-end;
      background: #059669;
      color: #fff;
      border-bottom-right-radius: 4px;
    }

    .chat_box_body .msg.other {
      align-self: flex-start;
      background: var(--surface-container-lowest);
      color: var(--on-surface);
      border: 1px solid var(--outline-variant);
      border-bottom-left-radius: 4px;
    }

    .chat_box_footer {
      padding: 10px;
      display: flex;
      gap: 8px;
      align-items: center;
      border-top: 1px solid var(--outline-variant);
      background: var(--surface-container-lowest);
    }

    .chat_box_footer input {
      flex: 1;
      padding: 10px 12px;
      border-radius: var(--radius-md);
      border: 1px solid var(--outline-variant);
      font-family: inherit;
    }

    .chat_box_footer button {
      background: var(--primary);
      color: #fff;
      border: none;
      padding: 9px 16px;
      border-radius: var(--radius-md);
      cursor: pointer;
    }

    @media (max-width:480px) {
      #chatbar.chat_box {
        right: 12px;
        left: 12px;
        bottom: 90px;
        width: auto;
      }
    }
  </style>

  <div id="chatPage" class="chat_page">
    <button id="chatToggle" class="chat_button" aria-label="Open chat">
      <span id="chatOpen" class="material-symbols-outlined">chat</span>
      <span id="chatBadge" style="display:none;position:absolute;top:-6px;right:-6px;background:var(--error);color:#fff;border-radius:999px;padding:2px 6px;font-size:0.7rem;font-weight:700;">0</span>
    </button>

    <div id="chatbar" class="chat_box">
      <div class="chat_box_header">MESSAGES</div>
      <div id="chatBody" class="chat_box_body"></div>
      <div class="chat_box_footer">
        <input type="text" id="MsgInput" placeholder="Enter Message">
        <button id="MsgSend" aria-label="Send message"><span class="material-symbols-outlined" style="font-size:18px;">send</span></button>
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
      var chatBody = document.getElementById('chatBody');
      var badge = document.getElementById('chatBadge');
      var currentUser = <?= json_encode($_SESSION['admin_user'] ?? '') ?>;
      var currentRole = <?= json_encode($_SESSION['admin_role'] ?? 'admin') ?>;

      function openChatBox() {
        if (!isOpen) {
          chatbar.style.display = 'flex';
          isOpen = true;
          icon.textContent = 'close';
          fetchAndRender();
          startPolling();
        } else {
          chatbar.style.display = 'none';
          isOpen = false;
          icon.textContent = 'chat';
          stopPolling();
        }
      }

      toggle.addEventListener('click', openChatBox);

      function renderMessages(messages) {
        if (!chatBody) return;
        chatBody.innerHTML = '';
        messages.forEach(function(m) {
          var div = document.createElement('div');
          var cls = (m.user === '<?= addslashes($_SESSION['admin_user'] ?? '') ?>') ? 'msg self' : 'msg other';
          div.className = cls;
          var d = new Date(m.time);
          var rel = d.toLocaleTimeString();
          var iso = d.toISOString();
          var title = d.toString();
          var content = '<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px;">';
          content += '<strong style="font-size:0.95rem;">' + escapeHtml(m.name) + '</strong> <span title="' + escapeHtml(title) + '" style="font-size:0.75rem;opacity:0.75;margin-left:8px;">' + escapeHtml(rel) + '</span>';
          content += '</div>';
          content += '<div style="margin-bottom:4px;line-height:1.4;">' + escapeHtml(m.message) + '</div>';
          if (currentRole === 'superadmin') {
            content += '<div style="text-align:right;margin-top:4px;"><button data-time="' + escapeHtml(m.time) + '" class="delete-msg" style="background:none;border:none;cursor:pointer;font-size:0.75rem;padding:0;text-decoration:none;font-weight:600;' + (cls.indexOf('self') > -1 ? 'color:#ffcfcf;' : 'color:var(--error);') + '">Delete</button></div>';
          }
          div.innerHTML = content;
          chatBody.appendChild(div);
        });
        chatBody.scrollTop = chatBody.scrollHeight;
      }

      var lastCount = 0;

      function fetchAndRender() {
        window.fetchChat().then(function(messages) {
          messages = messages || [];
          if (!isOpen) {
            if (messages.length > lastCount) {
              var diff = messages.length - lastCount;
              badge.style.display = 'inline-block';
              badge.textContent = diff;
            }
          } else {
            badge.style.display = 'none';
            lastCount = messages.length;
          }
          renderMessages(messages);
          if (currentRole === 'superadmin') {
            Array.from(document.getElementsByClassName('delete-msg')).forEach(function(btn) {
              btn.addEventListener('click', function() {
                var t = this.getAttribute('data-time');
                if (!t) return;
                window.adminConfirm('Delete message', 'Delete this message?').then(function(ok) {
                  if (!ok) return;
                  var payload = {
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
                      else window.adminAlert('Delete failed', JSON.stringify(r), 'error');
                    })
                    .catch(function() {
                      window.adminAlert('Delete failed', 'Network or server error', 'error');
                    });
                });
              });
            });
          }
        });
      }

      function send() {
        var v = msgInput.value.trim();
        if (!v) return;
        msgInput.value = '';
        window.postChat(v).then(function(res) {
          if (res && res.ok) {
            fetchAndRender();
          }
        });
      }

      msgSend.addEventListener('click', send);
      msgInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          send();
        }
      });

      var pollTimer = null;

      function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(fetchAndRender, 2000);
      }

      function stopPolling() {
        if (!pollTimer) return;
        clearInterval(pollTimer);
        pollTimer = null;
      }

      window.openChatBox = openChatBox;
      window.sendChatMessage = send;

    })();
  </script>
<?php endif; ?>
