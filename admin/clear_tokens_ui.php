<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
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
</div>

<script>
document.getElementById('revokeBtn').addEventListener('click', function(){
  var v = document.getElementById('revokeToken').value.trim(); if (!v) { alert('Enter token/ip/mac'); return; }
  // Try to guess if token looks like token (has - or uuid) or ip or mac
  var tokenPattern = /^[0-9a-fA-F\-]{8,}$/;
  var ipPattern = /^\d{1,3}(?:\.\d{1,3}){3}$/;
  var macPattern = /^[0-9A-Fa-f:]{6,}$/;
  var body = new URLSearchParams();
  if (ipPattern.test(v)) body.append('ip', v);
  else if (macPattern.test(v)) body.append('mac', v);
  else body.append('token', v);
  fetch('revoke_entry.php', { method:'POST', body: body }).then(r=>r.json()).then(j=>{ document.getElementById('revokeResult').textContent = JSON.stringify(j); }).catch(e=>document.getElementById('revokeResult').textContent='Error');
});

document.getElementById('searchClearBtn').addEventListener('click', function(){
  var v = document.getElementById('searchKey').value.trim(); if (!v) { alert('Enter search key'); return; }
  if (!confirm('Clear any entries matching this key?')) return;
  fetch('clear_device.php', { method:'POST', body: new URLSearchParams({ matric: v, fingerprint: v }) }).then(r=>r.json()).then(j=>{ document.getElementById('searchClearResult').textContent = JSON.stringify(j); }).catch(e=>document.getElementById('searchClearResult').textContent='Error');
});
</script>
