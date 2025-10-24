<?php
session_start();
$error = '';

// Ensure accounts file exists with a default admin (only on first run)
$accountsFile = __DIR__ . '/accounts.json';
if (!file_exists($accountsFile)) {
    $default = [
        'admin' => [
            'password' => password_hash('password123', PASSWORD_DEFAULT),
            'name' => 'Administrator',
            'avatar' => null
        ]
    ];
    file_put_contents($accountsFile, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$accounts = json_decode(file_get_contents($accountsFile), true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && isset($accounts[$username]) && password_verify($password, $accounts[$username]['password'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user'] = $username;
        $_SESSION['admin_name'] = $accounts[$username]['name'] ?? $username;
        $_SESSION['admin_avatar'] = $accounts[$username]['avatar'] ?? null;
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
    <style>
        :root {
            --primary-color: #667eea;
            --primary-dark: #5469d4;
            --primary-darker: #4353b8;
            --error-color: #d9534f;
            --text-color: #333;
            --bg-gradient: linear-gradient(135deg, #667eea, #764ba2);
        }

        * {
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        html, body {
            height: 100%;
            margin: 0;
            background: var(--bg-gradient);
            display: flex;
            justify-content: center;
            align-items: center;
            color: var(--text-color);
        }

        .container {
            width: 100%;
            max-width: 400px;
            background: #fff;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: box-shadow 0.3s ease;
        }

        .container:hover {
            box-shadow: 0 15px 45px rgba(0,0,0,0.15);
        }

        .login-box h1 {
            text-align: center;
            font-size: 1.9rem;
            color: var(--primary-color);
            margin-bottom: 28px;
            user-select: none;
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        input[type="text"],
        input[type="password"] {
            padding: 14px 16px;
            border-radius: 8px;
            border: 2px solid #ddd;
            font-size: 1rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 8px rgba(102, 126, 234, 0.4);
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
            font-size: 1.15rem;
            transition: color 0.25s ease;
        }

        .password-container .toggle-password:hover {
            color: var(--primary-color);
        }

        button[type="submit"] {
            padding: 14px 0;
            border: none;
            background: var(--primary-color);
            color: #fff;
            font-size: 1.15rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s ease, transform 0.2s ease;
        }

        button[type="submit"]:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.1);
        }

        button[type="submit"]:active {
            background: var(--primary-darker);
        }

        p.error-message {
            color: var(--error-color);
            text-align: center;
            font-weight: 600;
            background: #ffeaea;
            padding: 10px;
            border-radius: 8px;
            margin: -10px 0 10px 0;
            animation: shake 0.3s;
        }

        @keyframes shake {
            0% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            50% { transform: translateX(5px); }
            75% { transform: translateX(-5px); }
            100% { transform: translateX(0); }
        }

        .footer-container {
            margin-top: 24px;
            text-align: center;
            font-size: 0.85rem;
            color: #999;
            user-select: none;
        }

        @media (max-width: 480px) {
            .container {
                margin: 10px;
                padding: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-box">
            <h1>Admin Login</h1>

            <?php if ($error): ?>
                <p class="error-message"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <form method="POST">
                <input type="text" name="username" placeholder="Username" required>

                <div class="password-container">
                    <input type="password" name="password" placeholder="Password" id="password-field" required>
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
