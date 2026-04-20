<?php
require_once __DIR__ . '/../session_bootstrap.php';

require_once dirname(__DIR__, 2) . '/storage_helpers.php';
require_once dirname(__DIR__) . '/runtime_storage.php';
require_once dirname(__DIR__) . '/cache_helpers.php';
require_once dirname(__DIR__) . '/log_helpers.php';
require_once dirname(__DIR__) . '/state_helpers.php';
app_storage_init();
$logDir = app_storage_file('logs');
$courseFile = admin_course_storage_migrate_file('course.json');

// Load courses safely
$courses = admin_cached_json_file('courses_list', $courseFile, ['General'], 30);
if (empty($courses)) $courses = ['General'];

// Read and sanitize query params
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedCourse = isset($_GET['course']) ? trim($_GET['course']) : 'All';
$search = trim($_GET['search'] ?? '');
$failedPage = max(1, intval($_GET['failed_pg'] ?? 1));

// Validate course value
if (!in_array($selectedCourse, $courses) && $selectedCourse !== 'All') {
  $selectedCourse = 'All';
}

$perPage = 20;
$logs = [];
$settings = admin_load_settings_cached(15);
$checkinOnlyCountsAsSuccess = !empty($settings['checkin_only_counts_as_success']);

// Classic failed attempts
$failedLogFile = $logDir . DIRECTORY_SEPARATOR . $selectedDate . '_failed_attempts.log';
foreach (admin_failed_attempt_entries_for_date($failedLogFile, 15) as $entry) {
  $matchesCourse = ($selectedCourse === 'All' || ($entry['course'] ?? '') === $selectedCourse);
  $matchesSearch = ($search === '' || stripos($entry['name'] ?? '', $search) !== false || stripos($entry['matric'] ?? '', $search) !== false);

  if ($matchesCourse && $matchesSearch) {
    $logs[] = [
      'name' => $entry['name'] ?? '',
      'matric' => $entry['matric'] ?? '',
      'fingerprint' => $entry['fingerprint'] ?? '',
      'timestamp' => $entry['timestamp'] ?? '',
      'device' => $entry['device'] ?? '',
      'course' => $entry['course'] ?? '',
    ];
  }
}

// Check-In Only logic
$mainLogFile = "{$logDir}/{$selectedDate}.log";
$checkMap = [];

if (!$checkinOnlyCountsAsSuccess && file_exists($mainLogFile)) {
  foreach (admin_attendance_entries_for_date_parsed($mainLogFile, 15) as $entry) {
    $name = $entry['name'] ?? '';
    $matric = $entry['matric'] ?? '';
    $action = $entry['action'] ?? '';
    $finger = $entry['fingerprint'] ?? '';
    $ip = $entry['ip'] ?? '';
    $timestamp = $entry['timestamp'] ?? '';
    $device = $entry['device'] ?? '';
    $course = trim((string)($entry['course'] ?? ''));

    if ($selectedCourse !== 'All' && $course !== $selectedCourse) continue;

    if (!isset($checkMap[$matric])) {
      $checkMap[$matric] = [
        'name' => $name,
        'matric' => $matric,
        'checkin' => '',
        'checkout' => '',
        'fingerprint' => $finger,
        'timestamp' => $timestamp,
        'device' => $device,
        'course' => $course,
      ];
    }

    if (strtolower($action) === 'checkin') $checkMap[$matric]['checkin'] = $timestamp;
    if (strtolower($action) === 'checkout') $checkMap[$matric]['checkout'] = $timestamp;
  }

  foreach ($checkMap as $entry) {
    if ($entry['checkin'] && !$entry['checkout']) {
      $matchesSearch = ($search === '' || stripos($entry['name'], $search) !== false || stripos($entry['matric'], $search) !== false);
      if ($matchesSearch) {
        $logs[] = $entry;
      }
    }
  }
}

// Pagination
$totalLogs = count($logs);
$totalPages = ceil($totalLogs / $perPage);
$currentPage = min($failedPage, $totalPages > 0 ? $totalPages : 1);
$startIndex = ($currentPage - 1) * $perPage;
$logsPage = array_slice($logs, $startIndex, $perPage);
?>

<div style="max-width:1400px;margin:0 auto;">
  <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:24px;flex-wrap:wrap;gap:16px;">
    <div>
      <h2 style="font-size:1.5rem;font-weight:800;color:var(--error);letter-spacing:-0.02em;margin:0;">
        <span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;">error</span>Failed Attempts
      </h2>
      <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">Review biometric mismatches, rejected attempts, and incomplete check-ins.</p>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;">
      <a href="logs/export_failed.php?date=<?= urlencode($selectedDate) ?>&course=<?= urlencode($selectedCourse) ?>&search=<?= urlencode($search) ?>" class="st-btn st-btn-secondary" style="text-decoration:none;">
        <span class="material-symbols-outlined" style="font-size:1rem;">download</span> Export CSV
      </a>
      <a href="logs/export_simple_failed_attempts.php?date=<?= urlencode($selectedDate) ?>&course=<?= urlencode($selectedCourse) ?>&search=<?= urlencode($search) ?>" class="st-btn st-btn-secondary" style="text-decoration:none;">
        <span class="material-symbols-outlined" style="font-size:1rem;">text_snippet</span> Export Simple
      </a>
    </div>
  </div>

  <div class="st-card" style="margin-bottom:24px;padding:20px;">
    <form method="get" action="index.php" style="display:flex;gap:16px;flex-wrap:wrap;align-items:end;">
      <input type="hidden" name="page" value="failed_attempts">

      <div style="flex:1;min-width:180px;">
        <label style="display:block;font-size:0.8rem;font-weight:600;color:var(--on-surface-variant);margin-bottom:6px;text-transform:uppercase;letter-spacing:0.05em;">Date</label>
        <div class="st-input-icon">
          <span class="material-symbols-outlined">calendar_today</span>
          <input type="date" name="date" class="st-input" value="<?= htmlspecialchars($selectedDate) ?>" max="<?= date('Y-m-d') ?>" style="width:100%;">
        </div>
      </div>

      <div style="flex:1;min-width:180px;">
        <label style="display:block;font-size:0.8rem;font-weight:600;color:var(--on-surface-variant);margin-bottom:6px;text-transform:uppercase;letter-spacing:0.05em;">Course</label>
        <div style="position:relative;">
          <select name="course" class="st-input" onchange="this.form.submit()" style="width:100%;appearance:none;padding-right:36px;cursor:pointer;">
            <option value="All" <?= $selectedCourse === 'All' ? 'selected' : '' ?>>All Courses</option>
            <?php foreach ($courses as $course): ?>
              <option value="<?= htmlspecialchars(trim($course)) ?>" <?= trim($course) === $selectedCourse ? 'selected' : '' ?>>
                <?= htmlspecialchars($course) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="material-symbols-outlined" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--on-surface-variant);pointer-events:none;font-size:1.1rem;">expand_more</span>
        </div>
      </div>

      <div style="flex:2;min-width:240px;">
        <label style="display:block;font-size:0.8rem;font-weight:600;color:var(--on-surface-variant);margin-bottom:6px;text-transform:uppercase;letter-spacing:0.05em;">Search Student</label>
        <div class="st-input-icon">
          <span class="material-symbols-outlined">search</span>
          <input type="text" name="search" class="st-input" placeholder="Search name or matric..." value="<?= htmlspecialchars($search) ?>" style="width:100%;">
        </div>
      </div>

      <div>
        <button type="submit" class="st-btn st-btn-primary" style="height:42px;padding:0 24px;">Filter</button>
      </div>
    </form>
  </div>

  <div style="border:1px solid var(--outline-variant);border-radius:12px;overflow:hidden;background:var(--surface-container-lowest);">
    <?php if (count($logsPage) > 0): ?>
      <table class="st-table" style="width:100%;">
        <thead>
          <tr style="background:var(--error-container);color:var(--on-error-container);">
            <th>Student Name & Matric</th>
            <th>Fingerprint</th>
            <th>Course</th>
            <th>Timestamp</th>
            <th>Device</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logsPage as $log): ?>
            <tr>
              <td>
                <div style="font-weight:600;color:var(--on-surface);"><?= htmlspecialchars($log['name']) ?></div>
                <div style="font-size:0.8rem;color:var(--on-surface-variant);font-family:monospace;"><?= htmlspecialchars($log['matric']) ?></div>
              </td>
              <td style="font-family:monospace;font-size:0.85rem;color:var(--error);"><?= htmlspecialchars($log['fingerprint'] ?: 'N/A') ?></td>
              <td><span class="st-chip st-chip-primary"><?= htmlspecialchars($log['course']) ?></span></td>
              <td style="font-size:0.88rem;color:var(--on-surface-variant);"><?= htmlspecialchars($log['timestamp']) ?></td>
              <td>
                <div style="font-size:0.75rem;color:var(--outline);max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($log['device']) ?>"><?= htmlspecialchars($log['device']) ?></div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($totalPages > 1): ?>
        <div style="padding:16px 20px;border-top:1px solid var(--outline-variant);display:flex;justify-content:center;gap:8px;">
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="index.php?page=failed_attempts&date=<?= urlencode($selectedDate) ?>&course=<?= urlencode($selectedCourse) ?>&search=<?= urlencode($search) ?>&failed_pg=<?= $i ?>"
              style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;text-decoration:none;font-size:0.85rem;font-weight:600;<?= $i === $currentPage ? 'background:var(--error);color:#fff;' : 'background:var(--surface-container-high);color:var(--on-surface-variant);' ?>">
              <?= $i ?>
            </a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div style="padding:48px 24px;text-align:center;color:var(--on-surface-variant);">
        <span class="material-symbols-outlined" style="font-size:3rem;opacity:0.2;display:block;margin-bottom:12px;">rule</span>
        <p style="margin:0;font-weight:500;">No failed attempts found for the selected filters.</p>
      </div>
    <?php endif; ?>
  </div>
</div>
