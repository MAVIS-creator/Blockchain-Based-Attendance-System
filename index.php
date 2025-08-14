<?php
$status = json_decode(file_get_contents("status.json"), true);
$activeMode = $status["checkin"] ? "checkin" : ($status["checkout"] ? "checkout" : "");
if (!$activeMode) die("Attendance is currently closed.");

// Read active course
$activeCourse = "General";
$activeFile = __DIR__ . "/admin/courses/active_course.json";
if (file_exists($activeFile)) {
    $activeData = json_decode(file_get_contents($activeFile), true);
    if (is_array($activeData)) {
        $activeCourse = $activeData['course'] ?? "General";
    }
}

// Announcement logic
$announcementFile = __DIR__ . '/admin/announcement.json';
$announcement = ['enabled' => false, 'message' => ''];
if (file_exists($announcementFile)) {
    $json = json_decode(file_get_contents($announcementFile), true);
    if (is_array($json)) {
        $announcement['enabled'] = $json['enabled'] ?? false;
        $announcement['message'] = $json['message'] ?? '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Student Attendance</title>
  <style>
    body {
      margin: 0;
      font-family: 'Segoe UI', sans-serif;
      background: url(./asset/6071871_3139256.jpg) no-repeat center center/cover;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-direction: column;
      position: relative;
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
      border-radius: 0 0 8px 8px;
      z-index: 2000;
      overflow: hidden;
      display: none;
    }

    .scrolling-text {
      display: inline-block;
      white-space: nowrap;
      animation: scroll-left 20s linear infinite;
    }

    @keyframes scroll-left {
      0% { transform: translateX(100%); }
      100% { transform: translateX(-100%); }
    }

    .overlay {
      position: absolute;
      width: 100%;
      height: 100%;
      background: rgba(5, 15, 35, 0.7);
      z-index: 0;
    }

    .container {
      z-index: 1;
      background: rgba(0, 0, 0, 0.6);
      backdrop-filter: blur(15px);
      border-radius: 18px;
      padding: 35px;
      box-shadow: 0 8px 35px rgba(0, 255, 255, 0.15);
      width: 90%;
      max-width: 400px;
      margin-top: 60px;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .container:hover {
      transform: translateY(-5px);
      box-shadow: 0 12px 50px rgba(0, 255, 255, 0.25);
    }

    .form h2 {
      color: #00eaff;
      text-align: center;
      margin-bottom: 25px;
      font-size: 1.4rem;
    }

    .input-group {
      margin-bottom: 20px;
    }

    .input-group label {
      color: #9ecff7;
      display: block;
      margin-bottom: 5px;
      font-size: 0.9rem;
    }

    .input-group input {
      width: 100%;
      padding: 12px;
      border: 1px solid #00eaff;
      background: transparent;
      color: #fff;
      border-radius: 8px;
      transition: all 0.3s ease;
    }

    .input-group input:focus {
      outline: none;
      box-shadow: 0 0 12px #00eaff;
      border-color: #00ffff;
    }

    button {
      width: 100%;
      padding: 12px;
      background-color: #00eaff;
      border: none;
      color: #001a33;
      font-weight: bold;
      border-radius: 8px;
      cursor: pointer;
      font-size: 1rem;
      transition: all 0.3s ease;
    }

    button:hover {
      background-color: #00c5cc;
      transform: translateY(-2px);
    }

    .popup {
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      background: #fff;
      color: #222;
      padding: 20px 30px;
      border-radius: 10px;
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
      z-index: 1000;
      display: none;
      text-align: center;
    }

    .popup.success {
      border: 2px solid #00c5cc;
    }

    .popup.error {
      border: 2px solid #ff4d4f;
    }

    @media (max-width: 480px) {
      .container {
        padding: 25px;
      }

      .form h2 {
        font-size: 1.1rem;
      }

      .input-group label {
        font-size: 0.8rem;
      }

      button {
        font-size: 0.9rem;
      }
    }
  </style>
</head>
<body>

<div id="announcementBar" class="announcement-bar">
  <div id="scrollingText" class="scrolling-text"><?= htmlspecialchars($announcement['message']) ?></div>
</div>

<div class="overlay"></div>
<div class="container">
  <form class="form" id="attendanceForm">
    <h2>Student Attendance (<?= ucfirst($activeMode) ?>)</h2>

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

    <button id="submitBtn" type="submit" disabled>Submit</button>
    <p style="display: inline-block; margin: 0; font-size: 16px; color: #fff;">
      Have any issue? Complain to the admin
      <a href="support.php"
        style="
          display: inline-block;
          margin-left: 12px;
          padding: 10px 20px;
          background: linear-gradient(135deg, #00eaff, #00c5cc);
          color: #000;
          font-weight: bold;
          border-radius: 8px;
          text-decoration: none;
          box-shadow: 0 4px 12px rgba(0, 234, 255, 0.3);
          transition: background 0.3s, transform 0.2s;
        "
        onmouseover="this.style.background='linear-gradient(135deg, #00c5cc, #00a9b0)'; this.style.transform='translateY(-2px)';"
        onmouseout="this.style.background='linear-gradient(135deg, #00eaff, #00c5cc)'; this.style.transform='translateY(0)';">
        💬 Submit a Support Ticket
      </a>
    </p>
  </form>
</div>

<div id="popup" class="popup"></div>

<script src="./js/fp.min.js"></script>
<script>
const submitBtn = document.getElementById('submitBtn');
const fingerprintInput = document.getElementById('fingerprint');
const popup = document.getElementById('popup');
const announcementBar = document.getElementById('announcementBar');
const scrollingText = document.getElementById('scrollingText');

let inactivityTimer;
let fencingActive = true;

document.addEventListener('DOMContentLoaded', () => {
  const blockedDate = localStorage.getItem('attendanceBlocked');
  const blockedTime = localStorage.getItem('blockedTimestamp');
  const today = new Date().toISOString().split('T')[0];

  if (blockedDate === today && blockedTime) {
    const now = Date.now();
    const elapsed = now - parseInt(blockedTime, 10);

    if (elapsed >= 30 * 60 * 1000) {
      localStorage.removeItem('attendanceBlocked');
      localStorage.removeItem('blockedTimestamp');
    } else {
      window.location.href = 'closed.php';
    }
  }
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
  }).catch(err => {
    console.error('Fingerprint error:', err);
    alert("Fingerprint could not be generated.");
  });
});

document.getElementById('attendanceForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const formData = new FormData(this);
  fetch('submit.php', {
      method: 'POST',
      body: formData
    })
    .then(res => res.text())
    .then(text => {
      showPopup(text, true);
      this.reset();
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
      showPopup("Error occurred. Please try again.", false);
    });
});

function showPopup(message, success) {
  popup.textContent = message;
  popup.className = 'popup ' + (success ? 'success' : 'error');
  popup.style.display = 'block';
  setTimeout(() => {
    popup.style.display = 'none';
  }, 5000);
}

// Inactivity fencing
function startInactivityTimer() {
  inactivityTimer = setTimeout(() => {
    const today = new Date().toISOString().split('T')[0];
    localStorage.setItem('attendanceBlocked', today);
    localStorage.setItem('blockedTimestamp', Date.now().toString());

    fetch('log_inactivity.php', {
      method: 'POST',
      body: new URLSearchParams({ reason: 'Tab inactive too long' })
    }).finally(() => {
      alert("You were away too long! Attendance closed to ensure fairness.");
      window.location.href = 'closed.php';
    });
  }, 5000);
}

document.addEventListener('visibilitychange', () => {
  if (!fencingActive) return;

  if (document.hidden) {
    startInactivityTimer();
  } else {
    clearTimeout(inactivityTimer);
  }
});

// Announcement refresh
function fetchAnnouncement() {
  fetch('get_announcement.php')
    .then(res => res.json())
    .then(data => {
      if (data.enabled && data.message.trim()) {
        scrollingText.textContent = data.message;
        announcementBar.style.display = 'block';
      } else {
        announcementBar.style.display = 'none';
      }
    })
    .catch(err => {
      console.error("Announcement fetch error:", err);
    });
}

fetchAnnouncement();
setInterval(fetchAnnouncement, 10000);

// Status auto-refresh
function checkStatus() {
  fetch('status.json')
    .then(res => res.json())
    .then(data => {
      if (!data.checkin && !data.checkout) {
        alert("Attendance has now closed!");
        location.reload();
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
