<?php
session_start();
date_default_timezone_set('Africa/Lagos');
$ticketsFile = __DIR__ . '/admin/support_tickets.json';

if (!file_exists($ticketsFile)) {
    file_put_contents($ticketsFile, json_encode([]));
}

$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $matric = trim($_POST['matric'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $fingerprint = trim($_POST['fingerprint'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'];

    if ($name && $matric && $message) {
        $tickets = json_decode(file_get_contents($ticketsFile), true);
        $tickets[] = [
            'name' => $name,
            'matric' => $matric,
            'message' => $message,
            'fingerprint' => $fingerprint,
            'ip' => $ip,
            'timestamp' => date('Y-m-d H:i:s'),
            'resolved' => false
        ];
        file_put_contents($ticketsFile, json_encode($tickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $success = true;
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Support Ticket</title>
  <link rel="stylesheet" href="admin/boxicons.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root{ --accent-red:#ef4444; --accent-yellow:#facc15; --accent-dark:#111827; --muted:#6b7280 }
    body { margin:0; font-family:'Segoe UI',sans-serif; background:#f3f4f6; height:100vh; display:flex; align-items:center; justify-content:center; }
    .announcement-bar {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      background: #007bff;
      color: white;
      padding: 0.75rem 0;
      font-weight: bold;
      text-align: center;
      border-radius: 0 0 8px 8px;
      z-index: 2000;
      box-shadow: 0 2px 12px rgba(0,0,0,0.08);
      overflow: hidden;
      display: none;
    }
    .scrolling-text {
      display: flex;
      white-space: nowrap;
      animation: scroll-left 18s linear infinite;
    }
    .scrolling-text span {
      padding-right: 80px;
      font-size: 1.1em;
    }
    @keyframes scroll-left {
      0% { transform: translateX(0); }
      100% { transform: translateX(-50%); }
    }
    .overlay {
      position: absolute;
      width: 100%;
      height: 100%;
      background: rgba(5, 15, 35, 0.75);
      z-index: 0;
    }
    .container { z-index:1; background:#fff; border-radius:12px; padding:28px; box-shadow:0 10px 30px rgba(16,24,40,0.08); max-width:480px; width:92%; }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-20px); }
      to { opacity: 1; transform: translateY(0); }
    }
    h2 { color: #00eaff; text-align: center; margin-bottom: 25px; }
    h2 { color:var(--accent-dark); text-align:center; margin-bottom:12px }
    .input-group { margin-bottom: 20px; }
    .input-group label { color: #ccc; display: block; margin-bottom: 5px; }
    .input-group input, .input-group textarea { width:100%; padding:10px; border:1px solid #e6e9ef; background:#fff; color:#111; border-radius:8px }
    .input-group textarea { resize: vertical; min-height: 80px; }
    .input-group input:focus, .input-group textarea:focus {
      outline: none;
      box-shadow: 0 0 10px #00eaff;
    }
    button { width:100%; padding:12px; border-radius:8px; border:none; font-weight:700; cursor:pointer; }
    .btn { width:100%; padding:12px; border-radius:8px; border:none; font-weight:700; cursor:pointer; }
    .btn-primary { background: linear-gradient(90deg,var(--accent-red),#d97706); color:#fff; }
    .btn-accent { background: linear-gradient(90deg,var(--accent-yellow),#f59e0b); color:#111; }
    .success-message { background:#f0fdf4; color:#065f46; padding:12px; margin-top:12px; border-radius:6px; text-align:center; }
    .back-link { display:block; text-align:center; margin-top:14px; color:var(--accent-red); text-decoration:none; font-weight:700; }
    .disabled-link { pointer-events:none; opacity:0.5 }
  .logo{ height:120px; width:auto; max-width:240px; border-radius:8px; margin:0 auto 12px; display:block; background:transparent; }
  /* remove default link underlines and make anchors button-like where used */
  a { text-decoration: none; color: inherit }
  
  </style>
</head>
<body>

  <?php if ($announcement['enabled'] && $announcement['message']): ?>
  <div id="announcementBar" class="announcement-bar" style="display: block;">
    <div class="scrolling-text">
      <span><?= htmlspecialchars($announcement['message']) ?></span>
      <span><?= htmlspecialchars($announcement['message']) ?></span>
    </div>
  </div>
  <?php endif; ?>

  <div class="overlay"></div>
  <div class="container">
  <img class="logo" src="asset/lautech_logo.png" alt="logo" style="background:#fff;padding:8px;border-radius:8px;">
  <h2><i class='bx bx-ticket'></i> Submit Support Ticket</h2>

    <?php if ($success): ?>
      <div class="success-message">
        ✅ Your ticket has been submitted successfully!
      </div>
      <script>Swal.fire({ icon:'success', title: 'Ticket Submitted', text: 'Your support ticket was submitted successfully.', confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red') });</script>
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
  </script>
</body>
</html>
