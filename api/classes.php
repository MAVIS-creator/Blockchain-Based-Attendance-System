<?php
/**
 * Lesson Types Management API Endpoint
 * High-Q Solid Academy Biometric Attendance System
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

$pdo = get_db_connection();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// Auto create classes table if missing
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `classes` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(50) NOT NULL UNIQUE,
        `sort_order` INT DEFAULT 0,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Check if initial seeding has been executed using settings table
$seededCheck = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'lesson_types_seeded'")->fetchColumn();

if ($seededCheck === false) {
    // Perform initial seed of default High-Q Lesson Types
    $defaultTypes = ['JAMB', 'WAEC', 'NECO', 'GCE', 'Post UTME', 'NABTEB', 'JUPEB', 'IJMB'];
    $stmt = $pdo->prepare("INSERT IGNORE INTO classes (name, sort_order) VALUES (?, ?)");
    foreach ($defaultTypes as $idx => $typeName) {
        $stmt->execute([$typeName, $idx + 1]);
    }
    // Mark as seeded so deletions are persistent and never auto-restored
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('lesson_types_seeded', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
}

try {
    switch ($action) {
        case 'list':
            $stmt = $pdo->query("SELECT id, name, sort_order FROM classes ORDER BY sort_order ASC, id ASC");
            $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'classes' => $classes]);
            break;

        case 'add':
            if (!is_authenticated()) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit;
            }

            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                echo json_encode(['success' => false, 'message' => 'Lesson type name cannot be empty']);
                exit;
            }

            // Check duplicate
            $check = $pdo->prepare("SELECT id FROM classes WHERE name = ?");
            $check->execute([$name]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Lesson type already exists']);
                exit;
            }

            $maxOrder = (int)$pdo->query("SELECT MAX(sort_order) FROM classes")->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO classes (name, sort_order) VALUES (?, ?)");
            $stmt->execute([$name, $maxOrder + 1]);

            echo json_encode(['success' => true, 'message' => 'Lesson type added successfully', 'id' => $pdo->lastInsertId(), 'name' => $name]);
            break;

        case 'delete':
            if (!is_authenticated()) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit;
            }

            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid lesson type ID']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM classes WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'Lesson type removed successfully']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
