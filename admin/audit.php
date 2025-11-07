<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }

// include csrf helper if present
$csrfPath = __DIR__ . '/includes/csrf.php';
if (file_exists($csrfPath)) require_once $csrfPath;

// settings for retention (default fallback)
$settingsFileAdmin = __DIR__ . '/settings.json';
$defaults = ['audit_retention_days' => 90];
$settings = $defaults;
if (file_exists($settingsFileAdmin)) {
    $j = @json_decode(@file_get_contents($settingsFileAdmin), true);
    if (is_array($j)) $settings = array_merge($settings, $j);
}

$auditFile = __DIR__ . '/logs/audit.log';

// Purge old entries when posted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (function_exists('csrf_check_request') && !csrf_check_request()) { echo json_encode(['ok'=>false,'error'=>'csrf_failed']); exit; }
    $action = $_POST['action'] ?? $_GET['action'] ?? ''; 
    if ($action === 'purge') {
        $days = intval($_POST['days'] ?? $settings['audit_retention_days']);
        if ($days <= 0) $days = $settings['audit_retention_days'];
        $cut = strtotime("-" . intval($days) . " days");
        if (!file_exists($auditFile)) { echo json_encode(['ok'=>true,'purged'=>0]); exit; }
        $lines = file($auditFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $kept = [];
        $purged = 0;
        foreach ($lines as $ln) {
            // expect format: YYYY-MM-DD HH:MM:SS | ...
            $ts = substr($ln, 0, 19);
            $t = strtotime($ts);
            if ($t === false || $t >= $cut) { $kept[] = $ln; } else { $purged++; }
        }
        file_put_contents($auditFile, implode("\n", $kept) . (count($kept) ? PHP_EOL : ''), LOCK_EX);
        echo json_encode(['ok'=>true,'purged'=>$purged,'kept'=>count($kept)]);
        exit;
    }
    echo json_encode(['ok'=>false,'error'=>'unknown_action']); exit;
}

// Read file for viewer
$entries = [];
if (file_exists($auditFile)) {
    $lines = file($auditFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $ln) {
        $parts = array_map('trim', explode('|', $ln));
        $entries[] = $parts;
    }
}

?>
<style>
  .panel { max-width:1000px; margin:18px auto; padding:18px; background:#fff; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,0.06); }
  table { width:100%; border-collapse:collapse; }
  th, td { padding:8px; border-bottom:1px solid #f1f5f9; text-align:left; vertical-align:top; }
  .muted { color:#6b7280 }
</style>

<div class="panel">
  <h2>Audit Log Viewer</h2>
  <p class="muted">Showing entries from <code><?= htmlspecialchars($auditFile) ?></code>. Total entries: <?= count($entries) ?></p>
  <div style="margin-bottom:12px;">
    <label>Retention days: <strong><?= intval($settings['audit_retention_days']) ?></strong></label>
    <button id="purgeOld" class="btn btn-danger" style="margin-left:12px;padding:8px 12px;border-radius:8px;border:none;background:#ef4444;color:#fff;">Purge older</button>
  </div>
  <div style="overflow:auto; max-height:520px;">
    <table>
      <thead><tr><th style="min-width:160px">Timestamp</th><th>Action</th><th>Admin</th><th>Type</th><th>Target / Details</th></tr></thead>
      <tbody>
      <?php foreach ($entries as $e):
          $ts = $e[0] ?? '';
          $action = $e[1] ?? '';
          $admin = $e[2] ?? '';
          $type = $e[3] ?? '';
          $details = array_slice($e, 4);
      ?>
        <tr>
          <td><?= htmlspecialchars($ts) ?></td>
          <td><?= htmlspecialchars($action) ?></td>
          <td><?= htmlspecialchars($admin) ?></td>
          <td><?= htmlspecialchars($type) ?></td>
          <td><?= htmlspecialchars(implode(' | ', $details)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.getElementById('purgeOld').addEventListener('click', function(){
  if (!confirm('Purge audit entries older than <?= intval($settings['audit_retention_days']) ?> days?')) return; // fallback if adminConfirm unavailable
  var body = new URLSearchParams(); body.append('action','purge'); body.append('days','<?= intval($settings['audit_retention_days']) ?>');
  if (window.ADMIN_CSRF_TOKEN) body.append('csrf_token', window.ADMIN_CSRF_TOKEN);
  fetch('audit.php', { method:'POST', body: body }).then(r=>r.json()).then(j=>{
    if (j && j.ok) location.reload(); else alert('Purge failed: ' + JSON.stringify(j));
  }).catch(function(){ alert('Purge request failed'); });
});
</script>
