<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_logged_in'])) {
  header('Location: login.php');
  exit;
}

require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/hybrid_admin_read.php';
require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/cache_helpers.php';
app_storage_init();
$pageCsrfToken = csrf_token();
$flashMessage = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

$ticketsFile = admin_storage_migrate_file('support_tickets.json', app_storage_file('support_tickets.json'));
$tickets = [];
$ticketsSource = 'file';

$hybridTickets = hybrid_fetch_support_tickets($ticketsSource);
if (is_array($hybridTickets)) {
  $tickets = $hybridTickets;
} else if (file_exists($ticketsFile)) {
  $tickets = admin_cached_json_file('support_tickets_page', $ticketsFile, [], 15);
}

$today = date('Y-m-d');
$logFile = app_storage_file("logs/{$today}.log");
$logLines = admin_cached_file_lines('support_ticket_today_log', $logFile, 15);

function checkLogMatch($logLines, $needle, $index)
{
  foreach ($logLines as $line) {
    $fields = array_map('trim', explode('|', $line));
    if (isset($fields[$index]) && $fields[$index] === $needle) {
      return true;
    }
  }
  return false;
}

function resolve_ticket_atomic($ticketsFile, $resolveTime)
{
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

function bulk_update_tickets_atomic($ticketsFile, array $selectedTimes, $bulkAction, $logFile = null, $activeCourse = '')
{
  $bulkAction = strtolower(trim((string)$bulkAction));
  $allowedActions = ['resolve', 'checkin', 'checkout'];
  if (!in_array($bulkAction, $allowedActions, true)) {
    return ['updated' => 0, 'logged' => 0];
  }

  $selectedLookup = array_fill_keys(array_filter(array_map('strval', $selectedTimes)), true);
  if (!$selectedLookup) {
    return ['updated' => 0, 'logged' => 0];
  }

  $attendanceAction = $bulkAction === 'checkin' ? 'checkin' : ($bulkAction === 'checkout' ? 'checkout' : '');
  $timestamp = date('Y-m-d H:i:s');
  $updatedCount = 0;
  $logLines = [];

  $fp = fopen($ticketsFile, 'c+');
  if (!$fp) return ['updated' => 0, 'logged' => 0];
  if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    return ['updated' => 0, 'logged' => 0];
  }

  rewind($fp);
  $raw = stream_get_contents($fp);
  $tickets = json_decode($raw ?: '[]', true);
  if (!is_array($tickets)) $tickets = [];

  foreach ($tickets as &$ticket) {
    $ticketTime = (string)($ticket['timestamp'] ?? '');
    if (!isset($selectedLookup[$ticketTime])) {
      continue;
    }

    if (!($ticket['resolved'] ?? false)) {
      $ticket['resolved'] = true;
      $updatedCount++;
    }

    if ($attendanceAction !== '') {
      $name = trim((string)($ticket['name'] ?? 'Unknown'));
      $matric = trim((string)($ticket['matric'] ?? ''));
      $reason = trim((string)($ticket['message'] ?? 'Bulk support ticket action'));
      $logLines[] = "{$name} | {$matric} | {$attendanceAction} | MANUAL | ::1 | UNKNOWN | {$timestamp} | Bulk Ticket Panel | {$activeCourse} | {$reason}\n";
    }
  }
  unset($ticket);

  $payload = json_encode($tickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  rewind($fp);
  ftruncate($fp, 0);
  fwrite($fp, $payload);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  if (!empty($logLines) && $logFile) {
    file_put_contents($logFile, implode('', $logLines), FILE_APPEND | LOCK_EX);
  }

  return ['updated' => $updatedCount, 'logged' => count($logLines)];
}

// Handle resolve
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_ticket'])) {
  if (!csrf_check_request()) {
    $_SESSION['admin_flash'] = ['type' => 'error', 'title' => 'Invalid CSRF token', 'text' => 'Please refresh the page and try again.'];
    header("Location: index.php?page=support_tickets");
    exit;
  }
  $resolveTime = trim((string)($_POST['resolve_ticket'] ?? ''));
  if ($resolveTime === '') {
    $_SESSION['admin_flash'] = ['type' => 'error', 'title' => 'Resolve failed', 'text' => 'Ticket timestamp is missing.'];
    header("Location: index.php?page=support_tickets");
    exit;
  }
  hybrid_mark_support_ticket_resolved($resolveTime);
  $resolved = resolve_ticket_atomic($ticketsFile, $resolveTime);
  $_SESSION['admin_flash'] = $resolved
    ? ['type' => 'success', 'title' => 'Resolved', 'text' => 'Ticket marked as resolved.']
    : ['type' => 'error', 'title' => 'Resolve failed', 'text' => 'Could not update the ticket file.'];
  header("Location: index.php?page=support_tickets");
  exit;
}

// Handle manual check-in/out
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_action'], $_POST['name'], $_POST['matric'], $_POST['reason'])) {
  if (!csrf_check_request()) {
    $_SESSION['admin_flash'] = ['type' => 'error', 'title' => 'Invalid CSRF token', 'text' => 'Please refresh the page and try again.'];
    header("Location: index.php?page=support_tickets");
    exit;
  }

  $action = $_POST['manual_action'];
  $name = trim($_POST['name']);
  $matric = trim($_POST['matric']);
  $reason = trim($_POST['reason']);

  $activeCourse = admin_active_course_name_cached(15);

  $timestamp = date('Y-m-d H:i:s');
  $logFile = app_storage_file("logs/{$today}.log");

  // Standardized log format: name | matric | action | fingerprint | ip | mac | timestamp | userAgent | course | reason
  $line = "{$name} | {$matric} | {$action} | MANUAL | ::1 | UNKNOWN | {$timestamp} | Web Ticket Panel | {$activeCourse} | {$reason}\n";

  file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
  $_SESSION['admin_flash'] = ['type' => 'success', 'title' => 'Attendance recorded', 'text' => ucfirst($action) . ' was added from support tickets.'];
  header("Location: index.php?page=support_tickets");
  exit;
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'], $_POST['selected_tickets'])) {
  if (!csrf_check_request()) {
    $_SESSION['admin_flash'] = ['type' => 'error', 'title' => 'Invalid CSRF token', 'text' => 'Please refresh the page and try again.'];
    header("Location: index.php?page=support_tickets");
    exit;
  }

  $bulkAction = trim((string)$_POST['bulk_action']);
  $selectedTickets = array_values(array_filter(array_map('trim', (array)$_POST['selected_tickets'])));

  if (empty($selectedTickets)) {
    $_SESSION['admin_flash'] = ['type' => 'error', 'title' => 'Bulk action failed', 'text' => 'Select at least one ticket first.'];
    header("Location: index.php?page=support_tickets");
    exit;
  }

  $activeCourse = admin_active_course_name_cached(15);
  $result = bulk_update_tickets_atomic($ticketsFile, $selectedTickets, $bulkAction, $logFile, $activeCourse);
  $updatedCount = (int)($result['updated'] ?? 0);
  $loggedCount = (int)($result['logged'] ?? 0);

  foreach (array_unique($selectedTickets) as $resolveTime) {
    hybrid_mark_support_ticket_resolved($resolveTime);
  }

  if ($bulkAction === 'resolve') {
    $_SESSION['admin_flash'] = ['type' => 'success', 'title' => 'Bulk resolved', 'text' => "Resolved {$updatedCount} ticket(s)."];
  } elseif ($bulkAction === 'checkin') {
    $_SESSION['admin_flash'] = ['type' => 'success', 'title' => 'Bulk check-in', 'text' => "Checked in and resolved {$updatedCount} ticket(s). Logged {$loggedCount} attendance entry/entries."];
  } elseif ($bulkAction === 'checkout') {
    $_SESSION['admin_flash'] = ['type' => 'success', 'title' => 'Bulk check-out', 'text' => "Checked out and resolved {$updatedCount} ticket(s). Logged {$loggedCount} attendance entry/entries."];
  } else {
    $_SESSION['admin_flash'] = ['type' => 'error', 'title' => 'Bulk action failed', 'text' => 'Unsupported bulk action.'];
  }

  header("Location: index.php?page=support_tickets");
  exit;
}
?>

<!-- Support Tickets — Stitch UI -->
<div style="margin-bottom:24px;">
  <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;">
    <span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;">confirmation_number</span>Support Tickets
  </h2>
  <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">Review and resolve student support requests. Source: <strong><?= htmlspecialchars($ticketsSource) ?></strong></p>
</div>

<form id="bulkTicketForm" method="post" style="margin:0 0 16px 0;">
  <?php csrf_field(); ?>
  <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;padding:12px 14px;background:var(--surface-container-lowest);border:1px solid var(--outline-variant);border-radius:14px;box-shadow:var(--shadow-ambient);">
    <div style="display:flex;flex-direction:column;gap:4px;min-width:220px;">
      <strong style="color:var(--on-surface);font-size:0.95rem;">Bulk actions</strong>
      <span style="color:var(--on-surface-variant);font-size:0.82rem;">Select tickets below, then choose resolve, check-in, or check-out.</span>
    </div>
    <label style="display:inline-flex;align-items:center;gap:8px;color:var(--on-surface-variant);font-size:0.84rem;cursor:pointer;user-select:none;">
      <input type="checkbox" id="selectAllTickets" style="width:16px;height:16px;">
      Select all
    </label>
    <div style="display:flex;flex-wrap:wrap;gap:8px;">
      <button type="submit" name="bulk_action" value="resolve" class="st-btn st-btn-success st-btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
        <span class="material-symbols-outlined" style="font-size:1rem;">task_alt</span> Bulk Resolve
      </button>
      <button type="submit" name="bulk_action" value="checkin" class="st-btn st-btn-sm" style="display:inline-flex;align-items:center;gap:6px;background:#0ea5e9;color:#fff;border-color:#0ea5e9;">
        <span class="material-symbols-outlined" style="font-size:1rem;">login</span> Bulk Check-In
      </button>
      <button type="submit" name="bulk_action" value="checkout" class="st-btn st-btn-sm" style="display:inline-flex;align-items:center;gap:6px;background:#7c3aed;color:#fff;border-color:#7c3aed;">
        <span class="material-symbols-outlined" style="font-size:1rem;">logout</span> Bulk Check-Out
      </button>
    </div>
  </div>
</form>

<div class="tickets-wrapper" style="grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));">
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

        <div class="ticket-card" style="position:relative;overflow:visible;">
          <!-- Header -->
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid var(--surface-container-high);">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;max-width:72%;">
              <input type="checkbox" class="bulk-ticket-checkbox" form="bulkTicketForm" name="selected_tickets[]" value="<?= htmlspecialchars($ticket['timestamp']) ?>" style="width:16px;height:16px;accent-color:#1f5d99;">
              <strong style="color:var(--on-surface);font-size:1rem;"><?= htmlspecialchars($ticket['name']) ?></strong>
            </label>
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
            <div style="display:flex;align-items:center;gap:8px;margin-left:auto;">
              <div style="position:relative;" class="action-menu">
                <button type="button" class="action-menu-trigger" style="background:var(--surface-container-low);border:1px solid var(--outline-variant);border-radius:8px;padding:6px;cursor:pointer;display:flex;">
                  <span class="material-symbols-outlined" style="pointer-events:none;font-size:1rem;color:var(--on-surface-variant);">more_vert</span>
                </button>
                <form method="post" class="action-menu-content" hidden style="position:absolute;right:0;top:calc(100% + 6px);background:var(--surface-container-lowest);border:1px solid var(--outline-variant);border-radius:10px;box-shadow:var(--shadow-ambient);padding:4px;z-index:1000;min-width:160px;">
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
              <form method="post" class="resolve-ticket-form" style="margin:0;">
                <?php csrf_field(); ?>
                <input type="hidden" name="resolve_ticket" value="<?= htmlspecialchars($ticket['timestamp']) ?>">
                <button type="submit" class="st-btn st-btn-success st-btn-sm resolve-btn" onclick="return confirmResolve(event)">
                  <span class="material-symbols-outlined" style="font-size:1rem;">check_circle</span> Resolve
                </button>
              </form>
            </div>
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
  const selectAllTickets = document.getElementById('selectAllTickets');
  const bulkTicketCheckboxes = () => Array.from(document.querySelectorAll('.bulk-ticket-checkbox'));

  if (selectAllTickets) {
    selectAllTickets.addEventListener('change', () => {
      const checked = selectAllTickets.checked;
      bulkTicketCheckboxes().forEach(cb => {
        cb.checked = checked;
      });
    });

    document.addEventListener('change', (e) => {
      if (e.target && e.target.classList && e.target.classList.contains('bulk-ticket-checkbox')) {
        const boxes = bulkTicketCheckboxes();
        selectAllTickets.checked = boxes.length > 0 && boxes.every(cb => cb.checked);
      }
    });
  }

  document.addEventListener('click', (e) => {
    // Handle action menu trigger clicks
    const trigger = e.target.closest('.action-menu-trigger');
    if (trigger) {
      e.preventDefault();

      const menuWrapper = trigger.closest('.action-menu');
      if (!menuWrapper) return;

      const parentCard = menuWrapper.closest('.ticket-card');

      const menu = menuWrapper.querySelector('.action-menu-content');
      if (!menu) return;

      // Close all other menus
      document.querySelectorAll('.action-menu').forEach(wrapper => {
        const otherMenu = wrapper.querySelector('.action-menu-content');
        if (!otherMenu || otherMenu === menu) {
          return;
        }
        wrapper.classList.remove('active');
        otherMenu.hidden = true;
        const otherCard = wrapper.closest('.ticket-card');
        if (otherCard) otherCard.style.zIndex = '';
      });

      document.querySelectorAll('.ticket-card').forEach(card => {
        card.style.zIndex = '';
      });
      if (parentCard) {
        parentCard.style.zIndex = '40000';
      }

      // Toggle current menu
      if (menu) {
        const isOpen = menuWrapper.classList.contains('active');
        const nextState = isOpen ? 'none' : 'block';
        menuWrapper.classList.toggle('active', !isOpen);
        menu.hidden = isOpen;
        if (isOpen && parentCard) {
          parentCard.style.zIndex = '';
        }
      }
      return;
    }

    // Clicking outside completely closes any open menus
    if (!e.target.closest('.action-menu')) {
      document.querySelectorAll('.action-menu').forEach(wrapper => {
        wrapper.classList.remove('active');
        const menu = wrapper.querySelector('.action-menu-content');
        if (menu) menu.hidden = true;
      });
      document.querySelectorAll('.ticket-card').forEach(card => {
        card.style.zIndex = '';
      });
    }
  });

  function confirmResolve(e) {
    e.preventDefault();
    const form = e.currentTarget.closest('form');
    window.adminConfirm('Mark as Resolved?', 'This ticket will be marked as resolved.')
      .then((ok) => {
        if (ok && form) form.submit();
      });
    return false;
  }

  <?php if (is_array($flashMessage) && !empty($flashMessage['title'])): ?>
    window.adminAlert(
      <?= json_encode((string)$flashMessage['title']) ?>,
      <?= json_encode((string)($flashMessage['text'] ?? '')) ?>,
      <?= json_encode((string)($flashMessage['type'] ?? 'info')) ?>
    );
  <?php endif; ?>
</script>
