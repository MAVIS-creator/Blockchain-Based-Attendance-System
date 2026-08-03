<?php
/**
 * Attendance API Endpoint
 * High-Q Solid Academy Biometric Attendance System
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

$pdo = get_db_connection();

try {
    switch ($action) {
        case 'record_biometric':
            // Called by Public Attendance Terminal or C# Biometric Service
            $studentId = (int)($_POST['student_id'] ?? 0);
            $admissionNo = trim($_POST['admission_number'] ?? '');

            if ($studentId <= 0 && $admissionNo !== '') {
                $stStmt = $pdo->prepare("SELECT id FROM students WHERE admission_number = ?");
                $stStmt->execute([$admissionNo]);
                $studentId = (int)$stStmt->fetchColumn();
            }

            if ($studentId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Student not found']);
                exit;
            }

            // Fetch student info
            $stInfoStmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
            $stInfoStmt->execute([$studentId]);
            $student = $stInfoStmt->fetch();

            if (!$student) {
                echo json_encode(['success' => false, 'message' => 'Student record missing']);
                exit;
            }

            // Fetch settings for thresholds
            $settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
            $settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $lateThreshold = $settings['late_threshold_time'] ?? '08:00:00';
            $closingTime = $settings['attendance_closing_time'] ?? '15:30:00';

            $today = date('Y-m-d');
            $nowTime = date('H:i:s');

            // Check existing attendance today
            $attStmt = $pdo->prepare("SELECT * FROM attendance WHERE student_id = ? AND date = ?");
            $attStmt->execute([$studentId, $today]);
            $existing = $attStmt->fetch();

            if (!$existing) {
                // Check-In
                $status = ($nowTime <= $lateThreshold) ? 'Present' : 'Late';
                $ins = $pdo->prepare("
                    INSERT INTO attendance (student_id, date, check_in, status)
                    VALUES (?, ?, ?, ?)
                ");
                $ins->execute([$studentId, $today, $nowTime, $status]);

                echo json_encode([
                    'success' => true,
                    'type' => 'check_in',
                    'message' => "Welcome, {$student['firstname']}! Check-In recorded at " . date('g:i A'),
                    'student' => [
                        'id' => $student['id'],
                        'admission_number' => $student['admission_number'],
                        'name' => "{$student['surname']} {$student['firstname']}",
                        'class' => $student['class'],
                        'photo' => $student['photo'] ?: null
                    ],
                    'time' => date('g:i A'),
                    'status' => $status
                ]);
            } else if (empty($existing['check_out'])) {
                // Check-Out
                $status = ($nowTime >= $closingTime) ? 'Completed' : 'Early Departure';
                $upd = $pdo->prepare("
                    UPDATE attendance SET check_out = ?, status = ? WHERE id = ?
                ");
                $upd->execute([$nowTime, $status, $existing['id']]);

                echo json_encode([
                    'success' => true,
                    'type' => 'check_out',
                    'message' => "Goodbye, {$student['firstname']}! Check-Out recorded at " . date('g:i A'),
                    'student' => [
                        'id' => $student['id'],
                        'admission_number' => $student['admission_number'],
                        'name' => "{$student['surname']} {$student['firstname']}",
                        'class' => $student['class'],
                        'photo' => $student['photo'] ?: null
                    ],
                    'time' => date('g:i A'),
                    'status' => $status
                ]);
            } else {
                // Already completed check-in & check-out today
                echo json_encode([
                    'success' => true,
                    'type' => 'completed',
                    'message' => "Attendance already completed today for {$student['firstname']}.",
                    'student' => [
                        'id' => $student['id'],
                        'admission_number' => $student['admission_number'],
                        'name' => "{$student['surname']} {$student['firstname']}",
                        'class' => $student['class'],
                        'photo' => $student['photo'] ?: null
                    ],
                    'check_in' => date('g:i A', strtotime($existing['check_in'])),
                    'check_out' => date('g:i A', strtotime($existing['check_out'])),
                    'status' => $existing['status']
                ]);
            }
            break;

        case 'list':
            $date = trim($_GET['date'] ?? date('Y-m-d'));
            $classFilter = trim($_GET['class'] ?? '');
            $statusFilter = trim($_GET['status'] ?? '');
            $search = trim($_GET['search'] ?? '');

            $where = ["a.date = ?"];
            $params = [$date];

            if ($classFilter !== '') {
                $where[] = "s.class = ?";
                $params[] = $classFilter;
            }

            if ($statusFilter !== '') {
                $where[] = "a.status = ?";
                $params[] = $statusFilter;
            }

            if ($search !== '') {
                $where[] = "(s.surname LIKE ? OR s.firstname LIKE ? OR s.admission_number LIKE ?)";
                $sParam = "%$search%";
                $params[] = $sParam;
                $params[] = $sParam;
                $params[] = $sParam;
            }

            $whereSql = implode(' AND ', $where);

            $stmt = $pdo->prepare("
                SELECT a.*, s.admission_number, s.surname, s.firstname, s.class, s.photo
                FROM attendance a
                JOIN students s ON a.student_id = s.id
                WHERE $whereSql
                ORDER BY a.id DESC
            ");
            $stmt->execute($params);
            $records = $stmt->fetchAll();

            echo json_encode(['success' => true, 'data' => $records, 'date' => $date]);
            break;

        case 'dashboard_stats':
            $today = date('Y-m-d');

            $totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status != 'Inactive'")->fetchColumn();
            $presentToday = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE date = '$today'")->fetchColumn();
            $currentlyInSchool = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE date = '$today' AND check_out IS NULL")->fetchColumn();
            $absentToday = max(0, $totalStudents - $presentToday);
            $awaitingFingerprint = (int)$pdo->query("SELECT COUNT(*) FROM students s WHERE (SELECT COUNT(*) FROM fingerprints f WHERE f.student_id = s.id) = 0")->fetchColumn();

            // Recent activity
            $recentStmt = $pdo->query("
                SELECT a.*, s.surname, s.firstname, s.admission_number, s.class, s.photo 
                FROM attendance a 
                JOIN students s ON a.student_id = s.id 
                WHERE a.date = '$today' 
                ORDER BY a.updated_at DESC 
                LIMIT 10
            ");
            $recentActivity = $recentStmt->fetchAll();

            echo json_encode([
                'success' => true,
                'stats' => [
                    'total_students' => $totalStudents,
                    'present_today' => $presentToday,
                    'absent_today' => $absentToday,
                    'currently_in_school' => $currentlyInSchool,
                    'awaiting_fingerprint' => $awaitingFingerprint
                ],
                'recent_activity' => $recentActivity
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
