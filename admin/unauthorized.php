<?php
require_once __DIR__ . '/session_bootstrap.php';
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied | Attendance System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
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
            font-family: 'Inter', sans-serif;
            color: var(--on-surface);
        }
        .error-container {
            background: var(--surface-container-low);
            border: 1px solid var(--outline-variant);
            border-radius: var(--radius-xl);
            padding: clamp(32px, 5vw, 48px);
            max-width: 480px;
            width: 100%;
            text-align: center;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.05);
        }
        .error-icon {
            font-size: 64px;
            color: var(--error);
            margin-bottom: 24px;
            background: var(--error-container);
            width: 96px;
            height: 96px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        .error-title {
            font-size: 24px;
            font-weight: 800;
            color: var(--on-surface);
            margin-bottom: 12px;
            letter-spacing: -0.02em;
        }
        .error-message {
            font-size: 15px;
            color: var(--on-surface-variant);
            line-height: 1.6;
            margin-bottom: 32px;
        }
        .btn-home {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary);
            color: var(--on-primary);
            padding: 12px 24px;
            border-radius: var(--radius-full);
            font-weight: 600;
            text-decoration: none;
            transition: opacity 0.2s;
        }
        .btn-home:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">
            <span class="material-symbols-outlined">gpp_bad</span>
        </div>
        <h1 class="error-title">Access Restricted</h1>
        <p class="error-message">
            Your role (<strong><?= htmlspecialchars(ucfirst($_SESSION['admin_role'] ?? 'Admin')) ?></strong>) does not have the required permissions to view this module. If you believe this is a mistake, please contact the Primary Node Superadmin.
        </p>
        <a href="index.php?page=dashboard" class="btn-home">
            <span class="material-symbols-outlined" style="font-size: 20px;">home</span>
            Return to Dashboard
        </a>
    </div>
</body>
</html>
