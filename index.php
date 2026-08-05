<?php
/**
 * High-Q Solid Academy - Public Attendance Marking Fingerprint Page
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/db.php';

$pdo = get_db_connection();
$stmtPin = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'terminal_pin'");
$stmtPin->execute();
$terminalPin = trim((string)$stmtPin->fetchColumn());

$isPinRequired = !empty($terminalPin);
$isUnlocked = !$isPinRequired || !empty($_SESSION['terminal_unlocked']);

// Generate Alphanumeric CAPTCHA Code for Bot Verification
if (empty($_SESSION['terminal_captcha'])) {
    $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $_SESSION['terminal_captcha'] = substr(str_shuffle($chars), 0, 4);
}
$captchaCode = $_SESSION['terminal_captcha'];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>High-Q Solid Academy | Biometric Attendance Terminal</title>
    <link rel="shortcut icon" href="icon.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Hanken+Grotesk:wght@600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const HighQSwal = Swal.mixin({
            customClass: {
                confirmButton: 'px-5 py-2.5 bg-primary text-white font-semibold rounded-lg text-sm mx-1 shadow hover:opacity-90 transition-colors',
                cancelButton: 'px-5 py-2.5 border border-border-subtle text-on-surface font-semibold rounded-lg text-sm mx-1 hover:bg-surface-container transition-colors',
                popup: 'rounded-2xl border border-border-subtle font-body-md shadow-2xl p-6',
                title: 'font-headline-lg font-bold text-on-surface text-xl',
                htmlContainer: 'text-on-surface-variant text-sm'
            },
            buttonsStyling: false
        });
    </script>
    <style>
        .scan-animation-container {
            position: relative;
            width: 280px;
            height: 280px;
        }
        .scan-line {
            width: 100%;
            height: 3px;
            background: linear-gradient(to right, transparent, #fdc014, transparent);
            position: absolute;
            top: 0;
            left: 0;
            animation: scanMove 2.5s ease-in-out infinite alternate;
            box-shadow: 0 0 15px #fdc014;
        }
        @keyframes scanMove {
            0% { top: 10%; }
            100% { top: 90%; }
        }
        .ring-pulse {
            animation: pulseGlow 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        @keyframes pulseGlow {
            0%, 100% { opacity: 0.2; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(1.05); }
        }
        .shake-animation {
            animation: shakeKeypad 0.4s ease-in-out;
        }
        @keyframes shakeKeypad {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-10px); }
            40%, 80% { transform: translateX(10px); }
        }
    </style>
</head>
<body class="bg-background font-body-md text-on-surface min-h-screen flex flex-col justify-between overflow-x-hidden kiosk-gradient">

<?php if (!$isUnlocked): ?>
<!-- Terminal PIN Security Keypad Overlay -->
<div id="pinModalOverlay" class="fixed inset-0 z-[100] bg-slate-950/90 backdrop-blur-xl flex items-center justify-center p-4">
    <div id="pinModalCard" class="w-full max-w-md bg-white rounded-3xl p-6 md:p-8 border border-slate-200 shadow-2xl text-center space-y-5">
        <div class="space-y-1.5">
            <div class="w-14 h-14 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center mx-auto border border-amber-200/60 shadow-inner">
                <span class="material-symbols-outlined text-3xl">shield_lock</span>
            </div>
            <h2 class="font-headline-lg font-bold text-2xl text-slate-900">Protected Kiosk Terminal</h2>
            <p class="text-xs text-slate-500 max-w-xs mx-auto">Authorized Operator Verification Required</p>
        </div>

        <!-- Security Disclaimer Notice -->
        <div class="p-3 bg-amber-50 border border-amber-200/70 rounded-xl text-left flex items-start gap-2.5">
            <span class="material-symbols-outlined text-amber-700 text-lg flex-shrink-0 mt-0.5">warning</span>
            <p class="text-[11px] leading-tight text-amber-900">
                <strong>Operator Notice:</strong> Do NOT share or attempt to bypass the PIN. If lost, use the Emergency Master Recovery Key or reset in Admin Settings.
            </p>
        </div>

        <!-- Bot Verification CAPTCHA Badge -->
        <div class="bg-slate-100 p-3 rounded-2xl border border-slate-200 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-slate-500 text-sm">smart_toy</span>
                <span class="text-xs font-bold text-slate-700 uppercase">Bot Check:</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="px-3 py-1 bg-slate-900 text-amber-400 font-mono font-extrabold tracking-widest text-sm rounded-lg shadow-inner select-none select-none">
                    <?= $captchaCode ?>
                </span>
                <input type="text" id="captchaInput" uppercase placeholder="Code" maxlength="4" class="w-20 px-2 py-1 bg-white border border-slate-300 rounded-lg text-center text-xs font-mono font-bold uppercase focus:outline-none focus:border-amber-500">
            </div>
        </div>

        <!-- PIN Dots Display -->
        <div class="flex justify-center items-center gap-4 py-1">
            <div class="pin-dot w-4 h-4 rounded-full border-2 border-slate-300 bg-transparent transition-all"></div>
            <div class="pin-dot w-4 h-4 rounded-full border-2 border-slate-300 bg-transparent transition-all"></div>
            <div class="pin-dot w-4 h-4 rounded-full border-2 border-slate-300 bg-transparent transition-all"></div>
            <div class="pin-dot w-4 h-4 rounded-full border-2 border-slate-300 bg-transparent transition-all"></div>
        </div>

        <div id="pinErrorMsg" class="text-xs font-bold text-red-600 min-h-[18px] hidden"></div>

        <!-- On-Screen Numeric Keypad -->
        <div class="grid grid-cols-3 gap-2.5 max-w-[260px] mx-auto">
            <?php for ($d = 1; $d <= 9; $d++): ?>
                <button type="button" onclick="appendPin('<?= $d ?>')" class="w-14 h-14 rounded-2xl bg-slate-100 hover:bg-slate-200 active:scale-95 text-lg font-bold text-slate-800 transition-all shadow-sm flex items-center justify-center mx-auto">
                    <?= $d ?>
                </button>
            <?php endfor; ?>
            <button type="button" onclick="clearPin()" class="w-14 h-14 rounded-2xl bg-slate-100 hover:bg-red-50 text-red-600 active:scale-95 text-xs font-bold transition-all shadow-sm flex items-center justify-center mx-auto">
                CLEAR
            </button>
            <button type="button" onclick="appendPin('0')" class="w-14 h-14 rounded-2xl bg-slate-100 hover:bg-slate-200 active:scale-95 text-lg font-bold text-slate-800 transition-all shadow-sm flex items-center justify-center mx-auto">
                0
            </button>
            <button type="button" onclick="backspacePin()" class="w-14 h-14 rounded-2xl bg-slate-100 hover:bg-slate-200 active:scale-95 text-slate-700 transition-all shadow-sm flex items-center justify-center mx-auto">
                <span class="material-symbols-outlined text-lg">backspace</span>
            </button>
        </div>

        <!-- Emergency Master Key Entry Mode Toggle -->
        <div class="pt-2 border-t border-slate-100 flex items-center justify-between">
            <button type="button" onclick="promptMasterRecovery()" class="text-xs font-bold text-amber-700 hover:underline flex items-center gap-1">
                <span class="material-symbols-outlined text-sm">key_visual</span> Emergency Master Key
            </button>
            <button type="button" id="submitPinBtn" onclick="submitPin()" class="px-5 py-2.5 bg-slate-900 text-white font-bold rounded-xl hover:bg-slate-800 text-xs shadow-md flex items-center gap-1.5">
                <span class="material-symbols-outlined text-sm">key</span> Unlock
            </button>
        </div>
    </div>
</div>

<script>
    let currentPinInput = '';
    const pinDots = document.querySelectorAll('.pin-dot');

    function updatePinDots() {
        pinDots.forEach((dot, index) => {
            if (index < currentPinInput.length) {
                dot.classList.remove('bg-transparent', 'border-slate-300');
                dot.classList.add('bg-amber-500', 'border-amber-500', 'scale-110');
            } else {
                dot.classList.remove('bg-amber-500', 'border-amber-500', 'scale-110');
                dot.classList.add('bg-transparent', 'border-slate-300');
            }
        });
    }

    function appendPin(num) {
        if (currentPinInput.length < 8) {
            currentPinInput += num;
            updatePinDots();
            document.getElementById('pinErrorMsg').classList.add('hidden');
        }
        if (currentPinInput.length === 4) {
            submitPin();
        }
    }

    function clearPin() {
        currentPinInput = '';
        updatePinDots();
        document.getElementById('pinErrorMsg').classList.add('hidden');
    }

    function backspacePin() {
        currentPinInput = currentPinInput.slice(0, -1);
        updatePinDots();
        document.getElementById('pinErrorMsg').classList.add('hidden');
    }

    document.addEventListener('keydown', function(e) {
        if (document.getElementById('pinModalOverlay').classList.contains('hidden')) return;
        if (e.key >= '0' && e.key <= '9') {
            appendPin(e.key);
        } else if (e.key === 'Backspace') {
            backspacePin();
        } else if (e.key === 'Enter') {
            submitPin();
        }
    });

    async function submitPin() {
        if (!currentPinInput) return;
        const captchaVal = document.getElementById('captchaInput').value.trim();
        const btn = document.getElementById('submitPinBtn');
        const errEl = document.getElementById('pinErrorMsg');
        const card = document.getElementById('pinModalCard');

        if (!captchaVal) {
            errEl.innerText = 'Please enter the 4-character Bot Check Code above.';
            errEl.classList.remove('hidden');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined animate-spin">progress_activity</span> Verifying...';

        const formData = new FormData();
        formData.append('pin', currentPinInput);
        formData.append('captcha', captchaVal);

        try {
            const resp = await fetch('api/settings.php?action=verify_pin', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();

            if (data.success) {
                document.getElementById('pinModalOverlay').classList.add('hidden');
            } else {
                errEl.innerText = data.message || 'Incorrect Terminal PIN';
                errEl.classList.remove('hidden');
                card.classList.add('shake-animation');
                setTimeout(() => card.classList.remove('shake-animation'), 450);
                clearPin();
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined">key</span> Unlock';
            }
        } catch (e) {
            errEl.innerText = 'Network error verifying PIN';
            errEl.classList.remove('hidden');
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined">key</span> Unlock';
        }
    }

    async function promptMasterRecovery() {
        const { value: key } = await HighQSwal.fire({
            title: 'Master Emergency Key Recovery',
            text: 'Enter the Master Emergency Key (set in environment config) to unlock the kiosk terminal:',
            input: 'password',
            inputPlaceholder: 'Enter Master Key (e.g. HQ-MASTER-RECOVER-2026)',
            showCancelButton: true,
            confirmButtonText: 'Unlock Kiosk'
        });

        if (key) {
            const captchaVal = document.getElementById('captchaInput').value.trim();
            const formData = new FormData();
            formData.append('pin', key);
            formData.append('captcha', captchaVal);

            const resp = await fetch('api/settings.php?action=verify_pin', { method: 'POST', body: formData });
            const data = await resp.json();
            if (data.success) {
                document.getElementById('pinModalOverlay').classList.add('hidden');
                HighQSwal.fire('Unlocked', 'Terminal unlocked via Master Emergency Key.', 'success');
            } else {
                HighQSwal.fire('Recovery Failed', data.message || 'Invalid Master Recovery Key.', 'error');
            }
        }
    }
</script>
<?php endif; ?>

<!-- Header -->
<header class="flex items-center justify-between z-20 p-8">
    <div class="flex items-center gap-4">
        <div class="w-16 h-16 rounded-2xl bg-white p-1 shadow-md border border-border-subtle flex items-center justify-center overflow-hidden">
            <img src="logo.png" alt="High-Q Solid Academy Logo" class="w-full h-full object-contain"/>
        </div>
        <div>
            <h1 class="font-title-md text-2xl font-bold text-on-surface">High-Q Solid Academy</h1>
            <p class="text-xs text-on-surface-variant uppercase tracking-widest font-semibold">Biometric Fingerprint Attendance Marking Terminal</p>
        </div>
    </div>
    <div class="flex items-center gap-6">
        <a href="admin/login.php" class="px-4 py-2 bg-surface-container-lowest border border-border-subtle rounded-xl text-xs font-bold text-primary hover:bg-surface-container transition-colors shadow-sm flex items-center gap-2">
            <span class="material-symbols-outlined text-sm">admin_panel_settings</span> Admin Portal
        </a>
        <div class="text-right">
            <div class="font-display-lg text-4xl font-bold text-primary leading-none" id="kioskTime">00:00:00</div>
            <div class="text-sm font-semibold text-on-surface-variant mt-1" id="kioskDate">--</div>
        </div>
    </div>
</header>

<!-- Main Scanner Area -->
<main class="w-full flex-grow flex flex-col items-center justify-center p-4 md:p-8 relative my-auto">
    <!-- Scanner Kiosk Panel -->
    <div class="w-full max-w-[800px] bg-surface-container-lowest border border-border-subtle rounded-3xl p-8 md:p-12 shadow-2xl flex flex-col items-center text-center space-y-8 relative overflow-hidden">
        
        <div class="space-y-2">
            <span class="px-4 py-1.5 bg-surface-container-low border border-border-subtle text-primary font-bold text-xs uppercase tracking-widest rounded-full">
                BIOMETRIC KIOSK TERMINAL
            </span>
            <h1 class="text-3xl md:text-5xl font-extrabold text-on-surface tracking-tight">Place Finger on Glass Reader</h1>
            <p class="text-on-surface-variant text-sm md:text-base max-w-md mx-auto">
                Press your registered finger firmly onto the DigitalPersona scanner window to log your daily arrival or departure.
            </p>
        </div>

        <!-- Animated Scanner Ring -->
        <div class="scan-animation-container flex items-center justify-center">
            <div class="absolute inset-0 rounded-full border-4 border-secondary-container/40 ring-pulse"></div>
            <div class="w-56 h-56 rounded-full bg-surface-container-low border-2 border-border-subtle flex items-center justify-center shadow-inner relative overflow-hidden">
                <span class="material-symbols-outlined text-[100px] text-primary/80" style="font-variation-settings: 'FILL' 1;">fingerprint</span>
                <div class="scan-line"></div>
            </div>
        </div>

        <!-- Manual Kiosk Simulator Bar (For Testing without hardware) -->
        <div class="w-full max-w-md pt-4 border-t border-border-subtle space-y-3">
            <p class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Manual Kiosk Input / Hardware Simulation</p>
            <div class="flex gap-2">
                <input type="text" id="simAdmInput" placeholder="Enter Admission No. (e.g. HQA/2026/001)" class="flex-1 px-4 py-2.5 bg-surface-container-low border border-border-subtle rounded-xl text-sm font-mono focus:outline-none focus:border-primary">
                <button type="button" onclick="simulateScanFromInput()" class="px-5 py-2.5 bg-primary text-white font-bold text-sm rounded-xl hover:opacity-90 transition-all flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">sensors</span> Scan
                </button>
            </div>
        </div>

    </div>
</main>

<!-- Result Overlay (Matches & Verification) -->
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-md transition-all duration-300 opacity-0 pointer-events-none translate-y-4" id="successOverlay">
    <div class="bg-surface-container-lowest w-full max-w-xl rounded-3xl overflow-hidden shadow-2xl p-8 flex flex-col items-center text-center space-y-6">
        <div class="w-24 h-24 rounded-full bg-green-100 text-green-700 flex items-center justify-center font-bold text-3xl" id="modalInitials">
            --
        </div>

        <div class="space-y-1">
            <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-xs font-bold uppercase tracking-wide" id="modalBadge">CHECK-IN SUCCESS</span>
            <h3 class="font-headline-lg text-3xl font-bold text-primary pt-2" id="modalName">Student Name</h3>
            <p class="text-sm font-semibold text-on-surface-variant" id="modalSub">Class &bull; Admission Number</p>
        </div>

        <div class="w-full bg-surface-container-low p-4 rounded-2xl flex justify-around text-center text-xs">
            <div>
                <p class="text-on-surface-variant uppercase font-semibold">Time Recorded</p>
                <p class="text-base font-bold text-primary" id="modalTime">--</p>
            </div>
            <div class="h-8 w-[1px] bg-border-subtle"></div>
            <div>
                <p class="text-on-surface-variant uppercase font-semibold">Attendance Status</p>
                <p class="text-base font-bold text-primary" id="modalStatus">Present</p>
            </div>
        </div>

        <p class="text-xs text-on-surface-variant italic" id="modalMessage">Welcome to High-Q Solid Academy! Have a great day.</p>
    </div>
</div>

<!-- Footer / Testing Controls -->
<footer class="flex justify-between items-center z-20 p-8 border-t border-border-subtle/40 text-xs">
    <div class="flex items-center gap-4 text-on-surface-variant font-semibold">
        <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">wifi</span> ONLINE</span>
        <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">database</span> DB SYNCED</span>
    </div>
</footer>

<script>
    function updateClock() {
        const now = new Date();
        document.getElementById('kioskTime').innerText = now.toLocaleTimeString('en-US', { hour12: false });
        document.getElementById('kioskDate').innerText = now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    }
    setInterval(updateClock, 1000);
    updateClock();

    async function triggerBiometricAttendance(studentId, admissionNo = '') {
        const formData = new FormData();
        if (studentId) formData.append('student_id', studentId);
        if (admissionNo) formData.append('admission_number', admissionNo);

        try {
            const resp = await fetch('api/attendance.php?action=record_biometric', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();

            if (data.success) {
                showResultModal(data);
            } else {
                HighQSwal.fire('Attendance Notice', data.message || 'Student attendance match failed.', 'warning');
            }
        } catch (e) {
            console.error(e);
        }
    }

    function showResultModal(data) {
        const overlay = document.getElementById('successOverlay');
        const st = data.student || {};

        document.getElementById('modalInitials').innerText = st.name ? st.name.split(' ').map(n => n[0]).join('') : 'HQ';
        document.getElementById('modalBadge').innerText = data.type === 'check_out' ? 'CHECK-OUT SUCCESS' : (data.type === 'completed' ? 'ALREADY RECORDED' : 'CHECK-IN SUCCESS');
        document.getElementById('modalName').innerText = st.name || 'Student';
        document.getElementById('modalSub').innerText = `${st.class || ''} \u2022 ${st.admission_number || ''}`;
        document.getElementById('modalTime').innerText = data.time || new Date().toLocaleTimeString();
        document.getElementById('modalStatus').innerText = data.status || 'Present';
        document.getElementById('modalMessage').innerText = data.message || 'Welcome to High-Q Solid Academy!';

        overlay.classList.remove('opacity-0', 'pointer-events-none', 'translate-y-4');
        overlay.classList.add('opacity-100', 'translate-y-0');

        // Automatically hide after 5 seconds
        setTimeout(() => {
            overlay.classList.add('opacity-0', 'pointer-events-none', 'translate-y-4');
            overlay.classList.remove('opacity-100', 'translate-y-0');
        }, 5000);
    }

    function simulateScanFromInput() {
        const val = document.getElementById('simAdmInput').value.trim();
        if (!val) {
            HighQSwal.fire('Input Required', 'Please enter an admission number to simulate.', 'warning');
            return;
        }
        triggerBiometricAttendance(null, val);
    }

    // Poll local C# Biometric Service for real scanner matches
    async function pollBiometricService() {
        const modal = document.getElementById('pinModalOverlay');
        if (modal && !modal.classList.contains('hidden')) return;

        try {
            const resp = await fetch('http://localhost:8080/terminal_scan_event');
            const event = await resp.json();
            if (event && event.matched && event.student_id) {
                triggerBiometricAttendance(event.student_id, event.admission_number);
            }
        } catch (e) {
            // Quiet fail if desktop service is offline
        }
    }
    setInterval(pollBiometricService, 1500);
</script>
</body>
</html>
