<?php
require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/admin/runtime_storage.php';
require_once __DIR__ . '/admin/cache_helpers.php';
require_once __DIR__ . '/request_timing.php';
require_once __DIR__ . '/request_guard.php';
app_storage_init();
app_request_guard('index.php', 'public');
request_timing_start('index.php');

$statusFile = admin_storage_migrate_file('status.json', app_storage_file('status.json'));
$span = microtime(true);
$status = admin_cached_json_file('index_status', $statusFile, [], 2);
$status = is_array($status) ? $status : [];
$normalizedStatus = [
  'checkin' => !empty($status['checkin']),
  'checkout' => !empty($status['checkout']),
  'end_time' => isset($status['end_time']) && is_numeric($status['end_time']) ? (int)$status['end_time'] : null,
];
$activeModeConfigured = $normalizedStatus['checkin'] || $normalizedStatus['checkout'];
$timerValid = $normalizedStatus['end_time'] !== null && $normalizedStatus['end_time'] > time();
if ($activeModeConfigured && !$timerValid) {
  $normalizedStatus = ['checkin' => false, 'checkout' => false, 'end_time' => null];
}
if (!$normalizedStatus['checkin'] && !$normalizedStatus['checkout']) {
  $normalizedStatus['end_time'] = null;
}
if (($status['checkin'] ?? null) !== $normalizedStatus['checkin'] ||
  ($status['checkout'] ?? null) !== $normalizedStatus['checkout'] ||
  (($status['end_time'] ?? null) !== $normalizedStatus['end_time'])
) {
  @file_put_contents($statusFile, json_encode($normalizedStatus, JSON_PRETTY_PRINT), LOCK_EX);
}
$status = $normalizedStatus;
request_timing_span('load_status', $span);
$activeMode = $status["checkin"] ? "checkin" : ($status["checkout"] ? "checkout" : "");
if (!$activeMode) {
  header('Location: attendance_closed.php');
  exit;
}

// Read active course
$activeCourse = "General";
$activeFile = admin_course_storage_migrate_file('active_course.json');
$span = microtime(true);
if (file_exists($activeFile)) {
  $activeData = admin_cached_json_file('index_active_course', $activeFile, [], 10);
  if (is_array($activeData)) {
    $activeCourse = $activeData['course'] ?? "General";
  }
}
request_timing_span('load_active_course', $span);

$courseFile = admin_course_storage_migrate_file('course.json');
$activeCourseOutline = "Verified Academic Session";
if (file_exists($courseFile)) {
    $courses = admin_cached_json_file('index_courses', $courseFile, [], 10);
    if (is_array($courses) && isset($courses[$activeCourse])) {
        $activeCourseOutline = $courses[$activeCourse];
    }
}

// Load unified header
include __DIR__ . '/includes/public_header.php';
?>

<!-- Main Content Canvas -->
<main class="flex-grow flex flex-col items-center justify-start pt-8 pb-16 px-4">
    <div class="w-full max-w-2xl mt-8">
        <div class="bg-surface-container-low rounded-xl p-1 overflow-hidden">
            <div class="bg-surface-container-lowest rounded-lg shadow-[0_16px_36px_rgba(24,39,75,0.06)] overflow-hidden">
                <div class="blockchain-gradient p-8 text-white relative flex flex-col">
                    <div class="absolute top-0 right-0 p-8 opacity-10">
                        <span class="material-symbols-outlined text-8xl" data-icon="hub">hub</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 mb-4 z-10">
                        <span class="bg-white/20 backdrop-blur-md px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-widest flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-green-400 animate-pulse"></span>
                            Attendance Active
                        </span>
                        <span class="bg-white/20 backdrop-blur-md px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-widest flex items-center gap-2">
                            <?= ucfirst($activeMode) ?> Mode
                        </span>
                    </div>
                    <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight mb-2 z-10">
                        <?= htmlspecialchars($activeCourse) ?>
                    </h1>
                    <p class="text-white/70 text-sm font-medium z-10"><?= htmlspecialchars($activeCourseOutline) ?></p>
                </div>
                <div class="p-8 md:p-12">
                    <form id="attendanceForm" action="#" class="space-y-8" method="POST">
                        <div class="space-y-6">
                            <div class="space-y-2">
                                <label class="block text-xs font-bold text-on-surface-variant uppercase tracking-wider ml-1">Full Name</label>
                                <div class="relative group">
                                    <input class="w-full bg-surface-container-low border-none rounded-lg px-4 py-4 text-on-surface focus:ring-2 focus:ring-primary/20 transition-all placeholder:text-on-surface-variant/40" id="name" name="name" placeholder="Enter your official name" required="" type="text"/>
                                    <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-on-surface-variant/30" data-icon="person">person</span>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <label class="block text-xs font-bold text-on-surface-variant uppercase tracking-wider ml-1">Matric Number</label>
                                <div class="relative group">
                                    <input class="w-full bg-surface-container-low border-none rounded-lg px-4 py-4 text-on-surface focus:ring-2 focus:ring-primary/20 transition-all placeholder:text-on-surface-variant/40" id="matric" name="matric" inputmode="numeric" pattern="[0-9]{6,20}" minlength="6" maxlength="20" placeholder="0000000000" required="" type="text"/>
                                    <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-on-surface-variant/30" data-icon="fingerprint">fingerprint</span>
                                </div>
                            </div>
                            <input type="hidden" id="fingerprint" name="fingerprint">
                            <input type="hidden" name="action" value="<?= htmlspecialchars($activeMode) ?>">
                            <input type="hidden" name="course" value="<?= htmlspecialchars($activeCourse) ?>">
                        </div>
                        <div class="pt-4 space-y-4">
                            <button id="submitBtn" disabled class="w-full blockchain-gradient text-white font-bold py-4 rounded-lg shadow-lg hover:shadow-primary/20 transition-all duration-300 transform active:scale-[0.98] flex items-center justify-center gap-3 disabled:opacity-50 disabled:cursor-not-allowed" type="submit">
                                <span class="material-symbols-outlined" data-icon="verified">verified</span>
                                Submit Attendance
                            </button>
                            <a href="support.php" class="w-full bg-surface-container-high text-on-surface-variant font-semibold py-4 rounded-lg hover:bg-surface-container-highest transition-colors flex items-center justify-center gap-3 decoration-none cursor-pointer">
                                <span class="material-symbols-outlined" data-icon="help_center">help_center</span>
                                Support
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/public_footer.php'; ?>

<script src="./js/fp.min.js"></script>
<script>
    const submitBtn = document.getElementById('submitBtn');
    const fingerprintInput = document.getElementById('fingerprint');

    let inactivityTimer;
    let fencingActive = true;
    const TAB_AWAY_GRACE_MS = 6 * 1000;
    const FENCING_BLOCK_MS = 15 * 60 * 1000;
    const TAB_AWAY_MAX_STRIKES = 3;
    const TAB_AWAY_STRIKES_KEY = 'attendanceTabAwayStrikes';
    const TAB_AWAY_LOCK_UNTIL_KEY = 'attendanceTabAwayLockUntil';

    document.addEventListener('DOMContentLoaded', () => {
      const lockUntil = parseInt(localStorage.getItem(TAB_AWAY_LOCK_UNTIL_KEY) || '0', 10);
      const now = Date.now();
      if (lockUntil > now) {
        const remainingSec = Math.max(1, Math.ceil((lockUntil - now) / 1000));
        Swal.fire({
          icon: 'warning',
          title: 'Temporarily Locked',
          text: `Too many tab-away violations. Please wait ${remainingSec}s before trying again.`,
          confirmButtonColor: '#00457b'
        }).then(() => {
          window.location.href = 'closed.php';
        });
        return;
      }
      if (lockUntil > 0 && lockUntil <= now) {
        localStorage.removeItem(TAB_AWAY_LOCK_UNTIL_KEY);
        localStorage.setItem(TAB_AWAY_STRIKES_KEY, '0');
      }
      (function() {
        try {
          var stored = localStorage.getItem('attendance_token');
          if (!stored) return;
          // Avoid long-lived SSE streams on the public page.
          // Under high concurrency, SSE can exhaust PHP workers and cause timeouts.
          var attempts = 0;
          var poll = setInterval(function() {
            attempts++;
            fetch('admin/revoked_tokens.php', { cache: 'no-store' })
            .then(r => r.ok ? r.json() : null)
            .then(data => {
              if (!data || !data.revoked) return;
              var tokensObj = data.revoked.tokens || {};
              if (tokensObj[stored] || (Array.isArray(tokensObj) && tokensObj.indexOf(stored) !== -1)) {
                localStorage.removeItem('attendance_token');
                localStorage.removeItem('attendanceBlocked');
                try {
                  Swal.fire({
                    icon: 'info',
                    title: 'Token Revoked',
                    text: 'Your attendance token was revoked. Reloading...',
                    confirmButtonColor: '#00457b'
                  }).then(function() { location.reload(); });
                } catch (e) { location.reload(); }
                clearInterval(poll);
              }
            }).catch(() => {});
            if (attempts >= 120) clearInterval(poll);
          }, 5000);
        } catch (e) {}
      })();
    });

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
        Swal.fire({
          icon: 'error',
          title: 'Fingerprint Error',
          text: 'Fingerprint could not be generated. Please try again.',
          confirmButtonColor: '#00457b'
        });
      });
    });

    document.getElementById('attendanceForm').addEventListener('submit', function(e) {
      e.preventDefault();
      const form = this;
      const formData = new FormData(this);
      const showPopup = ({ icon = 'info', title = '', text = '', allowOutsideClick = true, showConfirmButton = true }) => {
        if (window.Swal && typeof Swal.fire === 'function') {
          return Swal.fire({ icon, title, text, allowOutsideClick, showConfirmButton });
        }
        return Promise.resolve();
      };

      submitBtn.disabled = true;
      Swal.fire({
        title: 'Submitting Attendance',
        text: 'Please wait...',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => { Swal.showLoading(); }
      });

      function getLocation(timeout = 5000) {
        return new Promise((resolve) => {
          if (!navigator.geolocation) return resolve(null);
          let settled = false;
          const timer = setTimeout(() => { if (!settled) { settled = true; resolve(null); } }, timeout);
          navigator.geolocation.getCurrentPosition(function(pos) {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            resolve({
              lat: pos.coords.latitude,
              lng: pos.coords.longitude,
              accuracyM: pos && pos.coords && typeof pos.coords.accuracy === 'number' ? pos.coords.accuracy : null,
              clientTs: Date.now(),
              highAccuracyRequested: true,
              source: 'browser-geolocation'
            });
          }, function() {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            resolve(null);
          }, { enableHighAccuracy: true, maximumAge: 60000, timeout: timeout });
        });
      }

      getLocation(5000).then(loc => {
        if (loc) {
          formData.append('lat', loc.lat);
          formData.append('lng', loc.lng);
          if (loc.accuracyM !== null && !Number.isNaN(loc.accuracyM)) {
            formData.append('geo_accuracy_m', loc.accuracyM);
          }
          formData.append('geo_client_ts', loc.clientTs || Date.now());
          formData.append('geo_high_accuracy', loc.highAccuracyRequested ? '1' : '0');
          formData.append('geo_source', loc.source || 'browser-geolocation');
        }
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 25000);

        fetch('submit.php', { method: 'POST', body: formData, signal: controller.signal })
          .finally(() => { clearTimeout(timeoutId); })
          .then(res => res.json())
          .then(json => {
            Swal.close();
            if (!json || !json.ok) {
              showPopup({ icon: 'error', title: 'Submission Failed', text: (json && json.message) || 'Submission failed' });
              submitBtn.disabled = false;
              return;
            }
            if (json.warning) {
              showPopup({ icon: 'warning', title: 'Attendance Marked with Warning', text: json.message }).then(() => {
                 window.location.href = json.redirect || 'index.php';
              });
            } else {
              showPopup({ icon: 'success', title: 'Success', text: json.message || 'Attendance recorded.' }).then(() => {
                 window.location.href = json.redirect || 'index.php';
              });
            }
          })
          .catch(err => {
            Swal.close();
            if (err.name === 'AbortError') {
              showPopup({ icon: 'error', title: 'Timeout', text: 'Request timed out' });
            } else {
              showPopup({ icon: 'error', title: 'Network Error', text: 'Could not communicate with the server. Please check your connection.' });
            }
            submitBtn.disabled = false;
          });
      });
    });

    function startInactivityTimer() {
      inactivityTimer = setTimeout(() => {
        const currentStrikes = parseInt(localStorage.getItem(TAB_AWAY_STRIKES_KEY) || '0', 10) + 1;
        localStorage.setItem(TAB_AWAY_STRIKES_KEY, String(currentStrikes));
        const strikesLeft = Math.max(0, TAB_AWAY_MAX_STRIKES - currentStrikes);
        const shouldLock = currentStrikes >= TAB_AWAY_MAX_STRIKES;
        if (shouldLock) {
          localStorage.setItem(TAB_AWAY_LOCK_UNTIL_KEY, String(Date.now() + FENCING_BLOCK_MS));
        }

        var tokenToSend = localStorage.getItem('attendance_token') || '';
        var fpValue = document.getElementById('fingerprint') ? document.getElementById('fingerprint').value : '';
        fetch('log_inactivity.php', {
          method: 'POST',
          body: new URLSearchParams({
            reason: shouldLock ? 'Tab-away limit reached (locked 15m)' : `Tab away beyond 6s grace (${currentStrikes}/${TAB_AWAY_MAX_STRIKES})`,
            should_lock: shouldLock ? '1' : '0',
            token: tokenToSend,
            fingerprint: fpValue
          })
        }).finally(() => {
          Swal.fire({
            icon: 'warning',
            title: shouldLock ? 'Session Locked' : 'Tab Away Warning',
            text: shouldLock ?
              'You used all 3 grace periods. This session is now locked for 15 minutes.' : `You were away for more than 6 seconds. Grace used: ${currentStrikes}/3. Remaining: ${strikesLeft}.`,
            confirmButtonColor: '#00457b'
          }).then(function() {
            if (shouldLock) {
              window.location.href = 'closed.php';
            }
          });
        });
      }, TAB_AWAY_GRACE_MS);
    }

    document.addEventListener('visibilitychange', () => {
      if (!fencingActive) return;
      if (document.hidden) {
        startInactivityTimer();
      } else {
        clearTimeout(inactivityTimer);
      }
    });

    function checkStatus() {
      fetch('status_api.php')
        .then(res => res.json())
        .then(data => {
          if (!data.checkin && !data.checkout) {
            Swal.fire({
              icon: 'info',
              title: 'Attendance Closed',
              text: 'Attendance has now closed!',
              confirmButtonColor: '#00457b'
            }).then(function() {
              location.reload();
            });
          }
        })
        .catch(err => { });
    }

    setInterval(checkStatus, 15000);
</script>
