<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/../src/AiSiteStructureContext.php';
require_once __DIR__ . '/../src/AiRulebook.php';

$message = null;
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rebuild_ai_context'])) {
  if (!csrf_check_request()) {
    $message = 'Invalid CSRF token. Please refresh and try again.';
    $messageType = 'error';
  } else {
    $bundle = AiSiteStructureContext::rebuild('admin');
    $meta = is_array($bundle['meta'] ?? null) ? $bundle['meta'] : [];
    $message = 'AI context index rebuilt successfully. Sources: ' . (int)($meta['source_count'] ?? 0)
      . ', Indexed pages: ' . (int)($meta['indexed_pages'] ?? 0) . '.';
    $messageType = 'success';

    if (function_exists('admin_log_action')) {
      admin_log_action('AI_Operator', 'AI Context Rebuilt', 'Rebuilt AI site context cache from preview page.');
    }
  }
}

$bundle = AiSiteStructureContext::getBundle('admin');
$meta = is_array($bundle['meta'] ?? null) ? $bundle['meta'] : [];
$cache = AiSiteStructureContext::getCacheSnapshot();
$payload = is_array($cache['payload'] ?? null) ? $cache['payload'] : [];
$sidebar = is_array($payload['sidebar'] ?? null) ? $payload['sidebar'] : [];
$routes = is_array($payload['routes'] ?? null) ? $payload['routes'] : [];
$docs = is_array($payload['docs'] ?? null) ? $payload['docs'] : [];

$contextText = (string)($bundle['context'] ?? '');
$contextPreview = $contextText;
if (strlen($contextPreview) > 5000) {
  $contextPreview = substr($contextPreview, 0, 5000) . "\n...[preview truncated]";
}

$rulebook = AiRulebook::load();
$ruleRows = array_values($rulebook['rules'] ?? []);
$enabledRuleCount = 0;
foreach ($ruleRows as $r) {
  if (!empty($r['enabled'])) {
    $enabledRuleCount++;
  }
}

$badgeClass = 'st-stat-badge info';
if (!empty($bundle['enabled'])) {
  $badgeClass = 'st-stat-badge success';
}
?>

<div style="margin-bottom:24px;">
  <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;display:flex;align-items:center;gap:8px;">
    <span class="material-symbols-outlined">visibility</span>
    AI Context Preview
  </h2>
  <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">
    Inspect the live site structure context injected into AI prompts (routes, sidebar map, and source-backed policy).
  </p>
</div>

<?php if ($message !== null): ?>
  <div style="margin-bottom:14px;padding:12px 14px;border-radius:10px;border:1px solid <?= $messageType === 'success' ? '#a7f3d0' : '#fecaca' ?>;background:<?= $messageType === 'success' ? '#ecfdf5' : '#fef2f2' ?>;color:<?= $messageType === 'success' ? '#065f46' : '#991b1b' ?>;font-size:0.85rem;">
    <?= htmlspecialchars($message) ?>
  </div>
<?php endif; ?>

<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
  <span class="<?= $badgeClass ?>">Enabled: <?= !empty($bundle['enabled']) ? 'yes' : 'no' ?></span>
  <span class="st-stat-badge info">Version: <?= htmlspecialchars((string)($meta['version'] ?? 'site-context-v1')) ?></span>
  <span class="st-stat-badge info">Sources: <?= (int)($meta['source_count'] ?? 0) ?></span>
  <span class="st-stat-badge info">Indexed pages: <?= (int)($meta['indexed_pages'] ?? 0) ?></span>
  <span class="st-stat-badge info">Last scan: <?= htmlspecialchars((string)($meta['last_scan_at'] ?? '-')) ?></span>
</div>

<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
  <form method="post" style="margin:0;">
    <?php csrf_field(); ?>
    <button type="submit" name="rebuild_ai_context" value="1" class="st-btn st-btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
      <span class="material-symbols-outlined" style="font-size:16px;">refresh</span>
      Rebuild Context Index
    </button>
  </form>

  <a href="index.php?page=ai_suggestions" class="st-btn st-btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
    <span class="material-symbols-outlined" style="font-size:16px;">smart_toy</span>
    Open AI Suggestions Queue
  </a>

  <a href="index.php?page=ai_rulebook" class="st-btn st-btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
    <span class="material-symbols-outlined" style="font-size:16px;">rule_settings</span>
    Open AI Rulebook Trainer
  </a>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;margin-bottom:16px;">
  <div style="padding:12px;border-radius:10px;border:1px solid var(--outline-variant);background:var(--surface-container-lowest);">
    <div style="font-weight:700;font-size:0.84rem;color:var(--on-surface);margin-bottom:6px;">Sidebar Items Indexed</div>
    <div style="font-size:1.2rem;font-weight:800;color:var(--on-surface);"><?= count($sidebar) ?></div>
  </div>
  <div style="padding:12px;border-radius:10px;border:1px solid var(--outline-variant);background:var(--surface-container-lowest);">
    <div style="font-weight:700;font-size:0.84rem;color:var(--on-surface);margin-bottom:6px;">Route Entries Indexed</div>
    <div style="font-size:1.2rem;font-weight:800;color:var(--on-surface);"><?= count($routes) ?></div>
  </div>
  <div style="padding:12px;border-radius:10px;border:1px solid var(--outline-variant);background:var(--surface-container-lowest);">
    <div style="font-weight:700;font-size:0.84rem;color:var(--on-surface);margin-bottom:6px;">Docs Indexed</div>
    <div style="font-size:1.2rem;font-weight:800;color:var(--on-surface);"><?= count($docs) ?></div>
  </div>
  <div style="padding:12px;border-radius:10px;border:1px solid var(--outline-variant);background:var(--surface-container-lowest);">
    <div style="font-weight:700;font-size:0.84rem;color:var(--on-surface);margin-bottom:6px;">Rulebook (Enabled/Total)</div>
    <div style="font-size:1.2rem;font-weight:800;color:var(--on-surface);"><?= (int)$enabledRuleCount ?> / <?= (int)count($ruleRows) ?></div>
  </div>
</div>

<div style="margin:0 0 16px 0;padding:12px;border-radius:10px;background:var(--surface-container-lowest);border:1px solid var(--outline-variant);">
  <div style="font-size:0.86rem;font-weight:700;color:var(--on-surface);margin-bottom:8px;">Current Context Preview</div>
  <pre style="margin:0;max-height:380px;overflow:auto;background:var(--surface);border:1px solid var(--outline-variant);border-radius:8px;padding:12px;font-size:0.76rem;line-height:1.45;color:var(--on-surface-variant);"><?= htmlspecialchars($contextPreview) ?></pre>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:12px;">
  <div style="padding:12px;border-radius:10px;background:#eef6ff;border:1px solid #cfe1f5;color:#1d4f80;">
    <div style="font-size:0.84rem;font-weight:700;margin-bottom:6px;">Top Sidebar Routes</div>
    <ul style="margin:0;padding-left:18px;font-size:0.78rem;line-height:1.4;max-height:180px;overflow:auto;">
      <?php foreach (array_slice($sidebar, 0, 20) as $it): ?>
        <li><?= htmlspecialchars((string)($it['label'] ?? 'Page')) ?> → <?= htmlspecialchars((string)($it['route'] ?? '')) ?></li>
      <?php endforeach; ?>
      <?php if (empty($sidebar)): ?><li>No sidebar routes indexed.</li><?php endif; ?>
    </ul>
  </div>

  <div style="padding:12px;border-radius:10px;background:#fff8e8;border:1px solid #f5dfad;color:#8a5a00;">
    <div style="font-size:0.84rem;font-weight:700;margin-bottom:6px;">Top Indexed Route Files</div>
    <ul style="margin:0;padding-left:18px;font-size:0.78rem;line-height:1.4;max-height:180px;overflow:auto;">
      <?php foreach (array_slice($routes, 0, 20) as $rt): ?>
        <li><?= htmlspecialchars((string)($rt['route'] ?? '')) ?> (<?= htmlspecialchars((string)($rt['scope'] ?? 'public')) ?>)</li>
      <?php endforeach; ?>
      <?php if (empty($routes)): ?><li>No routes indexed.</li><?php endif; ?>
    </ul>
  </div>
</div>
