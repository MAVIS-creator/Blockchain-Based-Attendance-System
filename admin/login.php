<?php
session_start();
$error = '';

// Ensure accounts file exists with a default superadmin (only on first run)
$accountsFile = __DIR__ . '/accounts.json';
if (!file_exists($accountsFile)) {
    // Default superadmin: username Mavis with the password supplied by user
    $defaultPassword = '.*123$<>Callmelater.,12';
    $default = [
        'Mavis' => [
            'password' => password_hash($defaultPassword, PASSWORD_DEFAULT),
            'name' => 'Mavis',
            'avatar' => null,
            'role' => 'superadmin'
        ]
    ];
    file_put_contents($accountsFile, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$accounts = json_decode(file_get_contents($accountsFile), true) ?: [];

// Normalize accounts storage: if the file contains a sequential array (e.g. []), convert to associative
if (!is_array($accounts)) $accounts = [];
// If accounts file is empty or not keyed by username, ensure default superadmin exists
if (!isset($accounts['Mavis'])) {
    // Create Mavis only if not present
    $defaultPassword = '.*123$<>Callmelater.,12';
    $accounts['Mavis'] = [
        'password' => password_hash($defaultPassword, PASSWORD_DEFAULT),
        'name' => 'Mavis',
        'avatar' => null,
        'role' => 'superadmin'
    ];
    file_put_contents($accountsFile, json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // find account case-insensitively
    $foundUser = null;
    foreach ($accounts as $u => $info) {
        if (strcasecmp($u, $username) === 0) {
            $foundUser = $u;
            break;
        }
    }

    // if not found but username requested is Mavis (case-insensitive), ensure Mavis exists (should have been created earlier)
    if ($foundUser === null && strcasecmp($username, 'Mavis') === 0 && isset($accounts['Mavis'])) {
        $foundUser = 'Mavis';
    }

    $authenticated = false;
    if ($foundUser !== null) {
        $stored = $accounts[$foundUser]['password'] ?? null;
        if ($stored && password_verify($password, $stored)) {
            $authenticated = true;
            $usernameToUse = $foundUser;
        }
    }

    // fallback: if no stored account but user typed the exact default password, allow and create account
    if (!$authenticated && strcasecmp($username, 'Mavis') === 0) {
        $defaultPassword = '.*123$<>Callmelater.,12';
        if ($password === $defaultPassword) {
            // create or overwrite the Mavis account entry
            $accounts['Mavis'] = [
                'password' => password_hash($defaultPassword, PASSWORD_DEFAULT),
                'name' => 'Mavis',
                'avatar' => null,
                'role' => 'superadmin'
            ];
            file_put_contents($accountsFile, json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $authenticated = true;
            $usernameToUse = 'Mavis';
        }
    }

    if ($authenticated) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user'] = $usernameToUse;
        $_SESSION['admin_name'] = $accounts[$usernameToUse]['name'] ?? $usernameToUse;
        $_SESSION['admin_avatar'] = $accounts[$usernameToUse]['avatar'] ?? null;
        $_SESSION['admin_role'] = $accounts[$usernameToUse]['role'] ?? 'admin';
        // Track session
        $sessionsFile = __DIR__ . '/sessions.json';
        $activeSessions = file_exists($sessionsFile) ? json_decode(file_get_contents($sessionsFile), true) : [];
        if (!is_array($activeSessions)) $activeSessions = [];
        $activeSessions[session_id()] = [
            'user' => $usernameToUse,
            'ip' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            'login_time' => time(),
            'last_activity' => time()
        ];
        file_put_contents($sessionsFile, json_encode($activeSessions, JSON_PRETTY_PRINT));
        header('Location: index.php');
        exit;
    } else {
        $error = "Invalid credentials";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Admin Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="../asset/favicon.ico">
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            background: var(--surface-container-lowest);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 24px;
        }

        .login-card {
            background: var(--surface);
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 12px 32px rgba(11, 23, 41, 0.08);
            border: 1px solid var(--outline-variant);
        }

        .login-card h1 {
            color: var(--on-surface);
            font-size: 1.8rem;
            font-weight: 800;
            margin: 0 0 8px;
            text-align: center;
            letter-spacing: -0.03em;
        }

        .login-card p.subtitle {
            color: var(--on-surface-variant);
            text-align: center;
            margin: 0 0 32px;
            font-size: 0.95rem;
        }

        .st-input-group {
            margin-bottom: 20px;
        }

        .st-input-group label {
            display: block;
            font-weight: 600;
            color: var(--on-surface-variant);
            margin-bottom: 8px;
            font-size: 0.85rem;
        }

        .st-input {
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid var(--outline-variant);
            background: var(--surface-container-low);
            color: var(--on-surface);
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }

        .st-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(31, 93, 153, 0.15);
            background: var(--surface);
        }

        /* Password Wrapper for eye icon */
        .pwd-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .pwd-wrapper .st-input {
            padding-right: 44px;
        }

        .pwd-toggle {
            position: absolute;
            right: 12px;
            color: var(--on-surface-variant);
            cursor: pointer;
            user-select: none;
            transition: color 0.2s;
        }

        .pwd-toggle:hover {
            color: var(--primary);
        }

        .submit-btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 12px;
            background: var(--primary);
            color: #fff;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
        }

        .submit-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .error-msg {
            background: var(--error-container);
            color: var(--error);
            padding: 12px 16px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: shake 0.4s ease-in-out;
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            20%,
            60% {
                transform: translateX(-4px);
            }

            40%,
            80% {
                transform: translateX(4px);
            }
        }

        .footer {
            text-align: center;
            margin-top: 32px;
            color: var(--outline);
            font-size: 0.85rem;
        }
    </style>
</head>

<body>
    <div>
        <div class="login-card">
            <h1>Admin Panel</h1>
            <p class="subtitle">Sign in to manage attendance settings</p>

            <?php if ($error): ?>
                <div class="error-msg">
                    <span class="material-symbols-outlined" style="font-size:1.2rem;">error</span>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="st-input-group">
                    <label for="username">Username</label>
                    <input class="st-input" id="username" type="text" name="username" placeholder="Enter username" required>
                </div>

                <div class="st-input-group">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <label for="password-field" style="margin-bottom:0;">Password</label>
                        <a href="forgot_password.php" style="font-size:0.75rem; font-weight:700; color:var(--primary); text-decoration:none;">Forgot Password?</a>
                    </div>
                    <div class="pwd-wrapper">
                        <input class="st-input" type="password" name="password" placeholder="Enter password" id="password-field" required>
                        <span class="material-symbols-outlined pwd-toggle" onclick="togglePassword(this)">visibility</span>
                    </div>
                </div>

                <button class="submit-btn" type="submit">
                    <span class="material-symbols-outlined" style="font-size:1.2rem;">login</span> Sign In
                </button>
            </form>
        </div>

        <div class="footer">
            &copy; <?= date('Y') ?> Admin Panel. All rights reserved.
        </div>
    </div>

    <script>
        function togglePassword(icon) {
            const input = document.getElementById('password-field');
            if (input.type === 'password') {
                input.type = 'text';
                icon.textContent = 'visibility_off';
            } else {
                input.type = 'password';
                icon.textContent = 'visibility';
            }
        }
    </script>
</body>

</html>
