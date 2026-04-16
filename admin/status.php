<?php
require_once __DIR__ . '/session_bootstrap.php';
if (empty($_SESSION['admin_logged_in'])) {
  header('Location: login.php');
  exit;
}

require_once __DIR__ . '/includes/csrf.php';
csrf_token();

require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/state_helpers.php';
app_storage_init();
$statusFile = admin_status_file();
// load status
$status = admin_load_status_cached(10);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $errorMessage = null;

  if (!csrf_check_request()) {
    $errorMessage = 'Invalid CSRF token.';
  }

  $duration = isset($_POST['duration']) && is_numeric($_POST['duration']) ? (int)$_POST['duration'] * 60 : 600; // default 10 minutes

  // try to load admin settings to enforce check windows
  $settings = admin_load_settings_cached(15);

  // helper: check time window
  $nowHM = date('H:i');
  $withinCheckinWindow = true;
  if (!empty($settings['checkin_time_start']) && !empty($settings['checkin_time_end'])) {
    $start = $settings['checkin_time_start'];
    $end = $settings['checkin_time_end'];
    if ($start <= $end) {
      $withinCheckinWindow = ($nowHM >= $start && $nowHM <= $end);
    } else {
      // overnight window
      $withinCheckinWindow = ($nowHM >= $start || $nowHM <= $end);
    }
  }

  if (empty($errorMessage) && isset($_POST['enable_checkin'])) {
    if (!$withinCheckinWindow) {
      $errorMessage = 'Cannot enable Check-In: current time is outside the configured check-in window.';
    } else {
      $status = ['checkin' => true, 'checkout' => false, 'end_time' => time() + $duration];
    }
  } elseif (empty($errorMessage) && isset($_POST['enable_checkout'])) {
    $status = ['checkin' => false, 'checkout' => true, 'end_time' => time() + $duration];
  } elseif (empty($errorMessage) && isset($_POST['disable'])) {
    $status = ['checkin' => false, 'checkout' => false, 'end_time' => null];
  }

  if (empty($errorMessage)) {
    file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT), LOCK_EX);
    header("Location: index.php?page=status");
    exit;
  }
  // if there was an error, fall through and render it below
}
?>

<!-- Status Control Page — Stitch UI -->
<div style="max-width:700px;margin:0 auto;">

  <div style="margin-bottom:24px;">
    <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;">
      <span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;font-size:1.4rem;">tune</span>System Status Control
    </h2>
    <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">Enable or disable check-in and check-out modes for the attendance system.</p>
  </div>

  <!-- Status Cards Grid -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">
    <!-- Check-In Card -->
    <div class="st-card" style="text-align:center;">
      <?php $checkinBg = $status['checkin'] ? '#ecfdf5' : '#fef2f2'; ?>
      <?php $checkinColor = $status['checkin'] ? '#059669' : 'var(--error)'; ?>
      <div style="display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:12px;margin-bottom:12px;background:<?= $checkinBg ?>;">
        <span class="material-symbols-outlined" style="font-size:1.5rem;font-variation-settings:'FILL' 1;color:<?= $checkinColor ?>;">login</span>
      </div>
      <p style="font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.08em;color:var(--on-surface-variant);margin:0 0 6px;">Check-In</p>
      <?php if ($status['checkin']): ?>
        <span class="status-enabled"><span class="material-symbols-outlined" style="font-size:1rem;">check_circle</span> ENABLED</span>
      <?php else: ?>
        <span class="status-disabled"><span class="material-symbols-outlined" style="font-size:1rem;">cancel</span> DISABLED</span>
      <?php endif; ?>
    </div>

    <!-- Check-Out Card -->
    <div class="st-card" style="text-align:center;">
      <?php $checkoutBg = $status['checkout'] ? '#ecfdf5' : '#fef2f2'; ?>
      <?php $checkoutColor = $status['checkout'] ? '#059669' : 'var(--error)'; ?>
      <div style="display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:12px;margin-bottom:12px;background:<?= $checkoutBg ?>;">
        <span class="material-symbols-outlined" style="font-size:1.5rem;font-variation-settings:'FILL' 1;color:<?= $checkoutColor ?>;">logout</span>
      </div>
      <p style="font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.08em;color:var(--on-surface-variant);margin:0 0 6px;">Check-Out</p>
      <?php if ($status['checkout']): ?>
        <span class="status-enabled"><span class="material-symbols-outlined" style="font-size:1rem;">check_circle</span> ENABLED</span>
      <?php else: ?>
        <span class="status-disabled"><span class="material-symbols-outlined" style="font-size:1rem;">cancel</span> DISABLED</span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($status['end_time']): ?>
    <!-- Countdown Timer -->
    <div class="st-card" style="text-align:center;margin-bottom:24px;">
      <p class="st-label" style="margin-bottom:12px;">Session Countdown</p>
      <div class="progress-wrapper">
        <svg class="progress-ring" width="120" height="120">
          <circle class="progress-ring__background" stroke="var(--surface-container-high)" stroke-width="10" fill="transparent" r="50" cx="60" cy="60" />
          <circle class="progress-ring__circle" stroke="var(--primary)" stroke-width="10" fill="transparent" r="50" cx="60" cy="60" />
        </svg>
        <div id="countdown-timer" class="timer-text" style="color:var(--primary);"></div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Control Form -->
  <div class="st-card">
    <p style="font-weight:700;color:var(--on-surface);margin:0 0 16px;">
      <span class="material-symbols-outlined" style="vertical-align:middle;margin-right:6px;font-size:1.1rem;">settings_remote</span>Controls
    </p>
    <form method="POST" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
      <?php csrf_field(); ?>
      <input type="number" name="duration" placeholder="Duration (min)" min="1" style="max-width:160px;padding:10px 14px;">
      <button type="submit" name="enable_checkin" class="st-btn st-btn-primary">
        <span class="material-symbols-outlined" style="font-size:16px;">login</span> Enable Check-In
      </button>
      <button type="submit" name="enable_checkout" class="st-btn st-btn-secondary">
        <span class="material-symbols-outlined" style="font-size:16px;">logout</span> Enable Check-Out
      </button>
      <button type="submit" name="disable" class="st-btn st-btn-danger">
        <span class="material-symbols-outlined" style="font-size:16px;">power_settings_new</span> Disable All
      </button>
    </form>
  </div>
</div>

<script>
  const endTime = <?= isset($status['end_time']) ? $status['end_time'] : 'null' ?>;

  <?php if (!empty($errorMessage)): ?>
  window.adminAlert('Action failed', <?= json_encode($errorMessage) ?>, 'error');
  <?php endif; ?>

  function formatCountdown(seconds) {
    const m = Math.floor(seconds / 60).toString().padStart(2, '0');
    const s = (seconds % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
  }

  if (endTime !== null) {
    const timerEl = document.getElementById('countdown-timer');
    const circle = document.querySelector('.progress-ring__circle');
    if (circle) {
      const radius = circle.r.baseVal.value;
      const circumference = 2 * Math.PI * radius;

      circle.style.strokeDasharray = `${circumference} ${circumference}`;
      circle.style.strokeDashoffset = `${circumference}`;

      const total = endTime - Math.floor(Date.now() / 1000);

      const interval = setInterval(() => {
        const now = Math.floor(Date.now() / 1000);
        const remaining = endTime - now;

        if (remaining > 0) {
          timerEl.textContent = formatCountdown(remaining);
          const percent = remaining / total;
          const offset = circumference * (1 - percent);
          circle.style.strokeDashoffset = offset;
        } else {
          clearInterval(interval);
          timerEl.textContent = "00:00";

          fetch(window.location.href, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
              'X-CSRF-Token': window.ADMIN_CSRF_TOKEN || ''
            },
            body: 'disable=1&csrf_token=' + encodeURIComponent(window.ADMIN_CSRF_TOKEN || '')
          }).then(() => {
            Swal.fire({
              icon: 'info',
              title: 'Mode Disabled!',
              text: 'The attendance mode has been automatically disabled after the countdown.',
              confirmButtonColor: 'var(--primary)'
            }).then(() => {
              location.reload();
            });
          });
        }
      }, 1000);
    }
  }
</script>
