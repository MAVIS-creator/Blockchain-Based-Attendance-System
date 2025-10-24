<?php
 $statusFile = __DIR__ . '/../status.json';
 // load status
 $status = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : ['checkin' => false, 'checkout' => false, 'end_time' => null];
if (!isset($status['end_time'])) {
    $status['end_time'] = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $duration = isset($_POST['duration']) && is_numeric($_POST['duration']) ? (int)$_POST['duration'] * 60 : 600; // default 10 minutes

    // try to load admin settings to enforce check windows
    $settingsPath = __DIR__ . '/settings.json';
    $settings = [];
    if (file_exists($settingsPath)) {
        $raw = file_get_contents($settingsPath);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $settings = $decoded;
        else {
          // try decrypt if starts with ENC:
          if (strpos($raw, 'ENC:') === 0) {
            $keyFile = __DIR__ . '/.settings_key';
            if (file_exists($keyFile)) {
              $key = trim(file_get_contents($keyFile));
              // same decrypt logic as settings.php
              $blob = base64_decode(substr($raw,4));
              $iv = substr($blob,0,16);
              $ct = substr($blob,16);
              $plain = openssl_decrypt($ct, 'AES-256-CBC', base64_decode($key), OPENSSL_RAW_DATA, $iv);
              $decoded = json_decode($plain, true);
              if (is_array($decoded)) $settings = $decoded;
            }
          }
        }
    }

    $errorMessage = null;

    // helper: check time window
    $nowHM = date('H:i');
    $withinCheckinWindow = true;
    if (!empty($settings['checkin_time_start']) && !empty($settings['checkin_time_end'])) {
      $start = $settings['checkin_time_start'];
      $end = $settings['checkin_time_end'];
      if ($start <= $end) {
        $withinCheckinWindow = ($nowHM >= $start && $nowHM <= $end);
      } else {
        // overnight window
        $withinCheckinWindow = ($nowHM >= $start || $nowHM <= $end);
      }
    }

    if (isset($_POST['enable_checkin'])) {
        if (!$withinCheckinWindow) {
          $errorMessage = 'Cannot enable Check-In: current time is outside the configured check-in window.';
        } else {
          $status = ['checkin' => true, 'checkout' => false, 'end_time' => time() + $duration];
        }
    } elseif (isset($_POST['enable_checkout'])) {
        // allow enabling checkout regardless of checkin window (but you could add separate window logic here)
        $status = ['checkin' => false, 'checkout' => true, 'end_time' => time() + $duration];
    } elseif (isset($_POST['disable'])) {
        $status = ['checkin' => false, 'checkout' => false, 'end_time' => null];
    }

    if (empty($errorMessage)) {
      file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));
      header("Location: index.php?page=status");
      exit;
    }
    // if there was an error, fall through and render it below
}
?>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
  body {
    margin: 0;
    font-family: "Segoe UI", sans-serif;
    background: linear-gradient(135deg, #111827, #1f2937);
    overflow-x: hidden;
    color: #f9fafb;
  }
  .status-card {
    max-width: 500px;
    margin: 100px auto;
    background: rgba(255, 255, 255, 0.05);
    border: 2px solid rgba(255, 255, 255, 0.1);
    border-radius: 20px;
    backdrop-filter: blur(20px);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    padding: 40px;
    text-align: center;
    position: relative;
    overflow: hidden;
  }
  .status-card::before {
    content: "";
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: conic-gradient(from 90deg at 50% 50%, #3b82f6, #10b981, #facc15, #ef4444, #3b82f6);
    animation: spin 6s linear infinite;
    z-index: 0;
    opacity: 0.2;
  }
  .status-card > * {
    position: relative;
    z-index: 1;
  }
  .status-card h2 {
    margin-top: 0;
    font-size: 2rem;
    font-weight: 700;
    background: linear-gradient(90deg, #3b82f6, #10b981);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
  }
  .status-row {
    margin: 25px 0;
    font-size: 1.2rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
  }
  .status-enabled,
  .status-disabled {
    display: flex;
    align-items: center;
    gap: 5px;
    font-weight: bold;
  }
  .status-btns {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: center;
    margin-top: 30px;
  }
  .status-btns button, .status-btns input[type="number"] {
    padding: 10px 18px;
    border: none;
    border-radius: 10px;
    font-size: 1rem;
    font-weight: 600;
    background: rgba(255, 255, 255, 0.08);
    color: var(--accent-color, #f9fafb);
    border: 2px solid var(--accent-border, #3b82f6);
    transition: all 0.4s ease;
    backdrop-filter: blur(12px);
  }
  .status-btns button:hover {
    transform: translateY(-3px) scale(1.05);
    border-color: rgba(255, 255, 255, 0.3);
    box-shadow: 0 10px 35px rgba(0, 0, 0, 0.3);
  }
  @keyframes spin {
    0% { transform: rotate(0deg);}
    100% { transform: rotate(360deg);}
  }
  .progress-wrapper {
    position: relative;
    display: inline-block;
    margin-top: 20px;
  }
  .progress-ring {
    transform: rotate(-90deg);
  }
  .timer-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 1.1rem;
    font-weight: bold;
    color: #facc15;
  }
  #palette-container {
  position: fixed;
  top: 20px;
  right: 20px;
  z-index: 9999;
}

#palette-icon {
  font-size: 28px;
  color: #fff;
  cursor: pointer;
  transition: transform 0.3s ease;
}

#palette-icon:hover {
  transform: scale(1.2);
}

#palette-options {
  display: none;
  flex-direction: column;
  gap: 6px;
  margin-top: 8px;
}

#palette-options button {
  width: 28px;
  height: 28px;
  border: none;
  border-radius: 50%;
  cursor: pointer;
  outline: 2px solid #fff;
  transition: transform 0.2s ease;
}

#palette-options button:hover {
  transform: scale(1.15);
}
</style>

<div class="status-card">
  <h2>System Status Control</h2>

  <div class="status-row">
    <span><i class='bx bx-log-in-circle'></i> Check-In:</span>
    <?php if ($status['checkin']): ?>
      <span class="status-enabled" style="color: var(--accent-color, #22c55e);"><i class='bx bx-check-circle'></i> ENABLED</span>
    <?php else: ?>
      <span class="status-disabled" style="color: #ef4444;"><i class='bx bx-x-circle'></i> DISABLED</span>
    <?php endif; ?>
  </div>

  <div class="status-row">
    <span><i class='bx bx-log-out-circle'></i> Check-Out:</span>
    <?php if ($status['checkout']): ?>
      <span class="status-enabled" style="color: var(--accent-color, #22c55e);"><i class='bx bx-check-circle'></i> ENABLED</span>
    <?php else: ?>
      <span class="status-disabled" style="color: #ef4444;"><i class='bx bx-x-circle'></i> DISABLED</span>
    <?php endif; ?>
  </div>

  <?php if ($status['end_time']): ?>
    <div class="progress-wrapper">
      <svg class="progress-ring" width="120" height="120">
        <circle class="progress-ring__background" stroke="#e5e7eb" stroke-width="10" fill="transparent" r="50" cx="60" cy="60"/>
        <circle class="progress-ring__circle" stroke="#facc15" stroke-width="10" fill="transparent" r="50" cx="60" cy="60"/>
      </svg>
      <div id="countdown-timer" class="timer-text"></div>
    </div>
  <?php endif; ?>

  <form method="POST" class="status-btns">
    <input type="number" name="duration" placeholder="Duration (min)" min="1">
    <button type="submit" name="enable_checkin"><i class='bx bx-log-in'></i> Enable Check-In</button>
    <button type="submit" name="enable_checkout"><i class='bx bx-log-out'></i> Enable Check-Out</button>
    <button type="submit" name="disable"><i class='bx bx-power-off'></i> Disable All</button>
  </form>
</div>
<div id="palette-container">
  <i class='bx bxs-color' id="palette-icon"></i>
  <div id="palette-options">
    <button style="background: linear-gradient(135deg, #3b82f6, #06b6d4);" onclick="setTheme(0)"></button>
    <button style="background: linear-gradient(135deg, #10b981, #22d3ee);" onclick="setTheme(1)"></button>
    <button style="background: linear-gradient(135deg, #f43f5e, #fb923c);" onclick="setTheme(2)"></button>
    <button style="background: linear-gradient(135deg, #a855f7, #3b82f6);" onclick="setTheme(3)"></button>
    <button style="background: linear-gradient(135deg, #ec4899, #f59e0b);" onclick="setTheme(4)"></button>
    <button style="background: linear-gradient(135deg, #14b8a6, #0ea5e9);" onclick="setTheme(5)"></button>
    <button style="background: linear-gradient(135deg, #f87171, #facc15);" onclick="setTheme(6)"></button>
  </div>
</div>

<script>
const endTime = <?= isset($status['end_time']) ? $status['end_time'] : 'null' ?>;

function formatCountdown(seconds) {
  const m = Math.floor(seconds / 60).toString().padStart(2, '0');
  const s = (seconds % 60).toString().padStart(2, '0');
  return `${m}:${s}`;
}

if (endTime !== null) {
  const timerEl = document.getElementById('countdown-timer');
  const circle = document.querySelector('.progress-ring__circle');
  const radius = circle.r.baseVal.value;
  const circumference = 2 * Math.PI * radius;

  circle.style.strokeDasharray = `${circumference} ${circumference}`;
  circle.style.strokeDashoffset = `${circumference}`;

  const total = endTime - Math.floor(Date.now() / 1000);

  const interval = setInterval(() => {
    const now = Math.floor(Date.now() / 1000);
    const remaining = endTime - now;

    if (remaining > 0) {
      timerEl.textContent = formatCountdown(remaining);
      const percent = remaining / total;
      const offset = circumference * (1 - percent);
      circle.style.strokeDashoffset = offset;
    } else {
      clearInterval(interval);
      timerEl.textContent = "00:00";

      fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'disable=1'
      }).then(() => {
        Swal.fire({
          icon: 'info',
          title: 'Mode Disabled!',
          text: 'The attendance mode has been automatically disabled after the countdown.',
          confirmButtonColor: '#3b82f6'
        }).then(() => {
          location.reload();
        });
      });
    }
  }, 1000);
}
const gradients = [
  "linear-gradient(135deg, #3b82f6, #06b6d4)",
  "linear-gradient(135deg, #10b981, #22d3ee)",
  "linear-gradient(135deg, #f43f5e, #fb923c)",
  "linear-gradient(135deg, #a855f7, #3b82f6)",
  "linear-gradient(135deg, #ec4899, #f59e0b)",
  "linear-gradient(135deg, #14b8a6, #0ea5e9)",
  "linear-gradient(135deg, #f87171, #facc15)"
];

function setTheme(index) {
  document.body.style.background = gradients[index];
  localStorage.setItem("selectedGradient", index);
}

document.getElementById("palette-icon").addEventListener("click", () => {
  const options = document.getElementById("palette-options");
  options.style.display = options.style.display === "flex" ? "none" : "flex";
});

// On page load, check saved
const saved = localStorage.getItem("selectedGradient");
if (saved !== null) {
  setTheme(parseInt(saved));
}
</script>
