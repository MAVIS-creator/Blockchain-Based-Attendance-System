<?php
session_start();

require_once __DIR__ . '/admin/runtime_storage.php';
$announcementFile = admin_storage_migrate_file('announcement.json');
$announcement = ['enabled' => false, 'message' => '', 'severity' => 'info', 'updated_at' => null];
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="admin/boxicons.min.css">
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
  <link rel="icon" type="image/svg+xml" href="asset/attendance-favicon.svg">
  <link rel="icon" type="image/x-icon" href="asset/favicon.ico">
  <link rel="apple-touch-icon" sizes="180x180" href="asset/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="asset/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="asset/favicon-16x16.png">
  <link rel="manifest" href="asset/site.webmanifest">
  <title>Attendance Closed</title>
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
      --soft: #f4f8fc;
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
        radial-gradient(circle at 12% 14%, rgba(59, 125, 182, 0.22), transparent 26%),
        radial-gradient(circle at 88% 82%, rgba(30, 142, 106, 0.14), transparent 24%),
        linear-gradient(180deg, var(--bg-top), var(--bg-bottom));
      padding: 86px 20px 20px;
    }

    .card {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 18px;
      box-shadow: var(--shadow);
      text-align: center;
      max-width: 720px;
      width: 100%;
      padding: 28px;
      margin-top: 58px;
      animation: rise-in 0.45s ease;
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

    @keyframes rise-in {
      from {
        opacity: 0;
        transform: translateY(10px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .logo {
      height: 62px;
      width: 62px;
      margin-bottom: 12px;
      border-radius: 14px;
      object-fit: contain;
      background: #f7fbff;
      border: 1px solid var(--line);
      padding: 8px;
    }

    h1 {
      margin: 0 0 8px;
      font-size: 1.58rem;
    }

    p {
      color: var(--muted);
      margin: 0 auto 18px;
      line-height: 1.55;
      max-width: 560px;
    }

    .actions {
      display: flex;
      gap: 12px;
      justify-content: center;
      flex-wrap: wrap;
    }

    .btn {
      min-width: 150px;
      padding: 11px 18px;
      border-radius: 10px;
      font-weight: 700;
      border: none;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .btn-accent {
      background: var(--soft);
      color: var(--primary);
      border: 1px solid var(--line);
    }

    .btn-primary {
      background: linear-gradient(90deg, var(--primary), var(--primary-2));
      color: #fff;
      box-shadow: 0 8px 20px rgba(31, 93, 153, 0.25);
    }

    .btn:hover {
      transform: translateY(-1px);
    }

    @media (max-width: 480px) {
      body {
        padding: 74px 14px 14px;
      }

      .card {
        padding: 20px;
        border-radius: 14px;
        margin-top: 48px;
      }

      h1 {
        font-size: 1.28rem;
      }

      p {
        font-size: 0.95rem;
      }

      .logo {
        height: 54px;
        width: 54px;
      }

      .btn {
        min-width: 130px;
        padding: 9px 16px;
        font-size: 0.9rem;
      }

      .alert-bar {
        padding: 10px 12px;
      }

      .alert-message {
        font-size: 0.9rem;
      }

      .page-footer {
        margin-top: 2.35rem;
      }
    }

    @media (max-width: 360px) {
      .actions {
        flex-direction: column;
        gap: 8px;
      }

      .btn {
        width: 100%;
      }
    }
  </style>
</head>

<body>
  <!-- Hybrid Announcement Model: Static Top Alert + Toast on Updates -->
  <div id="announcementBanner" class="alert-bar alert-info" style="<?= !empty($announcement['enabled']) ? 'display:flex;' : 'display:none;' ?>">
    <div class="alert-body">
      <strong id="announcementBannerTitle" class="alert-heading">ℹ️ INFORMATION</strong>
      <div id="announcementBannerText" class="alert-message"><?= htmlspecialchars($announcement['message'] !== '' ? $announcement['message'] : 'An important announcement is currently active.') ?></div>
      <small id="announcementBannerMeta" class="alert-meta">System update • Just now</small>
    </div>
    <button type="button" id="announcementBannerDismiss" aria-label="Dismiss announcement">×</button>
  </div>

  <div class="card">
    <img class="logo" src="asset/attendance-mark.svg" alt="Attendance Mark">
    <h1>Attendance Closed</h1>
    <p>Your session was interrupted due to inactivity or tab changes. Attendance is now closed for fairness and exam integrity.</p>
    <div class="actions">
      <a class="btn btn-accent" href="index.php"><i class='bx bx-home'></i> Return Home</a>
      <a class="btn btn-primary" href="support.php"><i class='bx bx-message'></i> Contact Support</a>
    </div>
  </div>

  <footer class="page-footer">
    <div class="page-footer-pill">
      <div class="page-footer-title">Created for Cyber Security Department</div>
    </div>
  </footer>

  <script>
    const announcementBanner = document.getElementById('announcementBanner');
    const announcementBannerDismiss = document.getElementById('announcementBannerDismiss');
    const announcementBannerText = document.getElementById('announcementBannerText');
    const announcementBannerTitle = document.getElementById('announcementBannerTitle');
    const announcementBannerMeta = document.getElementById('announcementBannerMeta');
    let announcementInitialized = false;
    let lastAnnouncementNotificationAt = 0;
    const ANNOUNCEMENT_DISMISS_PREFIX = 'announcementDismissed:';
    const ANNOUNCEMENT_ALERT_COOLDOWN_MS = 30 * 1000;
    let lastAnnouncementSignature = JSON.stringify({
      enabled: <?= !empty($announcement['enabled']) ? 'true' : 'false' ?>,
      message: <?= json_encode((string)$announcement['message']) ?>,
      severity: <?= json_encode((string)($announcement['severity'] ?? 'info')) ?>,
      updated_at: <?= json_encode($announcement['updated_at'] ?? null) ?>
    });

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
        // ignore audio restrictions
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
      fetch('get_announcement.php', {
          cache: 'no-store'
        })
        .then(res => res.json())
        .then(data => {
          const enabled = !!(data && data.enabled);
          const msg = (data && data.message ? String(data.message) : '').trim();
          const severity = (data && data.severity ? String(data.severity) : 'info').toLowerCase();
          const normalizedSeverity = ['info', 'warning', 'urgent'].includes(severity) ? severity : 'info';
          const updatedAt = data && data.updated_at ? String(data.updated_at) : '';
          const signature = JSON.stringify({
            enabled,
            message: msg,
            severity: normalizedSeverity,
            updated_at: updatedAt
          });
          const meta = getSeverityMeta(normalizedSeverity);
          const normalizedMessage = normalizeAnnouncementMessage(msg);
          const matric = extractMatricNumber(msg);
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
        .catch(() => {
          // keep current state quietly on fetch errors
        });
    }

    fetchAnnouncement();
    setInterval(fetchAnnouncement, 10000);

    // Auto-recover: when attendance is reopened (or token state is changed),
    // send user back to index instead of keeping them stuck on closed page.
    async function autoReturnToAttendanceIfAllowed() {
      try {
        const statusRes = await fetch('status_api.php', {
          cache: 'no-store'
        });
        if (!statusRes.ok) return;

        const status = await statusRes.json();
        const isOpen = !!(status && (status.checkin || status.checkout));
        if (!isOpen) return;

        let tokenRevoked = false;
        const token = localStorage.getItem('attendance_token') || '';
        if (token) {
          try {
            const revokedRes = await fetch('admin/revoked_tokens.php', {
              cache: 'no-store'
            });
            if (revokedRes.ok) {
              const revokedData = await revokedRes.json();
              const revokedTokens = (revokedData && revokedData.revoked && revokedData.revoked.tokens) ? revokedData.revoked.tokens : {};
              tokenRevoked = !!(revokedTokens && revokedTokens[token]);
            }
          } catch (e) {
            // If revoke endpoint is temporarily unavailable, continue with status-based recovery.
          }
        }

        // Clear local blockers so user can resume once class is reopened.
        localStorage.removeItem('attendanceBlocked');
        localStorage.removeItem('attendanceTabAwayStrikes');
        localStorage.removeItem('attendanceTabAwayLockUntil');

        // If token is revoked or has been cleared by admin workflow, drop stale local token too.
        if (tokenRevoked) {
          localStorage.removeItem('attendance_token');
        }

        window.location.href = 'index.php';
      } catch (e) {
        // no-op
      }
    }

    autoReturnToAttendanceIfAllowed();
    setInterval(autoReturnToAttendanceIfAllowed, 5000);
  </script>
</body>

</html>
