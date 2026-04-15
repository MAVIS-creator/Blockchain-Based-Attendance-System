<?php
/**
 * admin/verify_integrity.php
 * Tamper-check tool: compares local log hashes against Supabase anchors.
 * Admin-only — protected by session.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../chain_anchor.php';
require_once __DIR__ . '/../storage_helpers.php';

session_start();
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    exit('Access denied.');
}
app_storage_init();

$today    = date('Y-m-d');
$checkDate = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])
    ? $_GET['date']
    : $today;

// --- Load active course ---
$activeCourse = 'General';
$activeFile   = admin_course_storage_migrate_file('active_course.json');
if (file_exists($activeFile)) {
    $d = json_decode(file_get_contents($activeFile), true);
    if (is_array($d)) $activeCourse = $d['course'] ?? 'General';
}

// --- Run checks ---
$chainResult   = chain_verify_local();
$logFile       = app_storage_file('logs/' . $checkDate . '.log');
$anchorResult  = chain_verify_against_supabase($logFile, $checkDate, $activeCourse);

// Compute local log hash for display even if Supabase check not run
$localLogHash = file_exists($logFile) ? hash('sha256', file_get_contents($logFile)) : null;

$chainOk  = $chainResult['valid'] ?? false;
$anchorOk = $anchorResult['match'] === true;
$anchorNA = $anchorResult['match'] === null; // Supabase disabled or no anchor yet

$overallOk = $chainOk && ($anchorOk || $anchorNA);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Log Integrity Verification — SAMS Admin</title>
  <link rel="stylesheet" href="../admin/boxicons.min.css">
  <style>
    :root {
      --bg: #f0f4f8; --panel: #fff; --text: #10233a; --muted: #5f6d7d;
      --border: #d8e1eb; --primary: #1f5d99; --success: #1e8e6a;
      --danger: #c0392b; --warn: #d97706; --radius: 14px;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: var(--bg); font-family: "Segoe UI", system-ui, sans-serif;
           color: var(--text); min-height: 100vh; padding: 32px 20px; }
    .page { max-width: 820px; margin: 0 auto; }
    h1 { font-size: 1.4rem; font-weight: 700; margin-bottom: 6px; display: flex; align-items: center; gap: 10px; }
    .sub { color: var(--muted); font-size: 0.9rem; margin-bottom: 28px; }
    .card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius);
            padding: 22px 24px; margin-bottom: 18px; }
    .card-title { font-weight: 700; font-size: 0.97rem; margin-bottom: 14px;
                  display: flex; align-items: center; gap: 8px; }
    .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 11px;
             border-radius: 999px; font-size: 0.82rem; font-weight: 700;
             letter-spacing: 0.03em; }
    .badge-ok   { background: #d1fae5; color: #065f46; }
    .badge-fail { background: #fee2e2; color: #7f1d1d; }
    .badge-warn { background: #fef3c7; color: #92400e; }
    .row { display: flex; justify-content: space-between; align-items: flex-start;
           padding: 9px 0; border-bottom: 1px solid var(--border); gap: 12px; }
    .row:last-child { border-bottom: none; padding-bottom: 0; }
    .row-label { font-size: 0.88rem; color: var(--muted); flex-shrink: 0; min-width: 160px; }
    .row-value { font-size: 0.88rem; word-break: break-all; text-align: right; font-family: monospace; }
    .banner { border-radius: 12px; padding: 18px 22px; font-size: 0.95rem; font-weight: 600;
              display: flex; align-items: center; gap: 12px; margin-bottom: 22px; }
    .banner-ok   { background: #ecfdf5; border: 1.5px solid #34d399; color: #064e3b; }
    .banner-fail { background: #fef2f2; border: 1.5px solid #f87171; color: #7f1d1d; }
    .banner-warn { background: #fffbeb; border: 1.5px solid #fcd34d; color: #78350f; }
    .icon { font-size: 1.5rem; }
    form.filter { display: flex; gap: 10px; align-items: center; margin-bottom: 22px; flex-wrap: wrap; }
    form.filter input[type=date] { padding: 8px 12px; border: 1px solid var(--border);
      border-radius: 8px; font-size: 0.9rem; color: var(--text); }
    form.filter button { padding: 8px 18px; background: var(--primary); color: #fff;
      border: none; border-radius: 8px; font-size: 0.9rem; font-weight: 600; cursor: pointer; }
    .how { background: #f8fafc; border: 1px solid var(--border); border-radius: 10px;
           padding: 16px 20px; font-size: 0.85rem; color: var(--muted); line-height: 1.6; }
    .how strong { color: var(--text); }
    @media(max-width:600px) { .row { flex-direction: column; } .row-value { text-align: left; } }
  </style>
</head>
<body>
<div class="page">
  <h1><i class='bx bx-shield-quarter'></i> Log Integrity Verification</h1>
  <p class="sub">Checks whether attendance logs have been tampered with by comparing local SHA-256 hashes against the Supabase-anchored fingerprints.</p>

  <form class="filter" method="get">
    <label for="date">Check date:</label>
    <input type="date" id="date" name="date" value="<?= htmlspecialchars($checkDate) ?>" max="<?= $today ?>">
    <button type="submit"><i class='bx bx-search'></i> Verify</button>
  </form>

  <?php
  // --- Overall banner ---
  if ($overallOk): ?>
  <div class="banner banner-ok">
    <span class="icon">✅</span>
    <span>All checks passed for <strong><?= htmlspecialchars($checkDate) ?></strong>. Logs are intact — no tampering detected.</span>
  </div>
  <?php elseif (!$chainOk): ?>
  <div class="banner banner-fail">
    <span class="icon">🚨</span>
    <span><strong>TAMPERING DETECTED</strong> — The local blockchain chain is broken at <?= htmlspecialchars($chainResult['error'] ?? 'unknown block') ?>. Logs may have been modified.</span>
  </div>
  <?php elseif (!$anchorOk && !$anchorNA): ?>
  <div class="banner banner-fail">
    <span class="icon">🚨</span>
    <span><strong>LOG FILE MISMATCH</strong> — The current log file hash does not match the Supabase-anchored snapshot. Contents may have been altered after anchoring.</span>
  </div>
  <?php elseif ($anchorNA): ?>
  <div class="banner banner-warn">
    <span class="icon">⚠️</span>
    <span>Local chain is intact but no Supabase anchor was found for this date/course yet (first submission anchors automatically). Chain check alone passed.</span>
  </div>
  <?php endif; ?>

  <!-- Local Blockchain Chain Check -->
  <div class="card">
    <div class="card-title">
      <i class='bx bx-link-alt'></i> Local Blockchain Chain
      <?php if ($chainOk): ?>
        <span class="badge badge-ok">✓ Valid</span>
      <?php else: ?>
        <span class="badge badge-fail">✗ Broken</span>
      <?php endif; ?>
    </div>
    <div class="row">
      <span class="row-label">Total blocks</span>
      <span class="row-value"><?= (int)($chainResult['blocks'] ?? 0) ?></span>
    </div>
    <div class="row">
      <span class="row-label">Chain tip hash</span>
      <span class="row-value"><?= htmlspecialchars(substr($chainResult['tip_hash'] ?? '—', 0, 32)) ?>…</span>
    </div>
    <div class="row">
      <span class="row-label">Error</span>
      <span class="row-value"><?= htmlspecialchars($chainResult['error'] ?? 'none') ?></span>
    </div>
  </div>

  <!-- Daily Log File Hash vs Supabase Anchor -->
  <div class="card">
    <div class="card-title">
      <i class='bx bx-fingerprint'></i> Log File Hash (<?= htmlspecialchars($checkDate) ?> · <?= htmlspecialchars($activeCourse) ?>)
      <?php if ($anchorOk): ?>
        <span class="badge badge-ok">✓ Matches Anchor</span>
      <?php elseif ($anchorNA): ?>
        <span class="badge badge-warn">⚠ No Anchor Yet</span>
      <?php else: ?>
        <span class="badge badge-fail">✗ Mismatch!</span>
      <?php endif; ?>
    </div>
    <div class="row">
      <span class="row-label">Current log hash</span>
      <span class="row-value"><?= $localLogHash ? htmlspecialchars(substr($localLogHash, 0, 32)) . '…' : '<em>log file not found</em>' ?></span>
    </div>
    <div class="row">
      <span class="row-label">Supabase anchor hash</span>
      <span class="row-value">
        <?php if ($anchorResult['anchored_hash']): ?>
          <?= htmlspecialchars(substr($anchorResult['anchored_hash'], 0, 32)) ?>…
        <?php else: ?>
          <em>—</em>
        <?php endif; ?>
      </span>
    </div>
    <div class="row">
      <span class="row-label">Anchored at</span>
      <span class="row-value"><?= htmlspecialchars($anchorResult['anchored_at'] ?? '—') ?></span>
    </div>
    <div class="row">
      <span class="row-label">Log file exists</span>
      <span class="row-value"><?= file_exists($logFile) ? 'Yes' : 'No' ?></span>
    </div>
  </div>

  <!-- How It Works -->
  <div class="how">
    <strong>How this works:</strong><br>
    Every time a student submits attendance, two things happen automatically in the background:<br>
    1. <strong>Local chain</strong> — a new SHA-256 block is appended to <code>attendance_chain.json</code>, linked to the previous block's hash. Editing any past record breaks the chain.<br>
    2. <strong>Supabase anchor</strong> — a SHA-256 fingerprint of today's entire <code>.log</code> file is saved in your Supabase <code>log_anchors</code> table. Since Supabase is a completely separate system, both would need to be compromised simultaneously for tampering to go undetected.
  </div>
</div>
</body>
</html>
