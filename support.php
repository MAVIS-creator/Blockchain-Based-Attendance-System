<?php
session_start();
date_default_timezone_set('Africa/Lagos');
require_once __DIR__ . '/hybrid_dual_write.php';
$ticketsFile = __DIR__ . '/admin/support_tickets.json';

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

$success = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $name = trim($_POST['name'] ?? '');
  $matric = trim($_POST['matric'] ?? '');
  $message = trim($_POST['message'] ?? '');
  $fingerprint = trim($_POST['fingerprint'] ?? '');
  $ip = $_SERVER['REMOTE_ADDR'];

  if ($name && $matric && $message) {
    $createdAt = date('Y-m-d H:i:s');
    $saved = append_support_ticket_atomic($ticketsFile, [
      'name' => $name,
      'matric' => $matric,
      'message' => $message,
      'fingerprint' => $fingerprint,
      'ip' => $ip,
      'timestamp' => $createdAt,
      'resolved' => false
    ]);

    if ($saved) {
      hybrid_dual_write('support_ticket', 'support_tickets', [
        'timestamp' => date('c'),
        'name' => $name,
        'matric' => $matric,
        'message' => $message,
        'fingerprint' => $fingerprint,
        'ip' => $ip,
        'created_at_local' => $createdAt,
        'resolved' => false
      ]);
    }

    $success = $saved;
  }
}

// ✅ Announcement logic
$announcementFile = __DIR__ . '/admin/announcement.json';
$announcement = ['enabled' => false, 'message' => ''];
if (file_exists($announcementFile)) {
  $json = json_decode(file_get_contents($announcementFile), true);
  if (is_array($json)) {
    $announcement['enabled'] = $json['enabled'] ?? false;
    $announcement['message'] = $json['message'] ?? '';
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
      --shadow: 0 18px 40px rgba(24, 39, 75, 0.08);
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      font-family: "Trebuchet MS", "Segoe UI", sans-serif;
      min-height: 100vh;
      display: grid;
      place-items: center;
      background:
        radial-gradient(circle at 14% 14%, rgba(59, 125, 182, 0.22), transparent 26%),
        radial-gradient(circle at 86% 78%, rgba(30, 142, 106, 0.14), transparent 24%),
        linear-gradient(180deg, var(--bg-top), var(--bg-bottom));
      color: var(--text);
      padding: 18px;
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

    .announcement-panel {
      display: none;
      margin-bottom: 14px;
      background: var(--info-bg);
      border: 1px solid var(--info-line);
      color: var(--info-text);
      border-radius: 12px;
      padding: 10px 12px;
      font-size: 0.93rem;
      line-height: 1.35;
    }

    .announcement-title {
      font-weight: 700;
      margin-right: 6px;
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
    .input-group textarea {
      width: 100%;
      padding: 10px 11px;
      border: 1px solid var(--line);
      background: #fff;
      color: var(--text);
      border-radius: 10px;
      transition: border-color 0.18s ease, box-shadow 0.18s ease;
    }

    .input-group textarea {
      resize: vertical;
      min-height: 90px;
    }

    .input-group input:focus,
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
        padding: 14px;
      }

      .container {
        border-radius: 14px;
        padding: 18px;
      }

      .logo {
        height: 72px;
        width: 72px;
        border-radius: 14px;
      }

      h2 {
        font-size: 1.08rem;
      }
    }
  </style>
</head>

<body>

  <div class="container">
    <div id="announcementPanel" class="announcement-panel" style="<?= !empty($announcement['enabled']) ? 'display:block;' : 'display:none;' ?>">
      <span class="announcement-title"><i class='bx bx-bell'></i> Announcement:</span>
      <span id="announcementText"><?= htmlspecialchars(trim((string)($announcement['message'] ?? '')) !== '' ? (string)$announcement['message'] : 'An important announcement is currently active.') ?></span>
    </div>

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

    <form method="post">
      <div class="input-group">
        <label for="name"><i class='bx bx-user'></i> Name</label>
        <input type="text" id="name" name="name" placeholder="Your Name" required />
      </div>
      <div class="input-group">
        <label for="matric"><i class='bx bx-id-card'></i> Matric Number</label>
        <input type="text" id="matric" name="matric" placeholder="e.g., 2023000000" required />
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

  <!-- Add fingerprint script -->
  <script src="./js/fp.min.js"></script>
  <script>
    FingerprintJS.load().then(fp => {
      fp.get().then(result => {
        document.getElementById('fingerprint').value = result.visitorId;
      }).catch(err => {
        console.error('Fingerprint error:', err);
      });
    });

    // Keep announcement in sync with admin updates
    const announcementPanel = document.getElementById('announcementPanel');
    const announcementText = document.getElementById('announcementText');
    let announcementInitialized = false;
    let lastAnnouncementSignature = JSON.stringify({
      enabled: <?= !empty($announcement['enabled']) ? 'true' : 'false' ?>,
      message: <?= json_encode((string)($announcement['message'] ?? '')) ?>
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
        // ignore audio restrictions
      }
    }

    function showAnnouncementChangedNotice() {
      const toast = document.createElement('div');
      toast.className = 'announcement-toast';
      toast.textContent = '🔔 Announcement updated';
      document.body.appendChild(toast);
      requestAnimationFrame(() => toast.classList.add('show'));
      setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 220);
      }, 2600);
    }

    function fetchAnnouncement() {
      fetch('get_announcement.php', {
          cache: 'no-store'
        })
        .then(res => res.json())
        .then(data => {
          const enabled = !!(data && data.enabled);
          const msg = (data && data.message ? String(data.message) : '').trim();
          const signature = JSON.stringify({ enabled, message: msg });

          if (enabled) {
            announcementText.textContent = msg || 'An important announcement is currently active.';
            announcementPanel.style.display = 'block';
          } else {
            announcementPanel.style.display = 'none';
          }

          if (announcementInitialized && signature !== lastAnnouncementSignature) {
            showAnnouncementChangedNotice();
            playAnnouncementBeep();
          }
          lastAnnouncementSignature = signature;
          announcementInitialized = true;
        })
        .catch(err => {
          console.error('Announcement fetch error:', err);
        });
    }

    fetchAnnouncement();
    setInterval(fetchAnnouncement, 10000);
  </script>
</body>

</html>
