<?php
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/cache_helpers.php';
require_once __DIR__ . '/state_helpers.php';
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

$rows = array_slice($rows, 0, 120);

$needsReview = array_values(array_filter($rows, function ($r) {
  if (!is_array($r)) return false;
  $cls = (string)($r['classification'] ?? '');
  return in_array($cls, ['network_ip_rotation', 'new_or_suspicious_device', 'duplicate_or_fraudulent_sequence', 'blocked_revoked_device'], true)
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
</div>

<div style="display:flex;gap:10px;flex-wrap:wrap;margin:0 0 16px 0;">
  <span class="st-chip st-chip-info">Total diagnostics: <?= (int)count($rows) ?></span>
  <span class="st-chip st-chip-warning" style="background:#fff8e8;color:#8a5a00;border-color:#f5dfad;">Needs review: <?= (int)count($needsReview) ?></span>
  <a href="index.php?page=support_tickets" class="st-btn st-btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
    <span class="material-symbols-outlined" style="font-size:1rem;">confirmation_number</span>
    Open Support Tickets
  </a>
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
        if ($classification === 'blocked_revoked_device' || $classification === 'duplicate_or_fraudulent_sequence') {
          $severityBg = '#ffeef0';
          $severityLine = '#f5c2c8';
          $severityText = '#9f1d2c';
        } elseif ($classification === 'network_ip_rotation' || $classification === 'new_or_suspicious_device') {
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
          <div><strong>Recommendation:</strong> <?= htmlspecialchars((string)($row['suggested_admin_action'] ?? 'Review needed.')) ?></div>
          <div><strong>Status:</strong> <?= !empty($row['ticket_resolved']) ? 'resolved' : 'needs admin review' ?></div>
          <div><strong>At:</strong> <?= htmlspecialchars((string)($row['processed_at'] ?? '-')) ?></div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
