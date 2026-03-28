<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$logDir = __DIR__;
$courseFile = dirname(__DIR__) . "/courses/course.json";

// ✅ Load courses safely
$courses = file_exists($courseFile) ? json_decode(file_get_contents($courseFile), true) : ['General'];
if (empty($courses)) $courses = ['General'];

// ✅ Read and sanitize query params
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedCourse = isset($_GET['course']) ? trim($_GET['course']) : 'All';
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));

// ✅ Validate course value
if (!in_array($selectedCourse, $courses) && $selectedCourse !== 'All') {
    $selectedCourse = 'All';
}

$perPage = 20;
$logs = [];

// ✅ Classic failed attempts
$logFiles = glob($logDir . '/*_failed_attempts.log');
foreach ($logFiles as $filePath) {
    if (!preg_match('/(\d{4}-\d{2}-\d{2})_failed_attempts\.log$/', $filePath, $match)) continue;
    $logDate = $match[1];
    if ($logDate !== $selectedDate) continue;

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 5) continue;

        $macRegex = '/([0-9a-f]{2}[:\\-]){5}[0-9a-f]{2}/i';

        // Handle new standardized failed log format (with mac)
        if (count($parts) >= 9 && preg_match($macRegex, $parts[5])) {
            // name | matric | action | fingerprint | ip | mac | timestamp | userAgent | course | reason
            $nameVal = $parts[0];
            $matricVal = $parts[1];
            $finger = $parts[3] ?? '';
            $ipVal = $parts[4] ?? '';
            $timestampVal = $parts[6] ?? '';
            $deviceVal = $parts[7] ?? '';
            $courseVal = $parts[8] ?? '';
        } else {
            // Old format: name | matric | ip | fingerprint | timestamp | device | course
            $nameVal = $parts[0] ?? '';
            $matricVal = $parts[1] ?? '';
            $ipVal = $parts[2] ?? '';
            $finger = $parts[3] ?? '';
            $timestampVal = $parts[4] ?? '';
            $deviceVal = $parts[5] ?? '';
            $courseVal = $parts[6] ?? '';
        }

        $entry = [
            'name' => $nameVal,
            'matric' => $matricVal,
            'ip' => $ipVal,
            'fingerprint' => $finger,
            'timestamp' => $timestampVal,
            'device' => $deviceVal,
            'course' => $courseVal,
        ];

        $matchesCourse = ($selectedCourse === 'All' || $courseVal === $selectedCourse);
        $matchesSearch = ($search === '' || stripos($entry['name'], $search) !== false || stripos($entry['matric'], $search) !== false);

        if ($matchesCourse && $matchesSearch) {
            $logs[] = $entry;
        }
    }
}

// ✅ Check-In Only logic
$mainLogFile = "{$logDir}/{$selectedDate}.log";
$checkMap = [];

if (file_exists($mainLogFile)) {
    $lines = file($mainLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 5) continue;

        $macRegex = '/([0-9a-f]{2}[:\\-]){5}[0-9a-f]{2}/i';

        if (count($parts) >= 9 && preg_match($macRegex, $parts[5])) {
            // New: name | matric | action | fingerprint | ip | mac | timestamp | device | course | reason
            $name = $parts[0];
            $matric = $parts[1];
            $action = $parts[2];
            $finger = $parts[3];
            $ip = $parts[4];
            $timestamp = $parts[6] ?? '';
            $device = $parts[7] ?? '';
            $course = $parts[8] ?? '';
        } else {
            // Old: name | matric | action | fingerprint | ip | timestamp | device | course
            $name = $parts[0] ?? '';
            $matric = $parts[1] ?? '';
            $action = $parts[2] ?? '';
            $finger = $parts[3] ?? '';
            $ip = $parts[4] ?? '';
            $timestamp = $parts[5] ?? '';
            $device = $parts[6] ?? '';
            $course = $parts[7] ?? '';
        }

        $course = trim($course);

        if ($selectedCourse !== 'All' && $course !== $selectedCourse) continue;

        if (!isset($checkMap[$matric])) {
            $checkMap[$matric] = [
                'name' => $name,
                'matric' => $matric,
                'checkin' => '',
                'checkout' => '',
                'ip' => $ip,
                'fingerprint' => $finger,
                'timestamp' => $timestamp,
                'device' => $device,
                'course' => $course,
            ];
        }

        if (strtolower($action) === 'checkin') $checkMap[$matric]['checkin'] = $timestamp;
        if (strtolower($action) === 'checkout') $checkMap[$matric]['checkout'] = $timestamp;
    }

    foreach ($checkMap as $entry) {
        if ($entry['checkin'] && !$entry['checkout']) {
            $matchesSearch = ($search === '' || stripos($entry['name'], $search) !== false || stripos($entry['matric'], $search) !== false);
            if ($matchesSearch) {
                $logs[] = $entry;
            }
        }
    }
}

// ✅ Pagination
$totalLogs = count($logs);
$totalPages = ceil($totalLogs / $perPage);
$currentPage = min($page, $totalPages > 0 ? $totalPages : 1);
$startIndex = ($currentPage - 1) * $perPage;
$logsPage = array_slice($logs, $startIndex, $perPage);
?>


<!DOCTYPE html>
<html>

<head>
    <title>Failed Attempts</title>
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        :root {
            --accent-color: #b91c1c;
        }

        body {
            font-family: "Segoe UI", sans-serif;
            background: linear-gradient(135deg, #f3f4f6, #ffffff);
            margin: 0;
        }

        .container {
            max-width: 1200px;
            margin: 60px auto;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 8px 40px rgba(0, 0, 0, 0.08);
            border: 2px solid var(--accent-color);
            animation: fadeIn 1s ease forwards;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        h2 {
            text-align: center;
            color: var(--accent-color);
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }

        h2 i {
            font-size: 1.6rem;
        }

        .logs-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
            justify-content: center;
        }

        .logs-controls input,
        .logs-controls select,
        .logs-controls button {
            padding: 0.6rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.8);
            font-size: 0.95rem;
        }

        .logs-controls button {
            background: linear-gradient(135deg, var(--accent-color), #7f1d1d);
            color: #fff;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .logs-controls button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            overflow: hidden;
        }

        table th,
        table td {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            font-size: 0.95rem;
        }

        table th {
            background: var(--accent-color);
            color: #fff;
        }

        table tr:hover {
            background: rgba(185, 28, 28, 0.05);
        }

        .pagination {
            text-align: center;
            margin-top: 20px;
        }

        .pagination a {
            margin: 0 5px;
            background: rgba(185, 28, 28, 0.1);
            color: var(--accent-color);
            padding: 8px 14px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
            transition: all 0.3s;
        }

        .pagination a.active {
            background: var(--accent-color);
            color: #fff;
        }

        .pagination a:hover {
            background: #7f1d1d;
            color: #fff;
        }

        .no-logs {
            text-align: center;
            padding: 2rem;
            color: #6b7280;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 12px;
            margin-top: 1rem;
        }

        .palette-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            padding: 10px 15px;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 100;
        }

        .palette-toggle:hover {
            backdrop-filter: blur(10px);
            transform: scale(1.05);
        }

        .logs-controls button,
        .logs-controls .btn {
            background: linear-gradient(135deg, var(--accent-color), #0056b3);
            color: white;
            border: none;
            padding: 0.6rem 1.4rem;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .logs-controls button:hover,
        .logs-controls .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }
    </style>
</head>

<body>

    <button class="palette-toggle"><i class='bx bx-palette'></i></button>

    <div class="container">
        <h2><i class='bx bx-error-alt'></i> Failed Attempts</h2>
        <form method="get" class="logs-controls" action="">
            <input type="hidden" name="page" value="failed_attempts">
            <input type="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" max="<?= date('Y-m-d') ?>">
            <select name="course" onchange="this.form.submit()">
                <option value="All" <?= $selectedCourse === 'All' ? 'selected' : '' ?>>All</option>
                <?php foreach ($courses as $course): ?>
                    <option value="<?= htmlspecialchars(trim($course)) ?>" <?= trim($course) === $selectedCourse ? 'selected' : '' ?>>
                        <?= htmlspecialchars($course) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="search" placeholder="Search name or matric" value="<?= htmlspecialchars($search) ?>">
            <button type="submit"><i class='bx bx-filter-alt'></i> Filter</button><a href="../admin/logs/export_failed.php?date=<?= urlencode($selectedDate) ?>&course=<?= urlencode($selectedCourse) ?>&search=<?= urlencode($search) ?>" class="btn">
                <i class='bx bx-download'></i> Export CSV
            </a>
            <a href="../admin/logs/export_simple_failed_attempts.php?date=<?= urlencode($selectedDate) ?>&course=<?= urlencode($selectedCourse) ?>&search=<?= urlencode($search) ?>" class="btn">
                <i class='bx bx-download'></i> Export Simple
            </a>
        </form>



        <?php if (count($logsPage) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Matric</th>
                        <th>IP</th>
                        <th>Fingerprint</th>
                        <th>Timestamp</th>
                        <th>Device</th>
                        <th>Course</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logsPage as $log): ?>
                        <tr>
                            <td><?= htmlspecialchars($log['name']) ?></td>
                            <td><?= htmlspecialchars($log['matric']) ?></td>
                            <td><?= htmlspecialchars($log['ip']) ?></td>
                            <td><?= htmlspecialchars($log['fingerprint']) ?></td>
                            <td><?= htmlspecialchars($log['timestamp']) ?></td>
                            <td><?= htmlspecialchars($log['device']) ?></td>
                            <td><?= htmlspecialchars($log['course']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?date=<?= urlencode($selectedDate) ?>&course=<?= urlencode($selectedCourse) ?>&search=<?= urlencode($search) ?>&page=<?= $i ?>" class="<?= $i === $currentPage ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="no-logs">No logs found for your filters.</div>
        <?php endif; ?>
    </div>

    <script>
        const palettes = ['#b91c1c', '#3b82f6', '#22c55e', '#facc15', '#8b5cf6', '#ef4444'];
        let current = 0;
        document.querySelector('.palette-toggle').addEventListener('click', () => {
            current = (current + 1) % palettes.length;
            document.documentElement.style.setProperty('--accent-color', palettes[current]);
            localStorage.setItem('failedPalette', current);
        });
        document.addEventListener('DOMContentLoaded', () => {
            const saved = localStorage.getItem('failedPalette');
            if (saved !== null) {
                current = parseInt(saved);
                document.documentElement.style.setProperty('--accent-color', palettes[current]);
            }
        });
    </script>

</body>

</html>