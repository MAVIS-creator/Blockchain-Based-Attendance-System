<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<header class="page-header" style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;background:#fff;border-bottom:1px solid #eee;">
  <div style="display:flex;align-items:center;gap:12px;">
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
        <div title="<?= $adminName ?>" style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#3b82f6);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;">
          <?= $adminAvatar ? '<img src="'.htmlspecialchars($adminAvatar).'" alt="avatar" style="width:36px;height:36px;border-radius:50%;object-fit:cover;">' : $initials ?>
        </div>
        <div style="font-weight:600;color:#333;"><?= $adminName ?></div>
      </div>
    <?php else: ?>
      <a href="login.php" style="color:#333;text-decoration:none;font-weight:600;">Login</a>
    <?php endif; ?>
  </div>
</header>
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
      fetch('chat_fetch.php', {cache:'no-store'}).then(function(r){ if(!r.ok) throw new Error('Network'); return r.json(); }).then(function(data){ if (data.error) return; renderChat(data); }).catch(function(){});
    }
    function postChat(msg){
      fetch('chat_post.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({message: msg})}).then(function(r){ if(!r.ok) throw new Error('Network'); return r.json(); }).then(function(d){ if (d.ok) fetchChat(); }).catch(function(){ alert('Failed to send'); });
    }
    function escapeHtml(s){ return String(s).replace(/[&<>\"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    // initial load
    checkUpdates();
    setInterval(checkUpdates, 5000);

    // chat polling every 2s
    setInterval(fetchChat, 2000);
  })();
</script>
<!-- Floating admin chat box -->
<style>
  #admin-chat { position:fixed; right:18px; bottom:18px; width:300px; max-height:420px; z-index:9999; }
  #admin-chat .chat-panel { background:#fff;border:1px solid #e6e6e6;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,0.08);overflow:hidden;display:flex;flex-direction:column; }
  #admin-chat .chat-header { padding:8px 10px;background:linear-gradient(90deg,#6366f1,#3b82f6);color:#fff;font-weight:700; }
  #admin-chat-window { padding:8px; overflow:auto; flex:1; background:#fbfbfd; max-height:260px; }
  #admin-chat input[type=text]{ border:1px solid #e6e6e6;padding:8px;border-radius:6px;width:100%; }
  #admin-chat .chat-footer { padding:8px; display:flex; gap:8px; align-items:center; }
  .chat-msg { margin-bottom:10px; font-size:0.9rem; }
  .chat-msg .muted { color:#6b7280; font-size:0.8rem; margin-left:6px; }
</style>
<?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']===true): ?>
<div id="admin-chat">
  <div class="chat-panel">
    <div class="chat-header">Admin Chat</div>
    <div id="admin-chat-window"></div>
    <div class="chat-footer">
      <input id="admin-chat-input" type="text" placeholder="Type a message...">
      <button id="admin-chat-send" style="padding:6px 8px;background:#10b981;color:#fff;border:none;border-radius:6px;">Send</button>
    </div>
  </div>
</div>
<script>
  (function(){
    var input = document.getElementById('admin-chat-input');
    var send = document.getElementById('admin-chat-send');
    send.addEventListener('click', function(){ var v = input.value.trim(); if(!v) return; postChat(v); input.value=''; });
    input.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ e.preventDefault(); send.click(); } });
    // initial fetch
    fetchChat();
  })();
</script>
<?php endif; ?>
