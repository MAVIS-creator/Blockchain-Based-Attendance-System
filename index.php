<?php
$status = json_decode(file_get_contents("status.json"), true);
$activeMode = $status["checkin"] ? "checkin" : ($status["checkout"] ? "checkout" : "");
if (!$activeMode) {
  header('Location: attendance_closed.php');
  exit;
}

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
    :root{
      --accent-red: #ef4444;
      --accent-yellow: #facc15;
      --accent-dark: #111827;
      --card-bg: #ffffff;
      --muted: #6b7280;
    }
    body {
      margin: 0;
      font-family: 'Segoe UI', sans-serif;
      background: #ffffff;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-direction: column;
      position: relative;
      color: var(--accent-dark);
    }

    .announcement-bar {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      background: linear-gradient(90deg,var(--accent-yellow),var(--accent-red));
      color: #111;
      padding: 0.75rem 0;
      font-weight: bold;
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

    .overlay { display:none; }

    .container {
      z-index: 1;
      background: var(--card-bg);
      border-radius: 14px;
      padding: 28px;
      box-shadow: 0 8px 30px rgba(16,24,40,0.08);
      width: 95%;
      max-width: 460px;
      margin-top: 40px;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      border: 1px solid rgba(16,24,40,0.04);
    }

    .container:hover {
      transform: translateY(-5px);
      box-shadow: 0 12px 50px rgba(0, 255, 255, 0.25);
    }

    .brand {
      display:flex;align-items:center;gap:12px;margin-bottom:18px;
    }
  .brand img{ height:44px; width:44px; border-radius:8px; object-fit:cover }
  /* global link style for client pages */
  a { text-decoration: none; color: inherit }
    .brand h2{ margin:0; font-size:1.05rem; color:var(--accent-dark); }

    .form h2 { color:var(--muted); text-align:center; margin-bottom:14px; font-size:1.1rem }

    .input-group {
      margin-bottom: 20px;
    }

    .input-group label { color: var(--muted); display:block; margin-bottom:6px; font-size:0.9rem }

    .input-group input {
      width: 100%; padding:12px; border:1px solid #e6e9ef; background: #fff; color: var(--accent-dark); border-radius:8px; transition: all 0.15s ease;
    }

    .input-group input:focus { outline:none; box-shadow: 0 6px 20px rgba(16,24,40,0.06); border-color: rgba(16,24,40,0.06); }

    .btn { width:100%; padding:12px; border:none; color:#fff; font-weight:700; border-radius:8px; cursor:pointer; font-size:1rem; transition: all 0.15s ease; }
    .btn-primary { background: linear-gradient(90deg,var(--accent-red),#d97706); }
    .btn-accent { background: linear-gradient(90deg,var(--accent-yellow),#f59e0b); color:#111 }
    .btn:hover { transform: translateY(-2px); }

    button:hover {
      background-color: #00c5cc;
      transform: translateY(-2px);
    }

    /* SweetAlert2 customizations */
    .swal2-popup { border-radius:12px; }
    .swal2-header { color:var(--accent-dark) }
    .swal2-title { color:var(--accent-dark); font-weight:700 }
    .swal2-confirm { background: linear-gradient(90deg,var(--accent-red),var(--accent-yellow)) !important; color:#111 !important }

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
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<div id="announcementBar" class="announcement-bar">
  <div id="scrollingText" class="scrolling-text"><?= htmlspecialchars($announcement['message']) ?></div>
</div>

<div class="container">
  <div class="brand">
    <img src="https://www.clipartmax.com/png/middle/258-2587546_%5Bgood-news%5D-lautech-resumes-on-15th-of-september-and-ladoke-akintola-university.png" alt="LAUTECH">
    <h2>Student Attendance — <?= htmlspecialchars($activeCourse) ?></h2>
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
    <p style="display: inline-block; margin: 10px 0 0; font-size: 14px; color: var(--muted);">
      Need help? 
      <a href="support.php" style="margin-left:8px; padding:8px 14px; border-radius:8px; text-decoration:none;" class="btn btn-accent">💬 Support</a>
    </p>
  </form>
</div>


<script src="./js/fp.min.js"></script>
<script>
const submitBtn = document.getElementById('submitBtn');
const fingerprintInput = document.getElementById('fingerprint');
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
      window.location.href = 'attendance_closed.php';
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
    Swal.fire({ icon: 'error', title: 'Fingerprint Error', text: 'Fingerprint could not be generated. Please try again.', confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red') });
  });
});

document.getElementById('attendanceForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const formData = new FormData(this);
  fetch('submit.php', {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(json => {
      if (!json || !json.ok) {
        Swal.fire({ icon: 'error', title: 'Submission Failed', text: (json && json.message) || 'Submission failed', confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red') });
        return;
      }
      Swal.fire({ icon: 'success', title: 'Success', text: json.message, timer: 2200, showConfirmButton: false, background: '#fff' });
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
      Swal.fire({ icon: 'error', title: 'Error', text: 'Error occurred. Please try again.', confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red') });
    });
});

// popup removed: using SweetAlert2 for user messages

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
  Swal.fire({ icon: 'warning', title: 'Away Too Long', text: 'You were away too long! Attendance closed to ensure fairness.', confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red') }).then(function(){ window.location.href = 'attendance_closed.php'; });
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
        Swal.fire({ icon:'info', title: 'Attendance Closed', text: 'Attendance has now closed!', confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red') }).then(function(){ location.reload(); });
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
