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
        if (strcasecmp($u, $username) === 0) { $foundUser = $u; break; }
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="icon" type="image/svg+xml" href="../asset/attendance-favicon.svg">
  <link rel="icon" type="image/x-icon" href="../asset/favicon.ico">
  <link rel="apple-touch-icon" sizes="180x180" href="../asset/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="../asset/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="../asset/favicon-16x16.png">
  <link rel="manifest" href="../asset/site.webmanifest">
    <style>
        :root {
            --bg-top: #f3f7fc;
            --bg-bottom: #eaf0f6;
            --panel: #ffffff;
            --line: #d7e0ea;
            --text: #142b45;
            --muted: #5f6d7d;
            --primary: #1f5d99;
            --primary-dark: #1a4f84;
            --danger-bg: #fef0f0;
            --danger-text: #991b1b;
            --shadow: 0 18px 40px rgba(24, 39, 75, 0.08);
        }

        * {
            box-sizing: border-box;
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
        }

        html, body {
            height: 100%;
            margin: 0;
            display: grid;
            place-items: center;
            color: var(--text);
            background:
              radial-gradient(circle at 12% 14%, rgba(59, 125, 182, 0.20), transparent 26%),
              radial-gradient(circle at 88% 82%, rgba(30, 142, 106, 0.12), transparent 24%),
              linear-gradient(180deg, var(--bg-top), var(--bg-bottom));
            padding: 16px;
        }

        .container {
            width: 100%;
            max-width: 420px;
            background: var(--panel);
            border-radius: 18px;
            padding: 24px;
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            animation: rise-in 0.45s ease;
        }

        @keyframes rise-in {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .brand img {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: #f7fbff;
            padding: 4px;
            object-fit: contain;
        }

        .brand .brand-text {
            font-size: 0.95rem;
            color: var(--muted);
            letter-spacing: 0.2px;
        }

        .login-box h1 {
            text-align: center;
            font-size: 1.55rem;
            color: var(--text);
            margin: 0 0 6px;
            user-select: none;
        }

        .subtitle {
            text-align: center;
            color: var(--muted);
            font-size: 0.9rem;
            margin-bottom: 18px;
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .input-label {
            font-size: 0.85rem;
            color: var(--muted);
            margin-bottom: 6px;
            display: block;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 11px 12px;
            border-radius: 10px;
            border: 1px solid var(--line);
            font-size: 0.95rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            color: var(--text);
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(31, 93, 153, 0.16);
            outline: none;
        }

        .password-container {
            position: relative;
        }

        .password-container .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #888;
            font-size: 1rem;
            transition: color 0.2s ease;
        }

        .password-container .toggle-password:hover {
            color: var(--primary);
        }

        button[type="submit"] {
            padding: 11px 0;
            border: none;
            background: linear-gradient(90deg, var(--primary), #3b7db6);
            color: #fff;
            font-size: 0.98rem;
            font-weight: 600;
            border-radius: 10px;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
            box-shadow: 0 8px 20px rgba(31, 93, 153, 0.24);
        }

        button[type="submit"]:hover {
            transform: translateY(-1px);
        }

        button[type="submit"]:active {
            transform: translateY(0);
        }

        p.error-message {
            color: var(--danger-text);
            text-align: center;
            font-weight: 600;
            background: var(--danger-bg);
            padding: 9px 10px;
            border-radius: 8px;
            margin: 0 0 8px;
            animation: shake 0.3s;
            border: 1px solid #fbcaca;
            font-size: 0.9rem;
        }

        @keyframes shake {
            0% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            50% { transform: translateX(5px); }
            75% { transform: translateX(-5px); }
            100% { transform: translateX(0); }
        }

        .footer-container {
            margin-top: 16px;
            text-align: center;
            font-size: 0.8rem;
            color: #8a97a8;
            user-select: none;
        }

        @media (max-width: 480px) {
            .container {
                margin: 8px;
                padding: 18px;
                border-radius: 14px;
            }

            .login-box h1 {
                font-size: 1.35rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-box">
            <div class="brand">
                <img src="../asset/attendance-mark.svg" alt="Attendance Mark">
                <span class="brand-text">Attendance Administration</span>
            </div>
            <h1>Admin Login</h1>
            <div class="subtitle">Secure access to attendance controls</div>

            <?php if ($error): ?>
                <p class="error-message"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <form method="POST">
                <div>
                    <label class="input-label" for="username">Username</label>
                    <input id="username" type="text" name="username" placeholder="Enter username" required>
                </div>

                <div class="password-container">
                    <label class="input-label" for="password-field">Password</label>
                    <input type="password" name="password" placeholder="Enter password" id="password-field" required>
                    <i class="fa fa-eye toggle-password" onclick="togglePassword(this)"></i>
                </div>

                <button type="submit"><i class="fa fa-sign-in-alt"></i> Login</button>
            </form>
        </div>

        <div class="footer-container">
            &copy; <?= date('Y') ?> Admin Panel. All rights reserved.
        </div>
    </div>

    <script>
        function togglePassword(icon) {
            const input = document.getElementById('password-field');
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            icon.classList.toggle('fa-eye');
            icon.classList.toggle('fa-eye-slash');
        }
    </script>
</body>
</html>
