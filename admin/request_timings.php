<?php
require_once __DIR__ . '/session_bootstrap.php';
if (empty($_SESSION['admin_logged_in'])) {
  header('Location: login.php');
  exit;
}

require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/cache_helpers.php';
require_once __DIR__ . '/includes/hybrid_admin_read.php';
require_once __DIR__ . '/../hybrid_dual_write.php';
app_storage_init();
csrf_token();

$timingFile = app_storage_file('logs/request_timing.jsonl');
$message = '';
$timingSource = 'local';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check_request()) {
    http_response_code(403);
    $message = 'Invalid CSRF token.';
  } elseif (isset($_POST['clear_timings'])) {
    $localCleared = file_put_contents($timingFile, '', LOCK_EX) !== false;
    $remoteCleared = true;
    $remoteAttempted = function_exists('hybrid_admin_read_enabled') && hybrid_admin_read_enabled() && function_exists('hybrid_supabase_delete');

    if ($remoteAttempted) {
      $err = null;
      $remoteCleared = hybrid_supabase_delete('request_timings', ['id' => 'gt.0'], $err);
      if (!$remoteCleared) {
        $message = 'Local timings cleared, but Supabase clear failed.';
      }
    }

    if ($message === '') {
      if ($localCleared && $remoteCleared && $remoteAttempted) {
        $message = 'Local and Supabase timing logs cleared.';
      } elseif ($localCleared) {
        $message = 'Timing log cleared.';
      } else {
        $message = 'Failed to clear timing log.';
      }
    }
  }
}

$records = [];

if (function_exists('hybrid_admin_read_enabled') && hybrid_admin_read_enabled()) {
  $rows = null;
  $err = null;
  $ok = hybrid_supabase_select('request_timings', [
    'select' => 'route,started_at_epoch,finished_at,duration_ms,method,uri,status_code,memory_peak_mb,meta,spans',
    'order' => 'finished_at.desc',
    'limit' => '300'
  ], $rows, $err);
  if ($ok && is_array($rows) && count($rows) > 0) {
    foreach ($rows as $row) {
      $records[] = [
        'route' => (string)($row['route'] ?? 'unknown'),
        'started_at' => isset($row['started_at_epoch']) ? (float)$row['started_at_epoch'] : null,
        'finished_at' => (string)($row['finished_at'] ?? ''),
        'duration_ms' => isset($row['duration_ms']) ? (float)$row['duration_ms'] : 0,
        'method' => (string)($row['method'] ?? ''),
        'uri' => (string)($row['uri'] ?? ''),
        'status_code' => isset($row['status_code']) ? (int)$row['status_code'] : null,
        'memory_peak_mb' => isset($row['memory_peak_mb']) ? (float)$row['memory_peak_mb'] : 0,
        'meta' => is_array($row['meta'] ?? null) ? $row['meta'] : [],
        'spans' => is_array($row['spans'] ?? null) ? $row['spans'] : [],
      ];
    }
    $timingSource = 'supabase';
  }
}

if (empty($records) && file_exists($timingFile)) {
  $lines = admin_cached_file_lines('request_timings_lines', $timingFile, 10);
  $lines = array_slice($lines, -300);
  foreach ($lines as $line) {
    $decoded = json_decode($line, true);
    if (is_array($decoded) && isset($decoded['route'])) {
      $records[] = $decoded;
    }
  }
}

$routeStats = [];
$diagnosticBuckets = [
  'database_supabase' => 0,
  'app_server_azure' => 0,
  'network_or_edge_uncertain' => 0,
  'unknown' => 0,
];
foreach ($records as $record) {
  $route = (string)($record['route'] ?? 'unknown');
  $duration = (float)($record['duration_ms'] ?? 0);
  if (!isset($routeStats[$route])) {
    $routeStats[$route] = ['count' => 0, 'durations' => [], 'max_ms' => 0];
  }
  $routeStats[$route]['count']++;
  $routeStats[$route]['durations'][] = $duration;
  $routeStats[$route]['max_ms'] = max($routeStats[$route]['max_ms'], $duration);

  $diag = $record['meta']['diagnostics']['likely_layer'] ?? null;
  if (!is_string($diag) || $diag === '') {
    $diag = 'unknown';
  }
  if (!isset($diagnosticBuckets[$diag])) {
    $diagnosticBuckets[$diag] = 0;
  }
  $diagnosticBuckets[$diag]++;
}

foreach ($routeStats as $route => &$stat) {
  sort($stat['durations']);
  $count = count($stat['durations']);
  $sum = array_sum($stat['durations']);
  $p95Index = $count > 0 ? (int)floor(($count - 1) * 0.95) : 0;
  $stat['avg_ms'] = $count > 0 ? round($sum / $count, 2) : 0;
  $stat['p95_ms'] = $count > 0 ? round($stat['durations'][$p95Index], 2) : 0;
}
unset($stat);

uasort($routeStats, function ($a, $b) {
  return $b['avg_ms'] <=> $a['avg_ms'];
});
?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
  <div>
    <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);margin:0;">Request Timings
      <span style="font-size: 0.55rem; vertical-align: middle; padding: 3px 10px; border-radius: 10px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; margin-left: 8px;
        <?= $timingSource === 'supabase' ? 'background: rgba(34,197,94,0.15); color: #16a34a;' : 'background: rgba(59,130,246,0.15); color: #2563eb;' ?>
      "><?= $timingSource === 'supabase' ? 'SUPABASE' : 'LOCAL' ?></span>
    </h2>
    <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">Recent request durations and recorded spans from live traffic.</p>
  </div>
  <form method="post" style="margin:0;">
    <?php csrf_field(); ?>
    <button type="submit" name="clear_timings" value="1" class="st-btn st-btn-danger st-btn-sm">
      <span class="material-symbols-outlined" style="font-size:1rem;">delete</span> Clear Timings
    </button>
  </form>
</div>

<div class="stats" style="grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));margin-bottom:24px;">
  <div class="stat" style="text-align:left;border-top:none;">
    <p class="st-stat-label">Logged Requests</p>
    <p class="st-stat-value"><?= number_format(count($records)) ?></p>
  </div>
  <div class="stat" style="text-align:left;border-top:none;">
    <p class="st-stat-label">Tracked Routes</p>
    <p class="st-stat-value"><?= number_format(count($routeStats)) ?></p>
  </div>
  <div class="stat" style="text-align:left;border-top:none;">
    <p class="st-stat-label">Slowest Avg</p>
    <p class="st-stat-value">
      <?php
      $first = reset($routeStats);
      echo $first ? number_format($first['avg_ms'], 2) . ' ms' : '0 ms';
      ?>
    </p>
  </div>
  <div class="stat" style="text-align:left;border-top:none;">
    <p class="st-stat-label">Likely DB-Limited</p>
    <p class="st-stat-value"><?= number_format((int)($diagnosticBuckets['database_supabase'] ?? 0)) ?></p>
  </div>
  <div class="stat" style="text-align:left;border-top:none;">
    <p class="st-stat-label">Likely App-Limited</p>
    <p class="st-stat-value"><?= number_format((int)($diagnosticBuckets['app_server_azure'] ?? 0)) ?></p>
  </div>
</div>

<div class="st-card" style="margin-bottom:24px;">
  <p style="font-weight:700;color:var(--on-surface);margin:0 0 12px;">Likely Bottleneck Attribution</p>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">
    <div class="st-chip" style="justify-content:flex-start;padding:10px 12px;background:#ecfdf5;color:#065f46;">
      Supabase/DB: <strong style="margin-left:6px;"><?= (int)($diagnosticBuckets['database_supabase'] ?? 0) ?></strong>
    </div>
    <div class="st-chip" style="justify-content:flex-start;padding:10px 12px;background:#eff6ff;color:#1e40af;">
      Azure App: <strong style="margin-left:6px;"><?= (int)($diagnosticBuckets['app_server_azure'] ?? 0) ?></strong>
    </div>
    <div class="st-chip" style="justify-content:flex-start;padding:10px 12px;background:#fff7ed;color:#9a3412;">
      DNS/Edge Uncertain: <strong style="margin-left:6px;"><?= (int)($diagnosticBuckets['network_or_edge_uncertain'] ?? 0) ?></strong>
    </div>
    <div class="st-chip" style="justify-content:flex-start;padding:10px 12px;background:#f8fafc;color:#334155;">
      Unknown: <strong style="margin-left:6px;"><?= (int)($diagnosticBuckets['unknown'] ?? 0) ?></strong>
    </div>
  </div>
</div>

<div class="st-card" style="margin-bottom:24px;">
  <p style="font-weight:700;color:var(--on-surface);margin:0 0 16px;">Route Summary</p>
  <?php if (!empty($routeStats)): ?>
    <div style="overflow:auto;">
      <table class="st-table" style="width:100%;">
        <thead>
          <tr>
            <th>Route</th>
            <th>Count</th>
            <th>Avg</th>
            <th>P95</th>
            <th>Max</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($routeStats as $route => $stat): ?>
            <tr>
              <td style="font-weight:600;"><?= htmlspecialchars($route) ?></td>
              <td><?= number_format($stat['count']) ?></td>
              <td><?= number_format($stat['avg_ms'], 2) ?> ms</td>
              <td><?= number_format($stat['p95_ms'], 2) ?> ms</td>
              <td><?= number_format($stat['max_ms'], 2) ?> ms</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p style="margin:0;color:var(--on-surface-variant);">No timing data recorded yet.</p>
  <?php endif; ?>
</div>

<div class="st-card">
  <p style="font-weight:700;color:var(--on-surface);margin:0 0 16px;">Recent Requests</p>
  <?php if (!empty($records)): ?>
    <div style="display:grid;gap:12px;">
      <?php foreach (array_reverse(array_slice($records, -25)) as $record): ?>
        <?php
          $diag = is_array($record['meta']['diagnostics'] ?? null) ? $record['meta']['diagnostics'] : [];
          $likelyLayer = (string)($diag['likely_layer'] ?? 'unknown');
          $confidence = isset($diag['confidence']) ? (float)$diag['confidence'] : 0;
          $reason = (string)($diag['reason'] ?? 'No diagnostic reason captured.');
          $breakdown = is_array($diag['breakdown_ms'] ?? null) ? $diag['breakdown_ms'] : [];
          $policy = is_array($record['meta']['timing_policy'] ?? null) ? $record['meta']['timing_policy'] : [];
        ?>
        <div style="border:1px solid var(--outline-variant);border-radius:12px;padding:14px;background:var(--surface-container-low);">
          <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <strong><?= htmlspecialchars((string)$record['route']) ?></strong>
            <span><?= number_format((float)($record['duration_ms'] ?? 0), 2) ?> ms</span>
          </div>
          <div style="font-size:0.85rem;color:var(--on-surface-variant);margin-top:4px;">
            <?= htmlspecialchars((string)($record['method'] ?? 'GET')) ?> • <?= htmlspecialchars((string)($record['finished_at'] ?? '')) ?> • peak <?= number_format((float)($record['memory_peak_mb'] ?? 0), 2) ?> MB
          </div>
          <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">
            <span class="st-chip" style="background:#f1f5f9;color:#0f172a;">Likely: <?= htmlspecialchars($likelyLayer) ?></span>
            <span class="st-chip" style="background:#f8fafc;color:#334155;">Confidence: <?= number_format($confidence * 100, 0) ?>%</span>
            <?php if (!empty($policy['kept_because'])): ?>
              <span class="st-chip" style="background:#eef2ff;color:#3730a3;">Kept: <?= htmlspecialchars((string)$policy['kept_because']) ?></span>
            <?php endif; ?>
          </div>
          <div style="margin-top:8px;font-size:0.82rem;color:var(--on-surface-variant);">
            <?= htmlspecialchars($reason) ?>
          </div>
          <?php if (!empty($breakdown)): ?>
            <div style="margin-top:8px;font-size:0.8rem;color:var(--on-surface-variant);display:flex;gap:8px;flex-wrap:wrap;">
              <span>DB: <?= number_format((float)($breakdown['db_supabase'] ?? 0), 2) ?>ms</span>
              <span>App: <?= number_format((float)($breakdown['app_server'] ?? 0), 2) ?>ms</span>
              <span>Unattributed: <?= number_format((float)($breakdown['unattributed'] ?? 0), 2) ?>ms</span>
            </div>
          <?php endif; ?>
          <?php if (!empty($record['spans']) && is_array($record['spans'])): ?>
            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
              <?php foreach ($record['spans'] as $span): ?>
                <span class="st-chip">
                  <?= htmlspecialchars((string)($span['name'] ?? 'span')) ?>: <?= number_format((float)($span['duration_ms'] ?? 0), 2) ?> ms
                </span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p style="margin:0;color:var(--on-surface-variant);">Visit a few pages or submit attendance first, then refresh this page.</p>
  <?php endif; ?>
</div>

<?php if ($message !== ''): ?>
<script>
  window.adminAlert(
    <?= json_encode(stripos($message, 'invalid') === false ? 'Success' : 'Action failed') ?>,
    <?= json_encode($message) ?>,
    <?= json_encode(stripos($message, 'invalid') === false ? 'success' : 'error') ?>
  );
</script>
<?php endif; ?>
