<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Lagos');

$logDir = __DIR__ . '/logs';
$activeCourseFile = __DIR__ . '/courses/active_course.json';
$today = date('Y-m-d');
$logFile = "{$logDir}/{$today}.log";

if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

$activeCourse = 'General';
if (file_exists($activeCourseFile)) {
    $data = json_decode(file_get_contents($activeCourseFile), true);
    if (isset($data['course'])) {
        $activeCourse = $data['course'];
    }
}

$success = false;
$name = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $matric = trim($_POST['matric'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $action = 'checkin'; // You can change to checkout if needed

    if ($name && $matric && $reason) {
        // ✅ Load allowed keywords
        $allowedFile = __DIR__ . '/allowed_reasons.json';
        $allowedKeywords = [];

        if (file_exists($allowedFile)) {
            $data = json_decode(file_get_contents($allowedFile), true);
            if (isset($data['reasons']) && is_array($data['reasons'])) {
                $allowedKeywords = $data['reasons'];
            }
        }

        $isValidReason = false;
        foreach ($allowedKeywords as $keyword) {
            if (stripos($reason, $keyword) !== false) {
                $isValidReason = true;
                break;
            }
        }

        if (!$isValidReason) {
            $errorMessage = "Your reason did not contain any valid keywords. Attendance not marked.";
        } else {
            // Use shared MAC helper
            require_once __DIR__ . '/includes/get_mac.php';
            $ipAddr = $_SERVER['REMOTE_ADDR'];
            $macAddr = get_mac_from_ip($ipAddr);
            // ✅ Format log line
            // Standardized log format:
            // name | matric | action | fingerprint | ip | mac | timestamp | userAgent | course | reason
            $logLine = sprintf(
                "%s | %s | %s | %s | %s | %s | %s | %s | %s | %s\n",
                strtoupper($name),
                $matric,
                $action,
                'MANUAL',
                $ipAddr,
                $macAddr,
                date('Y-m-d H:i:s'),
                $_SERVER['HTTP_USER_AGENT'] ?? 'Web Ticket Panel',
                $activeCourse,
                $reason
            );

            file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

            $success = true;
        }
    }
}
?>


<!DOCTYPE html>
<html>

<head>
    <title>Manual Attendance (God Mode)</title>
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --bg-light: linear-gradient(120deg, #f9fafb, #e0f2fe);
            --bg-dark: linear-gradient(120deg, #1e293b, #0f172a);
            --card-bg: rgba(255, 255, 255, 0.95);
            --text-color: #1f2937;
        }

        body {
            font-family: "Segoe UI", sans-serif;
            background: var(--bg-light);
            margin: 0;
            padding: 0;
            transition: all 0.5s ease;
        }

        .palette-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, #6366f1, #3b82f6);
            color: #fff;
            border: none;
            padding: 10px 14px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
            transition: all 0.3s;
            z-index: 1000;
        }

        .palette-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.25);
        }

        .manual-form {
            width: 600px;
            margin: 80px auto 60px auto;
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            padding: 32px;
            border-radius: 16px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.12);
            transition: all 0.5s ease;
        }

        .manual-form h2 {
            margin-top: 0;
            text-align: center;
            color: var(--text-color);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }

        .manual-form label {
            font-weight: 600;
            display: block;
            margin-bottom: 6px;
            color: var(--text-color);
        }

        .manual-form input[type="text"] {
            width: 550px;
            padding: 14px;
            margin-bottom: 22px;
            border-radius: 12px;
            border: 1px solid #d1d5db;
            transition: all 0.3s;
        }

        .manual-form input[type="text"]:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.3);
            outline: none;
        }

        .manual-form button {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #6366f1, #3b82f6);
            color: white;
            font-weight: 700;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            box-shadow: 0 10px 30px rgba(99, 102, 241, 0.3);
            transition: all 0.3s;
        }

        .manual-form button:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(99, 102, 241, 0.4);
        }
        .manual-form textarea {
            width: 550px;
            padding: 14px;
            margin-bottom: 22px;
            border-radius: 12px;
            border: 1px solid #d1d5db;
            resize: vertical;
            transition: all 0.3s;
            font-family: inherit;
            font-size: 15px;
            background: #ffffff;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .manual-form textarea:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.3);
            outline: none;
            background: #ffffff;
        }
    </style>
</head>

<body>

    <button class="palette-btn" onclick="togglePalette()"><i class='bx bx-adjust'></i> Switch Palette</button>

    <form class="manual-form" method="post">
        <h2><i class='bx bx-edit'></i> Manual Attendance</h2>

        <label for="name">Full Name:</label>
        <input type="text" name="name" id="name" placeholder="e.g., BELLO HABEEB" required>

        <label for="matric">Matric Number:</label>
        <input type="text" name="matric" id="matric" placeholder="e.g., 2023000000" required>

        <label for="reason">Reason:</label>
        <textarea name="reason" id="reason" placeholder="Type your reason here..." rows="4" required></textarea>

        <input type="hidden" name="course" value="<?= htmlspecialchars($activeCourse) ?>">

        <button type="submit"><i class='bx bx-check-circle'></i> Mark Attendance</button>
    </form>

    <script>
        <?php if ($success): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: 'Attendance marked successfully for <?= addslashes($name) ?>.',
                confirmButtonColor: '#6366f1'
            });
        <?php elseif (!empty($errorMessage)): ?>
            Swal.fire({
                icon: 'error',
                title: 'Invalid Reason',
                text: '<?= addslashes($errorMessage) ?>',
                confirmButtonColor: '#ef4444'
            });
        <?php endif; ?>

        function togglePalette() {
            const root = document.documentElement;
            const body = document.body;
            if (body.style.background === 'var(--bg-dark)') {
                body.style.background = 'var(--bg-light)';
                root.style.setProperty('--card-bg', 'rgba(255,255,255,0.95)');
                root.style.setProperty('--text-color', '#1f2937');
            } else {
                body.style.background = 'var(--bg-dark)';
                root.style.setProperty('--card-bg', 'rgba(30,41,59,0.9)');
                root.style.setProperty('--text-color', '#f1f5f9');
            }
        }
    </script>

</body>

</html>