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
  <title>Attendance Portal</title>
  <link rel="icon" type="image/svg+xml" href="asset/attendance-favicon.svg">
  <link rel="icon" type="image/x-icon" href="asset/favicon.ico">
  <link rel="apple-touch-icon" sizes="180x180" href="asset/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="asset/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="asset/favicon-16x16.png">
  <link rel="manifest" href="asset/site.webmanifest">
  <style>
    :root{
      --bg-top: #f4f7fb;
      --bg-bottom: #edf2f7;
      --panel: #ffffff;
      --text: #10233a;
      --muted: #5f6d7d;
      --line: #d8e1eb;
      --primary: #1f5d99;
      --primary-2: #3b7db6;
      --info-bg: #eef6ff;
      --info-line: #cfe1f5;
      --info-text: #1d4f80;
      --success: #1e8e6a;
      --shadow: 0 18px 40px rgba(24, 39, 75, 0.08);
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: "Trebuchet MS", "Segoe UI", sans-serif;
      min-height: 100vh;
      display: grid;
      place-items: center;
      color: var(--text);
      background:
        radial-gradient(circle at 12% 16%, rgba(59,125,182,0.22), transparent 26%),
        radial-gradient(circle at 88% 82%, rgba(30,142,106,0.16), transparent 24%),
        linear-gradient(180deg, var(--bg-top), var(--bg-bottom));
      padding: 20px;
    }

    a { text-decoration: none; color: inherit; }

    .container {
      width: 100%;
      max-width: 500px;
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 18px;
      box-shadow: var(--shadow);
      padding: 24px;
      animation: rise-in 0.5s ease;
    }

    @keyframes rise-in {
      from { opacity: 0; transform: translateY(12px); }
      to { opacity: 1; transform: translateY(0); }
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

    .announcement-panel {
      display: none;
      margin-bottom: 14px;
      background: var(--info-bg);
      border: 1px solid var(--info-line);
      color: var(--info-text);
      border-radius: 12px;
      padding: 10px 12px;
      line-height: 1.35;
      font-size: 0.93rem;
    }

    .announcement-title {
      font-weight: 700;
      margin-right: 6px;
    }

    .form h2 {
      color: var(--muted);
      text-align: center;
      margin-bottom: 14px;
      font-size: 1.02rem;
      font-weight: 600;
    }

    .input-group { margin-bottom: 16px; }

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

    .input-group input:focus {
      outline: none;
      border-color: var(--primary-2);
      box-shadow: 0 0 0 3px rgba(59,125,182,0.16);
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
      box-shadow: 0 8px 20px rgba(31,93,153,0.25);
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

    .btn:hover { transform: translateY(-1px); }
    .btn:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }

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

    .swal2-popup { border-radius: 12px; }
    .swal2-title { color: var(--text); font-weight: 700; }
    .swal2-confirm { background: linear-gradient(90deg, var(--primary), var(--primary-2)) !important; }

    @media (max-width: 520px) {
      body { padding: 14px; }
      .container { padding: 18px; border-radius: 14px; }
      .brand { align-items: flex-start; }
      .brand h2 { font-size: 0.95rem; }
      .support-row { align-items: flex-start; }
    }
  </style>
  <link rel="stylesheet" href="admin/boxicons.min.css">
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<div class="container">
  <div class="brand">
    <img src="asset/attendance-mark.svg" alt="Attendance Mark">
    <h2>Attendance Portal - <?= htmlspecialchars($activeCourse) ?></h2>
  </div>
  <div id="announcementPanel" class="announcement-panel">
    <span class="announcement-title"><i class='bx bx-bell'></i> Announcement:</span>
    <span id="announcementText"><?= htmlspecialchars($announcement['message']) ?></span>
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
      Need help? 
      <a href="support.php" class="btn btn-accent"><i class='bx bx-message'></i> Support</a>
    </p>
  </form>
</div>


<script src="./js/fp.min.js"></script>
<script>
const submitBtn = document.getElementById('submitBtn');
const fingerprintInput = document.getElementById('fingerprint');
const announcementPanel = document.getElementById('announcementPanel');
const announcementText = document.getElementById('announcementText');

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
  // Poll revoked tokens list and clear local token immediately when revoked
  (function(){
    try{
      var stored = localStorage.getItem('attendance_token');
      if (!stored) return;
      // Try SSE first
      if (typeof(EventSource) !== 'undefined') {
        try {
          var src = new EventSource('admin/revoke_sse.php');
          src.addEventListener('revoked', function(e){
            try{
              var payload = JSON.parse(e.data);
              if (payload && payload.revoked && payload.revoked.tokens && payload.revoked.tokens[stored]) {
                localStorage.removeItem('attendance_token');
                localStorage.removeItem('attendanceBlocked');
                try{ Swal.fire({ icon: 'info', title: 'Token Revoked', text: 'Your attendance token was revoked by an administrator. The page will reload.' , confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red') }).then(function(){ location.reload(); }); }catch(e){ location.reload(); }
                src.close();
              }
            }catch(ignore){}
          });
        } catch(e) {
          // fallback to polling below
        }
      }

      // Poll fallback (in case SSE not available or fails)
      var attempts = 0;
      var maxAttempts = 120; // stop after ~10 minutes
      var poll = setInterval(function(){
        attempts++;
        fetch('admin/revoked_tokens.php', {cache:'no-store'}).then(function(r){ if(!r.ok) return null; return r.json(); }).then(function(data){
          if (!data || !data.revoked) return;
          var tokensObj = data.revoked.tokens||{};
          if (tokensObj[stored] || (Array.isArray(tokensObj) && tokensObj.indexOf(stored)!==-1)) {
            localStorage.removeItem('attendance_token');
            localStorage.removeItem('attendanceBlocked');
            console.info('Local attendance token revoked and cleared');
            try{ Swal.fire({ icon: 'info', title: 'Token Revoked', text: 'Your attendance token was revoked by an administrator. The page will reload.' , confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red') }).then(function(){ location.reload(); }); }catch(e){ location.reload(); }
            clearInterval(poll);
          }
        }).catch(function(){ /* ignore */ });
        if (attempts >= maxAttempts) clearInterval(poll);
      }, 5000);
    }catch(e){ }
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
  }).catch(err => {
    console.error('Fingerprint error:', err);
    Swal.fire({ icon: 'error', title: 'Fingerprint Error', text: 'Fingerprint could not be generated. Please try again.', confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red') });
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
      const timer = setTimeout(() => { if (!settled) { settled = true; resolve(null); } }, timeout);
      navigator.geolocation.getCurrentPosition(function(pos){
        if (settled) return; settled = true; clearTimeout(timer);
        resolve({lat: pos.coords.latitude, lng: pos.coords.longitude});
      }, function(){ if (settled) return; settled = true; clearTimeout(timer); resolve(null); }, {maximumAge:60000,timeout:timeout});
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
          Swal.fire({ icon: 'error', title: 'Submission Failed', text: (json && json.message) || 'Submission failed', confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red') });
          return;
        }
        Swal.fire({ icon: 'success', title: 'Success', text: json.message, timer: 2200, showConfirmButton: false, background: '#fff' });
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
        Swal.fire({ icon: 'error', title: 'Error', text: 'Error occurred. Please try again.', confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red') });
      });
  });
});

// popup removed: using SweetAlert2 for user messages

// Inactivity fencing
function startInactivityTimer() {
  inactivityTimer = setTimeout(() => {
    const today = new Date().toISOString().split('T')[0];
    localStorage.setItem('attendanceBlocked', today);
    localStorage.setItem('blockedTimestamp', Date.now().toString());

    // include client token and fingerprint when logging inactivity so admins can revoke/clear device-side tokens
    var tokenToSend = localStorage.getItem('attendance_token') || '';
    var fpValue = document.getElementById('fingerprint') ? document.getElementById('fingerprint').value : '';
    fetch('log_inactivity.php', {
      method: 'POST',
      body: new URLSearchParams({ reason: 'Tab inactive too long', token: tokenToSend, fingerprint: fpValue })
    }).finally(() => {
  Swal.fire({ icon: 'warning', title: 'Away Too Long', text: 'You were away too long! Attendance closed to ensure fairness.', confirmButtonColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-red') }).then(function(){ window.location.href = 'closed.php'; });
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
        announcementText.textContent = data.message;
        announcementPanel.style.display = 'block';
      } else {
        announcementPanel.style.display = 'none';
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
