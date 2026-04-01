<?php
session_start();

$announcementFile = __DIR__ . '/admin/announcement.json';
$announcement = ['enabled' => false, 'message' => ''];
if (file_exists($announcementFile)) {
  $json = json_decode(file_get_contents($announcementFile), true);
  if (is_array($json)) {
    $announcement['enabled'] = !empty($json['enabled']);
    $announcement['message'] = trim((string)($json['message'] ?? ''));
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
      color: var(--text);
      background:
        radial-gradient(circle at 12% 14%, rgba(59, 125, 182, 0.22), transparent 26%),
        radial-gradient(circle at 88% 82%, rgba(30, 142, 106, 0.14), transparent 24%),
        linear-gradient(180deg, var(--bg-top), var(--bg-bottom));
      padding: 20px;
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
      animation: rise-in 0.45s ease;
    }

    .announcement-panel {
      display: none;
      margin: 0 auto 14px;
      background: #eef6ff;
      border: 1px solid #cfe1f5;
      color: #1d4f80;
      border-radius: 12px;
      padding: 10px 12px;
      font-size: 0.93rem;
      line-height: 1.35;
      text-align: left;
      max-width: 560px;
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
      .card {
        padding: 20px;
        border-radius: 14px;
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
  <div class="card">
    <div id="announcementPanel" class="announcement-panel" style="<?= !empty($announcement['enabled']) ? 'display:block;' : 'display:none;' ?>">
      <span class="announcement-title"><i class='bx bx-bell'></i> Announcement:</span>
      <span id="announcementText"><?= htmlspecialchars($announcement['message'] !== '' ? $announcement['message'] : 'An important announcement is currently active.') ?></span>
    </div>

    <img class="logo" src="asset/attendance-mark.svg" alt="Attendance Mark">
    <h1>Attendance Closed</h1>
    <p>Your session was interrupted due to inactivity or tab changes. Attendance is now closed for fairness and exam integrity.</p>
    <div class="actions">
      <a class="btn btn-accent" href="index.php"><i class='bx bx-home'></i> Return Home</a>
      <a class="btn btn-primary" href="support.php"><i class='bx bx-message'></i> Contact Support</a>
    </div>
  </div>

  <script>
    const announcementPanel = document.getElementById('announcementPanel');
    const announcementText = document.getElementById('announcementText');
    let announcementInitialized = false;
    let lastAnnouncementSignature = JSON.stringify({
      enabled: <?= !empty($announcement['enabled']) ? 'true' : 'false' ?>,
      message: <?= json_encode((string)$announcement['message']) ?>
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
        .catch(() => {
          // keep current state quietly on fetch errors
        });
    }

    fetchAnnouncement();
    setInterval(fetchAnnouncement, 10000);
  </script>
</body>

</html>
