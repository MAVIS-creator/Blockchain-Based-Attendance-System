<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/runtime_storage.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check_request()) {
        $error = 'Session expired, please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Valid email address is required.';
        } else {
            $accountsFile = admin_storage_migrate_file('accounts.json');
            $accounts = file_exists($accountsFile) ? json_decode(file_get_contents($accountsFile), true) : [];

            $foundUser = null;
            if (is_array($accounts)) {
                foreach ($accounts as $username => $data) {
                    if (isset($data['email']) && strcasecmp($data['email'], $email) === 0) {
                        $foundUser = $username;
                        break;
                    }
                }
            }

            // Generate token (always say "If email exists", to prevent enumeration)
            if ($foundUser) {
                // Read .env for SMTP config
                $envVars = [];
                $envFile = __DIR__ . '/../.env';
                if (file_exists($envFile)) {
                    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    foreach ($lines as $l) {
                        $t = trim($l);
                        if ($t === '' || strpos($t, '#') === 0 || strpos($t, '=') === false) continue;
                        list($k, $v) = explode('=', $t, 2);
                        $envVars[trim($k)] = trim(trim($v), "\"'");
                    }
                }

                $smtpHost = $envVars['SMTP_HOST'] ?? '';
                if (!$smtpHost) {
                    $error = 'System SMTP is not configured. Password recovery unavailable.';
                } else {
                    $token = bin2hex(random_bytes(16));
                    $expiry = time() + 3600; // 1 hour

                    $resetsFile = admin_storage_migrate_file('password_resets.json');
                    $resets = file_exists($resetsFile) ? json_decode(file_get_contents($resetsFile), true) : [];
                    if (!is_array($resets)) $resets = [];

                    // clean old tokens for user
                    $resets = array_filter($resets, function ($r) use ($foundUser) {
                        return $r['user'] !== $foundUser && $r['expires'] > time();
                    });

                    $resets[] = [
                        'user' => $foundUser,
                        'email' => $email,
                        'token' => password_hash($token, PASSWORD_DEFAULT),
                        'expires' => $expiry
                    ];
                    file_put_contents($resetsFile, json_encode($resets, JSON_PRETTY_PRINT), LOCK_EX);

                    // Send Email using PHPMailer
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                    $resetLink = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/reset_password.php?token=' . $token . '&email=' . urlencode($email);

                    $sent = false;
                    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
                        require_once __DIR__ . '/../vendor/autoload.php';
                        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                            try {
                                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                                $mail->isSMTP();
                                $mail->Host = $smtpHost;
                                $mail->Port = intval($envVars['SMTP_PORT'] ?? 587);
                                $secure = $envVars['SMTP_SECURE'] ?? '';
                                if ($secure) $mail->SMTPSecure = $secure;
                                $mail->SMTPAuth = true;
                                $mail->Username = $envVars['SMTP_USER'] ?? '';
                                $mail->Password = $envVars['SMTP_PASS'] ?? '';

                                $mail->setFrom($envVars['SMTP_USER'] ?? 'admin@localhost', 'Stitch Attendance Admin');
                                $mail->addAddress($email);
                                $mail->isHTML(true);
                                $mail->Subject = 'Admin Password Reset Request';
                                $mail->Body = "<h2>Password Reset</h2><p>You requested a password reset for your administrator account.</p><p><a href='{$resetLink}' style='padding:10px 20px; background:#00457b; color:#fff; text-decoration:none; border-radius:5px;'>Reset Password</a></p><p>If you did not request this, please ignore this email. This link expires in 1 hour.</p>";

                                $mail->send();
                                $sent = true;
                            } catch (Exception $e) {
                                $error = "Failed to send email: " . $mail->ErrorInfo;
                            }
                        } else {
                            $error = "PHPMailer is not installed via Composer.";
                        }
                    } else {
                        // Fallback to native mail (rarely works on localhost but nice to have)
                        $headers = "MIME-Version: 1.0\r\n";
                        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
                        $headers .= "From: Admin <noreply@localhost>\r\n";
                        $msg = "<h2>Password Reset</h2><p>Click here to reset: <a href='{$resetLink}'>{$resetLink}</a></p>";
                        if (@mail($email, 'Password Reset', $msg, $headers)) {
                            $sent = true;
                        } else {
                            $error = "SMTP not properly configured or Composer vendor missing.";
                        }
                    }

                    if ($sent) {
                        $success = "If an account with that email exists, a password reset link has been sent.";
                    }
                }
            } else {
                $success = "If an account with that email exists, a password reset link has been sent.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Forgot Password</title>
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
            background: var(--primary-container);
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
        }

        .success-msg {
            background: #dcfce7;
            color: #166534;
            padding: 12px 16px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
    </style>
</head>

<body>
    <div class="login-card">
        <h1>Recover Password</h1>
        <p class="subtitle">Enter your recovery email address</p>

        <?php if ($error): ?>
            <div class="error-msg">
                <span class="material-symbols-outlined" style="font-size:1.2rem;">error</span> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success-msg">
                <span class="material-symbols-outlined" style="font-size:1.2rem;">check_circle</span> <?= htmlspecialchars($success) ?>
            </div>
        <?php else: ?>

            <form method="POST">
                <?= csrf_field() ?>
                <div class="st-input-group">
                    <label for="email">Account Email</label>
                    <input class="st-input" id="email" type="email" name="email" placeholder="admin@smartattendance.com" required>
                </div>

                <button class="submit-btn" type="submit">
                    <span class="material-symbols-outlined" style="font-size:1.2rem;">mail</span> Send Reset Link
                </button>
            </form>
        <?php endif; ?>

        <div style="text-align:center; margin-top:24px;">
            <a href="login.php" style="font-size:0.85rem; font-weight:600; color:var(--primary); text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                <span class="material-symbols-outlined" style="font-size:1rem;">arrow_back</span> Back to Login
            </a>
        </div>
    </div>
</body>

</html>
