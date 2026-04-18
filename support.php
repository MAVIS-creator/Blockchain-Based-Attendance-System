<?php
date_default_timezone_set('Africa/Lagos');
require_once __DIR__ . '/hybrid_dual_write.php';
require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/admin/runtime_storage.php';
require_once __DIR__ . '/admin/cache_helpers.php';
require_once __DIR__ . '/request_timing.php';
require_once __DIR__ . '/request_guard.php';
require_once __DIR__ . '/src/AiTicketAutomationEngine.php';
app_storage_init();
app_request_guard('support.php', 'public');
request_timing_start('support.php');

$ticketsFile = admin_storage_migrate_file('support_tickets.json', app_storage_file('support_tickets.json'));

if (!file_exists($ticketsFile)) {
  file_put_contents($ticketsFile, json_encode([]), LOCK_EX);
}

function append_support_ticket_atomic($ticketsFile, $ticket)
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

  $tickets[] = $ticket;
  $payload = json_encode($tickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

  rewind($fp);
  ftruncate($fp, 0);
  fwrite($fp, $payload);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  return true;
}

function support_run_ai_for_ticket(array $ticket)
{
  try {
    $engine = new AiTicketAutomationEngine();
    return $engine->processTicket($ticket);
  } catch (\Throwable $e) {
    return ['ok' => false, 'error' => 'ai_ticket_processing_failed', 'message' => $e->getMessage()];
  }
}

$success = false;
$formError = '';

$courseFile = admin_course_storage_migrate_file('course.json');
$courseRows = [];
if (file_exists($courseFile)) {
  $decodedCourses = admin_cached_json_file('support_courses', $courseFile, [], 15);
  if (is_array($decodedCourses)) {
    $courseRows = $decodedCourses;
  }
}

$courseOptions = [];
foreach ($courseRows as $courseRow) {
  $courseName = trim((string)$courseRow);
  if ($courseName === '') continue;
  if (!in_array($courseName, $courseOptions, true)) {
    $courseOptions[] = $courseName;
  }
}
if (empty($courseOptions)) {
  $courseOptions = ['General'];
}

$selectedCourseForm = trim((string)($_POST['course'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $submitSpan = microtime(true);
  $name = trim($_POST['name'] ?? '');
  $matric = preg_replace('/\D+/', '', trim((string)($_POST['matric'] ?? '')));
  $message = trim($_POST['message'] ?? '');
  $fingerprint = trim($_POST['fingerprint'] ?? '');
  $courseInput = trim((string)($_POST['course'] ?? ''));
  $course = $courseInput;
  if ($course === '') {
    $course = in_array('General', $courseOptions, true) ? 'General' : (string)($courseOptions[0] ?? 'General');
  }

  if (!in_array($course, $courseOptions, true)) {
    $formError = 'Please select a valid course from the course list.';
  }

  $requestedAction = strtolower(trim((string)($_POST['requested_action'] ?? '')));
  if (!in_array($requestedAction, ['checkin', 'checkout'], true)) {
    $requestedAction = '';
  }
  $ip = $_SERVER['REMOTE_ADDR'];

  if ($matric !== '' && !preg_match('/^\d{6,20}$/', $matric)) {
    $formError = 'Enter a valid matric number using digits only.';
  }

  if ($name && $matric && $message && $formError === '') {
    $createdAt = date('Y-m-d H:i:s');
    $saved = append_support_ticket_atomic($ticketsFile, [
      'name' => $name,
      'matric' => $matric,
      'message' => $message,
      'fingerprint' => $fingerprint,
      'course' => $course,
      'requested_action' => $requestedAction,
      'ip' => $ip,
      'timestamp' => $createdAt,
      'resolved' => false
    ]);

    if ($saved) {
      $dualWriteSpan = microtime(true);
      hybrid_dual_write('support_ticket', 'support_tickets', [
        'timestamp' => date('c'),
        'name' => $name,
        'matric' => $matric,
        'message' => $message,
        'fingerprint' => $fingerprint,
        'course' => $course,
        'requested_action' => $requestedAction,
        'ip' => $ip,
        'created_at_local' => $createdAt,
        'resolved' => false
      ]);
      request_timing_span('hybrid_dual_write', $dualWriteSpan);

      $aiTicket = [
        'name' => $name,
        'matric' => $matric,
        'message' => $message,
        'fingerprint' => $fingerprint,
        'course' => $course,
        'requested_action' => $requestedAction,
        'ip' => $ip,
        'timestamp' => $createdAt,
        'resolved' => false
      ];
      $aiSpan = microtime(true);
      $aiResult = support_run_ai_for_ticket($aiTicket);
      request_timing_span('ai_ticket_immediate_process', $aiSpan, [
        'ok' => !empty($aiResult['ok']),
        'processed' => (int)($aiResult['processed'] ?? 0),
        'error' => (string)($aiResult['error'] ?? '')
      ]);
    }

    $success = $saved;
    request_timing_span('submit_support_ticket', $submitSpan, ['saved' => $saved]);
  }
}

// ✅ Announcement logic
$announcementFile = admin_storage_migrate_file('announcement.json');
$announcement = ['enabled' => false, 'message' => '', 'severity' => 'info', 'updated_at' => null];
if (file_exists($announcementFile)) {
  $json = admin_cached_json_file('support_announcement', $announcementFile, [], 5);
  if (is_array($json)) {
    $announcement['enabled'] = isset($json['enabled']) ? (bool)$json['enabled'] : false;
    $announcement['message'] = isset($json['message']) ? (string)$json['message'] : '';
    $sev = isset($json['severity']) ? (string)$json['severity'] : 'info';
    $announcement['severity'] = in_array($sev, ['info', 'warning', 'urgent'], true) ? $sev : 'info';
    $announcement['updated_at'] = isset($json['updated_at']) ? $json['updated_at'] : null;
  }
}

// ✅ Blocked logic: Check if user is blocked via cookie
$blocked = false;
if (isset($_COOKIE['attendanceBlocked'])) {
  $blocked = true;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Attendance Support</title>
  <link rel="icon" type="image/svg+xml" href="asset/attendance-favicon.svg">
  <link rel="stylesheet" href="./admin/boxicons.min.css">
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
  <link rel="icon" type="image/x-icon" href="asset/favicon.ico">
  <link rel="apple-touch-icon" sizes="180x180" href="asset/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="asset/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="asset/favicon-16x16.png">
  <link rel="manifest" href="asset/site.webmanifest">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
      --success-bg: #e8f8f1;
      --success-text: #1b6c51;
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
      background:
        radial-gradient(circle at 14% 14%, rgba(59, 125, 182, 0.22), transparent 26%),
        radial-gradient(circle at 86% 78%, rgba(30, 142, 106, 0.14), transparent 24%),
        linear-gradient(180deg, var(--bg-top), var(--bg-bottom));
      color: var(--text);
      padding: 86px 18px 18px;
    }

    a {
      text-decoration: none;
      color: inherit;
    }

    .container {
      width: 100%;
      max-width: 520px;
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 22px;
      box-shadow: var(--shadow);
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

    .logo {
      height: 88px;
      width: 88px;
      border-radius: 18px;
      margin: 0 auto 10px;
      display: block;
      border: 1px solid var(--line);
      background: #f7fbff;
      padding: 8px;
      object-fit: contain;
    }

    h2 {
      color: var(--text);
      text-align: center;
      margin: 0 0 16px;
      font-size: 1.2rem;
    }

    .input-group {
      margin-bottom: 15px;
    }

    .input-group label {
      color: var(--muted);
      display: block;
      margin-bottom: 6px;
      font-size: 0.9rem;
    }

    .input-group input,
    .input-group select,
    .input-group textarea {
      width: 100%;
      padding: 10px 11px;
      border: 1px solid var(--line);
      background: #fff;
      color: var(--text);
      border-radius: 10px;
      transition: border-color 0.18s ease, box-shadow 0.18s ease;
    }

    .input-group select {
      padding-right: 40px;
      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;
      background-image: linear-gradient(45deg, transparent 50%, var(--muted) 50%), linear-gradient(135deg, var(--muted) 50%, transparent 50%);
      background-position: calc(100% - 18px) 50%, calc(100% - 12px) 50%;
      background-size: 6px 6px, 6px 6px;
      background-repeat: no-repeat;
    }

    .input-group textarea {
      resize: vertical;
      min-height: 90px;
    }

    .input-group input:focus,
    .input-group select:focus,
    .input-group textarea:focus {
      outline: none;
      border-color: var(--primary-2);
      box-shadow: 0 0 0 3px rgba(59, 125, 182, 0.16);
    }

    .btn {
      width: 100%;
      padding: 12px;
      border-radius: 10px;
      border: none;
      font-weight: 700;
      cursor: pointer;
      transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .btn-primary {
      background: linear-gradient(90deg, var(--primary), var(--primary-2));
      color: #fff;
      box-shadow: 0 8px 20px rgba(31, 93, 153, 0.25);
    }

    .btn-primary:hover {
      transform: translateY(-1px);
    }

    .success-message {
      background: var(--success-bg);
      color: var(--success-text);
      padding: 11px;
      margin-top: 12px;
      border-radius: 8px;
      text-align: center;
      border: 1px solid #cfeedd;
      font-size: 0.93rem;
    }

    .back-link {
      display: block;
      text-align: center;
      margin-top: 14px;
      color: var(--primary);
      font-weight: 700;
      font-size: 0.93rem;
    }

    .disabled-link {
      pointer-events: none;
      opacity: 0.5;
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
        border-radius: 14px;
        padding: 18px;
        margin-top: 36px;
      }

      .logo {
        height: 72px;
        width: 72px;
        border-radius: 14px;
      }

      h2 {
        font-size: 1.08rem;
      }

      .input-group input,
      .input-group select,
      .input-group textarea {
        border-radius: 12px;
        padding: 12px 13px;
        font-size: 0.95rem;
      }

      .input-group select {
        padding-right: 42px;
        background-position: calc(100% - 16px) 50%, calc(100% - 10px) 50%;
      }

      .alert-message {
        font-size: 0.9rem;
      }

      .page-footer {
        margin-top: 2.35rem;
      }
    }
  </style>
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
    <img class="logo" src="asset/attendance-mark.svg" alt="Attendance Mark">
    <h2><i class='bx bx-ticket'></i> Submit Support Ticket</h2>

    <?php if ($success): ?>
      <div class="success-message">
        ✅ Your ticket has been submitted successfully!
      </div>
      <script>
        Swal.fire({
          icon: 'success',
          title: 'Ticket Submitted',
          text: 'Your support ticket was submitted successfully.',
          confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red')
        });
      </script>
    <?php endif; ?>

    <?php if ($formError !== ''): ?>
      <div style="background:#fff1f2;color:#9f1d2c;padding:11px;margin-top:12px;border-radius:8px;text-align:center;border:1px solid #fecdd3;font-size:0.93rem;">
        <?= htmlspecialchars($formError) ?>
      </div>
    <?php endif; ?>

    <form method="post">
      <div class="input-group">
        <label for="name"><i class='bx bx-user'></i> Name</label>
        <input type="text" id="name" name="name" placeholder="Your Name" required />
      </div>
      <div class="input-group">
        <label for="matric"><i class='bx bx-id-card'></i> Matric Number</label>
        <input type="text" id="matric" name="matric" placeholder="e.g., 2023000000" inputmode="numeric" pattern="[0-9]{6,20}" maxlength="20" required />
      </div>
      <div class="input-group">
        <label for="course"><i class='bx bx-book-open'></i> Course (optional)</label>
        <select id="course" name="course" aria-label="Course optional">
          <option value="">Select a course</option>
          <?php foreach ($courseOptions as $courseName): ?>
            <option value="<?= htmlspecialchars($courseName) ?>" <?= $selectedCourseForm === (string)$courseName ? 'selected' : '' ?>>
              <?= htmlspecialchars($courseName) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="input-group">
        <label for="requested_action"><i class='bx bx-transfer-alt'></i> Failed Action (optional)</label>
        <select id="requested_action" name="requested_action" aria-label="Failed Action optional">
          <option value="">Not sure</option>
          <option value="checkin">Check-in failed</option>
          <option value="checkout">Check-out failed</option>
        </select>
      </div>
      <div class="input-group">
        <label for="message"><i class='bx bx-message'></i> Message</label>
        <textarea id="message" name="message" placeholder="Write your issue or question..." required></textarea>
      </div>
      <!-- Hidden fingerprint input -->
      <input type="hidden" id="fingerprint" name="fingerprint" value="">
      <button type="submit" class="btn btn-primary">Submit Ticket</button>
    </form>

    <a class="back-link <?= $blocked ? 'disabled-link' : '' ?>" href="<?= $blocked ? '#' : 'index.php' ?>"><i class='bx bx-chevron-left'></i> Back to Attendance</a>
  </div>

  <!-- Public Footer -->
  <footer class="page-footer">
    <div class="page-footer-pill">
      <div class="page-footer-title">Created for Cyber Security Department</div>
    </div>
  </footer>

  <!-- Add fingerprint script -->
  <script src="./js/fp.min.js"></script>
  <script>
    FingerprintJS.load().then(fp => {
      fp.get().then(result => {
        document.getElementById('fingerprint').value = result.visitorId;
        fetchAnnouncement();
      }).catch(err => {
        console.error('Fingerprint error:', err);
      });
    });

    // Keep announcement in sync with admin updates - Hybrid Banner Model
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
      message: <?= json_encode((string)($announcement['message'] ?? '')) ?>,
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
      const fpField = document.getElementById('fingerprint');
      const fpForAnnouncement = fpField && fpField.value ? fpField.value : '';
      const annUrl = fpForAnnouncement ?
        `get_announcement.php?fingerprint=${encodeURIComponent(fpForAnnouncement)}` :
        'get_announcement.php';

      fetch(annUrl, {
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
        .catch(err => {
          console.error('Announcement fetch error:', err);
        });
    }

    fetchAnnouncement();
    setInterval(fetchAnnouncement, 20000);
  </script>
</body>

</html>
