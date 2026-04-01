<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
  header('Location: login.php');
  exit;
}

require_once __DIR__ . '/../storage_helpers.php';
app_storage_init();
$chainFile = app_storage_migrate_file('secure_logs/attendance_chain.json', __DIR__ . '/../secure_logs/attendance_chain.json');
if (!file_exists($chainFile)) {
  $status = ['ok'=>false,'message'=>'Chain file not found.'];
} else {
  $chain = json_decode(file_get_contents($chainFile), true);
  if (!is_array($chain) || count($chain) === 0) {
    $status = ['ok'=>false,'message'=>'Chain is empty or invalid.'];
  } else {
    $valid = true;
    $prevHash = null;
    $errors = [];
    foreach ($chain as $i => $block) {
      $blockDataForHash = $block;
      unset($blockDataForHash['hash']);
      ksort($blockDataForHash);
      $expectedHash = hash('sha256', json_encode($blockDataForHash, JSON_UNESCAPED_SLASHES) . $prevHash);
      if (($block['hash'] ?? null) !== $expectedHash) {
        $errors[] = "Tampering detected at block #$i (hash mismatch)";
        $valid = false;
        break;
      }
      if ($i > 0 && (($block['prevHash'] ?? null) !== $prevHash)) {
        $errors[] = "Tampering detected at block #$i (prevHash mismatch)";
        $valid = false;
        break;
      }
      $prevHash = $block['hash'] ?? null;
    }
    $status = ['ok'=>$valid,'errors'=>$errors,'blocks'=>count($chain)];
  }
}
?>

<div style="max-width:900px;margin:0 auto;">
  <!-- Page Title -->
  <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:24px;">
    <div>
      <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;">
        <span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;">link</span>Attendance Chain
      </h2>
      <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">Blockchain integrity verification and block explorer.</p>
    </div>
  </div>

  <!-- Chain Status -->
  <?php if (!$status['ok']): ?>
    <div class="st-card" style="border-left:4px solid var(--error);margin-bottom:20px;">
      <div style="display:flex;align-items:center;gap:12px;">
        <div style="display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:10px;background:#fef2f2;">
          <span class="material-symbols-outlined" style="color:var(--error);font-variation-settings:'FILL' 1;">error</span>
        </div>
        <div>
          <p style="font-weight:700;color:var(--error);margin:0;">Chain Integrity Issue</p>
          <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;"><?= htmlspecialchars($status['message'] ?? implode('; ',$status['errors'] ?? [])) ?></p>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="st-card" style="border-left:4px solid #059669;margin-bottom:20px;">
      <div style="display:flex;align-items:center;gap:12px;">
        <div style="display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:10px;background:#ecfdf5;">
          <span class="material-symbols-outlined" style="color:#059669;font-variation-settings:'FILL' 1;">verified</span>
        </div>
        <div>
          <p style="font-weight:700;color:#059669;margin:0;">Chain Valid</p>
          <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;"><?= intval($status['blocks']) ?> blocks verified. All hashes match.</p>
        </div>
      </div>
    </div>

    <!-- Block Explorer -->
    <div class="st-card" style="padding:0;">
      <div style="padding:20px 24px;border-bottom:1px solid rgba(194,199,209,0.1);">
        <p style="font-weight:700;color:var(--on-surface);margin:0;display:flex;align-items:center;gap:8px;">
          <span class="material-symbols-outlined" style="font-size:1.1rem;">deployed_code</span>
          Block Explorer
          <span class="st-chip st-chip-info" style="margin-left:auto;"><?= count($chain) ?> blocks</span>
        </p>
      </div>
      <div style="max-height:500px;overflow-y:auto;padding:12px;">
        <?php foreach ($chain as $i => $block): ?>
          <div class="chain-block">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
              <span class="chain-block-id">
                <span class="material-symbols-outlined" style="font-size:0.95rem;vertical-align:middle;margin-right:4px;">cube</span>
                Block #<?= $i ?>
              </span>
              <span class="st-chip st-chip-neutral"><?= htmlspecialchars($block['action'] ?? 'N/A') ?></span>
            </div>
            <div class="chain-block-meta">
              <span class="material-symbols-outlined" style="font-size:0.85rem;vertical-align:middle;">schedule</span>
              <?= htmlspecialchars($block['timestamp'] ?? '') ?>
              &nbsp;·&nbsp;
              <strong><?= htmlspecialchars($block['name'] ?? '') ?></strong>
              &nbsp;·&nbsp;
              <?= htmlspecialchars($block['matric'] ?? '') ?>
            </div>
            <div class="chain-block-hash">
              <span class="material-symbols-outlined" style="font-size:0.75rem;vertical-align:middle;">fingerprint</span>
              <?= htmlspecialchars($block['hash'] ?? '') ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
