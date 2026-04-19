<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/../request_guard.php';
app_request_guard('admin/login.php', 'admin');
$error = '';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/sql_accounts.php';

$authDebugMode = admin_auth_debug_enabled();
$authIssue = trim((string)($_GET['auth_issue'] ?? ''));
if ($authIssue !== '') {
    admin_auth_debug_log('login_issue_hint', [
        'issue' => $authIssue,
        'referer' => (string)($_SERVER['HTTP_REFERER'] ?? ''),
    ]);
}

// Load environment variables via env_helpers if needed (already loaded by storage_helpers)

$useSqlAccounts = admin_should_use_sql_accounts();
$isLocalEnvironment = app_is_local_environment();
$accountsFile = admin_accounts_file();
$accounts = [];

if (!$useSqlAccounts) {
    if (!file_exists($accountsFile)) {
        @file_put_contents($accountsFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    $accounts = admin_load_accounts_cached(15);
    if (!is_array($accounts)) {
        $accounts = [];
    }

    if ($isLocalEnvironment && empty($accounts)) {
        $localDefaultUser = trim((string)app_env_value('ADMIN_DEFAULT_USER', 'Mavis'));
        $localDefaultPassword = (string)app_env_value('ADMIN_DEFAULT_PASSWORD', '');
        if ($localDefaultUser !== '' && $localDefaultPassword !== '') {
            $accounts[$localDefaultUser] = [
                'password' => password_hash($localDefaultPassword, PASSWORD_DEFAULT),
                'name' => $localDefaultUser,
                'avatar' => null,
                'role' => 'superadmin'
            ];
            @file_put_contents($accountsFile, json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    // Production safeguard: never auto-create default credentials in non-local environments.
    if (!$isLocalEnvironment && empty($accounts)) {
        $secureUser = trim((string)app_env_value('ADMIN_DEFAULT_USER', ''));
        $secureHash = trim((string)app_env_value('ADMIN_DEFAULT_PASSWORD_HASH', ''));
        if ($secureUser !== '' && $secureHash !== '') {
            $accounts[$secureUser] = [
                'password' => $secureHash,
                'name' => $secureUser,
                'avatar' => null,
                'role' => 'superadmin'
            ];
            @file_put_contents($accountsFile, json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    admin_auth_debug_log('login_attempt', [
        'entered_username' => $username,
        'accounts_backend' => $useSqlAccounts ? 'sql' : 'json',
        'accounts_count' => is_array($accounts) ? count($accounts) : 0,
        'cookie_present' => isset($_COOKIE[session_name()]),
    ]);

    $authenticated = false;
    $authAccount = null;
    $usernameToUse = '';
    $foundUser = null;

    if ($useSqlAccounts) {
        $sqlReason = null;
        if (admin_sql_authenticate_user($username, $password, $sqlAccount, $sqlReason)) {
            $authenticated = true;
            $authAccount = is_array($sqlAccount) ? $sqlAccount : [];
            $usernameToUse = (string)($authAccount['username'] ?? $username);
            $foundUser = $usernameToUse;
        } else {
            $foundUser = null;
            if (is_string($sqlReason) && $sqlReason !== '' && strpos($sqlReason, 'password_mismatch') === false && strpos($sqlReason, 'user_not_found') === false) {
                admin_auth_debug_log('login_sql_error', [
                    'reason' => $sqlReason,
                ]);
                $error = 'Login backend unavailable. Please verify SQL and secret settings.';
            }
        }
    } else {
        foreach ($accounts as $u => $info) {
            if (strcasecmp((string)$u, $username) === 0) {
                $foundUser = (string)$u;
                break;
            }
        }

        if ($foundUser !== null) {
            $stored = $accounts[$foundUser]['password'] ?? null;
            if ($stored && password_verify($password, (string)$stored)) {
                $authenticated = true;
                $usernameToUse = $foundUser;
                $authAccount = [
                    'username' => $foundUser,
                    'name' => $accounts[$usernameToUse]['name'] ?? $usernameToUse,
                    'avatar' => $accounts[$usernameToUse]['avatar'] ?? null,
                    'role' => $accounts[$usernameToUse]['role'] ?? 'admin',
                    'needs_tour' => !empty($accounts[$usernameToUse]['needs_tour']),
                ];
            }
        }
    }

    if ($authenticated) {
        // Use false here: On Azure App Service shared storage (CIFS/NFS), 
        // the session file is locked. Regenerate_id(true) attempts to unlink() the 
        // active file, which fails on Windows/SMB file shares and can break the login flow.
        session_regenerate_id(false);
        $trackerSid = trim((string)session_id());
        if ($trackerSid === '') {
            try {
                $trackerSid = bin2hex(random_bytes(16));
            } catch (Throwable $e) {
                $trackerSid = sha1(uniqid('admin_sid_', true));
            }
        }
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user'] = $usernameToUse;
        $_SESSION['admin_name'] = (string)($authAccount['name'] ?? $usernameToUse);
        $_SESSION['admin_avatar'] = $authAccount['avatar'] ?? null;
        $_SESSION['admin_role'] = (string)($authAccount['role'] ?? 'admin');
        $_SESSION['needs_tour'] = !empty($authAccount['needs_tour']);
        $registered = admin_register_session($usernameToUse, [
            'role' => (string)($authAccount['role'] ?? 'admin'),
            'name' => (string)($authAccount['name'] ?? $usernameToUse),
            'avatar' => $authAccount['avatar'] ?? null,
            'needs_tour' => !empty($authAccount['needs_tour']),
        ], $trackerSid);
        admin_auth_debug_log('login_success', [
            'user' => $usernameToUse,
            'registered_tracker' => (bool)$registered,
            'tracker_sid' => $trackerSid,
            'php_session_started' => (session_status() === PHP_SESSION_ACTIVE),
            'session_keys' => array_values(array_keys($_SESSION)),
        ]);

        // Explicitly flush the session file to disk BEFORE issuing the redirect.
        // Without this, session_regenerate_id(true) may not have synced the new
        // session file by the time the browser follows Location — especially on
        // Azure App Service where NFS shared storage has slight propagation delay
        // and multiple instances may handle the next request.
        $cookieParams = session_get_cookie_params();
        $httpsForwarded = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $httpsNative = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
        $trackerCookieOptions = [
            'path' => '/',
            'domain' => '',
            'secure' => ($httpsForwarded || $httpsNative),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        if (!empty($cookieParams['lifetime'])) {
            $trackerCookieOptions['expires'] = time() + (int)$cookieParams['lifetime'];
        }
        setcookie(ADMIN_SESSION_TRACKER_COOKIE, $trackerSid, [
            ...$trackerCookieOptions,
        ]);
        session_write_close();

        header('Location: index.php');
        exit;
    } else {
        if ($error === '') {
            $error = "Invalid credentials";
        }
        admin_auth_debug_log('login_failed', [
            'entered_username' => $username,
            'user_found' => $foundUser !== null,
            'reason' => ($foundUser === null ? 'user_not_found' : 'password_mismatch'),
        ]);
    }
}

$sessionName = (string)session_name();
$sessionSavePath = (string)ini_get('session.save_path');
$sessionSavePathWritable = ($sessionSavePath !== '' && is_dir($sessionSavePath) && is_writable($sessionSavePath));
$storagePath = app_storage_path();
$storagePathWritable = ($storagePath !== '' && is_dir($storagePath) && is_writable($storagePath));
$sessionTrackerFile = admin_sessions_file();
$sessionTrackerRows = admin_sessions_read_fresh();
$sessionTrackerCount = is_array($sessionTrackerRows) ? count($sessionTrackerRows) : 0;
$sessionTracked = isset($sessionTrackerRows[(string)session_id()]);
$authDebugRows = $authDebugMode ? admin_auth_debug_recent(50) : [];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="../asset/image.png">
    <link rel="apple-touch-icon" sizes="180x180" href="../asset/image.png">
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
            box-sizing: border-box;
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
            box-sizing: border-box;
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
            box-sizing: border-box;
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

        .debug-card {
            margin-top: 18px;
            background: #0b1220;
            color: #dbe6ff;
            border: 1px solid #203152;
            border-radius: 14px;
            padding: 14px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
            font-size: 0.76rem;
            line-height: 1.5;
            max-height: 320px;
            overflow: auto;
        }

        .debug-row {
            margin: 0 0 8px;
            word-break: break-word;
        }

        .debug-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            margin-bottom: 8px;
            background: #1f335a;
            color: #d8e7ff;
        }

        .debug-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 18px;
            color: var(--primary);
            font-weight: 700;
            text-decoration: none;
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
