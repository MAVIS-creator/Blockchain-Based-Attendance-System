<?php
/**
 * Admin Registration / Signup Page
 * High-Q Solid Academy Biometric Attendance System
 */

require_once __DIR__ . '/../includes/auth.php';

if (is_authenticated()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($fullname) || empty($username) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $res = register_admin_user($fullname, $username, $password);
        if ($res['success']) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true, 
                    'pending' => !empty($res['pending']),
                    'message' => $res['message'] ?? 'Account created successfully.',
                    'redirect' => !empty($res['pending']) ? 'login.php?pending=1' : 'index.php'
                ]);
                exit;
            }
            header('Location: ' . (!empty($res['pending']) ? 'login.php?pending=1' : 'index.php'));
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
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Create Admin Account | High-Q Solid Academy</title>
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
              "body-md": ["Inter"]
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
    </style>
</head>
<body class="bg-surface font-body-md text-on-surface min-h-screen flex flex-col items-center justify-center auth-bg-gradient p-4">
<div class="w-full max-w-[480px] z-10 flex flex-col items-center">
    <!-- Branding Header -->
    <div class="mb-6 text-center">
        <div class="mb-3 flex justify-center">
            <div class="w-16 h-16 rounded-2xl overflow-hidden shadow-xl border-4 border-white bg-white p-1 flex items-center justify-center">
                <img src="../logo.png" alt="High-Q Logo" class="w-full h-full object-contain"/>
            </div>
        </div>
        <h1 class="font-headline-lg text-2xl font-bold text-primary mb-1">Create Admin Account</h1>
        <p class="font-body-md text-on-surface-variant text-sm">Register a new administrator for High-Q Solid Academy</p>
    </div>

    <!-- Signup Card -->
    <div class="glass-panel w-full p-8 rounded-xl shadow-2xl space-y-6">
        <div id="errorAlert" class="<?= $error ? '' : 'hidden' ?> p-4 bg-error-container text-error rounded-lg text-sm font-semibold flex items-center gap-2">
            <span class="material-symbols-outlined">error</span>
            <span id="errorMsg"><?= htmlspecialchars($error) ?></span>
        </div>

        <form class="space-y-4" id="registerForm" method="POST">
            <div class="space-y-1.5">
                <label class="block text-xs font-semibold text-on-surface-variant uppercase tracking-wider" for="fullname">Full Name</label>
                <div class="relative group">
                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-on-surface-variant">badge</span>
                    <input class="w-full pl-12 pr-4 py-3 bg-white border border-border-subtle rounded-lg text-on-surface input-focus-ring text-sm" id="fullname" name="fullname" placeholder="e.g. Administrator Name" required type="text"/>
                </div>
            </div>

            <div class="space-y-1.5">
                <label class="block text-xs font-semibold text-on-surface-variant uppercase tracking-wider" for="username">Username / Email</label>
                <div class="relative group">
                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-on-surface-variant">person</span>
                    <input class="w-full pl-12 pr-4 py-3 bg-white border border-border-subtle rounded-lg text-on-surface input-focus-ring text-sm" id="username" name="username" placeholder="Choose a username" required type="text"/>
                </div>
            </div>

            <div class="space-y-1.5">
                <label class="block text-xs font-semibold text-on-surface-variant uppercase tracking-wider" for="password">Password</label>
                <div class="relative group">
                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-on-surface-variant">lock</span>
                    <input class="w-full pl-12 pr-4 py-3 bg-white border border-border-subtle rounded-lg text-on-surface input-focus-ring text-sm" id="password" name="password" placeholder="Create a strong password" required type="password"/>
                </div>
            </div>

            <button class="w-full py-3.5 bg-primary text-white rounded-lg font-semibold hover:bg-navy-muted active:scale-[0.98] transition-all shadow-lg flex items-center justify-center gap-2 mt-2" type="submit" id="submitBtn">
                <span class="material-symbols-outlined">person_add</span> Create Account
            </button>
        </form>

        <div class="text-center pt-2 border-t border-border-subtle flex justify-between text-xs">
            <a href="login.php" class="text-secondary font-bold hover:underline flex items-center gap-1">
                <span class="material-symbols-outlined text-sm">arrow_back</span> Already have an account? Sign In
            </a>
            <a href="../index.php" class="text-on-surface-variant hover:underline flex items-center gap-1">
                Kiosk Mode
            </a>
        </div>
    </div>
</div>

<script>
    document.getElementById('registerForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('submitBtn');
        const errAlert = document.getElementById('errorAlert');
        const errMsg = document.getElementById('errorMsg');

        errAlert.classList.add('hidden');
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined animate-spin">progress_activity</span> Creating Account...';

        const formData = new FormData(this);

        try {
            const resp = await fetch('register.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            const data = await resp.json();

            if (data.success) {
                btn.innerHTML = '<span class="material-symbols-outlined">check_circle</span> Registered!';
                if (data.pending) {
                    alert(data.message || 'Registration submitted! Your account is pending approval by the High-Q Main Site Super Administrator.');
                }
                window.location.href = data.redirect || 'index.php';
            } else {
                errMsg.innerText = data.message || 'Registration failed';
                errAlert.classList.remove('hidden');
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined">person_add</span> Create Account';
            }
        } catch (err) {
            errMsg.innerText = 'Server error occurred.';
            errAlert.classList.remove('hidden');
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined">person_add</span> Create Account';
        }
    });
</script>
</body>
</html>
