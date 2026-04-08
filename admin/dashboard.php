<?php
require_once __DIR__ . '/includes/hybrid_admin_read.php';
require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/cache_helpers.php';
require_once __DIR__ . '/../request_timing.php';
app_storage_init();
request_timing_start('admin/dashboard.php');
$logDir = app_storage_file('logs');

$today = new DateTime();

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
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- Page Title & Quick Actions -->
<div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:24px;">
  <div>
    <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;">System Overview</h2>
    <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">Blockchain-verified attendance records for current semester.</p>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:8px;" class="hide-on-mobile">
    <a href="index.php?page=settings" class="st-btn st-btn-ghost st-btn-sm">
      <span class="material-symbols-outlined" style="font-size:16px;">settings</span> Settings
    </a>
    <a href="index.php?page=logs" class="st-btn st-btn-secondary st-btn-sm">
      <span class="material-symbols-outlined" style="font-size:16px;">history</span> Logs
    </a>
    <a href="index.php?page=manual_attendance" class="st-btn st-btn-primary st-btn-sm">
      <span class="material-symbols-outlined" style="font-size:16px;">touch_app</span> Manual Attendance
    </a>
  </div>
</div>

<!-- Stats Grid (Primary) -->
<div class="stats" style="grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));">
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
</div>

<!-- Secondary Stats Row -->
<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;margin-bottom:24px;">
  <div class="st-card-soft" style="display:flex;align-items:center;justify-content:space-between;">
    <div>
      <p class="st-label" style="margin-bottom:4px;">Total Attendance</p>
      <p style="font-size:1.2rem;font-weight:800;color:var(--on-surface);margin:0;"><?= number_format(array_sum($dailyCounts)) ?></p>
    </div>
    <span class="material-symbols-outlined" style="font-size:2rem;color:var(--outline-variant);">calendar_month</span>
  </div>
  <div class="st-card-soft" style="display:flex;align-items:center;justify-content:space-between;">
    <div>
      <p class="st-label" style="margin-bottom:4px;">Courses</p>
      <p style="font-size:1.2rem;font-weight:800;color:var(--on-surface);margin:0;"><?= count($courseCounts) ?></p>
    </div>
    <span class="material-symbols-outlined" style="font-size:2rem;color:var(--outline-variant);">school</span>
  </div>
  <div class="st-card-soft" style="display:flex;align-items:center;justify-content:space-between;">
    <div>
      <p class="st-label" style="margin-bottom:4px;">Linked Fingerprints</p>
      <p style="font-size:1.2rem;font-weight:800;color:var(--on-surface);margin:0;"><?= number_format($fingerprintCount) ?></p>
    </div>
    <span class="material-symbols-outlined" style="font-size:2rem;color:var(--outline-variant);">fingerprint</span>
  </div>
  <div class="st-card-soft" style="display:flex;align-items:center;justify-content:space-between;">
    <div>
      <p class="st-label" style="margin-bottom:4px;">Open Support Tickets</p>
      <p style="font-size:1.2rem;font-weight:800;color:var(--on-surface);margin:0;"><?= str_pad($newSupportCount, 2, '0', STR_PAD_LEFT) ?></p>
    </div>
    <span class="material-symbols-outlined" style="font-size:2rem;color:var(--outline-variant);">confirmation_number</span>
  </div>
</div>

<!-- Charts Section -->
<div class="charts">
  <div class="chart-wrapper"><canvas id="attendanceChart"></canvas></div>
  <div class="chart-wrapper"><canvas id="coursePieChart"></canvas></div>
  <div class="chart-wrapper"><canvas id="failedChart"></canvas></div>
</div>

<!-- Recent Activity Logs -->
<div class="recent-logs">
  <h3><span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;">schedule</span>Recent Activity (Last 2 Days)</h3>
  <ul class="log-list">
    <?php if (!empty($recentLogs)): ?>
      <?php foreach (array_slice($recentLogs, 0, 20) as $log): ?>
        <?php
        $parts = array_map('trim', explode('|', $log));
        $name = isset($parts[0]) ? strtoupper($parts[0]) : 'Unknown';
        $matric = isset($parts[1]) ? $parts[1] : 'N/A';
        $macRegex = '/([0-9a-f]{2}[:\\\\-]){5}[0-9a-f]{2}/i';
        if (isset($parts[5]) && preg_match($macRegex, $parts[5])) {
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

<!-- Quick Actions -->
<div class="quick-actions">
  <h3><span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;">bolt</span>Quick Actions</h3>
  <a href="index.php?page=logs" class="st-btn st-btn-primary st-btn-sm">
    <span class="material-symbols-outlined" style="font-size:16px;">description</span> Logs
  </a>
  <a href="index.php?page=support_tickets" class="st-btn st-btn-success st-btn-sm">
    <span class="material-symbols-outlined" style="font-size:16px;">confirmation_number</span> Support
  </a>
  <a href="index.php?page=unlink_fingerprint" style="background:#f59e0b;color:#fff;" class="st-btn st-btn-sm">
    <span class="material-symbols-outlined" style="font-size:16px;">link_off</span> Fingerprints
  </a>
  <a href="./logs/export_simple.php" class="st-btn st-btn-danger st-btn-sm">
    <span class="material-symbols-outlined" style="font-size:16px;">download</span> Export
  </a>
</div>

<script>
  new Chart(document.getElementById('attendanceChart').getContext('2d'), {
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

  new Chart(document.getElementById('coursePieChart').getContext('2d'), {
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

  new Chart(document.getElementById('failedChart').getContext('2d'), {
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
</script>
