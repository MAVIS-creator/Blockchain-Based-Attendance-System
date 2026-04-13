<?php
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/cache_helpers.php';
require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/includes/ai_recommendation_formatter.php';
app_storage_init();

$diagFile = function_exists('ai_ticket_diagnostics_file')
  ? ai_ticket_diagnostics_file()
  : admin_storage_migrate_file('ai_ticket_diagnostics.json');

$rows = file_exists($diagFile)
  ? admin_cached_json_file('ai_suggestions_page', $diagFile, [], 10)
  : [];
if (!is_array($rows)) {
  $rows = [];
}

$allRows = $rows;

$suggestPerPage = 18;
$suggestPage = isset($_GET['suggest_pg']) ? (int)$_GET['suggest_pg'] : 1;
if ($suggestPage < 1) $suggestPage = 1;
$suggestTotal = count($allRows);
$suggestTotalPages = max(1, (int)ceil($suggestTotal / $suggestPerPage));
if ($suggestPage > $suggestTotalPages) $suggestPage = $suggestTotalPages;
$suggestOffset = ($suggestPage - 1) * $suggestPerPage;
$rows = array_slice($allRows, $suggestOffset, $suggestPerPage);

$needsReview = array_values(array_filter($allRows, function ($r) {
  if (!is_array($r)) return false;
  $cls = (string)($r['classification'] ?? '');
  return in_array($cls, ['network_ip_rotation', 'new_or_suspicious_device', 'duplicate_or_fraudulent_sequence', 'blocked_revoked_device', 'policy_device_sharing_risk', 'fingerprint_conflict_rig_attempt', 'invalid_course_reference', 'inactive_course_reference'], true)
    || empty($r['ticket_resolved']);
}));
?>

<div style="margin-bottom:24px;">
  <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;display:flex;align-items:center;gap:8px;">
    <span class="material-symbols-outlined">smart_toy</span>
    AI Suggestions & Review Queue
  </h2>
  <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">
    Real-time AI recommendations for support tickets. High-risk or unresolved items are listed first for admin action.
  </p>
  <p style="color:var(--on-surface-variant);font-size:0.82rem;margin:6px 0 0;">
    <strong>Note:</strong> The <em>Recommendation</em> field on each card is the AI-generated review plan for that ticket.
  </p>
</div>

<div style="display:flex;gap:10px;flex-wrap:wrap;margin:0 0 16px 0;">
  <span class="st-chip st-chip-info">Total diagnostics: <?= (int)count($allRows) ?></span>
  <span class="st-chip st-chip-warning" style="background:#fff8e8;color:#8a5a00;border-color:#f5dfad;">Needs review: <?= (int)count($needsReview) ?></span>
  <a href="index.php?page=support_tickets" class="st-btn st-btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
    <span class="material-symbols-outlined" style="font-size:1rem;">confirmation_number</span>
    Open Support Tickets
  </a>
</div>

<div style="margin:0 0 14px 0;padding:10px 12px;border-radius:10px;background:var(--surface-container-lowest);border:1px dashed var(--outline-variant);color:var(--on-surface-variant);font-size:0.8rem;">
  If this page is not visible in your sidebar, ask superadmin to enable <strong>AI Suggestions</strong> for your role in <code>index.php?page=roles</code>.
</div>

<?php if (empty($rows)): ?>
  <div style="padding:24px;border-radius:12px;background:var(--surface-container-lowest);border:1px solid var(--outline-variant);color:var(--on-surface-variant);">
    No AI diagnostics yet. Run <code>admin/ai_ticket_processor.php</code> to generate suggestions.
  </div>
<?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:12px;">
    <?php foreach ($rows as $row): ?>
      <?php
      $classification = (string)($row['classification'] ?? 'unknown');
      $severityBg = '#eef6ff';
      $severityLine = '#cfe1f5';
      $severityText = '#1d4f80';
      if ($classification === 'blocked_revoked_device' || $classification === 'duplicate_or_fraudulent_sequence' || $classification === 'policy_device_sharing_risk' || $classification === 'fingerprint_conflict_rig_attempt') {
        $severityBg = '#ffeef0';
        $severityLine = '#f5c2c8';
        $severityText = '#9f1d2c';
      } elseif ($classification === 'network_ip_rotation' || $classification === 'new_or_suspicious_device' || $classification === 'invalid_course_reference' || $classification === 'inactive_course_reference') {
        $severityBg = '#fff8e8';
        $severityLine = '#f5dfad';
        $severityText = '#8a5a00';
      }
      ?>
      <article style="padding:12px;border-radius:12px;background:<?= $severityBg ?>;border:1px solid <?= $severityLine ?>;color:<?= $severityText ?>;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
          <strong style="font-size:0.85rem;"><?= htmlspecialchars($classification) ?></strong>
          <span style="font-size:0.74rem;opacity:0.9;">Conf: <?= htmlspecialchars(number_format((float)($row['confidence'] ?? 0), 2)) ?></span>
        </div>

        <div style="font-size:0.78rem;line-height:1.45;display:grid;gap:4px;">
          <div><strong>Matric:</strong> <?= htmlspecialchars((string)($row['matric'] ?? '-')) ?></div>
          <div><strong>Course:</strong> <?= htmlspecialchars((string)($row['course'] ?? 'General')) ?></div>
          <div><strong>Requested:</strong> <?= htmlspecialchars((string)($row['requested_action'] ?? 'n/a')) ?></div>
          <div><strong>FP/IP:</strong> <?= !empty($row['fpMatch']) ? 'match' : 'no-fp-match' ?> / <?= !empty($row['ipMatch']) ? 'match' : 'no-ip-match' ?></div>
          <div><strong>AI:</strong> <?= htmlspecialchars((string)($row['ai_provider'] ?? 'rules')) ?> · <?= htmlspecialchars((string)($row['ai_model'] ?? 'rules-v1')) ?> · <?= (int)($row['ai_latency_ms'] ?? 0) ?>ms</div>
          <div>
            <strong>Recommendation:</strong>
            <div style="margin-top:4px;padding:8px 10px;border-radius:8px;background:rgba(255,255,255,0.55);border:1px solid rgba(0,0,0,0.06);color:var(--on-surface);">
              <?= ai_recommendation_render_html((string)($row['suggested_admin_action'] ?? 'Review needed.')) ?>
            </div>
          </div>
          <div><strong>Status:</strong> <?= !empty($row['ticket_resolved']) ? 'resolved' : 'needs admin review' ?></div>
          <div><strong>At:</strong> <?= htmlspecialchars((string)($row['processed_at'] ?? '-')) ?></div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($suggestTotal > 0 && $suggestTotalPages > 1): ?>
  <div style="margin-top:14px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
    <div style="font-size:0.82rem;color:var(--on-surface-variant);">
      Showing <?= (int)($suggestOffset + 1) ?>-<?= (int)min($suggestOffset + $suggestPerPage, $suggestTotal) ?> of <?= (int)$suggestTotal ?> diagnostics
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
      <?php if ($suggestPage > 1): ?>
        <a class="st-btn st-btn-sm" href="index.php?page=ai_suggestions&suggest_pg=<?= $suggestPage - 1 ?>">Prev</a>
      <?php endif; ?>
      <?php
      $startPage = max(1, $suggestPage - 2);
      $endPage = min($suggestTotalPages, $suggestPage + 2);
      for ($p = $startPage; $p <= $endPage; $p++):
      ?>
        <a class="st-btn st-btn-sm" href="index.php?page=ai_suggestions&suggest_pg=<?= $p ?>" style="<?= $p === $suggestPage ? 'background:#1f5d99;color:#fff;border-color:#1f5d99;' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($suggestPage < $suggestTotalPages): ?>
        <a class="st-btn st-btn-sm" href="index.php?page=ai_suggestions&suggest_pg=<?= $suggestPage + 1 ?>">Next</a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
