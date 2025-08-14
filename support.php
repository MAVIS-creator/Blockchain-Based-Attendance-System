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
  <style>
    body {
      margin: 0;
      font-family: 'Segoe UI', sans-serif;
      background: url(./asset/6071871_3139256.jpg) no-repeat center center/cover;
      height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      flex-direction: column;
    }
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
    .container {
      z-index: 1;
      background: rgba(255, 255, 255, 0.05);
      backdrop-filter: blur(10px);
      border-radius: 15px;
      padding: 40px;
      box-shadow: 0 0 25px rgba(0, 255, 255, 0.2);
      max-width: 400px;
      width: 100%;
      animation: fadeIn 1.2s ease;
      margin-top: 60px; /* offset for announcement bar */
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-20px); }
      to { opacity: 1; transform: translateY(0); }
    }
    h2 { color: #00eaff; text-align: center; margin-bottom: 25px; }
    .input-group { margin-bottom: 20px; }
    .input-group label { color: #ccc; display: block; margin-bottom: 5px; }
    .input-group input, .input-group textarea {
      width: 100%;
      padding: 10px;
      border: 1px solid #00eaff;
      background: transparent;
      color: #fff;
      border-radius: 8px;
      transition: 0.3s;
    }
    .input-group textarea { resize: vertical; min-height: 80px; }
    .input-group input:focus, .input-group textarea:focus {
      outline: none;
      box-shadow: 0 0 10px #00eaff;
    }
    button {
      width: 100%;
      padding: 12px;
      background-color: #00eaff;
      border: none;
      color: #000;
      font-weight: bold;
      border-radius: 8px;
      cursor: pointer;
      transition: 0.3s;
    }
    button:hover { background-color: #00c5cc; }
    .success-message {
      background: #d4edda;
      color: #155724;
      padding: 14px;
      margin-top: 18px;
      border: 1px solid #c3e6cb;
      border-radius: 6px;
      text-align: center;
    }
    .back-link {
      display: block;
      text-align: center;
      margin-top: 20px;
      color: #00eaff;
      text-decoration: none;
      font-weight: bold;
    }
    .back-link:hover { text-decoration: underline; }
    .disabled-link {
      pointer-events: none;
      opacity: 0.5;
      text-decoration: none;
    }
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
    <h2>🎫 Submit Support Ticket</h2>

    <?php if ($success): ?>
      <div class="success-message">
        ✅ Your ticket has been submitted successfully!
      </div>
    <?php endif; ?>

    <form method="post">
      <div class="input-group">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" placeholder="Your Name" required />
      </div>
      <div class="input-group">
        <label for="matric">Matric Number</label>
        <input type="text" id="matric" name="matric" placeholder="e.g., 2023000000" required />
      </div>
      <div class="input-group">
        <label for="message">Message</label>
        <textarea id="message" name="message" placeholder="Write your issue or question..." required></textarea>
      </div>
      <!-- Hidden fingerprint input -->
      <input type="hidden" id="fingerprint" name="fingerprint" value="">
      <button type="submit">Submit Ticket</button>
    </form>

    <a class="back-link <?= $blocked ? 'disabled-link' : '' ?>" href="<?= $blocked ? '#' : 'index.php' ?>">⬅️ Back to Attendance</a>
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
