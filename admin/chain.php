<?php
require_once __DIR__ . '/session_bootstrap.php';
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
  header('Location: login.php');
  exit;
}

require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/../hybrid_dual_write.php';
require_once __DIR__ . '/cache_helpers.php';
app_storage_init();

// ─── Local chain load + verify ────────────────────────────────────────────────
$chainPage    = isset($_GET['chain_pg']) && ctype_digit((string)$_GET['chain_pg'])
  ? max(1, (int)$_GET['chain_pg']) : 1;
$chainPerPage = 20;

$chainFile = app_storage_migrate_file(
  'secure_logs/attendance_chain.json',
  __DIR__ . '/../secure_logs/attendance_chain.json'
);

$chain  = [];
$status = ['ok' => false, 'message' => 'Chain file not found.'];

if (file_exists($chainFile)) {
  $chain = admin_cached_json_file('attendance_chain_page', $chainFile, [], 15);
  if (!is_array($chain) || count($chain) === 0) {
    $status = ['ok' => false, 'message' => 'Chain is empty or invalid.'];
    $chain  = [];
  } else {
    $valid    = true;
    $prevHash = null;
    $errors   = [];
    foreach ($chain as $i => $block) {
      $blockDataForHash = $block;
      unset($blockDataForHash['hash']);
      ksort($blockDataForHash);
      $expectedHash = hash('sha256', json_encode($blockDataForHash, JSON_UNESCAPED_SLASHES) . $prevHash);
      if (($block['hash'] ?? null) !== $expectedHash) {
        $errors[] = "Tampering detected at block #$i (hash mismatch)";
        $valid    = false;
        break;
      }
      if ($i > 0 && (($block['prevHash'] ?? null) !== $prevHash)) {
        $errors[] = "Tampering detected at block #$i (prevHash mismatch)";
        $valid    = false;
        break;
      }
      $prevHash = $block['hash'] ?? null;
    }
    $status = ['ok' => $valid, 'errors' => $errors, 'blocks' => count($chain), 'tip' => $prevHash];
  }
}

// ─── Supabase log_anchors ─────────────────────────────────────────────────────
$today        = date('Y-m-d');
$supabaseOk   = hybrid_enabled();
$anchors      = [];
$anchorErr    = null;
$todayAnchor  = null;
$localLogHash = null;

// Active course (for today's log comparison)
$activeCourse = 'General';
$activeFile   = admin_course_storage_migrate_file('active_course.json');
if (file_exists($activeFile)) {
  $d = json_decode(file_get_contents($activeFile), true);
  if (is_array($d)) $activeCourse = $d['course'] ?? 'General';
}

// Today's local log hash
$logFile = app_storage_file('logs/' . $today . '.log');
if (file_exists($logFile)) {
  $localLogHash = hash('sha256', file_get_contents($logFile));
}

if ($supabaseOk) {
  // Fetch last 30 anchor records (all dates)
  $rows = null;
  $err  = null;
  hybrid_supabase_select('log_anchors', [
    'select' => 'id,date,course,log_hash,chain_hash,anchored_at,server_id',
    'order'  => 'anchored_at.desc',
    'limit'  => '30',
  ], $rows, $err);
  if (is_array($rows)) $anchors = $rows;
  $anchorErr = $err;

  // Today's latest anchor for comparison
  $todayRows = null;
  hybrid_supabase_select('log_anchors', [
    'select' => 'log_hash,chain_hash,anchored_at',
    'date'   => 'eq.' . $today,
    'order'  => 'anchored_at.desc',
    'limit'  => '1',
  ], $todayRows, $err);
  if (is_array($todayRows) && count($todayRows) > 0) {
    $todayAnchor = $todayRows[0];
  }
}

// Compare today's local log hash vs Supabase anchor
$anchorMatch = null; // null = not checked / no anchor
if ($localLogHash && $todayAnchor) {
  $anchorMatch = ($localLogHash === ($todayAnchor['log_hash'] ?? null));
}
?>

<div style="max-width:920px;margin:0 auto;">

  <!-- Page Title -->
  <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:24px;">
    <div>
      <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;">
        <span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;">link</span>Blockchain Ledger
      </h2>
      <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">
        Local SHA-256 chain integrity · Supabase log anchor verification
      </p>
    </div>
  </div>

  <!-- ── SECTION 1: Local Chain Status ───────────────────────────────────────-- -->
  <p style="font-size:0.78rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--on-surface-variant);margin:0 0 10px;">Local Chain</p>

  <?php if (!$status['ok']): ?>
    <div class="st-card" style="border-left:4px solid var(--error);margin-bottom:20px;">
      <div style="display:flex;align-items:center;gap:12px;">
        <div style="display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:10px;background:#fef2f2;">
          <span class="material-symbols-outlined" style="color:var(--error);font-variation-settings:'FILL' 1;">error</span>
        </div>
        <div>
          <p style="font-weight:700;color:var(--error);margin:0;">Chain Integrity Issue</p>
          <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">
            <?= htmlspecialchars($status['message'] ?? implode('; ', $status['errors'] ?? [])) ?>
          </p>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="st-card" style="border-left:4px solid #059669;margin-bottom:20px;">
      <div style="display:flex;align-items:center;gap:12px;">
        <div style="display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:10px;background:#ecfdf5;">
          <span class="material-symbols-outlined" style="color:#059669;font-variation-settings:'FILL' 1;">verified</span>
        </div>
        <div style="flex:1;min-width:0;">
          <p style="font-weight:700;color:#059669;margin:0;">Chain Valid — <?= intval($status['blocks']) ?> blocks verified, all hashes match</p>
          <?php if (!empty($status['tip'])): ?>
            <p style="color:var(--on-surface-variant);font-size:0.78rem;margin:4px 0 0;word-break:break-all;">
              Tip hash: <?= htmlspecialchars($status['tip']) ?>
            </p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Block Explorer -->
    <div class="st-card" style="padding:0;margin-bottom:28px;">
      <div style="padding:16px 20px;border-bottom:1px solid var(--outline-variant);display:flex;align-items:center;gap:8px;">
        <span class="material-symbols-outlined" style="font-size:1.1rem;color:var(--on-surface-variant);">deployed_code</span>
        <span style="font-weight:700;color:var(--on-surface);">Block Explorer</span>
        <span class="st-chip st-chip-info" style="margin-left:auto;"><?= count($chain) ?> blocks</span>
      </div>

      <?php
        $chainTotal      = count($chain);
        $chainTotalPages = max(1, (int)ceil($chainTotal / $chainPerPage));
        $chainPage       = min($chainPage, $chainTotalPages);
        $chainOffset     = ($chainPage - 1) * $chainPerPage;
        $pagedChain      = array_slice($chain, $chainOffset, $chainPerPage, true);
      ?>

      <div style="max-height:440px;overflow-y:auto;padding:10px 12px;">
        <?php foreach ($pagedChain as $i => $block): ?>
          <div class="chain-block">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;">
              <span class="chain-block-id">
                <span class="material-symbols-outlined" style="font-size:0.95rem;vertical-align:middle;margin-right:4px;">cube</span>
                Block #<?= $i ?>
              </span>
              <span class="st-chip st-chip-neutral"><?= htmlspecialchars($block['action'] ?? 'N/A') ?></span>
            </div>
            <div class="chain-block-meta">
              <span class="material-symbols-outlined" style="font-size:0.85rem;vertical-align:middle;">schedule</span>
              <?= htmlspecialchars($block['timestamp'] ?? '') ?>
              &nbsp;·&nbsp;<strong><?= htmlspecialchars($block['name'] ?? '') ?></strong>
              &nbsp;·&nbsp;<?= htmlspecialchars($block['matric'] ?? '') ?>
              <?php if (!empty($block['course'])): ?>
                &nbsp;·&nbsp;<span style="opacity:.75;"><?= htmlspecialchars($block['course']) ?></span>
              <?php endif; ?>
            </div>
            <div class="chain-block-hash">
              <span class="material-symbols-outlined" style="font-size:0.75rem;vertical-align:middle;">fingerprint</span>
              <?= htmlspecialchars($block['hash'] ?? '') ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 16px;border-top:1px solid var(--outline-variant);">
        <div style="font-size:0.82rem;color:var(--on-surface-variant);">
          Showing <?= (int)($chainOffset + 1) ?>–<?= (int)min($chainOffset + $chainPerPage, $chainTotal) ?> of <?= (int)$chainTotal ?> blocks
        </div>
        <?php if ($chainTotalPages > 1): ?>
          <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
            <?php for ($p = 1; $p <= $chainTotalPages; $p++): ?>
              <a href="?page=chain&chain_pg=<?= (int)$p ?>"
                style="display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;border-radius:8px;padding:0 8px;text-decoration:none;font-size:0.82rem;font-weight:700;<?= $p === $chainPage ? 'background:var(--primary);color:#fff;' : 'background:var(--surface-container-low);color:var(--on-surface);border:1px solid var(--outline-variant);' ?>">
                <?= (int)$p ?>
              </a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- ── SECTION 2: Supabase Log Anchors ─────────────────────────────────────-- -->
  <p style="font-size:0.78rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--on-surface-variant);margin:0 0 10px;">
    Supabase Log Anchors
    <span style="font-weight:400;text-transform:none;letter-spacing:0;margin-left:6px;">
      — external SHA-256 fingerprints stored on a separate system
    </span>
  </p>

  <?php if (!$supabaseOk): ?>
    <div class="st-card" style="border-left:4px solid var(--outline-variant);margin-bottom:20px;">
      <p style="margin:0;color:var(--on-surface-variant);font-size:0.9rem;">
        <span class="material-symbols-outlined" style="vertical-align:middle;font-size:1rem;margin-right:6px;">cloud_off</span>
        Supabase hybrid mode is off (<code>HYBRID_MODE=off</code>). Set <code>HYBRID_MODE=dual_write</code> in <code>.env</code> to enable external anchoring.
      </p>
    </div>
  <?php else: ?>

    <!-- Today's comparison card -->
    <?php
      $todayLogExists = file_exists($logFile);
      if ($anchorMatch === true):
    ?>
      <div class="st-card" style="border-left:4px solid #059669;margin-bottom:16px;">
        <div style="display:flex;align-items:center;gap:10px;">
          <span class="material-symbols-outlined" style="color:#059669;font-variation-settings:'FILL' 1;">shield</span>
          <div>
            <p style="font-weight:700;color:#059669;margin:0;">Today's log matches Supabase anchor ✓</p>
            <p style="color:var(--on-surface-variant);font-size:0.82rem;margin:3px 0 0;">
              Anchored: <?= htmlspecialchars($todayAnchor['anchored_at'] ?? '—') ?>
              &nbsp;·&nbsp; Hash: <span style="font-family:monospace;"><?= htmlspecialchars(substr($todayAnchor['log_hash'] ?? '', 0, 20)) ?>…</span>
            </p>
          </div>
        </div>
      </div>
    <?php elseif ($anchorMatch === false): ?>
      <div class="st-card" style="border-left:4px solid var(--error);margin-bottom:16px;">
        <div style="display:flex;align-items:center;gap:10px;">
          <span class="material-symbols-outlined" style="color:var(--error);font-variation-settings:'FILL' 1;">gpp_bad</span>
          <div>
            <p style="font-weight:700;color:var(--error);margin:0;">⚠ Log mismatch — current file differs from Supabase anchor</p>
            <p style="color:var(--on-surface-variant);font-size:0.82rem;margin:3px 0 0;">
              Local: <span style="font-family:monospace;"><?= htmlspecialchars(substr($localLogHash ?? '', 0, 20)) ?>…</span>
              &nbsp;·&nbsp; Anchored: <span style="font-family:monospace;"><?= htmlspecialchars(substr($todayAnchor['log_hash'] ?? '', 0, 20)) ?>…</span>
            </p>
          </div>
        </div>
      </div>
    <?php elseif (!$todayLogExists): ?>
      <div class="st-card" style="border-left:4px solid var(--outline-variant);margin-bottom:16px;">
        <p style="margin:0;color:var(--on-surface-variant);font-size:0.88rem;">
          No attendance log file found for today (<?= htmlspecialchars($today) ?>).
        </p>
      </div>
    <?php else: ?>
      <div class="st-card" style="border-left:4px solid #d97706;margin-bottom:16px;">
        <p style="margin:0;color:#92400e;font-size:0.88rem;font-weight:600;">
          <span class="material-symbols-outlined" style="vertical-align:middle;font-size:1rem;margin-right:4px;">info</span>
          No Supabase anchor recorded yet for today. One will be saved automatically on the next attendance submission.
        </p>
      </div>
    <?php endif; ?>

    <?php if (!empty($anchorErr) && empty($anchors)): ?>
      <div class="st-card" style="border-left:4px solid var(--error);margin-bottom:16px;">
        <p style="margin:0;color:var(--error);font-size:0.88rem;">Supabase error: <?= htmlspecialchars($anchorErr) ?></p>
      </div>
    <?php endif; ?>

    <!-- Anchors table -->
    <?php if (!empty($anchors)): ?>
      <div class="st-card" style="padding:0;margin-bottom:20px;">
        <div style="padding:14px 20px;border-bottom:1px solid var(--outline-variant);display:flex;align-items:center;gap:8px;">
          <span class="material-symbols-outlined" style="font-size:1rem;color:var(--on-surface-variant);">table_rows</span>
          <span style="font-weight:700;color:var(--on-surface);">Recent Anchor Records</span>
          <span class="st-chip st-chip-neutral" style="margin-left:auto;"><?= count($anchors) ?> shown</span>
        </div>
        <div style="overflow-x:auto;">
          <table style="width:100%;border-collapse:collapse;font-size:0.84rem;">
            <thead>
              <tr style="background:var(--surface-container-low);">
                <th style="padding:10px 14px;text-align:left;font-weight:700;color:var(--on-surface-variant);border-bottom:1px solid var(--outline-variant);">Date</th>
                <th style="padding:10px 14px;text-align:left;font-weight:700;color:var(--on-surface-variant);border-bottom:1px solid var(--outline-variant);">Course</th>
                <th style="padding:10px 14px;text-align:left;font-weight:700;color:var(--on-surface-variant);border-bottom:1px solid var(--outline-variant);">Log Hash (SHA-256)</th>
                <th style="padding:10px 14px;text-align:left;font-weight:700;color:var(--on-surface-variant);border-bottom:1px solid var(--outline-variant);">Chain Tip</th>
                <th style="padding:10px 14px;text-align:left;font-weight:700;color:var(--on-surface-variant);border-bottom:1px solid var(--outline-variant);">Anchored At</th>
                <th style="padding:10px 14px;text-align:left;font-weight:700;color:var(--on-surface-variant);border-bottom:1px solid var(--outline-variant);">Match</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($anchors as $row):
                $isToday   = ($row['date'] ?? '') === $today;
                $rowLocal  = $isToday ? $localLogHash : null;
                $rowMatch  = $rowLocal ? ($rowLocal === ($row['log_hash'] ?? null)) : null;
              ?>
                <tr style="border-bottom:1px solid var(--outline-variant);">
                  <td style="padding:10px 14px;color:var(--on-surface);white-space:nowrap;">
                    <?= htmlspecialchars($row['date'] ?? '—') ?>
                    <?php if ($isToday): ?><span class="st-chip st-chip-info" style="margin-left:6px;font-size:0.72rem;">today</span><?php endif; ?>
                  </td>
                  <td style="padding:10px 14px;color:var(--on-surface);"><?= htmlspecialchars($row['course'] ?? '—') ?></td>
                  <td style="padding:10px 14px;font-family:monospace;color:var(--on-surface-variant);font-size:0.8rem;word-break:break-all;">
                    <?= htmlspecialchars(substr($row['log_hash'] ?? '', 0, 16)) ?>…
                  </td>
                  <td style="padding:10px 14px;font-family:monospace;color:var(--on-surface-variant);font-size:0.8rem;word-break:break-all;">
                    <?= !empty($row['chain_hash']) ? htmlspecialchars(substr($row['chain_hash'], 0, 16)) . '…' : '<span style="opacity:.5;">—</span>' ?>
                  </td>
                  <td style="padding:10px 14px;color:var(--on-surface-variant);white-space:nowrap;font-size:0.82rem;">
                    <?= htmlspecialchars($row['anchored_at'] ?? '—') ?>
                  </td>
                  <td style="padding:10px 14px;">
                    <?php if ($rowMatch === true): ?>
                      <span style="color:#059669;font-weight:700;font-size:0.82rem;">✓ Match</span>
                    <?php elseif ($rowMatch === false): ?>
                      <span style="color:var(--error);font-weight:700;font-size:0.82rem;">✗ Mismatch</span>
                    <?php else: ?>
                      <span style="color:var(--on-surface-variant);font-size:0.82rem;">Past date</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php elseif ($supabaseOk): ?>
      <div class="st-card" style="color:var(--on-surface-variant);font-size:0.9rem;">
        No anchor records found in Supabase yet. Submit an attendance to create the first one.
      </div>
    <?php endif; ?>

  <?php endif; // supabaseOk ?>

</div>
