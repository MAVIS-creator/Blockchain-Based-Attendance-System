<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$logDir = __DIR__;
$courseFile = dirname(__DIR__) . "/courses/course.json";
$activeCourseFile = dirname(__DIR__) . "/courses/active_course.json";

$courses = file_exists($courseFile) ? json_decode(file_get_contents($courseFile), true) : ['General'];
if (empty($courses)) $courses = ['General'];

$selectedDate = $_GET['logDate'] ?? date('Y-m-d');
$selectedCourse = null;
if (isset($_GET['course']) && trim($_GET['course']) !== '') {
  $selectedCourse = $_GET['course'];
} else {
  if (file_exists($activeCourseFile)) {
    $tmp = trim(file_get_contents($activeCourseFile));
    $selectedCourse = $tmp !== '' ? $tmp : 'General';
  } else {
    $selectedCourse = 'General';
  }
}

$searchName = trim($_GET['search'] ?? '');
$filterType = $_GET['filterType'] ?? 'both';

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
  $selectedDate = date('Y-m-d');
}

$entries = [];
$logFile = $logDir . "/{$selectedDate}.log";

if (file_exists($logFile)) {
  $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    $parts = array_map('trim', explode('|', $line));
    if (count($parts) < 6) continue;

    $macRegex = '/([0-9a-f]{2}[:\\-]){5}[0-9a-f]{2}/i';

    if (count($parts) >= 9 && preg_match($macRegex, $parts[5])) {
      // New format (with MAC)
      $entry = [
        'name'        => $parts[0] ?? '',
        'matric'      => $parts[1] ?? '',
        'action'      => $parts[2] ?? '',
        'fingerprint' => $parts[3] ?? '',
        'ip'          => $parts[4] ?? '',
        'mac'         => $parts[5] ?? 'UNKNOWN',
        'timestamp'   => $parts[6] ?? '',
        'device'      => $parts[7] ?? '',
        'course'      => $parts[8] ?? 'General',
        'reason'      => $parts[9] ?? '-'
      ];
    } else {
      // Old format (no MAC)
      $entry = [
        'name'        => $parts[0] ?? '',
        'matric'      => $parts[1] ?? '',
        'action'      => $parts[2] ?? '',
        'fingerprint' => $parts[3] ?? '',
        'ip'          => $parts[4] ?? '',
        'mac'         => 'UNKNOWN',
        'timestamp'   => $parts[5] ?? '',
        'device'      => $parts[6] ?? '',
        'course'      => $parts[7] ?? 'General',
        'reason'      => $parts[8] ?? '-'
      ];
    }

    // 🔍 Filter by name, IP, MAC, and course
    if (
      $searchName !== '' &&
      stripos($entry['name'], $searchName) === false &&
      stripos($entry['ip'], $searchName) === false &&
      stripos($entry['mac'], $searchName) === false
    ) continue;

    if ($entry['course'] !== $selectedCourse) continue;

    // 💡 Apply MAC/IP filter type
    if ($filterType === 'ip' && ($entry['ip'] === '' || $entry['ip'] === 'UNKNOWN')) continue;
    if ($filterType === 'mac' && ($entry['mac'] === '' || $entry['mac'] === 'UNKNOWN')) continue;

    $entries[] = $entry;
  }
}

// Combine check-ins and check-outs
$combined = [];
foreach ($entries as $entry) {
  $key = $entry['name'] . '|' . $entry['matric'];
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
      'reason'     => $entry['reason']
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

// Show only users who have both check-in and check-out
$combined = array_filter($combined, fn($e) => $e['check_in'] && $e['check_out']);
$total = count($combined);
$totalPages = ceil($total / $perPage);
$pagedEntries = array_slice($combined, ($page - 1) * $perPage, $perPage);
?>

<!-- 👇 Frontend -->
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<style>
  :root {
    --accent-color: #3b82f6;
  }

  body {
    background: linear-gradient(135deg, rgb(64, 66, 68), #ffffff);
    font-family: 'Segoe UI', sans-serif;
    margin: 0;
    overflow-x: hidden;
  }

  .logs-container {
    max-width: 1200px;
    margin: 60px auto;
    padding: 2rem;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 20px;
    backdrop-filter: blur(25px);
    border: 2px solid var(--accent-color);
    animation: fadeIn 1s ease forwards;
  }

  @keyframes fadeIn {
    from {
      opacity: 0;
      transform: translateY(20px);
    }

    to {
      opacity: 1;
      transform: translateY(0);
    }
  }

  .logs-title {
    font-size: 2rem;
    color: #1f2937;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .logs-title i {
    font-size: 1.6rem;
    color: var(--accent-color);
  }

  .logs-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.5rem;
    align-items: center;
  }

  .logs-controls label {
    font-weight: 600;
    color: #374151;
  }

  .logs-controls input,
  .logs-controls select {
    padding: 0.55rem 1rem;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.7);
    backdrop-filter: blur(10px);
    font-size: 0.95rem;
  }

  .logs-controls button,
  .logs-controls .btn {
    background: linear-gradient(135deg, var(--accent-color), #0056b3);
    color: white;
    border: none;
    padding: 0.6rem 1.4rem;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s ease;
    text-decoration: none;
  }

  .logs-controls button:hover,
  .logs-controls .btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
  }

  .logs-table-wrapper {
    overflow-x: auto;
    margin-top: 1rem;
  }

  .logs-table {
    width: 100%;
    border-collapse: collapse;
    background: rgba(255, 255, 255, 0.95);
    border-radius: 12px;
    overflow: hidden;
  }

  .logs-table th,
  .logs-table td {
    padding: 1rem;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.95rem;
  }

  .logs-table th {
    background: rgba(0, 0, 0, 0.05);
    font-weight: 700;
    color: #374151;
  }

  .logs-table tr:hover {
    background: rgba(59, 130, 246, 0.1);
    transition: all 0.3s ease;
  }

  .no-logs {
    text-align: center;
    padding: 2rem;
    color: #6b7280;
    background: rgba(255, 255, 255, 0.9);
    border-radius: 10px;
    margin-top: 1rem;
  }

  .pagination {
    text-align: center;
    margin-top: 20px;
  }

  .pagination a {
    padding: 8px 14px;
    margin: 0 4px;
    background: rgba(59, 130, 246, 0.1);
    color: var(--accent-color);
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
    transition: all 0.3s;
  }

  .pagination a.active {
    background: var(--accent-color);
    color: #fff;
  }

  .pagination a:hover {
    background: #0056b3;
    color: #fff;
  }

  .palette-toggle {
    position: fixed;
    top: 20px;
    right: 20px;
    background: rgba(255, 255, 255, 0.2);
    border: none;
    padding: 10px 15px;
    border-radius: 50px;
    cursor: pointer;
    transition: all 0.3s ease;
    z-index: 100;
    color: #1f2937;
  }

  .palette-toggle:hover {
    backdrop-filter: blur(10px);
    transform: scale(1.05);
  }

  .logs-table th:nth-child(7),
  .logs-table td:nth-child(7) {
    color: #1e40af;
    font-weight: 600;
  }

  .logs-controls select#filterType {
    border: 1px solid var(--accent-color);
  }
</style>

<button class="palette-toggle"><i class='bx bx-palette'></i></button>

<div class="logs-container">
  <h2 class="logs-title"><i class='bx bx-calendar-check'></i> Attendance Logs</h2>

  <form class="logs-controls" method="get" autocomplete="off">
    <input type="hidden" name="page" value="logs">

    <label for="logDate">Date:</label>
    <input type="date" id="logDate" name="logDate" value="<?= htmlspecialchars($selectedDate) ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()">

    <label for="course">Course:</label>
    <select id="course" name="course" onchange="this.form.submit()">
      <?php foreach ($courses as $course): ?>
        <option value="<?= htmlspecialchars($course) ?>" <?= $course === $selectedCourse ? 'selected' : '' ?>>
          <?= htmlspecialchars($course) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label for="filterType">Filter by:</label>
    <select id="filterType" name="filterType" onchange="this.form.submit()">
      <option value="both" <?= $filterType === 'both' ? 'selected' : '' ?>>IP & MAC</option>
      <option value="ip" <?= $filterType === 'ip' ? 'selected' : '' ?>>IP Only</option>
      <option value="mac" <?= $filterType === 'mac' ? 'selected' : '' ?>>MAC Only</option>
    </select>

    <label for="search">Search:</label>
    <input type="text" id="search" name="search" placeholder="Search name, IP, or MAC..." value="<?= htmlspecialchars($searchName) ?>">

    <button type="submit"><i class='bx bx-search-alt-2'></i> Filter</button>
    <a href="../admin/logs/export.php?logDate=<?= urlencode($selectedDate) ?>&course=<?= urlencode($selectedCourse) ?>&search=<?= urlencode($searchName) ?>" class="btn"><i class='bx bx-download'></i> Export CSV</a>
    <a href="../admin/logs/export_simple.php?logDate=<?= urlencode($selectedDate) ?>&course=<?= urlencode($selectedCourse) ?>" class="btn"><i class='bx bx-download'></i> Export Simple</a>
  </form>

  <?php if (count($pagedEntries)): ?>
    <div class="logs-table-wrapper">
      <table class="logs-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Matric Number</th>
            <th>Check-In</th>
            <th>Check-Out</th>
            <th>Fingerprint</th>
            <th>IP</th>
            <th>MAC</th>
            <th>Device</th>
            <th>Reason</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pagedEntries as $row): ?>
            <tr>
              <td><?= htmlspecialchars($row['name']) ?></td>
              <td><?= htmlspecialchars($row['matric']) ?></td>
              <td><?= htmlspecialchars($row['check_in']) ?></td>
              <td><?= htmlspecialchars($row['check_out']) ?></td>
              <td><?= htmlspecialchars($row['fingerprint']) ?></td>
              <td><?= htmlspecialchars($row['ip']) ?></td>
              <td><?= htmlspecialchars($row['mac']) ?></td>
              <td><?= htmlspecialchars($row['device']) ?></td>
              <td><?= htmlspecialchars($row['reason'] ?? '-') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="pagination">
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="?logDate=<?= urlencode($selectedDate) ?>&course=<?= urlencode($selectedCourse) ?>&search=<?= urlencode($searchName) ?>&filterType=<?= urlencode($filterType) ?>&page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>">
          <?= $i ?>
        </a>
      <?php endfor; ?>
    </div>
  <?php else: ?>
    <div class="no-logs">No valid logs found for the selected filters.</div>
  <?php endif; ?>
</div>

<script>
  const palettes = ['#3b82f6', '#22c55e', '#facc15', '#8b5cf6', '#ef4444'];
  let current = 0;
  document.querySelector('.palette-toggle').addEventListener('click', () => {
    current = (current + 1) % palettes.length;
    document.documentElement.style.setProperty('--accent-color', palettes[current]);
    localStorage.setItem('logsPalette', current);
  });
  document.addEventListener('DOMContentLoaded', () => {
    const saved = localStorage.getItem('logsPalette');
    if (saved !== null) {
      current = parseInt(saved);
      document.documentElement.style.setProperty('--accent-color', palettes[current]);
    }
  });
</script>