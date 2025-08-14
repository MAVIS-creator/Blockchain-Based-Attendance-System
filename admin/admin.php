<?php
session_start();

// If the admin is not logged in, redirect to login page
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Ensure logs directory exists
$logDir = "logs";
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Get selected date and course from GET or default to today and default course
$selectedDate = $_GET['logDate'] ?? date('Y-m-d');
$selectedCourse = $_GET['course'] ?? 'General';

// Basic validation of date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

// Use daily log files for main and failed logs
$logFile = "$logDir/{$selectedDate}_{$selectedCourse}.log";
$failedFile = "$logDir/{$selectedDate}_invalid_attempts.log";

$statusFile = "status.json";
$courseFile = "course.json";

// Handle mode switching
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mode'])) {
        $mode = $_POST['mode'];
        $status = ["checkin" => false, "checkout" => false];

        if ($mode === "checkin") $status["checkin"] = true;
        if ($mode === "checkout") $status["checkout"] = true;

        file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));
        $message = "Switched to " . strtoupper($mode) . " mode.";
    }

    // Handle course addition
    if (isset($_POST['newCourse']) && trim($_POST['newCourse']) !== '') {
        $newCourse = trim($_POST['newCourse']);
        $courses = file_exists($courseFile) ? json_decode(file_get_contents($courseFile), true) : [];

        if (!in_array($newCourse, $courses)) {
            $courses[] = $newCourse;
            file_put_contents($courseFile, json_encode($courses, JSON_PRETTY_PRINT));
            $message = "Course '$newCourse' added successfully.";
        }
    }
}

$currentStatus = file_exists($statusFile)
    ? json_decode(file_get_contents($statusFile), true)
    : ["checkin" => false, "checkout" => false];

// Load main log entries
$entries = file_exists($logFile) ? file($logFile) : [];
$combined = [];

$activeTab = $_GET['tab'] ?? 'main';
$showFailed = $activeTab === 'failed';

foreach ($entries as $line) {
    $parts = array_map('trim', explode('|', $line));
    if (count($parts) >= 7) {
        [$name, $matric, $action, $finger, $ip, $timestamp, $device] = $parts;
        if (!isset($combined[$finger])) {
            $combined[$finger] = [
                'name' => $name,
                'matric' => $matric,
                'finger' => $finger,
                'ip' => $ip,
                'device' => $device,
                'checkin' => '',
                'checkout' => ''
            ];
        }
        if (strtolower($action) === 'checkin') $combined[$finger]['checkin'] = $timestamp;
        if (strtolower($action) === 'checkout') $combined[$finger]['checkout'] = $timestamp;
    }
}

$courses = file_exists($courseFile) ? json_decode(file_get_contents($courseFile), true) : ['General'];
if (empty($courses)) $courses = ['General'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Admin Dashboard - Attendance Control</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <style>
    /* Your existing CSS styles... */
    body {
      font-family: 'Segoe UI', sans-serif;
      margin: 0;
      background-color: #f4f6f8;
    }
    header {
      background: linear-gradient(to right, #007bff, #0056b3);
      color: white;
      padding: 1.5rem;
      font-size: 1.75rem;
      text-align: center;
      border-radius: 0 0 10px 10px;
      box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    .container {
      max-width: 1000px;
      margin: 30px auto;
      padding: 1rem;
    }
    .mode-indicator {
      background-color: #d4edda;
      color: #155724;
      border-radius: 8px;
      padding: 10px;
      margin-bottom: 20px;
      font-weight: bold;
      text-align: center;
    }
    .buttons form {
      display: inline-block;
    }
    .buttons {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      justify-content: center;
      margin-bottom: 20px;
    }
    button {
      padding: 0.6rem 1.5rem;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-size: 16px;
      transition: all 0.2s ease-in-out;
    }
    .checkin-btn {
      background-color: <?= $currentStatus['checkin'] ? '#28a745' : '#6c757d' ?>;
      color: white;
    }
    .checkin-btn:hover {
      transform: scale(1.03);
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    .checkout-btn {
      background-color: <?= $currentStatus['checkout'] ? '#ffc107' : '#6c757d' ?>;
      color: black;
    }
    .checkout-btn:hover {
      transform: scale(1.03);
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    .csv-btn {
      background-color: #007bff;
      color: white;
    }
    .search-box {
      margin-bottom: 20px;
      text-align: center;
    }
    .search-box input {
      padding: 10px 15px;
      width: 60%;
      max-width: 400px;
      border-radius: 20px;
      border: 1px solid #ccc;
      font-size: 15px;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      background-color: white;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
      border-radius: 10px;
      overflow: hidden;
    }
    th, td {
      padding: 12px 15px;
      text-align: left;
    }
    th {
      background-color: #343a40;
      color: white;
      position: sticky;
      top: 0;
    }
    tr:nth-child(even) {
      background-color: #f2f2f2;
    }mvh.n j
    .device-info {
      font-size: 13px;
      color: #444;
      word-wrap: break-word;
    }
    .badge {
      display: inline-block;
      background: #28a745;
      color: white;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 14px;
      margin-bottom: 10px;
    }
    .message {
      text-align: center;
      margin: 15px 0;
      font-weight: bold;
      color: #155724;
      background-color: #d4edda;
      padding: 10px;
      border-radius: 8px;
    }
    @media (max-width: 768px) {
      .buttons {
        flex-direction: column;
        align-items: center;
      }
      .search-box input {
        width: 90%;
      }
    }
    .disable-btn {
      background-color: #dc3545;
      color: white;
    }
    .disable-btn:hover {
      transform: scale(1.03);
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    /* Date picker styling */
    .date-picker {
      text-align: center;
      margin-bottom: 20px;
    }
    .date-picker label {
      font-weight: bold;
      margin-right: 10px;
      font-size: 16px;
    }
    .date-picker input[type="date"] {
      padding: 8px 12px;
      font-size: 16px;
      border-radius: 6px;
      border: 1px solid #ccc;
      cursor: pointer;
    }
  </style>
</head>
<body>
  <form method="POST" style="margin: 20px 0; text-align: center;">
  <input type="text" name="newCourse" placeholder="Add New Course" style="padding: 8px; border-radius: 6px; border: 1px solid #ccc; font-size: 14px;" required>
  <button type="submit" style="padding: 8px 16px; background-color: #007bff; color: white; border: none; border-radius: 6px; cursor: pointer;">Add Course</button>
</form>
<div style="text-align:center; margin: 20px 0;">
  <?php foreach ($courses as $course): ?>
    <a href="?logDate=<?= htmlspecialchars($selectedDate) ?>&course=<?= urlencode($course) ?>" style="margin: 5px; padding: 8px 12px; background-color: <?= $course === $selectedCourse ? '#343a40' : '#6c757d' ?>; color: white; text-decoration: none; border-radius: 6px; display: inline-block;">
      <?= htmlspecialchars($course) ?> Logs
    </a>
  <?php endforeach; ?>
</div>
  <header>
    <i class="fas fa-user-shield"></i> Admin Dashboard - Attendance Control
  </header>

  <div class="container">
    <?php if (isset($message)): ?>
      <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="mode-indicator">
      <span class="badge">
        Current Mode:
        <?php
          if ($currentStatus['checkin']) echo "Check-In";
          elseif ($currentStatus['checkout']) echo "Check-Out";
          else echo "Disabled";
        ?>
      </span>
    </div>
    <div class="buttons">
      <form method="POST">
        <input type="hidden" name="mode" value="checkin" />
        <button type="submit" class="checkin-btn"><i class="fas fa-sign-in-alt"></i> Enable Check-In</button>
      </form>
      <form method="POST">
        <input type="hidden" name="mode" value="checkout" />
        <button type="submit" class="checkout-btn"><i class="fas fa-sign-out-alt"></i> Enable Check-Out</button>
      </form>
      <form method="POST">
        <input type="hidden" name="mode" value="disable" />
        <button type="submit" class="disable-btn"><i class="fas fa-ban"></i> Disable</button>
      </form>
      <form method="post" action="export.php">
        <button type="submit" class="csv-btn"><i class="fas fa-file-download"></i> Download CSV</button>
      </form>
      <form method="get">
        <input type="hidden" name="tab" value="failed" />
        <input type="hidden" name="logDate" value="<?= htmlspecialchars($selectedDate) ?>" />
        <button type="submit" class="disable-btn"><i class="fas fa-exclamation-triangle"></i> View Failed Attempts</button>
      </form>
    </div>

    <!-- Date picker to select log date -->
    <div class="date-picker">
      <form method="get" style="display:inline-block;">
        <label for="logDate">Select Log Date:</label>
        <input
          type="date"
          id="logDate"
          name="logDate"
          value="<?= htmlspecialchars($selectedDate) ?>"
          max="<?= date('Y-m-d') ?>"
          onchange="this.form.submit()"
        />
        <?php if ($showFailed): ?>
          <input type="hidden" name="tab" value="failed" />
        <?php endif; ?>
      </form>
    </div>

    <?php if (!$showFailed): ?>
      <div class="search-box">
        <input type="text" placeholder="🔍 Search by name, matric, IP..." id="searchInput" />
      </div>

      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Matric Number</th>
            <th>Fingerprint ID</th>
            <th>IP Address</th>
            <th>Check-In Time</th>
            <th>Check-Out Time</th>
            <th>Device Info</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($combined as $entry): ?>
            <tr>
              <td><?= htmlspecialchars($entry['name']) ?></td>
              <td><?= htmlspecialchars($entry['matric']) ?></td>
              <td><?= htmlspecialchars($entry['finger']) ?></td>
              <td><?= htmlspecialchars($entry['ip']) ?></td>
              <td><?= htmlspecialchars($entry['checkin']) ?: '-' ?></td>
              <td><?= htmlspecialchars($entry['checkout']) ?: '-' ?></td>
              <td class="device-info"><?= nl2br(htmlspecialchars($entry['device'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <h2 style="text-align:center;margin-top:30px;">🚫 Failed Attempts (<?= htmlspecialchars($selectedDate) ?>)</h2>
      <form method="get" style="text-align:center;margin-bottom:20px;">
        <input type="hidden" name="logDate" value="<?= htmlspecialchars($selectedDate) ?>" />
        <button type="submit" class="checkin-btn"><i class="fas fa-arrow-left"></i> Back to Attendance</button>
      </form>
      <pre style="background:#111;color:#0f0;padding:15px;border-radius:10px;max-height:500px;overflow:auto;">
<?php
  if (file_exists($failedFile)) {
      echo htmlspecialchars(file_get_contents($failedFile));
  } else {
      echo "No failed attempts logged for this date.";
  }
?>
      </pre>
    <?php endif; ?>
  </div>

  <form method="POST" action="logout.php" style="text-align:right; margin-bottom: 20px;">
    <button type="submit" style="padding:8px 16px; border:none; border-radius:6px; background:#dc3545; color:white; cursor:pointer;">
        Logout
    </button>
  </form>

  <script>
    const searchInput = document.getElementById('searchInput');
    const rows = document.querySelectorAll('table tbody tr');

    if (searchInput) {
      searchInput.addEventListener('input', function () {
        const query = this.value.toLowerCase();

        rows.forEach(row => {
          const text = row.textContent.toLowerCase();
          row.style.display = text.includes(query) ? '' : 'none';
        });
      });
    }
  </script>
</body>
</html>

<!-- HTML below remains the same, but inside .container: -->

<!-- The rest of the HTML layout, including table rendering, remains unchanged. -->
