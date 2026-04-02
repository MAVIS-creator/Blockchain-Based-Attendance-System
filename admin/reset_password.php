<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/state_helpers.php';

$error = '';
$success = '';
$validToken = false;
$userAccount = '';

$email = $_GET['email'] ?? ($_POST['email'] ?? '');
$token = $_GET['token'] ?? ($_POST['token'] ?? '');

$resetsFile = admin_storage_migrate_file('password_resets.json');
$resets = admin_cached_json_file('password_resets', $resetsFile, [], 10);

if (empty($email) || empty($token)) {
    $error = 'Invalid or missing reset token.';
} else {
    // Validate token
    if (is_array($resets)) {
        foreach ($resets as $index => $r) {
            if ($r['email'] === $email && $r['expires'] > time() && password_verify($token, $r['token'])) {
                $validToken = true;
                $userAccount = $r['user'];
                break;
            }
        }
    }

    if (!$validToken) {
        $error = 'This password reset link is invalid or has expired.';
    }
}

// Process new password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    if (!csrf_check_request()) {
        $error = 'Session expired, please try again.';
    } else {
        $newPass = $_POST['new_password'] ?? '';
        $confPass = $_POST['confirm_password'] ?? '';

        if (empty($newPass)) {
            $error = 'New password cannot be empty.';
        } elseif ($newPass !== $confPass) {
            $error = 'Passwords do not match.';
        } else {
            $accountsFile = admin_accounts_file();
            $accounts = admin_load_accounts_cached(15);

            if (isset($accounts[$userAccount])) {
                $accounts[$userAccount]['password'] = password_hash($newPass, PASSWORD_DEFAULT);
                file_put_contents($accountsFile, json_encode($accounts, JSON_PRETTY_PRINT), LOCK_EX);

                // Invalidate the token
                $resets = array_filter($resets, function($r) use ($email) {
                    return $r['email'] !== $email;
                });
                file_put_contents($resetsFile, json_encode($resets, JSON_PRETTY_PRINT), LOCK_EX);

                $success = 'Your password has been reset successfully! You can now log in.';
                $validToken = false; // hiding the form
            } else {
                $error = 'Account no longer exists.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="../asset/favicon.ico">
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: var(--surface-container-lowest); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 24px; }
        .login-card { background: var(--surface); border-radius: 20px; padding: 40px; width: 100%; max-width: 440px; box-shadow: 0 12px 32px rgba(11, 23, 41, 0.08); border: 1px solid var(--outline-variant); }
        .login-card h1 { color: var(--on-surface); font-size: 1.8rem; font-weight: 800; margin: 0 0 8px; text-align: center; letter-spacing: -0.03em; }
        .login-card p.subtitle { color: var(--on-surface-variant); text-align: center; margin: 0 0 32px; font-size: 0.95rem; }
        .st-input-group { margin-bottom: 20px; }
        .st-input-group label { display: block; font-weight: 600; color: var(--on-surface-variant); margin-bottom: 8px; font-size: 0.85rem; }
        .st-input { width: 100%; padding: 12px 14px; border-radius: 12px; border: 1px solid var(--outline-variant); background: var(--surface-container-low); color: var(--on-surface); font-family: 'Inter', sans-serif; font-size: 0.95rem; transition: all 0.2s ease; }
        .st-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(31, 93, 153, 0.15); background: var(--surface); }
        .submit-btn { width: 100%; padding: 14px; border: none; border-radius: 12px; background: var(--primary); color: #fff; font-size: 1rem; font-weight: 700; cursor: pointer; transition: all 0.2s ease; display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 12px; }
        .submit-btn:hover { background: var(--primary-container); transform: translateY(-1px); }
        .error-msg { background: var(--error-container); color: var(--error); padding: 12px 16px; border-radius: 12px; font-weight: 600; font-size: 0.9rem; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; }
        .success-msg { background: #dcfce7; color: #166534; padding: 12px 16px; border-radius: 12px; font-weight: 600; font-size: 0.9rem; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; }
    </style>
</head>
<body>
    <div class="login-card">
        <h1>New Password</h1>
        <p class="subtitle">Secure your admin account</p>

        <?php if ($error): ?>
            <div class="error-msg">
                <span class="material-symbols-outlined" style="font-size:1.2rem;">error</span> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success-msg">
                <span class="material-symbols-outlined" style="font-size:1.2rem;">check_circle</span> <?= htmlspecialchars($success) ?>
            </div>
            <div style="text-align:center; margin-top:24px;">
                <a href="login.php" class="submit-btn" style="text-decoration:none; display:inline-flex;">
                    <span class="material-symbols-outlined">login</span> Go to Login
                </a>
            </div>
        <?php elseif ($validToken): ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <div class="st-input-group">
                <label for="new_password">New Password</label>
                <input class="st-input" id="new_password" type="password" name="new_password" required>
            </div>

            <div class="st-input-group">
                <label for="confirm_password">Confirm Password</label>
                <input class="st-input" id="confirm_password" type="password" name="confirm_password" required>
            </div>

            <button class="submit-btn" type="submit">
                <span class="material-symbols-outlined" style="font-size:1.2rem;">lock_reset</span> Reset Password
            </button>
        </form>
        <?php else: ?>
            <div style="text-align:center; margin-top:24px;">
                <a href="forgot_password.php" style="font-size:0.85rem; font-weight:600; color:var(--primary); text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                    <span class="material-symbols-outlined" style="font-size:1rem;">refresh</span> Request New Link
                </a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
