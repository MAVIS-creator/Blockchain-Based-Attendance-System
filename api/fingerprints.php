<?php
/**
 * Fingerprint Template Management API Endpoint
 * High-Q Solid Academy Biometric Attendance System
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'list_all';

$pdo = get_db_connection();

try {
    switch ($action) {
        case 'save':
            // Save or update fingerprint template for student
            $studentId = (int)($_POST['student_id'] ?? 0);
            $template = trim($_POST['template'] ?? '');
            $fingerIndex = (int)($_POST['finger_index'] ?? 1);
            $quality = trim($_POST['quality'] ?? 'Good');

            if ($studentId <= 0 || $template === '') {
                echo json_encode(['success' => false, 'message' => 'Student ID and Fingerprint Template are required.']);
                exit;
            }

            // Check if student exists
            $stCheck = $pdo->prepare("SELECT id FROM students WHERE id = ?");
            $stCheck->execute([$studentId]);
            if (!$stCheck->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Student not found.']);
                exit;
            }

            // Save template
            $stmt = $pdo->prepare("
                INSERT INTO fingerprints (student_id, finger_index, template, quality)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE template = VALUES(template), quality = VALUES(quality), updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$studentId, $fingerIndex, $template, $quality]);

            // Update student status to 'Fingerprint Linked'
            $upd = $pdo->prepare("UPDATE students SET status = 'Fingerprint Linked' WHERE id = ?");
            $upd->execute([$studentId]);

            echo json_encode([
                'success' => true,
                'message' => 'Fingerprint template linked successfully.',
                'student_id' => $studentId
            ]);
            break;

        case 'get_templates':
            // Fetch all stored templates for identification by C# Service
            $stmt = $pdo->query("
                SELECT f.id, f.student_id, f.template, f.finger_index, s.admission_number, s.surname, s.firstname, s.class
                FROM fingerprints f
                JOIN students s ON f.student_id = s.id
                WHERE s.status != 'Inactive'
            ");
            $templates = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'count' => count($templates),
                'templates' => $templates
            ]);
            break;

        case 'get_student_fingerprint':
            $studentId = (int)($_GET['student_id'] ?? 0);
            if ($studentId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM fingerprints WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $fingerprints = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'student_id' => $studentId,
                'fingerprints' => $fingerprints
            ]);
            break;

        case 'delete':
            $studentId = (int)($_POST['student_id'] ?? 0);
            if ($studentId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM fingerprints WHERE student_id = ?");
            $stmt->execute([$studentId]);

            $upd = $pdo->prepare("UPDATE students SET status = 'Awaiting Fingerprint' WHERE id = ?");
            $upd->execute([$studentId]);

            echo json_encode(['success' => true, 'message' => 'Fingerprint template deleted successfully.']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
