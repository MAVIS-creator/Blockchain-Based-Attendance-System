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
        ['accounts','settings','chain'].forEach(function(k){
          if ((data[k]||0) !== (lastKnown[k]||0)) {
            lastKnown[k] = data[k];
            changed = true;
            if (currentPage === k) refreshCurrent();
          }
        });
        // global refresh if something changed and not on same page
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
    // initial load
    checkUpdates();
    setInterval(checkUpdates, 5000);
  })();
</script>
