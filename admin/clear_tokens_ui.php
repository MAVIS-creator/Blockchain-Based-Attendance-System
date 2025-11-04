<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }

// Gather tokens from blocked_tokens.log (generated when tab-fencing/inactivity fires)
$blockedFile = __DIR__ . '/logs/blocked_tokens.log';
$tokens = [];
if (file_exists($blockedFile)) {
  $lines = file($blockedFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    $parts = array_map('trim', explode('|', $line));
    // expected: timestamp | token | fingerprint | ip | mac | userAgent | reason
    if (count($parts) < 2) continue;
    $timestamp = $parts[0] ?? '';
    $token = $parts[1] ?? '';
    $fingerprint = $parts[2] ?? '';
    $ip = $parts[3] ?? '';
    $mac = $parts[4] ?? '';
    // optional: userAgent and reason at 5,6
    if ($token === '') continue;
    if (!isset($tokens[$token])) {
      $tokens[$token] = ['first'=>$timestamp,'last'=>$timestamp,'ips'=>[],'macs'=>[],'matrics'=>[],'count'=>0,'sample_fp'=>$fingerprint];
    }
    $tokens[$token]['count']++;
    if ($timestamp && ($tokens[$token]['first']==='' || strtotime($timestamp) < strtotime($tokens[$token]['first']))) $tokens[$token]['first'] = $timestamp;
    if ($timestamp && ($tokens[$token]['last']==='' || strtotime($timestamp) > strtotime($tokens[$token]['last']))) $tokens[$token]['last'] = $timestamp;
    if ($ip) $tokens[$token]['ips'][$ip] = true;
    if ($mac) $tokens[$token]['macs'][$mac] = true;
  }
}
// simplify sets to lists
foreach ($tokens as $k=>&$v){ $v['ips'] = array_keys($v['ips']); $v['macs'] = array_keys($v['macs']); $v['matrics'] = array_keys($v['matrics']); }
unset($v);
?>
<style>
  .panel { max-width:900px; margin:20px auto; padding:20px; background:#fff; border-radius:12px; box-shadow:0 8px 30px rgba(0,0,0,0.06);} 
  .row { display:flex; gap:16px; flex-wrap:wrap; }
  .col { flex:1; min-width:260px }
  .btn { padding:10px 14px; border-radius:8px; border:none; cursor:pointer; font-weight:600 }
  .btn-danger { background: linear-gradient(90deg,#ef4444,#d97706); color:#fff }
  .btn-primary { background: linear-gradient(90deg,#6366f1,#3b82f6); color:#fff }
  .muted { color:#6b7280 }
  input[type=file] { display:block }
  .small { font-size:0.95rem }
</style>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<div class="panel">
  <h2>Clear Attendance Tokens</h2>
  <p class="muted small">Clear server-side device-blocking entries and optionally revoke known client tokens. This will allow affected users to submit attendance again. Note: client localStorage cannot be modified server-side; clients should refresh the page after revocation.</p>

  <div class="row">
    <div class="col">
      <h3>Revoke Token</h3>
      <p class="small muted">Enter a token (attendance_token) or MAC/IP to revoke. Tokens may be collected via support tickets when users complain.</p>
      <input id="revokeToken" placeholder="token or ip or mac" style="padding:8px;border-radius:8px;border:1px solid #d1d5db;width:100%">
      <div style="margin-top:10px"><button id="revokeBtn" class="btn btn-primary">Revoke</button></div>
      <div id="revokeResult" style="margin-top:10px"></div>
    </div>

    <div class="col">
      <h3>Search & Clear</h3>
      <p class="small muted">Search for entries in today's device files and remove them (cooldowns, useragent locks, device mappings).</p>
      <input id="searchKey" placeholder="fingerprint or matric or ip or mac" style="padding:8px;border-radius:8px;border:1px solid #d1d5db;width:100%">
      <div style="margin-top:10px"><button id="searchClearBtn" class="btn btn-danger">Clear Matching Entries</button></div>
      <div id="searchClearResult" style="margin-top:10px"></div>
    </div>
  </div>
  
  <hr style="margin:18px 0">
  <h3>Known Tokens</h3>
  <p class="small muted">Tokens recorded in logs. You can revoke (add to revocation list) or clear server-side entries for each token.</p>
  <?php if (count($tokens) === 0): ?>
    <div class="muted">No tokens found in logs.</div>
  <?php else: ?>
    <div style="overflow:auto; max-height:420px; margin-top:8px;">
      <table style="width:100%; border-collapse:collapse;">
        <thead><tr style="text-align:left"><th style="padding:8px;border-bottom:1px solid #eee">Token</th><th style="padding:8px;border-bottom:1px solid #eee">First</th><th style="padding:8px;border-bottom:1px solid #eee">Last</th><th style="padding:8px;border-bottom:1px solid #eee">Count</th><th style="padding:8px;border-bottom:1px solid #eee">IPs</th><th style="padding:8px;border-bottom:1px solid #eee">MACs</th><th style="padding:8px;border-bottom:1px solid #eee">Matric</th><th style="padding:8px;border-bottom:1px solid #eee">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($tokens as $token => $info): ?>
          <tr>
            <td style="padding:8px;vertical-align:top"><code style="font-size:0.9rem"><?= htmlspecialchars($token) ?></code></td>
            <td style="padding:8px;vertical-align:top"><?= htmlspecialchars($info['first']) ?></td>
            <td style="padding:8px;vertical-align:top"><?= htmlspecialchars($info['last']) ?></td>
            <td style="padding:8px;vertical-align:top"><?= intval($info['count']) ?></td>
            <td style="padding:8px;vertical-align:top"><?= htmlspecialchars(implode(', ', array_slice($info['ips'],0,3))) ?><?php if (count($info['ips'])>3) echo '...'; ?></td>
            <td style="padding:8px;vertical-align:top"><?= htmlspecialchars(implode(', ', array_slice($info['macs'],0,2))) ?><?php if (count($info['macs'])>2) echo '...'; ?></td>
            <td style="padding:8px;vertical-align:top"><?= htmlspecialchars(implode(', ', array_slice($info['matrics'],0,2))) ?><?php if (count($info['matrics'])>2) echo '...'; ?></td>
            <td style="padding:8px;vertical-align:top">
              <button class="btn btn-primary revoke-token" data-token="<?= htmlspecialchars($token) ?>">Revoke</button>
              <button class="btn btn-danger clear-token" data-token="<?= htmlspecialchars($token) ?>">Clear</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="margin-top:12px"><button id="clearAllTokens" class="btn btn-danger">Clear All Tokens</button> <button id="revokeAllTokens" class="btn btn-primary">Revoke All Tokens</button></div>
  <?php endif; ?>
</div>

<script>
// helper to determine type
function detectType(v){ var ipPattern = /^\d{1,3}(?:\.\d{1,3}){3}$/; var macPattern = /^[0-9A-Fa-f:]{6,}$/; if (ipPattern.test(v)) return 'ip'; if (macPattern.test(v)) return 'mac'; return 'token'; }

document.getElementById('revokeBtn').addEventListener('click', function(){
  var v = document.getElementById('revokeToken').value.trim(); if (!v) { Swal.fire('Missing','Enter token/ip/mac','warning'); return; }
  var t = detectType(v);
  Swal.fire({ title: 'Revoke ' + t, input: 'number', inputLabel: 'Expiry (days)', inputValue: 7, inputAttrs: { min:1, step:1 }, showCancelButton: true }).then(function(res){ if (!res.value) return; var days = parseInt(res.value||7,10);
    var body = new URLSearchParams(); body.append(t, v); body.append('days', days);
    fetch('revoke_entry.php', { method:'POST', body: body }).then(r=>r.json()).then(j=>{ Swal.fire('Done','Revoked: '+JSON.stringify(j),'success'); }).catch(e=>Swal.fire('Error','Could not revoke','error'));
  });
});

document.getElementById('searchClearBtn').addEventListener('click', function(){
  var v = document.getElementById('searchKey').value.trim(); if (!v) { Swal.fire('Missing','Enter search key','warning'); return; }
  Swal.fire({ title:'Confirm clear', text:'Clear any entries matching this key?', icon:'warning', showCancelButton:true }).then(function(resp){ if (!resp.isConfirmed) return; fetch('clear_device.php', { method:'POST', body: new URLSearchParams({ matric: v, fingerprint: v }) }).then(r=>r.json()).then(j=>{ Swal.fire('Cleared','Result: '+JSON.stringify(j),'success'); }).catch(e=>Swal.fire('Error','Could not clear','error')); });
});

// per-token actions
Array.from(document.getElementsByClassName('clear-token')).forEach(function(b){ b.addEventListener('click', function(){ var t=this.getAttribute('data-token'); if (!t) return; Swal.fire({ title:'Clear & Revoke', text:'Clear server-side entries for this token AND revoke it on clients?', icon:'warning', showCancelButton:true }).then(function(res){ if (!res.isConfirmed) return; fetch('revoke_entry.php', { method:'POST', body: new URLSearchParams({ token: t, days:7 }) }).then(function(){ return fetch('clear_device.php', { method:'POST', body: new URLSearchParams({ fingerprint: t }) }); }).then(r=>r.json()).then(j=>{ Swal.fire('Done','Cleared & Revoked','success').then(()=>location.reload()); }).catch(e=>Swal.fire('Error','Operation failed','error')); }); }); });

Array.from(document.getElementsByClassName('revoke-token')).forEach(function(b){ b.addEventListener('click', function(){ var t=this.getAttribute('data-token'); if (!t) return; Swal.fire({ title:'Revoke token', input:'number', inputLabel:'Expiry (days)', inputValue:7, showCancelButton:true }).then(function(res){ if (!res.value) return; var days = parseInt(res.value||7,10); fetch('revoke_entry.php', { method:'POST', body: new URLSearchParams({ token: t, days: days }) }).then(r=>r.json()).then(j=>{ Swal.fire('Revoked','Result saved','success'); }).catch(e=>Swal.fire('Error','Could not revoke','error')); }); }); });

document.getElementById('clearAllTokens')?.addEventListener('click', function(){ var tokens = Array.from(document.querySelectorAll('button.clear-token')).map(b=>b.getAttribute('data-token')); if (!tokens.length) return Swal.fire('Empty','No tokens','info'); Swal.fire({ title:'Clear & Revoke All', text:'Clear server-side entries for ALL tokens found in logs and revoke them?', icon:'warning', showCancelButton:true }).then(function(resp){ if (!resp.isConfirmed) return; var promises = tokens.map(t=> fetch('revoke_entry.php', { method:'POST', body: new URLSearchParams({ token: t, days:7 }) }).then(function(){ return fetch('clear_device.php', { method:'POST', body: new URLSearchParams({ fingerprint: t }) }); }).then(r=>r.json())); Promise.all(promises).then(results=>{ Swal.fire('Done','Cleared & Revoked all tokens','success').then(()=>location.reload()); }).catch(e=>Swal.fire('Error','Error clearing some tokens','error')); }); });

document.getElementById('revokeAllTokens')?.addEventListener('click', function(){ var tokens = Array.from(document.querySelectorAll('button.revoke-token')).map(b=>b.getAttribute('data-token')); if (!tokens.length) return Swal.fire('Empty','No tokens','info'); Swal.fire({ title:'Revoke All Tokens', input:'number', inputLabel:'Expiry (days)', inputValue:7, showCancelButton:true }).then(function(res){ if (!res.value) return; var days = parseInt(res.value||7,10); var promises = tokens.map(t=> fetch('revoke_entry.php', { method:'POST', body: new URLSearchParams({ token: t, days: days }) }).then(r=>r.json())); Promise.all(promises).then(results=>{ Swal.fire('Done','Revoked all tokens','success'); }).catch(e=>Swal.fire('Error','Error revoking some tokens','error')); }); });

// Try to open SSE connection for immediate revocation push (optional)
if (typeof(EventSource) !== 'undefined') {
  try {
    var es = new EventSource('revoke_sse.php');
    es.addEventListener('revoked', function(e){ try { var payload = JSON.parse(e.data); if (payload && payload.revoked && payload.revoked.tokens) {
        var myToken = localStorage.getItem('attendance_token');
        if (myToken && payload.revoked.tokens[myToken]) {
          // clear local token and notify user
          localStorage.removeItem('attendance_token');
          localStorage.removeItem('attendanceBlocked');
          Swal.fire('Revoked','Your attendance token was revoked by admin. Please reload the page.','info');
        }
      } } catch(ignore){}
    });
  } catch(e) { /* ignore, fallback polling remains */ }
}
</script>
