<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__) . '/includes/hybrid_admin_read.php';
require_once dirname(__DIR__, 2) . '/storage_helpers.php';
require_once dirname(__DIR__) . '/runtime_storage.php';
require_once dirname(__DIR__) . '/cache_helpers.php';
require_once dirname(__DIR__) . '/log_helpers.php';
require_once dirname(__DIR__) . '/state_helpers.php';
app_storage_init();

$logDir = app_storage_file('logs');
$courseFile = admin_course_storage_migrate_file('course.json');

$courses = admin_cached_json_file('courses_list', $courseFile, ['General'], 30);
if (empty($courses)) $courses = ['General'];

$selectedDate = $_GET['logDate'] ?? date('Y-m-d');
$selectedCourse = null;
if (isset($_GET['course']) && trim($_GET['course']) !== '') {
  $selectedCourse = $_GET['course'];
} else {
  $selectedCourse = admin_active_course_name_cached(15);
}

$searchName = trim($_GET['search'] ?? '');
$filterType = $_GET['filterType'] ?? 'both';
$settings = admin_load_settings_cached(15);
$checkinOnlyCountsAsSuccess = !empty($settings['checkin_only_counts_as_success']);

$page = max(1, intval($_GET['page_num'] ?? 1)); // changed from intval($_GET['page'] ?? 1) to avoid collision with router page=logs
$perPage = 15;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
  $selectedDate = date('Y-m-d');
}

$entries = [];
$logFile = $logDir . "/{$selectedDate}.log";

$hybridSource = 'file';
$hybridEntries = hybrid_fetch_attendance_entries($selectedDate, $selectedCourse, $searchName, $filterType, $hybridSource);
if (is_array($hybridEntries)) {
  $entries = $hybridEntries;
}

if (empty($entries) && file_exists($logFile)) {
  foreach (admin_attendance_entries_for_date_parsed($logFile, 15) as $entry) {
    if (
      $searchName !== '' &&
      stripos($entry['name'], $searchName) === false &&
      stripos($entry['ip'], $searchName) === false &&
      stripos($entry['mac'], $searchName) === false
    ) continue;

    if ($entry['course'] !== $selectedCourse) continue;

    if ($filterType === 'ip' && ($entry['ip'] === '' || $entry['ip'] === 'UNKNOWN')) continue;
    if ($filterType === 'mac' && ($entry['mac'] === '' || $entry['mac'] === 'UNKNOWN')) continue;

    $entries[] = $entry;
  }
}

// Combine check-ins and check-outs
$combined = [];
foreach ($entries as $entry) {
  $key = $entry['name'] . '|' . $entry['matric'] . '|' . $entry['course'];
  if (!isset($combined[$key])) {
    $combined[$key] = [
      'name'       => $entry['name'],
      'matric'     => $entry['matric'],
      'check_in'   => '',
      'check_out'  => '',
      'fingerprint' => $entry['fingerprint'],
      'ip'         => $entry['ip'],
      'mac'        => $entry['mac'],
      'device'     => $entry['device'],
      'reason'     => $entry['reason'],
      'course'     => $entry['course']
    ];
  }

  $action = strtolower($entry['action']);
  if (in_array($action, ['checkin', 'in']) && $combined[$key]['check_in'] === '') {
    $combined[$key]['check_in'] = $entry['timestamp'];
  }
  if (in_array($action, ['checkout', 'out']) && $combined[$key]['check_out'] === '') {
    $combined[$key]['check_out'] = $entry['timestamp'];
  }
}

$total = count($combined);
$totalPages = ceil($total / $perPage);
$pagedEntries = array_slice($combined, ($page - 1) * $perPage, $perPage);
?>

<!-- Attendance Logs — Stitch UI -->
<div style="margin-bottom:24px;display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:12px;">
  <div>
    <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;">
      <span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;">receipt_long</span>Attendance Logs
    </h2>
    <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">Review, filter, and export student attendance records. Source: <strong><?= htmlspecialchars($hybridSource) ?></strong></p>
    <?php if ($checkinOnlyCountsAsSuccess): ?>
      <p style="color:#059669;font-size:0.82rem;margin:4px 0 0;font-weight:600;">Check-in only entries are currently treated as successful.</p>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="../admin/logs/export.php?logDate=<?= urlencode($selectedDate) ?>&course=<?= urlencode($selectedCourse) ?>&search=<?= urlencode($searchName) ?>" class="st-btn st-btn-success st-btn-sm">
      <span class="material-symbols-outlined" style="font-size:1.1rem;">download</span> Export CSV
    </a>
    <a href="../admin/logs/export_simple.php?logDate=<?= urlencode($selectedDate) ?>&course=<?= urlencode($selectedCourse) ?>" class="st-btn st-btn-secondary st-btn-sm">
      <span class="material-symbols-outlined" style="font-size:1.1rem;">description</span> Export Simple
    </a>
  </div>
</div>

<div class="st-card" style="margin-bottom:24px;">
  <!-- Filters -->
  <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin-bottom:16px;">
    <input type="hidden" name="page" value="logs">

    <div>
      <label style="display:block;font-weight:600;margin-bottom:6px;color:var(--on-surface-variant);font-size:0.85rem;">Date</label>
      <input type="date" name="logDate" value="<?= htmlspecialchars($selectedDate) ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()" style="padding:8px 12px;border-radius:8px;border:1px solid var(--outline-variant);background:var(--surface-container-low);color:var(--on-surface);font-family:inherit;">
    </div>

    <div>
      <label style="display:block;font-weight:600;margin-bottom:6px;color:var(--on-surface-variant);font-size:0.85rem;">Course</label>
      <select name="course" onchange="this.form.submit()" style="padding:8px 12px;border-radius:8px;border:1px solid var(--outline-variant);background:var(--surface-container-low);color:var(--on-surface);font-family:inherit;">
        <?php foreach ($courses as $course): ?>
          <option value="<?= htmlspecialchars($course) ?>" <?= $course === $selectedCourse ? 'selected' : '' ?>>
            <?= htmlspecialchars($course) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label style="display:block;font-weight:600;margin-bottom:6px;color:var(--on-surface-variant);font-size:0.85rem;">Filter Network</label>
      <select name="filterType" onchange="this.form.submit()" style="padding:8px 12px;border-radius:8px;border:1px solid var(--outline-variant);background:var(--surface-container-low);color:var(--on-surface);font-family:inherit;">
        <option value="both" <?= $filterType === 'both' ? 'selected' : '' ?>>IP & MAC</option>
        <option value="ip" <?= $filterType === 'ip' ? 'selected' : '' ?>>IP Only</option>
        <option value="mac" <?= $filterType === 'mac' ? 'selected' : '' ?>>MAC Only</option>
      </select>
    </div>

    <div style="flex:1;min-width:200px;">
      <label style="display:block;font-weight:600;margin-bottom:6px;color:var(--on-surface-variant);font-size:0.85rem;">Search</label>
      <div style="display:flex;gap:8px;">
        <input type="text" name="search" placeholder="Name, IP, or MAC..." value="<?= htmlspecialchars($searchName) ?>" style="flex:1;padding:8px 12px;border-radius:8px;border:1px solid var(--outline-variant);background:var(--surface-container-low);color:var(--on-surface);font-family:inherit;">
        <button type="submit" class="st-btn st-btn-primary st-btn-sm"><span class="material-symbols-outlined" style="font-size:1.1rem;">search</span></button>
      </div>
    </div>
  </form>

  <hr style="border:none;border-top:1px solid var(--surface-container-high);margin:0 -24px 16px;">

  <!-- Quick Actions -->
  <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:end;">
    <div style="flex:1;min-width:280px;background:var(--surface-container-lowest);padding:12px;border-radius:10px;border:1px solid var(--outline-variant);">
      <label style="display:block;font-weight:600;margin-bottom:8px;color:var(--on-surface-variant);font-size:0.8rem;">Clear Device Ban</label>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <input type="text" id="clearFingerprint" placeholder="Fingerprint" style="flex:1;padding:8px;border-radius:6px;border:1px solid var(--outline-variant);font-size:0.85rem;min-width:120px;">
        <input type="text" id="clearMatric" placeholder="Matric (opt)" style="flex:1;padding:8px;border-radius:6px;border:1px solid var(--outline-variant);font-size:0.85rem;min-width:100px;">
        <button type="button" id="clearDeviceBtn" class="st-btn st-btn-secondary st-btn-sm" style="background:#6366f1;color:#fff;border-color:#4f46e5;">Clear</button>
      </div>
    </div>
    <div>
      <button type="button" id="clearLogsBtn" class="st-btn st-btn-danger">
        <span class="material-symbols-outlined" style="font-size:1.1rem;">delete_sweep</span> Clear All Logs
      </button>
    </div>
  </div>
</div>

<!-- Data Table -->
<div class="st-card" style="padding:0;overflow-x:auto;">
  <?php if (count($pagedEntries)): ?>
    <table class="st-table" style="width:100%;">
      <thead>
        <tr>
          <th>Name</th>
          <th>Matric</th>
          <th>Course</th>
          <th>Check-In</th>
          <th>Check-Out</th>
          <th>Status</th>
          <th class="mobile-hide-col">Fingerprint</th>
          <th class="mobile-hide-col">IP</th>
          <th class="mobile-hide-col">MAC</th>
          <th class="mobile-hide-col">Device</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pagedEntries as $row): ?>
          <tr>
            <td style="font-weight:600;"><?= htmlspecialchars($row['name']) ?></td>
            <td><span class="st-chip st-chip-neutral"><?= htmlspecialchars($row['matric']) ?></span></td>
            <td><?= htmlspecialchars($row['course']) ?></td>
            <td><?= $row['check_in'] !== '' ? htmlspecialchars(date('H:i:s', strtotime($row['check_in']))) : 'Pending' ?></td>
            <td><?= $row['check_out'] !== '' ? htmlspecialchars(date('H:i:s', strtotime($row['check_out']))) : 'Pending' ?></td>
            <td>
              <?php
              if ($row['check_in'] !== '' && $row['check_out'] !== '') {
                $statusLabel = 'Complete';
                $statusClass = 'st-chip st-chip-neutral';
                $statusStyle = '';
              } elseif ($row['check_in'] !== '') {
                if ($checkinOnlyCountsAsSuccess) {
                  $statusLabel = 'Successful (Check-in only)';
                  $statusClass = 'st-chip';
                  $statusStyle = 'background:#dcfce7;color:#166534;border-color:#86efac;';
                } else {
                  $statusLabel = 'Check-in only';
                  $statusClass = 'st-chip st-chip-neutral';
                  $statusStyle = '';
                }
              } else {
                $statusLabel = 'Check-out only';
                $statusClass = 'st-chip st-chip-neutral';
                $statusStyle = '';
              }
              ?>
              <span class="<?= htmlspecialchars($statusClass) ?>" style="<?= htmlspecialchars($statusStyle) ?>"><?= htmlspecialchars($statusLabel) ?></span>
            </td>
            <td class="mobile-hide-col" style="font-family:monospace;font-size:0.8rem;color:var(--on-surface-variant);"><?= htmlspecialchars(substr($row['fingerprint'], 0, 16)) ?>...</td>
            <td class="mobile-hide-col" style="font-family:monospace;font-size:0.85rem;"><?= htmlspecialchars($row['ip']) ?></td>
            <td class="mobile-hide-col" style="font-family:monospace;font-size:0.85rem;"><?= htmlspecialchars($row['mac']) ?></td>
            <td class="mobile-hide-col" style="font-size:0.8rem;color:var(--on-surface-variant);max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($row['device']) ?>"><?= htmlspecialchars($row['device']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
      <div style="padding:16px 24px;border-top:1px solid var(--surface-container-high);display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <a href="?page=logs&logDate=<?= urlencode($selectedDate) ?>&course=<?= urlencode($selectedCourse) ?>&search=<?= urlencode($searchName) ?>&filterType=<?= urlencode($filterType) ?>&page_num=<?= $i ?>"
            style="display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;border-radius:6px;font-weight:600;font-size:0.85rem;text-decoration:none;<?= $i === $page ? 'background:var(--primary);color:#fff;' : 'background:var(--surface-container-low);color:var(--on-surface);' ?>">
            <?= $i ?>
          </a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <div style="text-align:center;padding:48px 24px;">
      <span class="material-symbols-outlined" style="font-size:3rem;color:var(--outline-variant);display:block;margin-bottom:12px;">search_off</span>
      <p style="font-weight:600;color:var(--on-surface-variant);margin:0;">No matching attendance logs found.</p>
    </div>
  <?php endif; ?>
</div>

<script>
  // Clear logs button
  document.getElementById('clearLogsBtn')?.addEventListener('click', function() {
    window.adminConfirm('Clear all logs', 'Are you sure you want to delete logs and backups? This action cannot be undone.', 'warning').then(function(ok) {
      if (!ok) return;
      var body = new URLSearchParams();
      body.append('scope', 'all');
      fetch('../clear_logs.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': window.ADMIN_CSRF_TOKEN
          },
          body: body.toString()
        })
        .then(r => r.json()).then(j => {
          if (j && j.ok) {
            window.adminAlert('Logs cleared', 'Logs and backups removed', 'success').then(() => location.reload());
          } else window.adminAlert('Failed', JSON.stringify(j), 'error');
        })
        .catch(e => window.adminAlert('Error', 'Error clearing logs', 'error'));
    });
  });

  // Clear device button
  document.getElementById('clearDeviceBtn')?.addEventListener('click', function() {
    var fp = document.getElementById('clearFingerprint').value.trim();
    var mt = document.getElementById('clearMatric').value.trim();
    if (!fp && !mt) {
      window.adminAlert('Input required', 'Enter a fingerprint or matric to clear', 'warning');
      return;
    }
    window.adminConfirm('Clear device ban', 'Clear device entries for this fingerprint/matric?').then(function(ok) {
      if (!ok) return;
      var body = new URLSearchParams();
      if (fp) body.append('fingerprint', fp);
      if (mt) body.append('matric', mt);
      body.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');
      fetch('../clear_device.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': window.ADMIN_CSRF_TOKEN
          },
          body: body.toString()
        })
        .then(r => r.json()).then(j => {
          if (j && j.ok) {
            window.adminAlert('Device cleared', 'Device entries removed: ' + JSON.stringify(j.result), 'success');
          } else window.adminAlert('Failed', JSON.stringify(j), 'error');
        })
        .catch(e => window.adminAlert('Error', 'Error clearing device', 'error'));
    });
  });
</script>
