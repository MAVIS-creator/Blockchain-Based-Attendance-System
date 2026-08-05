<?php
/**
 * Public Kiosk Attendance Terminal
 * High-Q Solid Academy Biometric Attendance System
 */
require_once __DIR__ . '/includes/db.php';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>High-Q Solid Academy | Attendance Terminal</title>
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
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "on-surface": "#0b1c30",
                        "on-surface-variant": "#44474c",
                        "primary": "#000000",
                        "secondary": "#795900",
                        "border-subtle": "#E2E8F0",
                        "surface-container": "#e5eeff",
                        "surface-container-low": "#eff4ff",
                        "surface-container-lowest": "#ffffff",
                        "secondary-container": "#fdc014",
                        "background": "#f8f9ff"
                    },
                    fontFamily: {
                        "title-md": ["Hanken Grotesk"],
                        "display-lg": ["Hanken Grotesk"],
                        "headline-lg": ["Hanken Grotesk"],
                        "body-md": ["Inter"],
                        "code-snippet": ["JetBrains Mono"]
                    }
                }
            }
        }
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
            z-index: 10;
            animation: scan 2.5s infinite linear;
            box-shadow: 0 0 15px #fdc014;
        }
        @keyframes scan {
            0% { top: 5%; opacity: 0; }
            15% { opacity: 1; }
            85% { opacity: 1; }
            100% { top: 95%; opacity: 0; }
        }
        .kiosk-gradient {
            background: radial-gradient(circle at center, #ffffff 0%, #f0f4ff 100%);
        }
    </style>
</head>
<body class="bg-background text-on-background min-h-screen overflow-hidden kiosk-gradient flex flex-col justify-between p-8 font-body-md select-none">

<!-- Header -->
<header class="flex items-center justify-between z-20">
    <div class="flex items-center gap-4">
        <div class="w-16 h-16 rounded-2xl bg-black text-white font-bold text-2xl flex items-center justify-center shadow-lg border-2 border-white">
            HQ
        </div>
        <div>
            <h1 class="font-title-md text-2xl font-bold text-on-surface">High-Q Solid Academy</h1>
            <p class="text-xs text-on-surface-variant uppercase tracking-widest font-semibold">Biometric Kiosk Attendance Terminal</p>
        </div>
    </div>
    <div class="text-right">
        <div class="font-display-lg text-4xl font-bold text-primary leading-none" id="kioskTime">00:00:00</div>
        <div class="text-sm font-semibold text-on-surface-variant mt-1" id="kioskDate">--</div>
    </div>
</header>

<!-- Main Scanner Area -->
<main class="flex-1 flex flex-col items-center justify-center relative z-10">
    <div class="text-center mb-8">
        <h2 class="font-headline-lg text-3xl font-bold text-primary mb-1" id="kioskPromptTitle">Place Your Registered Finger</h2>
        <p class="text-on-surface-variant text-base" id="kioskPromptSub">Place finger firmly on the DigitalPersona scanner glass to mark attendance</p>
    </div>

    <!-- Scanner Graphics -->
    <div class="relative flex items-center justify-center">
        <div class="scan-animation-container bg-surface-container-lowest rounded-3xl shadow-2xl border border-border-subtle flex items-center justify-center overflow-hidden">
            <div class="scan-line"></div>
            <span class="material-symbols-outlined text-9xl text-primary opacity-80" id="terminalFpIcon">fingerprint</span>
        </div>
    </div>

    <!-- Status Badge -->
    <div class="mt-10 flex items-center gap-3 px-6 py-2.5 bg-surface-container-low border border-border-subtle rounded-full text-xs font-semibold text-on-surface">
        <span class="w-3 h-3 bg-green-500 rounded-full animate-pulse"></span>
        <span id="terminalStatusText">Scanner Active & Locked (Listening on http://localhost:8080)</span>
    </div>
</main>

<!-- Result Overlay (Matches & Verification) -->
<div class="fixed inset-0 z-50 flex items-center justify-center bg-primary/40 backdrop-blur-md transition-all duration-300 opacity-0 pointer-events-none translate-y-4" id="successOverlay">
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
<footer class="flex justify-between items-center z-20 pt-4 border-t border-border-subtle/40 text-xs">
    <div class="flex items-center gap-4 text-on-surface-variant font-semibold">
        <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">wifi</span> ONLINE</span>
        <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">database</span> DB SYNCED</span>
    </div>

    <!-- Demo Simulation Trigger -->
    <div class="flex items-center gap-2">
        <input type="text" id="simAdmInput" placeholder="Simulate Adm No (e.g. HQ/2026/001)" class="px-3 py-1.5 bg-white border border-border-subtle rounded-lg text-xs">
        <button onclick="simulateScanFromInput()" class="px-4 py-1.5 bg-primary text-white font-semibold rounded-lg hover:opacity-90">Simulate Touch</button>
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
        try {
            const resp = await fetch('http://localhost:8080/terminal_scan_event');
            const event = await resp.json();
            if (event && event.matched && event.student_id) {
                triggerBiometricAttendance(event.student_id, event.admission_number);
            }
        } catch (e) {
            // Quiet fail if service is offline
        }
    }
    setInterval(pollBiometricService, 1500);
</script>
</body>
</html>
