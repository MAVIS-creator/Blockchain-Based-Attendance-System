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

          <div class="action-menu">
            <button class="action-trigger" onclick="toggleActionMenu(this)">
              <i class='bx bx-dots-vertical-rounded'></i>
            </button>
            <form method="post" class="action-menu-content">
              <input type="hidden" name="name" value="<?= htmlspecialchars($ticket['name']) ?>">
              <input type="hidden" name="matric" value="<?= htmlspecialchars($ticket['matric']) ?>">
              <input type="hidden" name="reason" value="<?= htmlspecialchars($ticket['message']) ?>">
              
              <button type="submit" name="manual_action" value="checkin" class="action-menu-item">
                <i class='bx bx-log-in'></i> Check-In
              </button>
              <button type="submit" name="manual_action" value="checkout" class="action-menu-item">
                <i class='bx bx-log-out'></i> Check-Out
              </button>
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
.page-title {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 2rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid var(--light);
}

.page-title i {
  color: var(--primary);
  font-size: 1.75rem;
}

.tickets-wrapper {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
  gap: 1.5rem;
  padding: 1.5rem;
}

.ticket-card {
  background: var(--white);
  border-radius: var(--border-radius-lg);
  box-shadow: var(--shadow);
  padding: 1.5rem;
  position: relative;
  transition: transform 0.2s, box-shadow 0.2s;
  border: 1px solid var(--light);
}

.ticket-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-lg);
}

.ticket-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
  padding-bottom: 0.75rem;
  border-bottom: 1px solid var(--light);
}

.ticket-matric {
  background: var(--bg-gradient);
  color: var(--white);
  padding: 0.35rem 0.75rem;
  border-radius: var(--border-radius);
  font-size: 0.875rem;
  font-weight: 500;
}

.ticket-message {
  margin-bottom: 1.25rem;
  color: var(--dark);
  line-height: 1.6;
}

.ticket-fingerprint, .ticket-ip {
  font-size: 0.875rem;
  background: var(--light);
  padding: 0.75rem;
  border-radius: var(--border-radius);
  margin-bottom: 0.75rem;
  word-break: break-all;
}

.ticket-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 1rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--light);
  font-size: 0.875rem;
  color: var(--secondary);
}

.ticket-footer i {
  margin-right: 0.5rem;
}

.resolve-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  background: var(--bg-gradient);
  color: var(--white);
  border-radius: var(--border-radius);
  font-weight: 500;
  text-decoration: none;
  transition: all 0.2s;
}

.resolve-btn:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow);
}

.empty-tickets {
  text-align: center;
  padding: 3rem;
  color: var(--secondary);
  font-size: 1.1rem;
}

.theme-toggle {
  position: fixed;
  top: 1.5rem;
  right: 1.5rem;
  z-index: 999;
}

#palette-icon {
  font-size: 1.5rem;
  color: var(--primary);
  cursor: pointer;
  transition: transform 0.2s;
  padding: 0.5rem;
  border-radius: var(--border-radius);
  background: var(--white);
  box-shadow: var(--shadow);
}

#palette-icon:hover {
  transform: scale(1.1);
}

#palette-options {
  position: absolute;
  right: 0;
  top: 100%;
  margin-top: 0.5rem;
  background: var(--white);
  border-radius: var(--border-radius);
  box-shadow: var(--shadow-lg);
  padding: 0.75rem;
  display: none;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.5rem;
}

#palette-options button {
  width: 2rem;
  height: 2rem;
  border: none;
  border-radius: 50%;
  cursor: pointer;
  transition: transform 0.2s;
  box-shadow: var(--shadow-sm);
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
// Handle action menu toggles
function toggleActionMenu(trigger) {
  // Close any other open menus first
  document.querySelectorAll('.action-menu.active').forEach(menu => {
    if (menu !== trigger.parentElement) {
      menu.classList.remove('active');
    }
  });
  
  // Toggle the clicked menu
  trigger.parentElement.classList.toggle('active');
}

// Close action menus when clicking outside
document.addEventListener('click', (e) => {
  if (!e.target.closest('.action-menu')) {
    document.querySelectorAll('.action-menu.active').forEach(menu => {
      menu.classList.remove('active');
    });
  }
});

// Theme management
const themes = [
  {
    name: 'Blue Ocean',
    colors: {
      primary: '#3b82f6',
      info: '#0ea5e9',
      bg: '#f8fafc'
    }
  },
  {
    name: 'Sunset',
    colors: {
      primary: '#f97316',
      info: '#f59e0b',
      bg: '#fff7ed'
    }
  },
  {
    name: 'Forest',
    colors: {
      primary: '#10b981',
      info: '#059669',
      bg: '#f0fdf4'
    }
  },
  {
    name: 'Royal',
    colors: {
      primary: '#8b5cf6',
      info: '#6366f1',
      bg: '#f5f3ff'
    }
  },
  {
    name: 'Ruby',
    colors: {
      primary: '#e11d48',
      info: '#be123c',
      bg: '#fff1f2'
    }
  },
  {
    name: 'Emerald',
    colors: {
      primary: '#059669',
      info: '#0d9488',
      bg: '#ecfdf5'
    }
  }
];

function setTheme(index) {
  const theme = themes[index];
  document.documentElement.style.setProperty('--primary', theme.colors.primary);
  document.documentElement.style.setProperty('--info', theme.colors.info);
  document.documentElement.style.setProperty('--bg-gradient', `linear-gradient(135deg, ${theme.colors.primary}, ${theme.colors.info})`);
  document.body.style.background = theme.colors.bg;
  localStorage.setItem('selectedTheme', index);
}

// Theme switcher toggle
document.getElementById('palette-icon').addEventListener('click', (e) => {
  e.stopPropagation();
  const options = document.getElementById('palette-options');
  options.style.display = options.style.display === 'grid' ? 'none' : 'grid';
});

// Close theme options when clicking outside
document.addEventListener('click', (e) => {
  if (!e.target.closest('#palette-options')) {
    document.getElementById('palette-options').style.display = 'none';
  }
});

// Load saved theme
const savedTheme = localStorage.getItem('selectedTheme');
if (savedTheme !== null) {
  setTheme(parseInt(savedTheme));
}

// SweetAlert2 Theme
const Toast = Swal.mixin({
  toast: true,
  position: 'top-end',
  showConfirmButton: false,
  timer: 3000,
  timerProgressBar: true
});

</script>
