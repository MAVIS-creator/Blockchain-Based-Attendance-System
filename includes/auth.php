<?php
/**
 * Authentication & Session Helper
 * High-Q Solid Academy Biometric Attendance System
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

function is_authenticated(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function require_login(): void {
    if (!is_authenticated()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        header('Location: login.php');
        exit;
    }
}

function get_logged_user(): ?array {
    if (!is_authenticated()) return null;
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'fullname' => $_SESSION['fullname'] ?? '',
        'role' => $_SESSION['role'] ?? 'admin'
    ];
}

function ensure_default_admin_exists(): void {
    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("SELECT id, password FROM users WHERE username = 'admin'");
        $stmt->execute();
        $user = $stmt->fetch();

        $hash = password_hash('admin123', PASSWORD_DEFAULT);

        if (!$user) {
            $stmtInsert = $pdo->prepare("INSERT INTO users (username, password, fullname, role) VALUES ('admin', ?, 'Administrator', 'superadmin')");
            $stmtInsert->execute([$hash]);
        } else if (!password_verify('admin123', $user['password'])) {
            $stmtUpdate = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmtUpdate->execute([$hash, $user['id']]);
        }
    } catch (Exception $e) {
        // Silently fail if table creation is pending
    }
}

function login_user(string $username, string $password): array {
    ensure_default_admin_exists();

    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR username = ?");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['fullname'] = $user['fullname'];
        $_SESSION['role'] = $user['role'];
        return ['success' => true, 'user' => $user];
    }

    return ['success' => false, 'message' => 'Invalid username or password'];
}

function register_admin_user(string $fullname, string $username, string $password): array {
    $pdo = get_db_connection();

    // Check if username exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Username already taken'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmtInsert = $pdo->prepare("INSERT INTO users (username, password, fullname, role) VALUES (?, ?, ?, 'admin')");
    $res = $stmtInsert->execute([$username, $hash, $fullname]);

    if ($res) {
        $userId = $pdo->lastInsertId();
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['fullname'] = $fullname;
        $_SESSION['role'] = 'admin';
        return ['success' => true, 'user_id' => $userId];
    }

    return ['success' => false, 'message' => 'Failed to create admin account'];
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
