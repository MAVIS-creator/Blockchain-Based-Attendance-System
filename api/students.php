<?php
/**
 * Student API Endpoint
 * High-Q Solid Academy Biometric Attendance System
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

// Allow public read for terminal or require login for admin
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if (!in_array($action, ['get', 'list']) && !is_authenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$pdo = get_db_connection();

try {
    switch ($action) {
        case 'list':
            $search = trim($_GET['search'] ?? '');
            $classFilter = trim($_GET['class'] ?? '');
            $statusFilter = trim($_GET['status'] ?? '');
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
            $offset = ($page - 1) * $limit;

            $where = ["1=1"];
            $params = [];

            if ($search !== '') {
                $where[] = "(surname LIKE ? OR firstname LIKE ? OR admission_number LIKE ? OR parent_phone LIKE ?)";
                $sParam = "%$search%";
                $params[] = $sParam;
                $params[] = $sParam;
                $params[] = $sParam;
                $params[] = $sParam;
            }

            if ($classFilter !== '') {
                $where[] = "class = ?";
                $params[] = $classFilter;
            }

            if ($statusFilter !== '') {
                $where[] = "status = ?";
                $params[] = $statusFilter;
            }

            $whereSql = implode(' AND ', $where);

            // Count total
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE $whereSql");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // Fetch records
            $stmt = $pdo->prepare("
                SELECT s.*, 
                       (SELECT COUNT(*) FROM fingerprints f WHERE f.student_id = s.id) as fingerprint_count
                FROM students s 
                WHERE $whereSql 
                ORDER BY s.id DESC 
                LIMIT $limit OFFSET $offset
            ");
            $stmt->execute($params);
            $students = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'data' => $students,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            $admission = trim($_GET['admission_number'] ?? '');

            if ($id > 0) {
                $stmt = $pdo->prepare("SELECT s.*, (SELECT COUNT(*) FROM fingerprints f WHERE f.student_id = s.id) as fingerprint_count FROM students s WHERE s.id = ?");
                $stmt->execute([$id]);
            } else if ($admission !== '') {
                $stmt = $pdo->prepare("SELECT s.*, (SELECT COUNT(*) FROM fingerprints f WHERE f.student_id = s.id) as fingerprint_count FROM students s WHERE s.admission_number = ?");
                $stmt->execute([$admission]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Missing ID or Admission Number']);
                exit;
            }

            $student = $stmt->fetch();
            if (!$student) {
                echo json_encode(['success' => false, 'message' => 'Student not found']);
                exit;
            }

            echo json_encode(['success' => true, 'student' => $student]);
            break;

        case 'save':
            $id = (int)($_POST['id'] ?? 0);
            $admission_number = trim($_POST['admission_number'] ?? '');
            $surname = trim($_POST['surname'] ?? '');
            $firstname = trim($_POST['firstname'] ?? '');
            $middlename = trim($_POST['middlename'] ?? '');
            $gender = trim($_POST['gender'] ?? 'Male');
            $dob = $_POST['dob'] ?: null;
            $class = trim($_POST['class'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $parent_name = trim($_POST['parent_name'] ?? '');
            $parent_phone = trim($_POST['parent_phone'] ?? '');
            $parent_email = trim($_POST['parent_email'] ?? '');
            $status = trim($_POST['status'] ?? 'Awaiting Fingerprint');

            if ($admission_number === '' || $surname === '' || $firstname === '' || $class === '') {
                echo json_encode(['success' => false, 'message' => 'Admission Number, Surname, First Name and Class are required.']);
                exit;
            }

            // Photo upload
            $photoPath = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../storage/photos/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $filename = 'student_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $filename)) {
                    $photoPath = 'storage/photos/' . $filename;
                }
            }

            if ($id > 0) {
                // Check unique admission number
                $check = $pdo->prepare("SELECT id FROM students WHERE admission_number = ? AND id != ?");
                $check->execute([$admission_number, $id]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Admission number already exists.']);
                    exit;
                }

                $query = "UPDATE students SET admission_number=?, surname=?, firstname=?, middlename=?, gender=?, dob=?, class=?, address=?, parent_name=?, parent_phone=?, parent_email=?, status=?";
                $params = [$admission_number, $surname, $firstname, $middlename, $gender, $dob, $class, $address, $parent_name, $parent_phone, $parent_email, $status];

                if ($photoPath !== null) {
                    $query .= ", photo=?";
                    $params[] = $photoPath;
                }
                $query .= " WHERE id=?";
                $params[] = $id;

                $stmt = $pdo->prepare($query);
                $stmt->execute($params);

                echo json_encode(['success' => true, 'message' => 'Student updated successfully', 'id' => $id]);
            } else {
                // Check unique admission number
                $check = $pdo->prepare("SELECT id FROM students WHERE admission_number = ?");
                $check->execute([$admission_number]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Admission number already exists.']);
                    exit;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO students (admission_number, surname, firstname, middlename, gender, dob, class, address, parent_name, parent_phone, parent_email, photo, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$admission_number, $surname, $firstname, $middlename, $gender, $dob, $class, $address, $parent_name, $parent_phone, $parent_email, $photoPath, $status]);

                $newId = $pdo->lastInsertId();
                echo json_encode(['success' => true, 'message' => 'Student registered successfully', 'id' => $newId]);
            }
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Student deleted successfully']);
            break;

        case 'validate_import':
        case 'import':
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'Please select a valid CSV file.']);
                exit;
            }

            $tmpFile = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($tmpFile, 'r');
            if (!$handle) {
                echo json_encode(['success' => false, 'message' => 'Failed to read file.']);
                exit;
            }

            $header = fgetcsv($handle);
            if (!$header) {
                echo json_encode(['success' => false, 'message' => 'Empty CSV file.']);
                exit;
            }

            // Normalize header column names
            $headersMap = [];
            foreach ($header as $idx => $colName) {
                $cleanCol = strtolower(trim(str_replace([' ', '_', '-'], '', $colName)));
                $headersMap[$cleanCol] = $idx;
            }

            $rows = [];
            $validCount = 0;
            $invalidCount = 0;
            $errors = [];
            $line = 1;

            // Pre-fetch existing admission numbers to detect duplicates
            $existingStmt = $pdo->query("SELECT admission_number FROM students");
            $existingAdmissions = array_flip($existingStmt->fetchAll(PDO::FETCH_COLUMN));

            $seenAdmissionsInFile = [];

            while (($data = fgetcsv($handle)) !== false) {
                $line++;
                if (count($data) < 3) continue; // Skip empty/incomplete lines

                $adm = trim($data[$headersMap['admissionnumber'] ?? $headersMap['admno'] ?? $headersMap['admission'] ?? 0] ?? '');
                $surname = trim($data[$headersMap['surname'] ?? $headersMap['lastname'] ?? 1] ?? '');
                $firstname = trim($data[$headersMap['firstname'] ?? $headersMap['first'] ?? 2] ?? '');
                $middlename = trim($data[$headersMap['middlename'] ?? 3] ?? '');
                $gender = trim($data[$headersMap['gender'] ?? 4] ?? 'Male');
                $gender = in_array(strtolower($gender), ['f', 'female']) ? 'Female' : 'Male';
                $class = trim($data[$headersMap['class'] ?? 5] ?? 'Basic 1');
                $parentName = trim($data[$headersMap['parentname'] ?? $headersMap['parent'] ?? 6] ?? '');
                $parentPhone = trim($data[$headersMap['parentphone'] ?? $headersMap['phone'] ?? 7] ?? '');
                $parentEmail = trim($data[$headersMap['parentemail'] ?? $headersMap['email'] ?? 8] ?? '');

                $rowErrors = [];
                if ($adm === '') $rowErrors[] = 'Missing Admission Number';
                if ($surname === '') $rowErrors[] = 'Missing Surname';
                if ($firstname === '') $rowErrors[] = 'Missing First Name';
                if (isset($existingAdmissions[$adm])) $rowErrors[] = "Admission number '$adm' already exists in database";
                if (isset($seenAdmissionsInFile[$adm])) $rowErrors[] = "Duplicate admission number '$adm' in CSV file";
                if ($parentEmail !== '' && !filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) $rowErrors[] = "Invalid Email: '$parentEmail'";

                if (!empty($adm)) {
                    $seenAdmissionsInFile[$adm] = true;
                }

                $isValid = empty($rowErrors);
                if ($isValid) $validCount++;
                else $invalidCount++;

                $rowObj = [
                    'line' => $line,
                    'admission_number' => $adm,
                    'surname' => $surname,
                    'firstname' => $firstname,
                    'middlename' => $middlename,
                    'gender' => $gender,
                    'class' => $class,
                    'parent_name' => $parentName,
                    'parent_phone' => $parentPhone,
                    'parent_email' => $parentEmail,
                    'status' => $isValid ? 'Valid' : 'Invalid',
                    'errors' => implode(', ', $rowErrors)
                ];

                $rows[] = $rowObj;
            }
            fclose($handle);

            if ($action === 'validate_import') {
                echo json_encode([
                    'success' => true,
                    'total' => count($rows),
                    'validCount' => $validCount,
                    'invalidCount' => $invalidCount,
                    'rows' => $rows
                ]);
                exit;
            }

            // Commit valid rows to DB
            $inserted = 0;
            $insertStmt = $pdo->prepare("
                INSERT INTO students (admission_number, surname, firstname, middlename, gender, class, parent_name, parent_phone, parent_email, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Awaiting Fingerprint')
            ");

            foreach ($rows as $r) {
                if ($r['status'] === 'Valid') {
                    $insertStmt->execute([
                        $r['admission_number'],
                        $r['surname'],
                        $r['firstname'],
                        $r['middlename'],
                        $r['gender'],
                        $r['class'],
                        $r['parent_name'],
                        $r['parent_phone'],
                        $r['parent_email']
                    ]);
                    $inserted++;
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "Successfully imported $inserted valid student records.",
                'inserted' => $inserted,
                'skipped' => $invalidCount
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
