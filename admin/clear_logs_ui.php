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
  /* modal */
  .modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:2000 }
  .modal .dialog { background:#fff;padding:18px;border-radius:10px;max-width:520px;width:90%; }
</style>

<div class="panel">
  <h2>Logs — Backup / Restore / Clear</h2>
  <p class="muted small">Create backups of logs and fingerprints, restore from a backup, or clear data. Clearing is irreversible; use backup before clearing.</p>

  <div class="row">
    <div class="col">
      <h3>Backup</h3>
      <p class="small muted">Create a ZIP backup of `admin/logs`, `admin/backups`, `admin/fingerprints.json` and `secure_logs`.</p>
      <button id="backupBtn" class="btn btn-primary">Create Backup</button>
      <div id="backupResult" style="margin-top:10px"></div>
    </div>

    <div class="col">
      <h3>Restore</h3>
      <p class="small muted">Upload a previously created backup ZIP to restore logs/fingerprints/chain.</p>
      <form id="restoreForm" enctype="multipart/form-data">
        <label class="file-upload" style="display:inline-block;cursor:pointer;background:#f3f4f6;border:1px dashed #d1d5db;padding:10px 14px;border-radius:8px;">
          <input type="file" name="backup" accept=".zip" required style="display:none">
          <span id="fileLabel">Choose a ZIP file…</span>
        </label>
        <div style="margin-top:8px"><button class="btn btn-primary">Upload & Restore</button></div>
      </form>
      <div id="restoreResult" style="margin-top:10px"></div>
    </div>

    <div class="col">
      <h3>Clear</h3>
      <p class="small muted">Select scopes to delete. Backup recommended first.</p>
      <div style="display:flex;gap:8px;flex-direction:column;">
        <label><input type="checkbox" id="scopeLogs" checked> Logs (daily .log files)</label>
        <label><input type="checkbox" id="scopeBackups"> Backups folder</label>
        <label><input type="checkbox" id="scopeChain"> Attendance chain</label>
        <label><input type="checkbox" id="scopeFingerprints"> fingerprints.json</label>
      </div>
      <div style="margin-top:12px">
        <button id="clearSelectedBtn" class="btn btn-danger">Clear Selected</button>
      </div>
      <div id="clearResult" style="margin-top:10px"></div>
    </div>
  </div>
</div>

<!-- modal confirmation -->
<div id="confirmModal" class="modal"><div class="dialog"><h3 id="confirmTitle">Confirm</h3><p id="confirmBody"></p><div style="text-align:right;margin-top:12px"><button id="confirmCancel" class="btn">Cancel</button> <button id="confirmOk" class="btn btn-danger">Proceed</button></div></div></div>

<script>
  document.getElementById('backupBtn').addEventListener('click', function(){
    var btn = this;
    btn.disabled = true; btn.textContent = 'Creating...';
    fetch('backup_logs.php', { method:'POST', headers: {'X-CSRF-Token': window.ADMIN_CSRF_TOKEN } })
      .then(function(r){ return r.json(); })
      .then(function(j){ btn.disabled=false; btn.textContent='Create Backup'; if (j && j.ok && j.file) { document.getElementById('backupResult').innerHTML = 'Backup created: <a href="backups/'+encodeURIComponent(j.file)+'" target="_blank">'+j.file+'</a>'; } else document.getElementById('backupResult').textContent = 'Backup failed: '+JSON.stringify(j); })
      .catch(function(e){ btn.disabled=false; btn.textContent='Create Backup'; document.getElementById('backupResult').textContent='Error'; });
  });

document.getElementById('restoreForm').addEventListener('submit', function(e){
  e.preventDefault();
  var fd = new FormData(this);
  // include CSRF token
  if (window.ADMIN_CSRF_TOKEN) fd.append('csrf_token', window.ADMIN_CSRF_TOKEN);
  document.getElementById('restoreResult').textContent = 'Uploading...';
  fetch('restore_logs.php', { method:'POST', body: fd }).then(r=>r.json()).then(j=>{
    if (j && j.ok) document.getElementById('restoreResult').textContent = 'Restore successful';
    else document.getElementById('restoreResult').textContent = 'Restore failed: '+JSON.stringify(j);
  }).catch(err=> document.getElementById('restoreResult').textContent='Error');
});

// file picker label update
var fileInput = document.querySelector('#restoreForm input[type=file]');
if (fileInput) {
  fileInput.addEventListener('change', function(){
    var f = this.files && this.files[0];
    document.getElementById('fileLabel').textContent = f ? (f.name + ' (' + Math.round(f.size/1024) + 'KB)') : 'Choose a ZIP file…';
  });
}

function showConfirm(title, body, okCb){
  document.getElementById('confirmTitle').textContent = title;
  document.getElementById('confirmBody').textContent = body;
  var modal = document.getElementById('confirmModal'); modal.style.display='flex';
  var ok = document.getElementById('confirmOk'); var cancel = document.getElementById('confirmCancel');
  function cleanup(){ modal.style.display='none'; ok.removeEventListener('click',okCbWrap); cancel.removeEventListener('click',cancelWrap); }
  function okCbWrap(){ cleanup(); okCb(); }
  function cancelWrap(){ cleanup(); }
  ok.addEventListener('click', okCbWrap); cancel.addEventListener('click', cancelWrap);
}

document.getElementById('clearSelectedBtn').addEventListener('click', function(){
  var scopes = [];
  if (document.getElementById('scopeLogs').checked) scopes.push('logs');
  if (document.getElementById('scopeBackups').checked) scopes.push('backups');
  if (document.getElementById('scopeChain').checked) scopes.push('chain');
  if (document.getElementById('scopeFingerprints').checked) scopes.push('fingerprints');
  if (!scopes.length) { window.adminAlert('Select items','Select something to clear','warning'); return; }
  window.adminConfirm('Confirm Clear', 'This will permanently delete selected items. Proceed?').then(function(ok){
    if (!ok) return;
    var body = new URLSearchParams(); body.append('scope', scopes.join(',')); body.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');
    fetch('clear_logs.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token': window.ADMIN_CSRF_TOKEN }, body: body.toString() })
      .then(r=>r.json()).then(j=>{ document.getElementById('clearResult').textContent = JSON.stringify(j); if (j && j.ok) { window.adminAlert('Cleared','Selected items removed','success'); } })
      .catch(e=>document.getElementById('clearResult').textContent='Error');
  });
});
</script>
