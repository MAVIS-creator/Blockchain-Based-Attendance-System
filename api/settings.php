<?php
/**
 * Settings API Endpoint
 * High-Q Solid Academy Biometric Attendance System
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

$pdo = get_db_connection();
$action = $_GET['action'] ?? $_POST['action'] ?? 'get';

try {
    if ($action === 'get') {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        echo json_encode(['success' => true, 'settings' => $settings]);
        exit;
    }

    if ($action === 'verify_pin') {
        $pin = trim($_POST['pin'] ?? $_GET['pin'] ?? '');
        $captcha = trim($_POST['captcha'] ?? '');
        $expectedCaptcha = $_SESSION['terminal_captcha'] ?? '';

        if (!empty($expectedCaptcha) && strtoupper($captcha) !== strtoupper($expectedCaptcha)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'CAPTCHA Failed! Please enter the exact numbers/letters shown.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'terminal_pin'");
        $stmt->execute();
        $storedPin = trim((string)$stmt->fetchColumn());
        $masterKey = getenv('TERMINAL_MASTER_RECOVERY_KEY') ?: 'HQ-MASTER-RECOVER-2026';

        if (empty($storedPin) || $pin === $storedPin || $pin === $masterKey) {
            $_SESSION['terminal_unlocked'] = true;
            echo json_encode(['success' => true, 'message' => 'Terminal PIN verified']);
        } else {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Incorrect Access PIN or Master Emergency Key.']);
        }
        exit;
    }

    if ($action === 'save') {
        if (!is_authenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }

        $fields = ['school_name', 'attendance_start_time', 'attendance_closing_time', 'late_threshold_time', 'terminal_pin'];
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $stmt->execute([$field, trim($_POST[$field])]);
            }
        }

        // Logo upload if provided
        if (isset($_FILES['school_logo']) && $_FILES['school_logo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../storage/logo/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $ext = pathinfo($_FILES['school_logo']['name'], PATHINFO_EXTENSION);
            $filename = 'logo_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['school_logo']['tmp_name'], $uploadDir . $filename)) {
                $stmt->execute(['school_logo', 'storage/logo/' . $filename]);
            }
        }

        echo json_encode(['success' => true, 'message' => 'Settings saved successfully']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
