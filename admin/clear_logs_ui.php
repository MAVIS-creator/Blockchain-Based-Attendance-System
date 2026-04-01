<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
?>

<div style="max-width:900px;margin:0 auto;">
  <div style="margin-bottom:24px;">
    <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;">
      <span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;">settings_backup_restore</span>Logs & Backups
    </h2>
    <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">Create backups, restore data, or clear system logs.</p>
  </div>

  <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start;">

    <!-- Backup Card -->
    <div class="st-card" style="flex:1;min-width:280px;">
      <h3 style="margin:0 0 8px;font-size:1.1rem;color:var(--on-surface);display:flex;align-items:center;gap:6px;">
        <span class="material-symbols-outlined" style="font-size:1.2rem;color:var(--primary);">backup</span> Backup
      </h3>
      <p style="font-size:0.85rem;color:var(--on-surface-variant);margin:0 0 16px;">Create a ZIP of logs, backups, fingerprints, and secure records.</p>

      <button id="backupBtn" class="st-btn st-btn-primary" style="width:100%;">
        <span class="material-symbols-outlined" style="font-size:1.1rem;">archive</span> Create Backup
      </button>
      <div id="backupResult" style="margin-top:12px;font-size:0.85rem;"></div>
    </div>

    <!-- Restore Card -->
    <div class="st-card" style="flex:1;min-width:280px;">
      <h3 style="margin:0 0 8px;font-size:1.1rem;color:var(--on-surface);display:flex;align-items:center;gap:6px;">
        <span class="material-symbols-outlined" style="font-size:1.2rem;color:var(--success);">restore</span> Restore
      </h3>
      <p style="font-size:0.85rem;color:var(--on-surface-variant);margin:0 0 16px;">Upload a previously created ZIP backup to restore data.</p>

      <form id="restoreForm" enctype="multipart/form-data">
        <label style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;border:2px dashed var(--outline-variant);border-radius:12px;background:var(--surface-container-lowest);cursor:pointer;transition:all 0.2s ease;">
          <input type="file" name="backup" accept=".zip" required style="display:none">
          <span class="material-symbols-outlined" style="font-size:1.2rem;color:var(--on-surface-variant);">upload_file</span>
          <span id="fileLabel" style="font-weight:600;font-size:0.9rem;color:var(--on-surface-variant);">Choose ZIP file…</span>
        </label>
        <button type="submit" class="st-btn st-btn-success" style="width:100%;margin-top:12px;">
          <span class="material-symbols-outlined" style="font-size:1.1rem;">cloud_upload</span> Upload & Restore
        </button>
      </form>
      <div id="restoreResult" style="margin-top:12px;font-size:0.85rem;"></div>
    </div>

  </div>

  <!-- Clear Card -->
  <div class="st-card" style="margin-top:20px;border-color:var(--error-container);">
    <h3 style="margin:0 0 8px;font-size:1.1rem;color:var(--error);display:flex;align-items:center;gap:6px;">
      <span class="material-symbols-outlined" style="font-size:1.2rem;">delete_sweep</span> Clear Data
    </h3>
    <p style="font-size:0.85rem;color:var(--on-surface-variant);margin:0 0 16px;">Select scopes to permanently delete. Backup recommended first.</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;background:var(--surface-container-lowest);padding:16px;border-radius:12px;border:1px solid var(--outline-variant);margin-bottom:16px;">
      <label style="display:flex;align-items:center;gap:8px;font-size:0.9rem;color:var(--on-surface);cursor:pointer;font-weight:600;">
        <input type="checkbox" id="scopeLogs" checked> Logs (daily .log files)
      </label>
      <label style="display:flex;align-items:center;gap:8px;font-size:0.9rem;color:var(--on-surface);cursor:pointer;font-weight:600;">
        <input type="checkbox" id="scopeBackups"> Backups folder
      </label>
      <label style="display:flex;align-items:center;gap:8px;font-size:0.9rem;color:var(--on-surface);cursor:pointer;font-weight:600;">
        <input type="checkbox" id="scopeChain"> Attendance chain
      </label>
      <label style="display:flex;align-items:center;gap:8px;font-size:0.9rem;color:var(--on-surface);cursor:pointer;font-weight:600;">
        <input type="checkbox" id="scopeFingerprints"> fingerprints.json
      </label>
    </div>

    <button id="clearSelectedBtn" class="st-btn st-btn-danger">
      <span class="material-symbols-outlined" style="font-size:1.1rem;">delete_forever</span> Clear Selected
    </button>
    <div id="clearResult" style="margin-top:12px;font-size:0.85rem;"></div>
  </div>

</div>

<!-- Internal custom confirm modal replaced by SweetAlert via global window.adminConfirm, same logic -->

<script>
  document.getElementById('backupBtn').addEventListener('click', function(){
    var btn = this;
    const ogHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:1.1rem;animation:spin 1s linear infinite;">refresh</span> Creating...';
    fetch('backup_logs.php', { method:'POST', headers: {'X-CSRF-Token': window.ADMIN_CSRF_TOKEN } })
      .then(function(r){ return r.json(); })
      .then(function(j){
        btn.disabled=false;
        btn.innerHTML=ogHtml;
        if (j && j.ok && j.file) {
          document.getElementById('backupResult').innerHTML = '<span style="color:var(--success);font-weight:600;">Backup created:</span> <a href="backups/'+encodeURIComponent(j.file)+'" target="_blank" style="color:var(--primary);text-decoration:none;">'+j.file+'</a>';
        } else {
          document.getElementById('backupResult').innerHTML = '<span style="color:var(--error);font-weight:600;">Backup failed:</span> '+JSON.stringify(j);
        }
      })
      .catch(function(e){
        btn.disabled=false;
        btn.innerHTML=ogHtml;
        document.getElementById('backupResult').innerHTML='<span style="color:var(--error);font-weight:600;">Network error.</span>';
      });
  });

document.getElementById('restoreForm').addEventListener('submit', function(e){
  e.preventDefault();
  var fd = new FormData(this);
  if (window.ADMIN_CSRF_TOKEN) fd.append('csrf_token', window.ADMIN_CSRF_TOKEN);
  document.getElementById('restoreResult').innerHTML = '<span style="color:var(--on-surface-variant);">Uploading and restoring...</span>';
  fetch('restore_logs.php', { method:'POST', body: fd }).then(r=>r.json()).then(j=>{
    if (j && j.ok) document.getElementById('restoreResult').innerHTML = '<span style="color:var(--success);font-weight:600;">Restore successful.</span>';
    else document.getElementById('restoreResult').innerHTML = '<span style="color:var(--error);font-weight:600;">Restore failed:</span> '+JSON.stringify(j);
  }).catch(err=> document.getElementById('restoreResult').innerHTML='<span style="color:var(--error);font-weight:600;">Restore transfer error.</span>');
});

var fileInput = document.querySelector('#restoreForm input[type=file]');
var fileLabel = document.getElementById('fileLabel');
if (fileInput) {
  fileInput.addEventListener('change', function(){
    var f = this.files && this.files[0];
    if (f) {
      fileLabel.textContent = f.name + ' (' + Math.round(f.size/1024) + 'KB)';
      fileLabel.parentElement.style.borderColor = 'var(--success)';
      fileLabel.parentElement.style.background = 'var(--success-container)';
    } else {
      fileLabel.textContent = 'Choose ZIP file…';
      fileLabel.parentElement.style.borderColor = 'var(--outline-variant)';
      fileLabel.parentElement.style.background = 'var(--surface-container-lowest)';
    }
  });
}

document.getElementById('clearSelectedBtn').addEventListener('click', function(){
  var scopes = [];
  if (document.getElementById('scopeLogs').checked) scopes.push('logs');
  if (document.getElementById('scopeBackups').checked) scopes.push('backups');
  if (document.getElementById('scopeChain').checked) scopes.push('chain');
  if (document.getElementById('scopeFingerprints').checked) scopes.push('fingerprints');

  if (!scopes.length) {
    if (window.adminAlert) window.adminAlert('Select items','Select something to clear','warning');
    return;
  }

  if (window.adminConfirm) {
    window.adminConfirm('Confirm Clear', 'This will permanently delete selected items. Proceed?', 'warning').then(function(ok){
      if (!ok) return;
      executeClear(scopes);
    });
  }
});

function executeClear(scopes) {
    var body = new URLSearchParams();
    body.append('scope', scopes.join(','));
    body.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');

    document.getElementById('clearResult').innerHTML = '<span style="color:var(--on-surface-variant);font-weight:600;">Processing...</span>';

    fetch('clear_logs.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token': window.ADMIN_CSRF_TOKEN }, body: body.toString() })
      .then(r=>r.json()).then(j=>{
        if (j && j.ok) {
          var deletedCount = j.result && j.result.deleted ? j.result.deleted.length : 0;
          var errorCount = j.result && j.result.errors ? j.result.errors.length : 0;
          var msg = 'Successfully deleted ' + deletedCount + ' item(s).';
          if (errorCount > 0) msg += ' ' + errorCount + ' error(s) occurred.';
          document.getElementById('clearResult').innerHTML = '<span style="color:var(--success);font-weight:600;">✓ ' + msg + '</span>';
          if (window.adminAlert) window.adminAlert('Cleared', msg, 'success');
        } else {
          document.getElementById('clearResult').innerHTML = '<span style="color:var(--error);font-weight:600;">Failed to clear items.</span> ' + (j.error || '');
        }
      })
      .catch(e=>{ document.getElementById('clearResult').innerHTML = '<span style="color:var(--error);font-weight:600;">Error occurred during clearing.</span>'; });
}
</script>
