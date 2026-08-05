<?php
/**
 * High-Q Solid Academy - Public Attendance Marking Fingerprint Page (Landing Kiosk Terminal)
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

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .scan-animation-container {
        position: relative;
        width: 260px;
        height: 260px;
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
                <span class="px-3 py-1 bg-slate-900 text-amber-400 font-mono font-extrabold tracking-widest text-sm rounded-lg shadow-inner select-none">
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
        if (document.getElementById('pinModalOverlay') && document.getElementById('pinModalOverlay').classList.contains('hidden')) return;
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

<!-- Main Kiosk Terminal Content -->
<main class="w-full flex-grow flex flex-col items-center justify-center p-4 md:p-8 my-auto">
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
            <div class="absolute inset-0 rounded-full border-4 border-amber-400/40 ring-pulse"></div>
            <div class="w-52 h-52 rounded-full bg-surface-container-low border-2 border-border-subtle flex items-center justify-center shadow-inner relative overflow-hidden">
                <span class="material-symbols-outlined text-[90px] text-primary/80" style="font-variation-settings: 'FILL' 1;">fingerprint</span>
                <div class="scan-line"></div>
            </div>
        </div>

        <!-- Manual Kiosk Input Bar (Hardware Simulation) -->
        <div class="w-full max-w-md pt-4 border-t border-border-subtle space-y-3">
            <p class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Manual Kiosk Input / Hardware Simulation</p>
            <div class="flex gap-2">
                <input type="text" id="simAdmInput" placeholder="Enter Admission No. (e.g. HQA/2026/001)" class="flex-1 px-4 py-2.5 bg-surface-container-low border border-border-subtle rounded-xl text-sm font-mono focus:outline-none focus:border-primary">
                <button type="button" onclick="simulateScanFromInput()" class="px-5 py-2.5 bg-primary text-white font-bold text-sm rounded-xl hover:opacity-90 transition-all flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">sensors</span> Scan
                </button>
            </div>
        </div>

        <!-- Admin Portal Quick Action Button -->
        <div class="pt-2">
            <a href="admin/login.php" class="px-5 py-2.5 bg-surface-container-low border border-border-subtle rounded-xl text-xs font-bold text-primary hover:bg-surface-container transition-all flex items-center gap-2 inline-flex">
                <span class="material-symbols-outlined text-sm">admin_panel_settings</span> Open Admin Portal
            </a>
        </div>
    </div>
</main>

<!-- Success Result Overlay Modal -->
<div id="successOverlay" class="fixed inset-0 z-50 bg-black/60 backdrop-blur-md flex items-center justify-center p-4 opacity-0 pointer-events-none transition-all duration-300">
    <div class="bg-surface-container-lowest border border-border-subtle rounded-3xl p-8 md:p-10 max-w-md w-full shadow-2xl text-center space-y-6 transform transition-all duration-300">
        
        <div class="w-20 h-20 rounded-full bg-primary text-white flex items-center justify-center mx-auto text-3xl font-extrabold shadow-lg" id="modalInitials">
            HQ
        </div>

        <div class="space-y-1">
            <span class="px-3 py-1 bg-amber-400 text-slate-900 font-bold text-xs uppercase tracking-wider rounded-full" id="modalBadge">CHECK-IN SUCCESS</span>
            <h2 class="text-2xl font-extrabold text-on-surface pt-2" id="modalName">Student Name</h2>
            <p class="text-xs text-on-surface-variant font-mono" id="modalSub">SS2 • HQA/2026/001</p>
        </div>

        <div class="bg-surface-container-low p-4 rounded-2xl space-y-2 border border-border-subtle">
            <div class="flex justify-between items-center text-xs">
                <span class="text-on-surface-variant">Time Recorded:</span>
                <span class="font-bold font-mono text-primary text-sm" id="modalTime">08:02 AM</span>
            </div>
            <div class="flex justify-between items-center text-xs">
                <span class="text-on-surface-variant">Attendance Status:</span>
                <span class="font-bold text-green-700" id="modalStatus">Present</span>
            </div>
        </div>

        <p class="text-xs text-on-surface-variant" id="modalMessage">Welcome to High-Q Solid Academy!</p>

        <button type="button" onclick="document.getElementById('successOverlay').classList.add('opacity-0', 'pointer-events-none')" class="w-full py-3 bg-primary text-white font-bold text-sm rounded-xl hover:opacity-90 transition-all">
            Dismiss
        </button>
    </div>
</div>

<script>
    async function triggerBiometricAttendance(studentId, admissionNo = '') {
        const formData = new FormData();
        if (studentId) formData.append('student_id', studentId);
        if (admissionNo) formData.append('admission_number', admissionNo);

        try {
            const resp = await fetch('api/attendance.php?action=mark', {
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
