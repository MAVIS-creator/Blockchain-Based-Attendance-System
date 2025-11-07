<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<header class="page-header" style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;background:#fff;border-bottom:1px solid #eee;">
  <div style="display:flex;align-items:center;gap:12px;">
    <img src="../asset/lautech_logo.png" alt="logo" style="height:40px;width:auto;background:#fff;padding:6px;border-radius:6px;box-shadow:0 6px 18px rgba(16,24,40,0.06)">
    <h1 style="margin:0;font-size:1.2rem;color:#333;">Attendance Admin Panel</h1>
  </div>

  <div style="display:flex;align-items:center;gap:12px;">
    <?php if (!empty($_SESSION['admin_name'])): ?>
      <?php
        $adminName = htmlspecialchars($_SESSION['admin_name']);
        $adminAvatar = $_SESSION['admin_avatar'] ?? null;
        // Build initials avatar if no avatar set
        $initials = trim(array_reduce(explode(' ', $adminName), function($carry, $part){ return $carry . ($part[0] ?? ''); }, ''));
        $initials = strtoupper(substr($initials, 0, 2));
      ?>
      <div style="display:flex;align-items:center;gap:10px;">
         <img src="../asset/lautech_logo.png" alt="logo" style="height:40px;width:auto;background:#fff;padding:6px;border-radius:6px;">
         <?php if ($adminAvatar): ?>
           <img class="admin-avatar" src="<?= htmlspecialchars($adminAvatar) ?>" alt="avatar">
         <?php else: ?>
           <span class="admin-initials"><?= $initials ?></span>
         <?php endif; ?>
      </div>
      <div style="font-weight:600;color:#333;"><?= $adminName ?></div>
    <?php else: ?>
      <a href="login.php" style="color:#333;text-decoration:none;font-weight:600;">Login</a>
    <?php endif; ?>
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
  // make CSRF token available to admin scripts
  window.ADMIN_CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
  // Lightweight helpers so pages can replace native alert/confirm easily
  window.adminAlert = function(title, text, icon){
    icon = icon || 'info';
    return Swal.fire({ title: title || '', text: text || '', icon: icon, confirmButtonText: 'OK' });
  };
  window.adminConfirm = function(title, text){
    return Swal.fire({ title: title || 'Confirm', text: text || '', icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes', cancelButtonText: 'Cancel' }).then(function(res){ return !!res.isConfirmed; });
  };
</script>
<script>
  // detect whether icon fonts loaded; if not, enable fallback CSS
  (function(){
    try{
      var span = document.createElement('span'); span.className='fa'; span.style.display='none'; document.body.appendChild(span);
      var ff = window.getComputedStyle(span).getPropertyValue('font-family') || '';
      document.body.removeChild(span);
      ff = ff.toLowerCase();
      if (ff.indexOf('fontawesome') === -1 && ff.indexOf('boxicons') === -1) {
        document.body.classList.add('icons-fallback');
      }
    }catch(e){ document.body.classList.add('icons-fallback'); }
  })();
</script>
<script>
  (function(){
    var currentPage = '<?= htmlspecialchars($page ?? '') ?>';
    var lastKnown = {};
    function safeFetch(url){ return fetch(url, {cache:'no-store'}).then(function(r){ if(!r.ok) throw new Error('Network'); return r.json(); }); }
    function checkUpdates(){
      safeFetch('_last_updates.php').then(function(data){
        if (!lastKnown.accounts) { lastKnown = data; return; }
        var changed = false;
        // list of keys to check. If current page matches any key name we attempt to refresh.
        var keys = ['accounts','settings','chain','tickets','fingerprints','courses','active_course','status','view_tickets_page','unlink_page','add_course_page','chat'];
        keys.forEach(function(k){
          if ((data[k]||0) !== (lastKnown[k]||0)) {
            lastKnown[k] = data[k];
            changed = true;
            // if the user is viewing a page that corresponds to this key, refresh it
            if (currentPage === 'accounts' && k === 'accounts') refreshCurrent();
            if (currentPage === 'settings' && k === 'settings') refreshCurrent();
            if (currentPage === 'chain' && k === 'chain') refreshCurrent();
            if (currentPage === 'logs' && (k === 'tickets' || k === 'view_tickets_page')) refreshCurrent();
            if (currentPage === 'failed_attempts' && k === 'fingerprints') refreshCurrent();
            if (currentPage === 'add_course' && k === 'add_course_page') refreshCurrent();
            if (currentPage === 'set_active' && (k === 'active_course' || k === 'courses')) refreshCurrent();
            if (currentPage === 'status' && k === 'status') refreshCurrent();
            // chat handled separately
            if (k === 'chat') fetchChat();
          }
        });
        // small global refresh if something changed and not on a named page
        if (changed && currentPage === '') {
          location.reload();
        }
      }).catch(function(){ /* ignore network errors silently */ });
    }
    function refreshCurrent(){
      var container = document.querySelector('.content-wrapper');
      if (!container) return;
      fetch((currentPage || 'dashboard') + '.php', {cache:'no-store'})
        .then(function(r){ if(!r.ok) throw new Error('Network'); return r.text(); })
        .then(function(html){ container.innerHTML = html; console.info('Refreshed '+currentPage); })
        .catch(function(){ /* ignore */ });
    }
    // Chat: fetch and render
    function renderChat(messages){
      var w = document.getElementById('admin-chat-window');
      if (!w) return;
      var html = '';
      messages.forEach(function(m){
        var time = new Date(m.time).toLocaleTimeString();
        html += '<div class="chat-msg"><strong>'+escapeHtml(m.name)+"</strong> <span class='muted'>"+time+"</span><div>"+escapeHtml(m.message)+"</div></div>";
      });
      w.innerHTML = html;
      w.scrollTop = w.scrollHeight;
    }
    function fetchChat(){
      return fetch('chat_fetch.php', {cache:'no-store'}).then(function(r){ if(!r.ok) throw new Error('Network'); return r.json(); }).then(function(data){ if (data.error) return []; return data; }).catch(function(){ return []; });
    }
    function postChat(msg){
      var payload = { message: msg };
      if (window.ADMIN_CSRF_TOKEN) payload.csrf_token = window.ADMIN_CSRF_TOKEN;
      return fetch('chat_post.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)}).then(function(r){ if(!r.ok) throw new Error('Network'); return r.json(); }).catch(function(){ return {ok:false}; });
    }
    function escapeHtml(s){ return String(s).replace(/[&<>\"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    // expose to global scope so other scripts can call
    window.fetchChat = fetchChat;
    window.postChat = postChat;
    window.escapeHtml = escapeHtml;
    // initial load
    checkUpdates();
    setInterval(checkUpdates, 5000);

    // chat polling handled by the chat UI which will call window.fetchChat
  })();
</script>
<!-- Chat UI: replaced with user-provided style and behavior -->
<?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']===true): ?>
<style>
  /* Chat button */
  .chat_button{ position:fixed; right:24px; bottom:24px; height:64px; width:64px; border-radius:50%; background:#fff; border:none; box-shadow:0 6px 20px rgba(0,0,0,0.12); cursor:pointer; display:flex;align-items:center;justify-content:center; z-index:10001; }
  .chat_button i { color: #3b82f6; font-size:22px; }
  .chat_button:hover { transform:translateY(-3px); }

  /* Chat box */
  #chatbar.chat_box { position:fixed; right:24px; bottom:100px; width:360px; max-height:520px; background:#fff; border-radius:12px; box-shadow:0 12px 40px rgba(0,0,0,0.12); overflow:hidden; z-index:10000; display:none; flex-direction:column; }
  .chat_box_header { padding:14px 16px; background:linear-gradient(90deg,#6366f1,#3b82f6); color:#fff; font-weight:700; letter-spacing:1px; }
  .chat_box_body { padding:12px; max-height:360px; overflow:auto; background: #f8fafc; }
  .chat_box_body .msg { clear:both; margin:8px 0; max-width:80%; padding:10px 12px; border-radius:14px; font-size:0.95rem; }
  .chat_box_body .msg.self { background: linear-gradient(90deg,#10b981,#059669); color:#fff; float:right; }
  .chat_box_body .msg.other { background:#eef2ff; color:#0f172a; float:left; }
  .chat_box_footer { padding:10px; display:flex; gap:8px; align-items:center; border-top:1px solid #eef2ff; background:#fff; }
  .chat_box_footer input { flex:1; padding:10px 12px; border-radius:10px; border:1px solid #e6eef9; }
  .chat_box_footer button { background:#3b82f6; color:#fff; border:none; padding:9px 12px; border-radius:8px; cursor:pointer; }

  /* small screens */
  @media (max-width:480px){ #chatbar.chat_box{ right:12px; left:12px; bottom:90px; width:auto; } }
</style>

<!-- Chat button and box HTML -->
<div id="chatPage" class="chat_page">
  <button id="chatToggle" class="chat_button" aria-label="Open chat">
    <i id="chatOpen" class="bx bx-message-rounded"></i>
    <span id="chatBadge" style="display:none;position:absolute;top:-6px;right:-6px;background:#ef4444;color:#fff;border-radius:999px;padding:2px 6px;font-size:0.75rem;">0</span>
  </button>

  <div id="chatbar" class="chat_box animated fadeInUp">
    <div class="chat_box_header">MESSAGES</div>
    <div id="chatBody" class="chat_box_body"></div>
    <div class="chat_box_footer">
      <input type="text" id="MsgInput" placeholder="Enter Message">
      <button id="MsgSend" aria-label="Send message"><i class="bx bx-send"></i></button>
    </div>
  </div>
</div>

<script>
  (function(){
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

    function openChatBox(){
      if(!isOpen){ chatbar.style.display='flex'; isOpen = true; icon.classList.remove('fa-comments'); icon.classList.add('fa-times'); fetchAndRender(); startPolling(); }
      else { chatbar.style.display='none'; isOpen = false; icon.classList.add('fa-comments'); icon.classList.remove('fa-times'); stopPolling(); }
    }

    toggle.addEventListener('click', openChatBox);

    function renderMessages(messages){
      if(!chatBody) return;
      chatBody.innerHTML = '';
      messages.forEach(function(m){
        var div = document.createElement('div');
        var cls = (m.user === '<?= addslashes($_SESSION['admin_user'] ?? '') ?>') ? 'msg self' : 'msg other';
        div.className = cls;
        var d = new Date(m.time);
        var rel = d.toLocaleTimeString();
        var iso = d.toISOString();
        var title = d.toString();
        // message container with timestamp title and optional delete for superadmin
        var content = '<strong>'+escapeHtml(m.name)+'</strong> <span title="'+escapeHtml(title)+'" style="font-size:0.75rem;color:#475569;margin-left:8px;">'+escapeHtml(rel)+'</span>';
        content += '<div style="margin-top:6px;">'+escapeHtml(m.message)+'</div>';
        if (currentRole === 'superadmin') {
          content += '<div style="margin-top:6px;text-align:right;"><button data-time="'+escapeHtml(m.time)+'" class="delete-msg" style="background:transparent;border:none;color:#ef4444;cursor:pointer;font-size:0.85rem;">Delete</button></div>';
        }
        div.innerHTML = content;
        chatBody.appendChild(div);
      });
      chatBody.scrollTop = chatBody.scrollHeight;
    }

    var lastCount = 0;
    function fetchAndRender(){
      window.fetchChat().then(function(messages){
        messages = messages || [];
        // update unread badge if closed
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
        // attach delete handlers
        if (currentRole === 'superadmin') {
          Array.from(document.getElementsByClassName('delete-msg')).forEach(function(btn){
            btn.addEventListener('click', function(){
              var t = this.getAttribute('data-time');
              if (!t) return;
              // use the SweetAlert2-based helper
              window.adminConfirm('Delete message', 'Delete this message?').then(function(ok){
                if (!ok) return;
                var payload = { time: t };
                if (window.ADMIN_CSRF_TOKEN) payload.csrf_token = window.ADMIN_CSRF_TOKEN;
                fetch('chat_delete.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)})
                  .then(function(r){ return r.json(); })
                  .then(function(r){ if (r && r.ok) fetchAndRender(); else window.adminAlert('Delete failed', JSON.stringify(r), 'error'); })
                  .catch(function(){ window.adminAlert('Delete failed', 'Network or server error', 'error'); });
              });
            });
          });
        }
      });
    }

    // send message
    function send(){ var v = msgInput.value.trim(); if(!v) return; msgInput.value=''; window.postChat(v).then(function(res){ if(res && res.ok){ fetchAndRender(); } }); }

    msgSend.addEventListener('click', send);
    msgInput.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ e.preventDefault(); send(); } });

  // poll for new messages every 2s when open; also refresh when file changes via header's checkUpdates
  var pollTimer = null;
  function startPolling(){ if(pollTimer) return; pollTimer = setInterval(fetchAndRender, 2000); }
  function stopPolling(){ if(!pollTimer) return; clearInterval(pollTimer); pollTimer = null; }

    // expose functions for other scripts
    window.openChatBox = openChatBox;
    window.sendChatMessage = send;

  })();
</script>
<?php endif; ?>
