<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_logged_in'])) {
  header('Location: login.php');
  exit;
}

require_once __DIR__ . '/includes/csrf.php';
$pageCsrfToken = csrf_token();

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

function resolve_ticket_atomic($ticketsFile, $resolveTime) {
  $fp = fopen($ticketsFile, 'c+');
  if (!$fp) return false;
  if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    return false;
  }

  rewind($fp);
  $raw = stream_get_contents($fp);
  $tickets = json_decode($raw ?: '[]', true);
  if (!is_array($tickets)) $tickets = [];

  foreach ($tickets as &$ticket) {
    if (($ticket['timestamp'] ?? '') === $resolveTime) {
      $ticket['resolved'] = true;
      break;
    }
  }

  $payload = json_encode($tickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  rewind($fp);
  ftruncate($fp, 0);
  fwrite($fp, $payload);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  return true;
}

// Handle resolve
if (isset($_GET['resolve'])) {
  $csrfOk = isset($_GET['csrf_token']) && hash_equals((string)($_SESSION['_csrf']['token'] ?? ''), (string)$_GET['csrf_token']);
  if (!$csrfOk) {
    http_response_code(403);
    echo 'Invalid CSRF token.';
    exit;
  }
    $resolveTime = $_GET['resolve'];
  resolve_ticket_atomic($ticketsFile, $resolveTime);
    header("Location: index.php?page=support_tickets");
    exit;
}

// Handle manual check-in/out
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_action'], $_POST['name'], $_POST['matric'], $_POST['reason'])) {
  if (!csrf_check_request()) {
    http_response_code(403);
    echo 'Invalid CSRF token.';
    exit;
  }

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

<!-- Support Tickets — Stitch UI -->
<div style="margin-bottom:24px;">
  <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;">
    <span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;">confirmation_number</span>Support Tickets
  </h2>
  <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">Review and resolve student support requests.</p>
</div>

<div class="tickets-wrapper" style="grid-template-columns:repeat(auto-fit, minmax(340px, 1fr));">
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
          <!-- Header -->
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid var(--surface-container-high);">
            <strong style="color:var(--on-surface);font-size:1rem;"><?= htmlspecialchars($ticket['name']) ?></strong>
            <span class="st-chip st-chip-info"><?= htmlspecialchars($ticket['matric']) ?></span>
          </div>

          <!-- Message -->
          <p style="color:var(--on-surface);line-height:1.6;margin-bottom:16px;font-size:0.9rem;">
            <?= nl2br(htmlspecialchars($ticket['message'])) ?>
          </p>

          <!-- Fingerprint & IP matches -->
          <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">
            <div style="font-size:0.85rem;background:var(--surface-container-low);padding:10px 14px;border-radius:8px;word-break:break-all;color:<?= $fpMatch ? '#059669' : 'var(--error)' ?>;">
              <strong>Fingerprint:</strong> <?= htmlspecialchars($fp ?: 'Not submitted') ?>
              <?= $fpMatch ? '<span class="st-chip st-chip-success" style="margin-left:8px;">Matched ✓</span>' : '<span class="st-chip st-chip-danger" style="margin-left:8px;">No match</span>' ?>
            </div>
            <div style="font-size:0.85rem;background:var(--surface-container-low);padding:10px 14px;border-radius:8px;word-break:break-all;color:<?= $ipMatch ? '#059669' : 'var(--error)' ?>;">
              <strong>IP:</strong> <?= htmlspecialchars($ip ?: 'Not submitted') ?>
              <?= $ipMatch ? '<span class="st-chip st-chip-success" style="margin-left:8px;">Matched ✓</span>' : '<span class="st-chip st-chip-danger" style="margin-left:8px;">No match</span>' ?>
            </div>
          </div>

          <!-- Footer -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding-top:12px;border-top:1px solid var(--surface-container-high);font-size:0.85rem;color:var(--on-surface-variant);flex-wrap:wrap;gap:8px;">
            <span><span class="material-symbols-outlined" style="font-size:0.9rem;vertical-align:middle;">schedule</span> <?= htmlspecialchars($ticket['timestamp']) ?></span>
            <a class="st-btn st-btn-success st-btn-sm resolve-btn" href="index.php?page=support_tickets&resolve=<?= urlencode($ticket['timestamp']) ?>&csrf_token=<?= urlencode($pageCsrfToken) ?>" onclick="return confirmResolve(event)">
              <span class="material-symbols-outlined" style="font-size:1rem;">check_circle</span> Resolve
            </a>
          </div>

          <!-- Action Menu -->
          <div style="position:absolute;top:16px;right:16px;" class="action-menu">
            <button type="button" style="background:var(--surface-container-low);border:1px solid var(--outline-variant);border-radius:8px;padding:6px;cursor:pointer;display:flex;" onclick="toggleActionMenu(this)">
              <span class="material-symbols-outlined" style="font-size:1rem;color:var(--on-surface-variant);">more_vert</span>
            </button>
            <form method="post" class="action-menu-content" style="display:none;position:absolute;right:0;top:calc(100% + 6px);background:var(--surface-container-lowest);border:1px solid var(--outline-variant);border-radius:10px;box-shadow:var(--shadow-ambient);padding:4px;z-index:50;min-width:160px;">
              <?php csrf_field(); ?>
              <input type="hidden" name="name" value="<?= htmlspecialchars($ticket['name']) ?>">
              <input type="hidden" name="matric" value="<?= htmlspecialchars($ticket['matric']) ?>">
              <input type="hidden" name="reason" value="<?= htmlspecialchars($ticket['message']) ?>">
              <button type="submit" name="manual_action" value="checkin" style="display:flex;align-items:center;gap:8px;width:100%;padding:10px 14px;background:none;border:none;cursor:pointer;border-radius:6px;font-family:inherit;font-size:0.88rem;color:var(--on-surface);transition:background 0.15s;">
                <span class="material-symbols-outlined" style="font-size:1rem;">login</span> Check-In
              </button>
              <button type="submit" name="manual_action" value="checkout" style="display:flex;align-items:center;gap:8px;width:100%;padding:10px 14px;background:none;border:none;cursor:pointer;border-radius:6px;font-family:inherit;font-size:0.88rem;color:var(--on-surface);transition:background 0.15s;">
                <span class="material-symbols-outlined" style="font-size:1rem;">logout</span> Check-Out
              </button>
            </form>
          </div>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!$hasUnresolved): ?>
      <div style="text-align:center;padding:48px 24px;color:var(--on-surface-variant);grid-column:1/-1;">
        <span class="material-symbols-outlined" style="font-size:3rem;color:var(--outline-variant);display:block;margin-bottom:12px;">celebration</span>
        <p style="font-size:1.1rem;font-weight:600;">All support tickets have been resolved!</p>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <div style="text-align:center;padding:48px 24px;color:var(--on-surface-variant);grid-column:1/-1;">
      <span class="material-symbols-outlined" style="font-size:3rem;color:var(--outline-variant);display:block;margin-bottom:12px;">inbox</span>
      <p style="font-size:1.1rem;font-weight:600;">No support tickets submitted yet.</p>
    </div>
  <?php endif; ?>
</div>

<script>
function confirmResolve(e) {
  e.preventDefault();
  const url = e.currentTarget.href;
  Swal.fire({
    title: 'Mark as Resolved?',
    text: "This ticket will be marked as resolved.",
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#059669',
    cancelButtonColor: '#6b7280',
    confirmButtonText: 'Yes, resolve it'
  }).then((result) => {
    if (result.isConfirmed) window.location.href = url;
  });
}

function toggleActionMenu(trigger) {
  document.querySelectorAll('.action-menu-content').forEach(menu => {
    if (menu !== trigger.nextElementSibling) menu.style.display = 'none';
  });
  var menu = trigger.nextElementSibling;
  if (menu) menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
}

document.addEventListener('click', (e) => {
  if (!e.target.closest('.action-menu')) {
    document.querySelectorAll('.action-menu-content').forEach(m => m.style.display = 'none');
  }
});
</script>
