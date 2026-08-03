<?php
/**
 * Admin Login Page
 * High-Q Solid Academy Biometric Attendance System
 */

require_once __DIR__ . '/../includes/auth.php';

if (is_authenticated()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? $_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $res = login_user($username, $password);
    if ($res['success']) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'redirect' => 'index.php']);
            exit;
        }
        header('Location: index.php');
        exit;
    } else {
        $error = $res['message'];
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $error]);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Admin Login | High-Q Solid Academy</title>
    <link rel="shortcut icon" href="../icon.png" type="image/png"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Hanken+Grotesk:wght@600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script>
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            colors: {
              "on-surface": "#0b1c30",
              "on-surface-variant": "#44474c",
              "outline": "#75777d",
              "primary": "#000000",
              "secondary": "#795900",
              "error": "#ba1a1a",
              "error-container": "#ffdad6",
              "surface": "#f8f9ff",
              "border-subtle": "#E2E8F0",
              "navy-muted": "#1E293B"
            },
            fontFamily: {
              "headline-lg": ["Hanken Grotesk"],
              "body-md": ["Inter"],
              "label-caps": ["JetBrains Mono"]
            }
          }
        }
      }
    </script>
    <style>
        .glass-panel {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .input-focus-ring:focus {
            border-color: #0d1c2e;
            box-shadow: 0 0 0 3px rgba(253, 192, 20, 0.25);
            outline: none;
        }
        .auth-bg-gradient {
            background: radial-gradient(circle at top right, #e5eeff 0%, #ffffff 100%);
        }
        @keyframes subtle-float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }
        .animate-float {
            animation: subtle-float 6s ease-in-out infinite;
        }
    </style>
</head>
<body class="bg-surface font-body-md text-on-surface min-h-screen flex flex-col items-center justify-center auth-bg-gradient p-4">
<div class="w-full max-w-[480px] z-10 flex flex-col items-center">
    <!-- Branding Header -->
    <div class="mb-8 text-center animate-float">
        <div class="mb-4 flex justify-center">
            <div class="w-20 h-20 rounded-2xl overflow-hidden shadow-xl border-4 border-white bg-white p-1 flex items-center justify-center">
                <img src="../logo.png" alt="High-Q Logo" class="w-full h-full object-contain"/>
            </div>
        </div>
        <h1 class="font-headline-lg text-3xl font-bold text-primary mb-1">High-Q Solid Academy</h1>
        <p class="font-body-md text-on-surface-variant text-sm">Admin Management Portal</p>
    </div>

    <!-- Login Card -->
    <div class="glass-panel w-full p-8 rounded-xl shadow-2xl space-y-6">
        <div id="errorAlert" class="<?= $error ? '' : 'hidden' ?> p-4 bg-error-container text-error rounded-lg text-sm font-semibold flex items-center gap-2">
            <span class="material-symbols-outlined">error</span>
            <span id="errorMsg"><?= htmlspecialchars($error) ?></span>
        </div>

        <form class="space-y-6" id="loginForm" method="POST">
            <div class="space-y-2">
                <label class="block text-xs font-semibold text-on-surface-variant uppercase tracking-wider" for="username">Username / Email</label>
                <div class="relative group">
                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-on-surface-variant">person</span>
                    <input class="w-full pl-12 pr-4 py-3 bg-white border border-border-subtle rounded-lg text-on-surface input-focus-ring" id="username" name="username" placeholder="admin" required type="text" value="admin"/>
                </div>
            </div>

            <div class="space-y-2">
                <label class="block text-xs font-semibold text-on-surface-variant uppercase tracking-wider" for="password">Password</label>
                <div class="relative group">
                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-on-surface-variant">lock</span>
                    <input class="w-full pl-12 pr-12 py-3 bg-white border border-border-subtle rounded-lg text-on-surface input-focus-ring" id="password" name="password" placeholder="••••••••••••" required type="password" value="admin123"/>
                    <button class="absolute right-4 top-1/2 -translate-y-1/2 text-on-surface-variant hover:text-primary" onclick="togglePassword()" type="button">
                        <span class="material-symbols-outlined" id="passwordToggleIcon">visibility</span>
                    </button>
                </div>
            </div>

            <button class="w-full py-3.5 bg-primary text-white rounded-lg font-semibold hover:bg-navy-muted active:scale-[0.98] transition-all shadow-lg flex items-center justify-center gap-2" type="submit" id="submitBtn">
                <span class="material-symbols-outlined">login</span> Sign In
            </button>
        </form>

        <div class="text-center pt-2">
            <a href="../index.php" class="text-xs font-semibold text-secondary hover:underline flex items-center justify-center gap-1">
                <span class="material-symbols-outlined text-sm">desktop_windows</span> Switch to Public Kiosk Scanner Mode
            </a>
        </div>
    </div>

    <div class="mt-6 flex items-center gap-2 text-xs text-on-surface-variant opacity-70">
        <span class="material-symbols-outlined text-[16px]">verified_user</span>
        <span>High-Q Solid Academy Biometric System</span>
    </div>
</div>

<script>
    function togglePassword() {
        const input = document.getElementById('password');
        const icon = document.getElementById('passwordToggleIcon');
        if (input.type === 'password') {
            input.type = 'text';
            icon.innerText = 'visibility_off';
        } else {
            input.type = 'password';
            icon.innerText = 'visibility';
        }
    }

    document.getElementById('loginForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('submitBtn');
        const errAlert = document.getElementById('errorAlert');
        const errMsg = document.getElementById('errorMsg');

        errAlert.classList.add('hidden');
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined animate-spin">progress_activity</span> Authenticating...';

        const formData = new FormData(this);

        try {
            const resp = await fetch('login.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            const data = await resp.json();

            if (data.success) {
                btn.innerHTML = '<span class="material-symbols-outlined">check_circle</span> Success!';
                window.location.href = data.redirect || 'index.php';
            } else {
                errMsg.innerText = data.message || 'Login failed';
                errAlert.classList.remove('hidden');
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined">login</span> Sign In';
            }
        } catch (err) {
            errMsg.innerText = 'Server error occurred.';
            errAlert.classList.remove('hidden');
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined">login</span> Sign In';
        }
    });
</script>
</body>
</html>
