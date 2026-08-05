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
    
    // Support querying by email, username, or name across main site and local users table schema
    $stmt = $pdo->prepare("SELECT u.*, r.slug AS role_slug, r.name AS role_name 
                           FROM users u 
                           LEFT JOIN roles r ON (u.role_id = r.id) 
                           WHERE u.email = ? OR u.username = ? OR u.name = ? 
                           LIMIT 1");
    try {
        $stmt->execute([$username, $username, $username]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        // Fallback for simple local users schema without roles table join
        $stmtSimple = $pdo->prepare("SELECT * FROM users WHERE username = ? OR username = ? LIMIT 1");
        $stmtSimple->execute([$username, $username]);
        $user = $stmtSimple->fetch();
    }

    if ($user && password_verify($password, $user['password'])) {
        // Check approval status (is_active: 0 = pending, 1 = active, 2 = banned)
        if (isset($user['is_active'])) {
            if ((int)$user['is_active'] === 0) {
                return [
                    'success' => false, 
                    'message' => 'Your account is pending approval by the High-Q Main Site Super Administrator (admin.highqsolidacademy.com).'
                ];
            }
            if ((int)$user['is_active'] === 2) {
                return [
                    'success' => false, 
                    'message' => 'Your account has been suspended or banned.'
                ];
            }
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'] ?? $user['email'] ?? $username;
        $_SESSION['fullname'] = $user['name'] ?? $user['fullname'] ?? 'Administrator';
        $_SESSION['role'] = $user['role_slug'] ?? $user['role'] ?? 'admin';
        return ['success' => true, 'user' => $user];
    }

    return ['success' => false, 'message' => 'Invalid email/username or password.'];
}

function register_admin_user(string $fullname, string $username, string $password): array {
    $pdo = get_db_connection();
    $email = strpos($username, '@') !== false ? strtolower($username) : strtolower($username) . '@highqsolidacademy.com';

    // Check if username/email already exists
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
    } catch (PDOException $e) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
    }

    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'An account with this username or email already exists.'];
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $is_active = 0; // Requires approval from main site admin

    try {
        // Try inserting into unified users table with role_id and is_active = 0
        $stmtInsert = $pdo->prepare("INSERT INTO users (role_id, name, phone, email, username, password, is_active, created_at) VALUES (2, ?, '', ?, ?, ?, ?, NOW())");
        $res = $stmtInsert->execute([$fullname, $email, $username, $hash, $is_active]);
    } catch (PDOException $e) {
        // Fallback for simple local users schema
        try {
            $stmtInsert = $pdo->prepare("INSERT INTO users (username, password, fullname, role, is_active) VALUES (?, ?, ?, 'admin', ?)");
            $res = $stmtInsert->execute([$username, $hash, $fullname, $is_active]);
        } catch (PDOException $e2) {
            $stmtInsert = $pdo->prepare("INSERT INTO users (username, password, fullname, role) VALUES (?, ?, ?, 'admin')");
            $res = $stmtInsert->execute([$username, $hash, $fullname]);
            $is_active = 1;
        }
    }

    if ($res) {
        $userId = $pdo->lastInsertId();
        if ($is_active === 0) {
            return [
                'success' => true, 
                'pending' => true, 
                'user_id' => $userId,
                'message' => 'Registration successful! Your account is pending approval by the High-Q Main Site Super Administrator (admin.highqsolidacademy.com). You will be able to log in once approved.'
            ];
        }
        
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['fullname'] = $fullname;
        $_SESSION['role'] = 'admin';
        return ['success' => true, 'pending' => false, 'user_id' => $userId];
    }

    return ['success' => false, 'message' => 'Failed to create admin account. Please try again.'];
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
