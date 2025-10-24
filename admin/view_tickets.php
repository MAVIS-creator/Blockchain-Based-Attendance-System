<?php
$ticketsFile = __DIR__ . '/support_tickets.json';
$tickets = [];

if (file_exists($ticketsFile)) {
    $tickets = json_decode(file_get_contents($ticketsFile), true);
}

$today = date('Y-m-d');
$logFile = __DIR__ . "/logs/{$today}.log";
$logLines = file_exists($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

function checkLogMatch($logLines, $needle, $index) {
    foreach ($logLines as $line) {
        $fields = array_map('trim', explode('|', $line));
        if (isset($fields[$index]) && $fields[$index] === $needle) {
            return true;
        }
    }
    return false;
}

// Handle resolve
if (isset($_GET['resolve'])) {
    $resolveTime = $_GET['resolve'];
    foreach ($tickets as &$ticket) {
        if ($ticket['timestamp'] === $resolveTime) {
            $ticket['resolved'] = true;
            break;
        }
    }
    file_put_contents($ticketsFile, json_encode($tickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    header("Location: index.php?page=support_tickets");
    exit;
}

// Handle manual check-in/out
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_action'], $_POST['name'], $_POST['matric'], $_POST['reason'])) {
    $action = $_POST['manual_action'];
    $name = trim($_POST['name']);
    $matric = trim($_POST['matric']);
    $reason = trim($_POST['reason']);

    $activeCourseFile = __DIR__ . '/course/active_course.json';
    $activeCourse = file_exists($activeCourseFile) ? json_decode(file_get_contents($activeCourseFile), true)['course'] ?? 'General' : 'General';

    $timestamp = date('Y-m-d H:i:s');
    $logFile = __DIR__ . "/logs/{$today}.log";

  // Standardized log format: name | matric | action | fingerprint | ip | mac | timestamp | userAgent | course | reason
  $line = "{$name} | {$matric} | {$action} | MANUAL | ::1 | UNKNOWN | {$timestamp} | Web Ticket Panel | {$activeCourse} | {$reason}\n";

    file_put_contents($logFile, $line, FILE_APPEND);
    header("Location: index.php?page=support_tickets");
    exit;
}
?>

<h1 class="page-title"><i class='bx bx-envelope'></i> Support Tickets</h1>

<div class="theme-toggle">
  <i class='bx bxs-color' id="palette-icon"></i>
  <div id="palette-options">
    <button onclick="setTheme(0)" style="background: linear-gradient(135deg, #00eaff, #00c5cc);"></button>
    <button onclick="setTheme(1)" style="background: linear-gradient(135deg, #ff7e5f, #feb47b);"></button>
    <button onclick="setTheme(2)" style="background: linear-gradient(135deg, #3b82f6, #06b6d4);"></button>
    <button onclick="setTheme(3)" style="background: linear-gradient(135deg, #10b981, #22d3ee);"></button>
    <button onclick="setTheme(4)" style="background: linear-gradient(135deg, #a855f7, #3b82f6);"></button>
    <button onclick="setTheme(5)" style="background: linear-gradient(135deg, #ec4899, #f59e0b);"></button>
    <button onclick="setTheme(6)" style="background: linear-gradient(135deg, #14b8a6, #0ea5e9);"></button>
  </div>
</div>


<div class="tickets-wrapper">
  <?php 
  $hasUnresolved = false;
  if (!empty($tickets)): ?>
    <?php foreach (array_reverse($tickets) as $ticket): ?>
      <?php if (!($ticket['resolved'] ?? false)): ?>
        <?php $hasUnresolved = true; ?>

        <?php
          $fp = $ticket['fingerprint'] ?? '';
          $ip = $ticket['ip'] ?? '';

          $fpMatch = $fp ? checkLogMatch($logLines, $fp, 3) : false;
          $ipMatch = $ip ? checkLogMatch($logLines, $ip, 4) : false;
        ?>

        <div class="ticket-card">
          <div class="ticket-header">
            <strong><?= htmlspecialchars($ticket['name']) ?></strong> 
            <span class="ticket-matric"><?= htmlspecialchars($ticket['matric']) ?></span>
          </div>
          <div class="ticket-message">
            <?= nl2br(htmlspecialchars($ticket['message'])) ?>
          </div>

          <div class="ticket-fingerprint" style="color: <?= $fpMatch ? '#28a745' : '#dc3545' ?>;">
            <strong>Fingerprint:</strong> <?= htmlspecialchars($fp ?: 'Not submitted') ?>
            <?= $fpMatch ? '(Matched in logs ✅)' : '(No match ❌)' ?>
          </div>

          <div class="ticket-ip" style="color: <?= $ipMatch ? '#28a745' : '#dc3545' ?>;">
            <strong>IP:</strong> <?= htmlspecialchars($ip ?: 'Not submitted') ?>
            <?= $ipMatch ? '(Matched in logs ✅)' : '(No match ❌)' ?>
          </div>

          <div class="ticket-footer">
            <i class='bx bx-time-five'></i> <?= htmlspecialchars($ticket['timestamp']) ?>
            <a class="resolve-btn" href="index.php?page=support_tickets&resolve=<?= urlencode($ticket['timestamp']) ?>" onclick="return confirmResolve(event)">
              <i class='bx bx-check-circle'></i> Mark as Resolved
            </a>
          </div>

          <div class="dot-container">
            <div class="dot" onclick="toggleAction(this)"></div>
            <form method="post" class="dot-actions" style="display:none;">
              <input type="hidden" name="name" value="<?= htmlspecialchars($ticket['name']) ?>">
              <input type="hidden" name="matric" value="<?= htmlspecialchars($ticket['matric']) ?>">
              <input type="hidden" name="reason" value="<?= htmlspecialchars($ticket['message']) ?>">

              <button type="submit" name="manual_action" value="checkin" class="checkin-btn">Check-In</button>
              <button type="submit" name="manual_action" value="checkout" class="checkout-btn">Check-Out</button>
            </form>
          </div>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!$hasUnresolved): ?>
      <p class="empty-tickets">All support tickets have been resolved <i class='bx bx-party'></i></p>
    <?php endif; ?>
  <?php else: ?>
    <p class="empty-tickets">No support tickets submitted yet.</p>
  <?php endif; ?>
</div>

<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
:root {
  --primary-gradient: linear-gradient(135deg, #00eaff, #00c5cc);
  --card-bg: rgba(255, 255, 255, 0.05);
  --text-color: #fff;
  --header-color: #00eaff;
  --shadow-color: rgba(0, 234, 255, 0.2);
}
body.light-mode {
  --primary-gradient: linear-gradient(135deg, #ff7e5f, #feb47b);
  --card-bg: #ffffff;
  --text-color: #333;
  --header-color: #ff7e5f;
  --shadow-color: rgba(255, 126, 95, 0.3);
}
body {
  margin: 0;
  font-family: 'Segoe UI', sans-serif;
  background: #001a33;
  color: var(--text-color);
  transition: all 0.3s ease;
}
.page-title {
  text-align: center;
  margin-top: 30px;
  font-size: 2em;
  color: var(--header-color);
}
.tickets-wrapper {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 20px;
  padding: 20px;
}
.ticket-card {
  background: var(--card-bg);
  border-radius: 16px;
  box-shadow: 0 8px 25px var(--shadow-color);
  padding: 20px;
  display: flex;
  flex-direction: column;
  transition: transform 0.3s, box-shadow 0.3s;
  backdrop-filter: blur(10px);
}
.ticket-card:hover {
  transform: translateY(-8px);
  box-shadow: 0 12px 40px var(--shadow-color);
}
.ticket-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
  font-weight: bold;
}
.ticket-matric {
  background: var(--primary-gradient);
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 0.85em;
  color: #000;
}
.ticket-message {
  margin-bottom: 10px;
  flex-grow: 1;
}
.ticket-fingerprint, .ticket-ip {
  font-size: 0.85em;
  background: rgba(255, 255, 255, 0.1);
  padding: 6px 10px;
  border-radius: 6px;
  margin-bottom: 8px;
  word-break: break-all;
}
.ticket-footer {
  font-size: 0.85em;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.resolve-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: var(--primary-gradient);
  color: #000;
  padding: 6px 14px;
  border-radius: 8px;
  text-decoration: none;
  font-weight: bold;
  box-shadow: 0 2px 10px var(--shadow-color);
  transition: 0.3s;
}
.resolve-btn:hover {
  transform: translateY(-2px);
}
.dot-container {
  text-align: center;
  margin-top: 10px;
}
.dot {
  width: 16px;
  height: 16px;
  background: var(--primary-gradient);
  border-radius: 50%;
  margin: 0 auto;
  cursor: pointer;
  box-shadow: 0 0 8px rgba(0,0,0,0.2);
  transition: transform 0.2s;
}
.dot:hover {
  transform: scale(1.2);
}
.dot-actions button {
  display: block;
  width: 80%;
  margin: 6px auto;
  padding: 6px;
  border: none;
  border-radius: 6px;
  font-size: 0.85em;
  font-weight: bold;
  cursor: pointer;
  transition: 0.3s;
}
.dot-actions .checkin-btn {
  background: linear-gradient(135deg, #22c55e, #15803d);
  color: white;
}
.dot-actions .checkout-btn {
  background: linear-gradient(135deg, #ef4444, #b91c1c);
  color: white;
}
.dot-actions button:hover {
  filter: brightness(1.1);
}
.empty-tickets {
  text-align: center;
  font-style: italic;
  margin-top: 50px;
}
.theme-toggle {
  position: fixed;
  top: 20px;
  right: 20px;
  z-index: 999;
}

#palette-icon {
  font-size: 28px;
  color: var(--header-color);
  cursor: pointer;
  transition: transform 0.3s;
}

#palette-icon:hover {
  transform: scale(1.2);
}

#palette-options {
  display: none;
  flex-direction: column;
  gap: 6px;
  margin-top: 8px;
}

#palette-options button {
  width: 28px;
  height: 28px;
  border: none;
  border-radius: 50%;
  outline: 2px solid #fff;
  cursor: pointer;
  transition: transform 0.2s;
}

#palette-options button:hover {
  transform: scale(1.15);
}

</style>

<script>
function confirmResolve(e) {
  e.preventDefault();
  const url = e.currentTarget.href;
  Swal.fire({
    title: 'Are you sure?',
    text: "This ticket will be marked as resolved.",
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#00eaff',
    cancelButtonColor: '#d33',
    confirmButtonText: 'Yes, mark it!'
  }).then((result) => {
    if (result.isConfirmed) {
      window.location.href = url;
    }
  });
}
function toggleAction(dot) {
  const form = dot.nextElementSibling;
  form.style.display = form.style.display === "none" ? "block" : "none";
}
const themes = [
  { gradient: "linear-gradient(135deg, #00eaff, #00c5cc)", header: "#00eaff", shadow: "rgba(0, 234, 255, 0.2)", text: "#fff", bg: "#001a33" },
  { gradient: "linear-gradient(135deg, #ff7e5f, #feb47b)", header: "#ff7e5f", shadow: "rgba(255, 126, 95, 0.3)", text: "#333", bg: "#fff" },
  { gradient: "linear-gradient(135deg, #3b82f6, #06b6d4)", header: "#3b82f6", shadow: "rgba(59, 130, 246, 0.3)", text: "#fff", bg: "#001a33" },
  { gradient: "linear-gradient(135deg, #10b981, #22d3ee)", header: "#10b981", shadow: "rgba(16, 185, 129, 0.3)", text: "#fff", bg: "#001a33" },
  { gradient: "linear-gradient(135deg, #a855f7, #3b82f6)", header: "#a855f7", shadow: "rgba(168, 85, 247, 0.3)", text: "#fff", bg: "#001a33" },
  { gradient: "linear-gradient(135deg, #ec4899, #f59e0b)", header: "#ec4899", shadow: "rgba(236, 72, 153, 0.3)", text: "#fff", bg: "#001a33" },
  { gradient: "linear-gradient(135deg, #14b8a6, #0ea5e9)", header: "#14b8a6", shadow: "rgba(20, 184, 166, 0.3)", text: "#fff", bg: "#001a33" },
];

function setTheme(index) {
  const t = themes[index];
  document.documentElement.style.setProperty("--primary-gradient", t.gradient);
  document.documentElement.style.setProperty("--header-color", t.header);
  document.documentElement.style.setProperty("--shadow-color", t.shadow);
  document.documentElement.style.setProperty("--text-color", t.text);
  document.body.style.background = t.bg;
  localStorage.setItem("selectedTheme", index);
}

document.getElementById("palette-icon").addEventListener("click", () => {
  const options = document.getElementById("palette-options");
  options.style.display = options.style.display === "flex" ? "none" : "flex";
});

// On page load
const savedTheme = localStorage.getItem("selectedTheme");
if (savedTheme !== null) {
  setTheme(parseInt(savedTheme));
}

</script>
