<?php
require_once __DIR__ . '/includes/hybrid_admin_read.php';
require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/cache_helpers.php';
require_once __DIR__ . '/../env_helpers.php';
require_once __DIR__ . '/../request_timing.php';
app_storage_init();
request_timing_start('admin/dashboard.php');
$logDir = app_storage_file('logs');

$today = new DateTime();

$dashCurrentRole = $_SESSION['admin_role'] ?? 'admin';
$dashPermissions = admin_load_permissions_cached(15);
$dashAllowedPages = ($dashCurrentRole === 'superadmin')
  ? []
  : (is_array($dashPermissions) ? ($dashPermissions[$dashCurrentRole] ?? []) : []);
$dashCanView = static function (string $pageId) use ($dashCurrentRole, $dashAllowedPages): bool {
  return $dashCurrentRole === 'superadmin' || in_array($pageId, $dashAllowedPages, true);
};

$canViewLogs = $dashCanView('logs') || $dashCanView('request_timings') || $dashCanView('chain');
$canViewFailed = $dashCanView('failed_attempts');
$canViewSupport = $dashCanView('support_tickets');
$canViewFingerprints = $dashCanView('unlink_fingerprint');
$canViewAi = $dashCanView('ai_suggestions');
$canViewSettings = $dashCanView('settings');
$canViewManual = $dashCanView('manual_attendance');
$canViewCourses = $dashCanView('set_active') || $dashCanView('add_course');

$span = microtime(true);
$logSummary = admin_dashboard_log_summary(20, 2);
$dailyCounts = $logSummary['dailyCounts'] ?? [];
$courseCounts = $logSummary['courseCounts'] ?? [];
$failedCounts = $logSummary['failedCounts'] ?? [];
$recentLogs = $logSummary['recentLogs'] ?? [];
request_timing_span('scan_dashboard_logs', $span, [
  'daily_files' => (int)($logSummary['attendanceFileCount'] ?? 0),
  'failed_files' => (int)($logSummary['failedFileCount'] ?? 0),
]);

$supportFile = admin_storage_migrate_file('support_tickets.json', app_storage_file('support_tickets.json'));
$supportSource = 'file';
$supportTickets = hybrid_fetch_support_tickets($supportSource);
if (!is_array($supportTickets)) {
  $supportTickets = admin_cached_json_file('support_tickets_dashboard', $supportFile, [], 15);
}
$newSupportCount = 0;
if (is_array($supportTickets)) {
  foreach ($supportTickets as $ticket) {
    if (!($ticket['resolved'] ?? false)) {
      $newSupportCount++;
    }
  }
}

$fingerprintCount = admin_fingerprint_count_cached(15);
$activeCourse = admin_active_course_name_cached(15);

$todayStr = $today->format('Y-m-d');
$todayCount = $dailyCounts[$todayStr] ?? 0;
$todayFailed = $failedCounts[$todayStr] ?? 0;

$aiDiagFile = function_exists('ai_ticket_diagnostics_file')
  ? ai_ticket_diagnostics_file()
  : admin_storage_migrate_file('ai_ticket_diagnostics.json');
$aiDiagRows = file_exists($aiDiagFile)
  ? admin_cached_json_file('dashboard_ai_diagnostics', $aiDiagFile, [], 10)
  : [];
if (!is_array($aiDiagRows)) {
  $aiDiagRows = [];
}
$aiDiagRows = array_slice($aiDiagRows, 0, 400);

$configuredProvider = strtolower((string)app_env_value('AI_AUTOMATION_PROVIDER', 'rules'));
if (!in_array($configuredProvider, ['rules', 'groq', 'openrouter', 'gemini', 'auto'], true)) {
  $configuredProvider = 'rules';
}

$aiProviderActive = $configuredProvider;
$aiLatencySamples = [];
$aiPendingReviewCount = 0;

if (!empty($aiDiagRows)) {
  $aiProviderActive = (string)($aiDiagRows[0]['ai_provider'] ?? 'rules');
}

$metricsFile = app_storage_file('admin/ai_provider_metrics.json');
$metricsRows = file_exists($metricsFile)
  ? admin_cached_json_file('dashboard_ai_provider_metrics', $metricsFile, [], 10)
  : [];
if (!is_array($metricsRows)) {
  $metricsRows = [];
}

if (empty($aiDiagRows) && !empty($metricsRows)) {
  $latestProvider = '';
  $latestTs = 0;
  foreach ($metricsRows as $providerName => $metric) {
    if (!is_array($metric)) continue;
    $ts = strtotime((string)($metric['updated_at'] ?? '')) ?: 0;
    if ($ts > $latestTs) {
      $latestTs = $ts;
      $latestProvider = (string)$providerName;
    }
  }
  if ($latestProvider !== '') {
    $aiProviderActive = $latestProvider;
  }
}

foreach ($aiDiagRows as $row) {
  if (!is_array($row)) continue;
  $lat = (int)($row['ai_latency_ms'] ?? 0);
  if ($lat > 0) {
    $aiLatencySamples[] = $lat;
  }

  $cls = (string)($row['classification'] ?? '');
  if (
    in_array($cls, ['network_ip_rotation', 'new_or_suspicious_device', 'duplicate_or_fraudulent_sequence', 'blocked_revoked_device', 'policy_device_sharing_risk'], true)
    || empty($row['ticket_resolved'])
  ) {
    $aiPendingReviewCount++;
  }
}

$aiAvgLatencyMs = !empty($aiLatencySamples)
  ? (int)round(array_sum($aiLatencySamples) / count($aiLatencySamples))
  : 0;

if ($aiAvgLatencyMs === 0 && !empty($metricsRows)) {
  $sum = 0.0;
  $count = 0;
  foreach ($metricsRows as $metric) {
    if (!is_array($metric)) continue;
    $avg = (float)($metric['avg_ms'] ?? 0);
    if ($avg > 0) {
      $sum += $avg;
      $count++;
    }
  }
  if ($count > 0) {
    $aiAvgLatencyMs = (int)round($sum / $count);
  }
}

$aiProviderBadgeText = htmlspecialchars($aiProviderActive);
if (empty($aiDiagRows) && !empty($metricsRows)) {
  $aiProviderBadgeText = htmlspecialchars($aiProviderActive) . ' (last-run)';
} elseif (empty($aiDiagRows) && empty($metricsRows)) {
  $aiProviderBadgeText = htmlspecialchars($configuredProvider) . ' (configured)';
}
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- Page Title & Quick Actions -->
<div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:24px;">
  <div>
    <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;">System Overview</h2>
    <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">Blockchain-verified attendance records for current semester.</p>
  </div>
  <?php if ($canViewSettings || $canViewLogs || $canViewManual): ?>
    <div style="display:flex;flex-wrap:wrap;gap:8px;" class="hide-on-mobile">
      <?php if ($canViewSettings): ?>
        <a href="index.php?page=settings" class="st-btn st-btn-ghost st-btn-sm">
          <span class="material-symbols-outlined" style="font-size:16px;">settings</span> Settings
        </a>
      <?php endif; ?>
      <?php if ($canViewLogs): ?>
        <a href="index.php?page=logs" class="st-btn st-btn-secondary st-btn-sm">
          <span class="material-symbols-outlined" style="font-size:16px;">history</span> Logs
        </a>
      <?php endif; ?>
      <?php if ($canViewManual): ?>
        <a href="index.php?page=manual_attendance" class="st-btn st-btn-primary st-btn-sm">
          <span class="material-symbols-outlined" style="font-size:16px;">touch_app</span> Manual Attendance
        </a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Stats Grid (Primary) -->
<div class="stats" style="grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));">
  <?php if ($canViewLogs): ?>
    <!-- Today's Attendance -->
    <div class="stat" style="text-align:left;border-top:none;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <div class="st-stat-icon primary">
          <span class="material-symbols-outlined" style="font-variation-settings:'FILL' 1;">person_check</span>
        </div>
        <?php if ($todayCount > 0): ?>
          <span class="st-stat-badge success">Today</span>
        <?php endif; ?>
      </div>
      <p class="st-stat-label">Attendance (Today)</p>
      <p class="st-stat-value"><?= number_format($todayCount) ?></p>
    </div>
  <?php endif; ?>

  <?php if ($canViewLogs): ?>
    <!-- Total Students -->
    <div class="stat" style="text-align:left;border-top:none;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <div class="st-stat-icon secondary">
          <span class="material-symbols-outlined" style="font-variation-settings:'FILL' 1;">groups</span>
        </div>
        <span class="st-stat-badge info">Active</span>
      </div>
      <p class="st-stat-label">Total Students</p>
      <p class="st-stat-value"><?= number_format((int)($logSummary['uniqueStudentCount'] ?? 0)) ?></p>
    </div>
  <?php endif; ?>

  <?php if ($canViewFailed): ?>
    <!-- Failed Attempts -->
    <div class="stat" style="text-align:left;border-top:none;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <div class="st-stat-icon error">
          <span class="material-symbols-outlined" style="font-variation-settings:'FILL' 1;">warning</span>
        </div>
        <?php if (array_sum($failedCounts) > 0): ?>
          <span class="st-stat-badge danger">Attention</span>
        <?php endif; ?>
      </div>
      <p class="st-stat-label">Failed Attempts</p>
      <p class="st-stat-value"><?= number_format(array_sum($failedCounts)) ?></p>
    </div>
  <?php endif; ?>

  <?php if ($canViewCourses): ?>
    <!-- Active Course -->
    <div class="stat" style="text-align:left;border-top:none;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <div class="st-stat-icon tertiary">
          <span class="material-symbols-outlined" style="font-variation-settings:'FILL' 1;">book</span>
        </div>
        <span class="st-stat-badge info">Live</span>
      </div>
      <p class="st-stat-label">Active Course</p>
      <p style="font-size:1.1rem;font-weight:800;color:var(--on-surface);margin-top:4px;word-break:break-word;"><?= htmlspecialchars($activeCourse) ?></p>
    </div>
  <?php endif; ?>
</div>

<!-- Secondary Stats Row -->
<?php if ($canViewLogs || $canViewCourses || $canViewFingerprints || $canViewSupport || $canViewAi): ?>
  <div class="hide-on-mobile" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;margin-bottom:24px;">
    <?php if ($canViewLogs): ?>
      <div class="st-card-soft" style="display:flex;align-items:center;justify-content:space-between;">
        <div>
          <p class="st-label" style="margin-bottom:4px;">Total Attendance</p>
          <p style="font-size:1.2rem;font-weight:800;color:var(--on-surface);margin:0;"><?= number_format(array_sum($dailyCounts)) ?></p>
        </div>
        <span class="material-symbols-outlined" style="font-size:2rem;color:var(--outline-variant);">calendar_month</span>
      </div>
    <?php endif; ?>
    <?php if ($canViewCourses): ?>
      <div class="st-card-soft" style="display:flex;align-items:center;justify-content:space-between;">
        <div>
          <p class="st-label" style="margin-bottom:4px;">Courses</p>
          <p style="font-size:1.2rem;font-weight:800;color:var(--on-surface);margin:0;"><?= count($courseCounts) ?></p>
        </div>
        <span class="material-symbols-outlined" style="font-size:2rem;color:var(--outline-variant);">school</span>
      </div>
    <?php endif; ?>
    <?php if ($canViewFingerprints): ?>
      <div class="st-card-soft" style="display:flex;align-items:center;justify-content:space-between;">
        <div>
          <p class="st-label" style="margin-bottom:4px;">Linked Fingerprints</p>
          <p style="font-size:1.2rem;font-weight:800;color:var(--on-surface);margin:0;"><?= number_format($fingerprintCount) ?></p>
        </div>
        <span class="material-symbols-outlined" style="font-size:2rem;color:var(--outline-variant);">fingerprint</span>
      </div>
    <?php endif; ?>
    <?php if ($canViewSupport): ?>
      <div class="st-card-soft" style="display:flex;align-items:center;justify-content:space-between;">
        <div>
          <p class="st-label" style="margin-bottom:4px;">Open Support Tickets</p>
          <p style="font-size:1.2rem;font-weight:800;color:var(--on-surface);margin:0;"><?= str_pad($newSupportCount, 2, '0', STR_PAD_LEFT) ?></p>
        </div>
        <span class="material-symbols-outlined" style="font-size:2rem;color:var(--outline-variant);">confirmation_number</span>
      </div>
    <?php endif; ?>

    <?php if ($canViewAi): ?>
      <div class="st-card-soft" style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
        <div>
          <p class="st-label" style="margin-bottom:6px;">AI Automation Health</p>
          <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
            <span class="st-stat-badge info">Provider: <?= $aiProviderBadgeText ?></span>
            <span class="st-stat-badge success">Avg: <?= (int)$aiAvgLatencyMs ?>ms</span>
            <span class="st-stat-badge <?= $aiPendingReviewCount > 0 ? 'danger' : 'success' ?>">Pending: <?= (int)$aiPendingReviewCount ?></span>
          </div>
          <div style="margin-top:8px;">
            <a href="index.php?page=ai_suggestions" class="st-btn st-btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
              <span class="material-symbols-outlined" style="font-size:16px;">smart_toy</span>
              Open AI Review Queue / Plan
            </a>
          </div>
        </div>
        <span class="material-symbols-outlined" style="font-size:2rem;color:var(--outline-variant);">smart_toy</span>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- Charts Section -->
<?php if ($canViewLogs || $canViewFailed): ?>
  <div class="charts dashboard-charts">
    <?php if ($canViewLogs): ?>
      <div class="chart-wrapper"><canvas id="attendanceChart"></canvas></div>
      <div class="chart-wrapper dashboard-desktop-only"><canvas id="coursePieChart"></canvas></div>
    <?php endif; ?>
    <?php if ($canViewFailed): ?>
      <div class="chart-wrapper dashboard-desktop-only"><canvas id="failedChart"></canvas></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- Recent Activity Logs -->
<?php if ($canViewLogs): ?>
  <div class="recent-logs">
    <h3><span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;">schedule</span>Recent Activity (Last 2 Days)</h3>
    <ul class="log-list">
      <?php if (!empty($recentLogs)): ?>
        <?php foreach (array_slice($recentLogs, 0, 20) as $log): ?>
          <?php
          $parts = array_map('trim', explode('|', $log));
          $name = isset($parts[0]) ? strtoupper($parts[0]) : 'Unknown';
          $matric = isset($parts[1]) ? $parts[1] : 'N/A';
          $macRegex = '/([0-9a-f]{2}[:\\-]){5}[0-9a-f]{2}/i';
          $isAiStructuredRow = count($parts) >= 10 && in_array(strtolower((string)($parts[7] ?? '')), ['ai ticket processor', 'sentinel ai'], true);
          if ($isAiStructuredRow || (isset($parts[5]) && preg_match($macRegex, $parts[5]))) {
            $course = isset($parts[8]) ? $parts[8] : 'General';
          } else {
            $course = isset($parts[7]) ? $parts[7] : 'General';
          }
          ?>
          <li>
            <div class="log-main">
              <span class="log-name"><?= htmlspecialchars($name) ?></span>
              <span class="log-matric"><?= htmlspecialchars($matric) ?></span>
            </div>
            <div class="log-course"><?= htmlspecialchars($course) ?></div>
          </li>
        <?php endforeach; ?>
      <?php else: ?>
        <li class="empty-log">No recent logs.</li>
      <?php endif; ?>
    </ul>
  </div>
<?php endif; ?>

<!-- Quick Actions -->
<?php if ($canViewLogs || $canViewSupport || $canViewFingerprints): ?>
  <div class="quick-actions">
    <h3><span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;">bolt</span>Quick Actions</h3>
    <?php if ($canViewLogs): ?>
      <a href="index.php?page=logs" class="st-btn st-btn-primary st-btn-sm">
        <span class="material-symbols-outlined" style="font-size:16px;">description</span> Logs
      </a>
    <?php endif; ?>
    <?php if ($canViewSupport): ?>
      <a href="index.php?page=support_tickets" class="st-btn st-btn-success st-btn-sm">
        <span class="material-symbols-outlined" style="font-size:16px;">confirmation_number</span> Support
      </a>
    <?php endif; ?>
    <?php if ($canViewFingerprints): ?>
      <a href="index.php?page=unlink_fingerprint" style="background:#f59e0b;color:#fff;" class="st-btn st-btn-sm">
        <span class="material-symbols-outlined" style="font-size:16px;">link_off</span> Fingerprints
      </a>
    <?php endif; ?>
    <?php if ($dashCurrentRole === 'superadmin' && $canViewLogs): ?>
      <a href="./logs/export_simple.php" class="st-btn st-btn-danger st-btn-sm dashboard-desktop-only-inline">
        <span class="material-symbols-outlined" style="font-size:16px;">download</span> Export
      </a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<style>
  @media (max-width: 1024px) {
    .dashboard-desktop-only,
    .dashboard-desktop-only-inline {
      display: none !important;
    }

    .dashboard-charts {
      grid-template-columns: 1fr !important;
    }
  }

  @media (max-width: 768px) {
    .recent-logs .log-list li:nth-child(n+9) {
      display: none;
    }
  }
</style>

<script>
  const attendanceChartEl = document.getElementById('attendanceChart');
  if (attendanceChartEl) {
    new Chart(attendanceChartEl.getContext('2d'), {
    type: 'line',
    data: {
      labels: <?= json_encode(array_keys($dailyCounts)) ?>,
      datasets: [{
        label: 'Attendance',
        data: <?= json_encode(array_values($dailyCounts)) ?>,
        backgroundColor: 'rgba(0, 69, 123, 0.1)',
        borderColor: '#00457b',
        tension: 0.4,
        fill: true,
        pointBackgroundColor: '#00457b',
        pointBorderColor: '#fff',
        pointBorderWidth: 2,
        pointRadius: 4,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true,
          labels: {
            font: {
              family: 'Inter',
              weight: '600'
            },
            color: '#424750'
          }
        }
      },
      scales: {
        x: {
          grid: {
            display: false
          },
          ticks: {
            font: {
              family: 'Inter',
              size: 11
            },
            color: '#727781'
          }
        },
        y: {
          grid: {
            color: 'rgba(194,199,209,0.15)'
          },
          ticks: {
            font: {
              family: 'Inter',
              size: 11
            },
            color: '#727781'
          }
        }
      }
    }
    });
  }

  const coursePieChartEl = document.getElementById('coursePieChart');
  if (coursePieChartEl) {
    new Chart(coursePieChartEl.getContext('2d'), {
    type: 'doughnut',
    data: {
      labels: <?= json_encode(array_keys($courseCounts)) ?>,
      datasets: [{
        label: 'Courses',
        data: <?= json_encode(array_values($courseCounts)) ?>,
        backgroundColor: ['#00457b', '#15629a', '#1f5d99', '#2f4560', '#475c79', '#83c1fe', '#a1c9ff'],
        borderWidth: 2,
        borderColor: '#fff',
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom',
          labels: {
            font: {
              family: 'Inter',
              size: 11
            },
            color: '#424750',
            padding: 12
          }
        }
      },
      cutout: '60%',
    }
    });
  }

  const failedChartEl = document.getElementById('failedChart');
  if (failedChartEl) {
    new Chart(failedChartEl.getContext('2d'), {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_keys($failedCounts)) ?>,
      datasets: [{
        label: 'Failed Attempts',
        data: <?= json_encode(array_values($failedCounts)) ?>,
        backgroundColor: 'rgba(186, 26, 26, 0.15)',
        borderColor: '#ba1a1a',
        borderWidth: 2,
        borderRadius: 6,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true,
          labels: {
            font: {
              family: 'Inter',
              weight: '600'
            },
            color: '#424750'
          }
        }
      },
      scales: {
        x: {
          grid: {
            display: false
          },
          ticks: {
            font: {
              family: 'Inter',
              size: 11
            },
            color: '#727781'
          }
        },
        y: {
          grid: {
            color: 'rgba(194,199,209,0.15)'
          },
          ticks: {
            font: {
              family: 'Inter',
              size: 11
            },
            color: '#727781'
          }
        }
      }
    }
    });
  }
</script>
