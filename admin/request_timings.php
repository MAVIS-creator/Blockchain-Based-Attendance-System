<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_logged_in'])) {
  header('Location: login.php');
  exit;
}

require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/includes/csrf.php';
app_storage_init();
csrf_token();

$timingFile = app_storage_file('logs/request_timing.jsonl');
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check_request()) {
    http_response_code(403);
    $message = 'Invalid CSRF token.';
  } elseif (isset($_POST['clear_timings'])) {
    file_put_contents($timingFile, '', LOCK_EX);
    $message = 'Timing log cleared.';
  }
}

$records = [];
if (file_exists($timingFile)) {
  $lines = @file($timingFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
  $lines = array_slice($lines, -300);
  foreach ($lines as $line) {
    $decoded = json_decode($line, true);
    if (is_array($decoded) && isset($decoded['route'])) {
      $records[] = $decoded;
    }
  }
}

$routeStats = [];
foreach ($records as $record) {
  $route = (string)($record['route'] ?? 'unknown');
  $duration = (float)($record['duration_ms'] ?? 0);
  if (!isset($routeStats[$route])) {
    $routeStats[$route] = ['count' => 0, 'durations' => [], 'max_ms' => 0];
  }
  $routeStats[$route]['count']++;
  $routeStats[$route]['durations'][] = $duration;
  $routeStats[$route]['max_ms'] = max($routeStats[$route]['max_ms'], $duration);
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
    <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);margin:0;">Request Timings</h2>
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
        <div style="border:1px solid var(--outline-variant);border-radius:12px;padding:14px;background:var(--surface-container-low);">
          <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <strong><?= htmlspecialchars((string)$record['route']) ?></strong>
            <span><?= number_format((float)($record['duration_ms'] ?? 0), 2) ?> ms</span>
          </div>
          <div style="font-size:0.85rem;color:var(--on-surface-variant);margin-top:4px;">
            <?= htmlspecialchars((string)($record['method'] ?? 'GET')) ?> • <?= htmlspecialchars((string)($record['finished_at'] ?? '')) ?> • peak <?= number_format((float)($record['memory_peak_mb'] ?? 0), 2) ?> MB
          </div>
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
