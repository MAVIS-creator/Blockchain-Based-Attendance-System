<?php
require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/admin/runtime_storage.php';
require_once __DIR__ . '/request_timing.php';
app_storage_init();
request_timing_start('index.php');
$statusFile = admin_storage_migrate_file('status.json', app_storage_file('status.json'));
$span = microtime(true);
$rawStatus = @file_get_contents($statusFile);
$status = $rawStatus !== false ? json_decode($rawStatus, true) : null;
$status = is_array($status) ? $status : [];
$normalizedStatus = [
  'checkin' => !empty($status['checkin']),
  'checkout' => !empty($status['checkout']),
  'end_time' => isset($status['end_time']) && is_numeric($status['end_time']) ? (int)$status['end_time'] : null,
];
$activeModeConfigured = $normalizedStatus['checkin'] || $normalizedStatus['checkout'];
$timerValid = $normalizedStatus['end_time'] !== null && $normalizedStatus['end_time'] > time();
if ($activeModeConfigured && !$timerValid) {
  $normalizedStatus = ['checkin' => false, 'checkout' => false, 'end_time' => null];
}
if (!$normalizedStatus['checkin'] && !$normalizedStatus['checkout']) {
  $normalizedStatus['end_time'] = null;
}
if (($status['checkin'] ?? null) !== $normalizedStatus['checkin'] ||
  ($status['checkout'] ?? null) !== $normalizedStatus['checkout'] ||
  (($status['end_time'] ?? null) !== $normalizedStatus['end_time'])
) {
  if (is_array(json_decode((string)$rawStatus, true))) {
    @file_put_contents($statusFile, json_encode($normalizedStatus, JSON_PRETTY_PRINT), LOCK_EX);
  }
}
$status = $normalizedStatus;
request_timing_span('load_status', $span);
$activeMode = $status["checkin"] ? "checkin" : ($status["checkout"] ? "checkout" : "");
if (!$activeMode) {
  header('Location: attendance_closed.php');
  exit;
}

// Read active course
$activeCourse = "General";
$activeFile = admin_course_storage_migrate_file('active_course.json');
$span = microtime(true);
if (file_exists($activeFile)) {
  $activeData = json_decode(file_get_contents($activeFile), true);
  if (is_array($activeData)) {
    $activeCourse = $activeData['course'] ?? "General";
  }
}
request_timing_span('load_active_course', $span);

// Announcement logic
$announcementFile = admin_storage_migrate_file('announcement.json');
$announcement = ['enabled' => false, 'message' => '', 'severity' => 'info', 'updated_at' => null];
$span = microtime(true);
if (file_exists($announcementFile)) {
  $json = json_decode(file_get_contents($announcementFile), true);
  if (is_array($json)) {
    $announcement['enabled'] = isset($json['enabled']) ? (bool)$json['enabled'] : false;
    $announcement['message'] = isset($json['message']) ? (string)$json['message'] : '';
    $sev = isset($json['severity']) ? (string)$json['severity'] : 'info';
    $announcement['severity'] = in_array($sev, ['info', 'warning', 'urgent'], true) ? $sev : 'info';
    $announcement['updated_at'] = isset($json['updated_at']) ? $json['updated_at'] : null;
  }
}
request_timing_span('load_announcement', $span);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Attendance Portal</title>
  <link rel="icon" type="image/svg+xml" href="asset/attendance-favicon.svg">
  <link rel="icon" type="image/x-icon" href="asset/favicon.ico">
  <link rel="apple-touch-icon" sizes="180x180" href="asset/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="asset/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="asset/favicon-16x16.png">
  <link rel="manifest" href="asset/site.webmanifest">
  <style>
    :root {
      --bg-top: #f4f7fb;
      --bg-bottom: #edf2f7;
      --panel: #ffffff;
      --text: #10233a;
      --muted: #5f6d7d;
      --line: #d8e1eb;
      --primary: #1f5d99;
      --primary-2: #3b7db6;
      --accent-red: #1f5d99;
      --info-bg: #eef6ff;
      --info-line: #cfe1f5;
      --info-text: #1d4f80;
      --success: #1e8e6a;
      --announce-info-bg: #eef6ff;
      --announce-info-line: #cfe1f5;
      --announce-info-text: #1d4f80;
      --announce-warning-bg: #fff8e8;
      --announce-warning-line: #f5dfad;
      --announce-warning-text: #8a5a00;
      --announce-urgent-bg: #ffeef0;
      --announce-urgent-line: #f5c2c8;
      --announce-urgent-text: #9f1d2c;
      --shadow: 0 18px 40px rgba(24, 39, 75, 0.08);
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      font-family: "Trebuchet MS", "Segoe UI", sans-serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      color: var(--text);
      background:
        radial-gradient(circle at 12% 16%, rgba(59, 125, 182, 0.22), transparent 26%),
        radial-gradient(circle at 88% 82%, rgba(30, 142, 106, 0.16), transparent 24%),
        linear-gradient(180deg, var(--bg-top), var(--bg-bottom));
      padding: 86px 20px 20px;
    }

    a {
      text-decoration: none;
      color: inherit;
    }

    .container {
      width: 100%;
      max-width: 500px;
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 18px;
      box-shadow: var(--shadow);
      padding: 24px;
      margin-top: 46px;
      animation: rise-in 0.5s ease;
    }

    @keyframes rise-in {
      from {
        opacity: 0;
        transform: translateY(12px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 16px;
    }

    .brand img {
      height: 50px;
      width: 50px;
      border-radius: 12px;
      object-fit: contain;
      background: #f7fbff;
      border: 1px solid var(--line);
      padding: 6px;
    }

    .brand h2 {
      margin: 0;
      font-size: 1.02rem;
      color: var(--text);
      letter-spacing: 0.15px;
    }

    .alert-bar {
      position: sticky;
      top: 0;
      width: 100%;
      background: #eef4ff;
      color: #1e3a8a;
      padding: 12px 20px;
      display: none;
      justify-content: space-between;
      align-items: flex-start;
      border-left: 4px solid #3b82f6;
      border-bottom: 1px solid #dbeafe;
      font-family: system-ui, sans-serif;
      z-index: 999;
      box-shadow: 0 6px 14px rgba(30, 58, 138, 0.1);
      animation: slideDown 0.4s ease;
    }

    @keyframes slideDown {
      from {
        transform: translateY(-100%);
        opacity: 0;
      }

      to {
        transform: translateY(0);
        opacity: 1;
      }
    }

    .alert-info {
      background: #eef4ff;
      border-left-color: #3b82f6;
      color: #1e3a8a;
    }

    .alert-warning {
      background: #fff8e8;
      border-left-color: #f59e0b;
      color: #8a5a00;
    }

    .alert-error {
      background: #fef2f2;
      border-left-color: #ef4444;
      color: #7f1d1d;
    }

    .alert-success {
      background: #ecfdf5;
      border-left-color: #10b981;
      color: #065f46;
    }

    .alert-body {
      min-width: 0;
      flex: 1;
      display: grid;
      gap: 3px;
      text-align: left;
    }

    .alert-heading {
      font-size: 0.86rem;
      letter-spacing: 0.035em;
      text-transform: uppercase;
    }

    .alert-message {
      font-size: 0.95rem;
      line-height: 1.35;
      color: inherit;
      overflow-wrap: anywhere;
    }

    .alert-meta {
      opacity: 0.8;
      font-size: 0.78rem;
    }

    .alert-bar button {
      background: transparent;
      border: none;
      font-size: 18px;
      cursor: pointer;
      color: inherit;
      opacity: 0.6;
      line-height: 1;
      padding: 0 4px;
      margin-left: 10px;
      transition: opacity 0.2s ease;
    }

    .alert-bar button:hover {
      opacity: 1;
    }

    .announcement-toast {
      position: fixed;
      top: 18px;
      right: 18px;
      z-index: 9999;
      background: #1f5d99;
      color: #fff;
      border-radius: 10px;
      padding: 10px 12px;
      box-shadow: 0 10px 24px rgba(24, 39, 75, 0.25);
      font-size: 0.9rem;
      opacity: 0;
      transform: translateY(-6px);
      transition: all 0.2s ease;
      pointer-events: none;
    }

    .announcement-toast.show {
      opacity: 1;
      transform: translateY(0);
    }

    .page-footer {
      margin: 3.15rem auto 0.25rem;
      width: 100%;
      border-top: 1px solid rgba(95, 109, 125, 0.16);
      padding: 1rem 0 0.25rem;
      text-align: center;
      color: #3f4d5d;
      font-size: 0.82rem;
      margin-top: auto;
    }

    .page-footer-pill {
      display: inline-flex;
      flex-direction: column;
      gap: 4px;
      padding: 0.42rem 0.9rem;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.78);
      border: 1px solid rgba(216, 225, 235, 0.9);
      backdrop-filter: blur(6px);
      box-shadow: 0 8px 18px rgba(24, 39, 75, 0.07);
    }

    .page-footer-title {
      font-weight: 800;
      letter-spacing: 0.02em;
      text-transform: uppercase;
      font-size: 0.74rem;
      color: var(--primary);
    }

    .page-footer-subtitle {
      font-size: 0.75rem;
      color: #46566a;
    }

    .form h2 {
      color: var(--muted);
      text-align: center;
      margin-bottom: 14px;
      font-size: 1.02rem;
      font-weight: 600;
    }

    .input-group {
      margin-bottom: 16px;
    }

    .input-group label {
      color: var(--muted);
      display: block;
      margin-bottom: 6px;
      font-size: 0.89rem;
    }

    .input-group input {
      width: 100%;
      padding: 11px 12px;
      border: 1px solid var(--line);
      background: #fff;
      color: var(--text);
      border-radius: 10px;
      transition: border-color 0.18s ease, box-shadow 0.18s ease;
    }

    .input-group textarea {
      width: 100%;
      padding: 11px 12px;
      border: 1px solid var(--line);
      background: #fff;
      color: var(--text);
      border-radius: 10px;
      min-height: 84px;
      resize: vertical;
      transition: border-color 0.18s ease, box-shadow 0.18s ease;
    }

    .input-group input:focus {
      outline: none;
      border-color: var(--primary-2);
      box-shadow: 0 0 0 3px rgba(59, 125, 182, 0.16);
    }

    .input-group textarea:focus {
      outline: none;
      border-color: var(--primary-2);
      box-shadow: 0 0 0 3px rgba(59, 125, 182, 0.16);
    }

    .btn {
      width: 100%;
      padding: 12px;
      border: none;
      color: #fff;
      font-weight: 700;
      border-radius: 10px;
      cursor: pointer;
      font-size: 0.98rem;
      transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.2s ease;
    }

    .btn-primary {
      background: linear-gradient(90deg, var(--primary), var(--primary-2));
      box-shadow: 0 8px 20px rgba(31, 93, 153, 0.25);
    }

    .btn-accent {
      background: #f4f8fc;
      color: var(--primary);
      border: 1px solid var(--line);
      font-size: 0.9rem;
      padding: 8px 12px;
      border-radius: 8px;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      width: auto;
    }

    .btn:hover {
      transform: translateY(-1px);
    }

    .btn:disabled {
      opacity: 0.7;
      cursor: not-allowed;
      transform: none;
    }

    .support-row {
      margin: 12px 0 0;
      color: var(--muted);
      font-size: 0.9rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }

    .swal2-popup {
      border-radius: 12px;
    }

    .swal2-title {
      color: var(--text);
      font-weight: 700;
    }

    .swal2-confirm {
      background: linear-gradient(90deg, var(--primary), var(--primary-2)) !important;
    }

    @media (max-width: 520px) {
      body {
        padding: 74px 14px 14px;
      }

      .alert-bar {
        padding: 12px 12px 10px;
        gap: 10px;
        align-items: flex-start;
        border-left-width: 0;
        border-bottom: 1px solid #dbeafe;
        border-radius: 0 0 12px 12px;
      }

      .alert-body {
        gap: 4px;
      }

      .alert-heading {
        font-size: 0.8rem;
      }

      .alert-meta {
        font-size: 0.74rem;
      }

      .alert-bar button {
        width: 32px;
        height: 32px;
        min-width: 32px;
        border-radius: 999px;
        margin-left: 0;
        padding: 0;
        font-size: 20px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, 0.45);
      }

      .container {
        padding: 18px;
        border-radius: 14px;
        margin-top: 36px;
      }

      .brand {
        align-items: flex-start;
      }

      .brand h2 {
        font-size: 0.95rem;
      }

      .support-row {
        align-items: flex-start;
      }

      .alert-message {
        font-size: 0.9rem;
      }

      .page-footer {
        margin-top: 2.35rem;
      }
    }
  </style>
  <link rel="stylesheet" href="admin/boxicons.min.css">
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body>

  <!-- Hybrid Announcement Model: Static Top Alert + Toast on Updates -->
  <div id="announcementBanner" class="alert-bar alert-info" style="display:none;">
    <div class="alert-body">
      <strong id="announcementBannerTitle" class="alert-heading">ℹ️ INFORMATION</strong>
      <div id="announcementBannerText" class="alert-message"><?= htmlspecialchars(trim((string)($announcement['message'] ?? '')) !== '' ? (string)$announcement['message'] : 'An important announcement is currently active.') ?></div>
      <small id="announcementBannerMeta" class="alert-meta">System update • Just now</small>
    </div>
    <button type="button" id="announcementBannerDismiss" aria-label="Dismiss announcement">×</button>
  </div>

  <div class="container">
    <div class="brand">
      <img src="asset/attendance-mark.svg" alt="Attendance Mark">
      <h2>Attendance Portal - <?= htmlspecialchars($activeCourse) ?></h2>
    </div>
    <form class="form" id="attendanceForm">
      <h2>Attendance (<?= ucfirst($activeMode) ?>)</h2>

      <div class="input-group">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" placeholder="Name" required />
      </div>

      <div class="input-group">
        <label for="matric">Matric Number</label>
        <input type="text" id="matric" name="matric" minlength="1" maxlength="10" placeholder="0000000000" required />
      </div>

      <input type="hidden" id="fingerprint" name="fingerprint">
      <input type="hidden" name="action" value="<?= $activeMode ?>">
      <input type="hidden" name="course" value="<?= htmlspecialchars($activeCourse) ?>">

      <button id="submitBtn" class="btn btn-primary" type="submit" disabled>Submit Attendance</button>
      <p class="support-row">
        Need help? If attendance fails, open Support with your course + failed action. AI sends device-specific updates to your fingerprinted session.
        <a href="support.php" class="btn btn-accent"><i class='bx bx-message'></i> Support</a>
      </p>
    </form>
  </div>

  <!-- Public Footer -->
  <footer class="page-footer">
    <div class="page-footer-pill">
      <div class="page-footer-title">Created by CYB 204 Group 5</div>
      <div class="page-footer-subtitle">(Headed by Maximus and Mavis)</div>
    </div>
  </footer>

  <script src="./js/fp.min.js"></script>
  <script>
    const submitBtn = document.getElementById('submitBtn');
    const fingerprintInput = document.getElementById('fingerprint');
    const announcementBanner = document.getElementById('announcementBanner');
    const announcementBannerDismiss = document.getElementById('announcementBannerDismiss');
    const announcementBannerText = document.getElementById('announcementBannerText');
    const announcementBannerTitle = document.getElementById('announcementBannerTitle');
    const announcementBannerMeta = document.getElementById('announcementBannerMeta');

    let inactivityTimer;
    let fencingActive = true;
    const TAB_AWAY_GRACE_MS = 6 * 1000;
    const FENCING_BLOCK_MS = 15 * 60 * 1000;
    const TAB_AWAY_MAX_STRIKES = 3;
    const TAB_AWAY_STRIKES_KEY = 'attendanceTabAwayStrikes';
    const TAB_AWAY_LOCK_UNTIL_KEY = 'attendanceTabAwayLockUntil';
    const ANNOUNCEMENT_DISMISS_PREFIX = 'announcementDismissed:';
    const ANNOUNCEMENT_ALERT_COOLDOWN_MS = 30 * 1000;

    document.addEventListener('DOMContentLoaded', () => {
      const lockUntil = parseInt(localStorage.getItem(TAB_AWAY_LOCK_UNTIL_KEY) || '0', 10);
      const now = Date.now();
      if (lockUntil > now) {
        const remainingSec = Math.max(1, Math.ceil((lockUntil - now) / 1000));
        Swal.fire({
          icon: 'warning',
          title: 'Temporarily Locked',
          text: `Too many tab-away violations. Please wait ${remainingSec}s before trying again.`,
          confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red')
        }).then(() => {
          window.location.href = 'closed.php';
        });
        return;
      }
      if (lockUntil > 0 && lockUntil <= now) {
        localStorage.removeItem(TAB_AWAY_LOCK_UNTIL_KEY);
        localStorage.setItem(TAB_AWAY_STRIKES_KEY, '0');
      }
      // Poll revoked tokens list and clear local token immediately when revoked
      (function() {
        try {
          var stored = localStorage.getItem('attendance_token');
          if (!stored) return;
          // Try SSE first
          if (typeof(EventSource) !== 'undefined') {
            try {
              var src = new EventSource('admin/revoke_sse.php');
              src.addEventListener('revoked', function(e) {
                try {
                  var payload = JSON.parse(e.data);
                  if (payload && payload.revoked && payload.revoked.tokens && payload.revoked.tokens[stored]) {
                    localStorage.removeItem('attendance_token');
                    localStorage.removeItem('attendanceBlocked');
                    try {
                      Swal.fire({
                        icon: 'info',
                        title: 'Token Revoked',
                        text: 'Your attendance token was revoked by an administrator. The page will reload.',
                        confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red')
                      }).then(function() {
                        location.reload();
                      });
                    } catch (e) {
                      location.reload();
                    }
                    src.close();
                  }
                } catch (ignore) {}
              });
            } catch (e) {
              // fallback to polling below
            }
          }

          // Poll fallback (in case SSE not available or fails)
          var attempts = 0;
          var maxAttempts = 120; // stop after ~10 minutes
          var poll = setInterval(function() {
            attempts++;
            fetch('admin/revoked_tokens.php', {
              cache: 'no-store'
            }).then(function(r) {
              if (!r.ok) return null;
              return r.json();
            }).then(function(data) {
              if (!data || !data.revoked) return;
              var tokensObj = data.revoked.tokens || {};
              if (tokensObj[stored] || (Array.isArray(tokensObj) && tokensObj.indexOf(stored) !== -1)) {
                localStorage.removeItem('attendance_token');
                localStorage.removeItem('attendanceBlocked');
                console.info('Local attendance token revoked and cleared');
                try {
                  Swal.fire({
                    icon: 'info',
                    title: 'Token Revoked',
                    text: 'Your attendance token was revoked by an administrator. The page will reload.',
                    confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red')
                  }).then(function() {
                    location.reload();
                  });
                } catch (e) {
                  location.reload();
                }
                clearInterval(poll);
              }
            }).catch(function() {
              /* ignore */
            });
            if (attempts >= maxAttempts) clearInterval(poll);
          }, 5000);
        } catch (e) {}
      })();
    });

    // Load fingerprint
    FingerprintJS.load().then(fp => {
      fp.get().then(result => {
        const visitorId = result.visitorId;
        let token = localStorage.getItem('attendance_token');

        if (!token) {
          token = crypto.randomUUID();
          localStorage.setItem('attendance_token', token);
        }

        fingerprintInput.value = visitorId + "_" + token;
        submitBtn.disabled = false;
        fetchAnnouncement();
      }).catch(err => {
        console.error('Fingerprint error:', err);
        Swal.fire({
          icon: 'error',
          title: 'Fingerprint Error',
          text: 'Fingerprint could not be generated. Please try again.',
          confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red')
        });
      });
    });

    document.getElementById('attendanceForm').addEventListener('submit', function(e) {
      e.preventDefault();
      const form = this;
      const formData = new FormData(this);

      // Try to get geolocation (will be sent if available). Timeout after 5s.
      function getLocation(timeout = 5000) {
        return new Promise((resolve) => {
          if (!navigator.geolocation) return resolve(null);
          let settled = false;
          const timer = setTimeout(() => {
            if (!settled) {
              settled = true;
              resolve(null);
            }
          }, timeout);
          navigator.geolocation.getCurrentPosition(function(pos) {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            resolve({
              lat: pos.coords.latitude,
              lng: pos.coords.longitude
            });
          }, function() {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            resolve(null);
          }, {
            maximumAge: 60000,
            timeout: timeout
          });
        });
      }

      getLocation(5000).then(loc => {
        if (loc) {
          formData.append('lat', loc.lat);
          formData.append('lng', loc.lng);
        }

        fetch('submit.php', {
            method: 'POST',
            body: formData
          })
          .then(res => res.json())
          .then(json => {
            if (!json || !json.ok) {
              Swal.fire({
                icon: 'error',
                title: 'Submission Failed',
                text: (json && json.message) || 'Submission failed',
                confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red')
              });
              return;
            }
            Swal.fire({
              icon: 'success',
              title: 'Success',
              text: json.message,
              timer: 2200,
              showConfirmButton: false,
              background: '#fff'
            });
            form.reset();
            submitBtn.disabled = true;

            fencingActive = false;
            clearTimeout(inactivityTimer);

            FingerprintJS.load().then(fp => {
              fp.get().then(result => {
                let token = localStorage.getItem('attendance_token');
                if (!token) {
                  token = crypto.randomUUID();
                  localStorage.setItem('attendance_token', token);
                }
                fingerprintInput.value = result.visitorId + "_" + token;
                submitBtn.disabled = false;
              });
            });
          })
          .catch(err => {
            console.error(err);
            Swal.fire({
              icon: 'error',
              title: 'Error',
              text: 'Error occurred. Please try again.',
              confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red')
            });
          });
      });
    });

    // popup removed: using SweetAlert2 for user messages

    // Inactivity fencing
    function getSeverityMeta(severity) {
      if (severity === 'urgent') {
        return {
          title: '🚨 URGENT ALERT',
          toastLabel: 'Urgent',
          cssClass: 'alert-error'
        };
      }
      if (severity === 'warning') {
        return {
          title: '⚠️ WARNING',
          toastLabel: 'Warning',
          cssClass: 'alert-warning'
        };
      }
      return {
        title: 'ℹ️ INFORMATION',
        toastLabel: 'Information',
        cssClass: 'alert-info'
      };
    }

    function extractMatricNumber(message) {
      const match = String(message || '').match(/\b\d{6,}\b/);
      return match ? match[0] : null;
    }

    function formatRelativeTimestamp(updatedAt) {
      if (!updatedAt) return 'Just now';
      const d = new Date(updatedAt);
      if (Number.isNaN(d.getTime())) return 'Just now';
      const diff = Math.floor((Date.now() - d.getTime()) / 1000);
      if (diff < 45) return 'Just now';
      if (diff < 3600) return `${Math.floor(diff / 60)} min ago`;
      if (diff < 86400) return `${Math.floor(diff / 3600)} hr ago`;
      return `${Math.floor(diff / 86400)} day(s) ago`;
    }

    function normalizeAnnouncementMessage(message) {
      const text = String(message || '').trim();
      if (!text) return 'An important announcement is currently active.';
      return text
        .replace(/\b\d{6,}\b/g, '')
        .replace(/\b(issue\s+has\s+been\s+resolved)\b/gi, 'issue resolved successfully')
        .replace(/\s+/g, ' ')
        .replace(/^[-:;,\s]+|[-:;,\s]+$/g, '') || text;
    }

    function clearDismissFor(signature) {
      try {
        localStorage.removeItem(ANNOUNCEMENT_DISMISS_PREFIX + signature);
      } catch (e) {}
    }

    function isDismissed(signature) {
      try {
        return localStorage.getItem(ANNOUNCEMENT_DISMISS_PREFIX + signature) === '1';
      } catch (e) {
        return false;
      }
    }

    function setDismissed(signature) {
      try {
        localStorage.setItem(ANNOUNCEMENT_DISMISS_PREFIX + signature, '1');
      } catch (e) {}
    }

    function startInactivityTimer() {
      inactivityTimer = setTimeout(() => {
        const currentStrikes = parseInt(localStorage.getItem(TAB_AWAY_STRIKES_KEY) || '0', 10) + 1;
        localStorage.setItem(TAB_AWAY_STRIKES_KEY, String(currentStrikes));
        const strikesLeft = Math.max(0, TAB_AWAY_MAX_STRIKES - currentStrikes);
        const shouldLock = currentStrikes >= TAB_AWAY_MAX_STRIKES;
        if (shouldLock) {
          localStorage.setItem(TAB_AWAY_LOCK_UNTIL_KEY, String(Date.now() + FENCING_BLOCK_MS));
        }

        // include client token and fingerprint when logging inactivity so admins can revoke/clear device-side tokens
        var tokenToSend = localStorage.getItem('attendance_token') || '';
        var fpValue = document.getElementById('fingerprint') ? document.getElementById('fingerprint').value : '';
        fetch('log_inactivity.php', {
          method: 'POST',
          body: new URLSearchParams({
            reason: shouldLock ? 'Tab-away limit reached (locked 15m)' : `Tab away beyond 6s grace (${currentStrikes}/${TAB_AWAY_MAX_STRIKES})`,
            token: tokenToSend,
            fingerprint: fpValue
          })
        }).finally(() => {
          Swal.fire({
            icon: 'warning',
            title: shouldLock ? 'Session Locked' : 'Tab Away Warning',
            text: shouldLock ?
              'You used all 3 grace periods. This session is now locked for 15 minutes.' : `You were away for more than 6 seconds. Grace used: ${currentStrikes}/3. Remaining: ${strikesLeft}.`,
            confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red')
          }).then(function() {
            window.location.href = 'closed.php';
          });
        });
      }, TAB_AWAY_GRACE_MS);
    }

    document.addEventListener('visibilitychange', () => {
      if (!fencingActive) return;

      if (document.hidden) {
        startInactivityTimer();
      } else {
        clearTimeout(inactivityTimer);
      }
    });

    // Announcement refresh + change notification
    let announcementInitialized = false;
    let lastAnnouncementNotificationAt = 0;
    let lastAnnouncementSignature = JSON.stringify({
      enabled: <?= !empty($announcement['enabled']) ? 'true' : 'false' ?>,
      message: <?= json_encode((string)($announcement['message'] ?? '')) ?>,
      severity: <?= json_encode((string)($announcement['severity'] ?? 'info')) ?>,
      updated_at: <?= json_encode($announcement['updated_at'] ?? null) ?>
    });

    function playAnnouncementBeep() {
      try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = 880;
        gain.gain.value = 0.0001;
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        gain.gain.exponentialRampToValueAtTime(0.12, ctx.currentTime + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.28);
        osc.stop(ctx.currentTime + 0.3);
      } catch (e) {
        // Non-fatal if autoplay/audio is blocked by browser policy.
      }
    }

    function showAnnouncementChangedNotice(severityLabel) {
      const toast = document.createElement('div');
      toast.className = 'announcement-toast';
      toast.textContent = `🔔 ${severityLabel} announcement updated`;
      document.body.appendChild(toast);
      requestAnimationFrame(() => toast.classList.add('show'));
      setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 220);
      }, 5200);
    }

    if (announcementBannerDismiss) {
      announcementBannerDismiss.addEventListener('click', () => {
        if (!lastAnnouncementSignature) return;
        setDismissed(lastAnnouncementSignature);
        if (announcementBanner) {
          announcementBanner.style.display = 'none';
        }
      });
    }

    function fetchAnnouncement() {
      const fpForAnnouncement = (fingerprintInput && fingerprintInput.value) ? fingerprintInput.value : '';
      const annUrl = fpForAnnouncement ?
        `get_announcement.php?fingerprint=${encodeURIComponent(fpForAnnouncement)}` :
        'get_announcement.php';

      fetch(annUrl, {
          cache: 'no-store'
        })
        .then(res => res.json())
        .then(data => {
          const enabled = !!(data && data.enabled);
          const message = (data && data.message ? String(data.message) : '').trim();
          const severity = (data && data.severity ? String(data.severity) : 'info').toLowerCase();
          const normalizedSeverity = ['info', 'warning', 'urgent'].includes(severity) ? severity : 'info';
          const updatedAt = data && data.updated_at ? String(data.updated_at) : '';
          const signature = JSON.stringify({
            enabled,
            message,
            severity: normalizedSeverity,
            updated_at: updatedAt
          });
          const meta = getSeverityMeta(normalizedSeverity);
          const normalizedMessage = normalizeAnnouncementMessage(message);
          const matric = extractMatricNumber(message);
          const relativeUpdatedAt = formatRelativeTimestamp(updatedAt);

          // Update top alert styling/content (Final Hybrid Model)
          announcementBanner.classList.remove('alert-info', 'alert-warning', 'alert-error', 'alert-success');
          announcementBanner.classList.add(meta.cssClass);

          if (announcementBannerTitle) {
            announcementBannerTitle.textContent = meta.title;
          }
          announcementBannerText.textContent = normalizedMessage;
          announcementBannerMeta.textContent = matric ? `Matric No: ${matric} • ${relativeUpdatedAt}` : `System update • ${relativeUpdatedAt}`;

          // Static top alert bar (remains visible while active)
          if (enabled && !isDismissed(signature)) {
            announcementBanner.style.display = 'flex';
          } else {
            announcementBanner.style.display = 'none';
          }

          // Toast notification on change (with cooldown)
          if (announcementInitialized && signature !== lastAnnouncementSignature) {
            clearDismissFor(signature);
            const now = Date.now();
            if ((now - lastAnnouncementNotificationAt) >= ANNOUNCEMENT_ALERT_COOLDOWN_MS) {
              showAnnouncementChangedNotice(meta.toastLabel);
              playAnnouncementBeep();
              lastAnnouncementNotificationAt = now;
            }
          }
          lastAnnouncementSignature = signature;
          announcementInitialized = true;
        })
        .catch(err => {
          console.error("Announcement fetch error:", err);
        });
    }

    fetchAnnouncement();
    setInterval(fetchAnnouncement, 10000);

    // Status auto-refresh
    function checkStatus() {
      fetch('status_api.php')
        .then(res => res.json())
        .then(data => {
          if (!data.checkin && !data.checkout) {
            Swal.fire({
              icon: 'info',
              title: 'Attendance Closed',
              text: 'Attendance has now closed!',
              confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red')
            }).then(function() {
              location.reload();
            });
          }
        })
        .catch(err => {
          console.error("Error checking status:", err);
        });
    }

    setInterval(checkStatus, 5000);
  </script>
</body>

</html>
