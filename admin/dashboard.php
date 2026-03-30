<?php
$logDir = __DIR__ . '/logs';
$failedDir = $logDir;

$dailyCounts = [];
$courseCounts = [];
$failedCounts = [];
$uniqueStudents = [];
$recentLogs = [];

$today = new DateTime();
$twoDaysAgo = (clone $today)->modify('-2 days');

foreach (glob($logDir . '/*.log') as $file) {
    if (preg_match('/(\d{4}-\d{2}-\d{2})\.log$/', $file, $match)) {
        $date = $match[1];
        $fileDate = new DateTime($date);
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $dailyCounts[$date] = count($lines);

        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (isset($parts[1])) {
                $matric = trim($parts[1]);
                $uniqueStudents[$matric] = true;
            }

            // detect course index depending on whether MAC field exists
            $macRegex = '/([0-9a-f]{2}[:\\-]){5}[0-9a-f]{2}/i';
            if (isset($parts[5]) && preg_match($macRegex, $parts[5])) {
                // new format: course likely at index 8
                $course = isset($parts[8]) ? trim($parts[8]) : 'General';
            } else {
                // old format: course likely at index 7
                $course = isset($parts[7]) ? trim($parts[7]) : 'General';
            }
            $courseCounts[$course] = ($courseCounts[$course] ?? 0) + 1;
        }

        if ($fileDate >= $twoDaysAgo) {
            foreach (array_reverse($lines) as $recentLine) {
                $recentLogs[] = $recentLine;
            }
        }
    }
}

foreach (glob($failedDir . '/*_failed_attempts.log') as $file) {
    if (preg_match('/(\d{4}-\d{2}-\d{2})_failed_attempts\.log$/', $file, $match)) {
        $date = $match[1];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $failedCounts[$date] = count($lines);
    }
}

$supportFile = __DIR__ . '/support_tickets.json';
$supportTickets = file_exists($supportFile) ? json_decode(file_get_contents($supportFile), true) : [];
$newSupportCount = 0;
if (is_array($supportTickets)) {
    foreach ($supportTickets as $ticket) {
        if (!($ticket['resolved'] ?? false)) {
            $newSupportCount++;
        }
    }
}

$fingerprintFile = __DIR__ . '/fingerprints.json';
$fingerprintsData = file_exists($fingerprintFile) ? json_decode(file_get_contents($fingerprintFile), true) : [];
$fingerprintCount = is_array($fingerprintsData) ? count($fingerprintsData) : 0;

$activeCourse = "General";
$activeFile = __DIR__ . "/courses/active_course.json";
if (file_exists($activeFile)) {
    $activeData = json_decode(file_get_contents($activeFile), true);
    if (is_array($activeData)) {
        $activeCourse = $activeData['course'] ?? "General";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        :root {
            --accent-color: #3b82f6;
            --bg-gradient: linear-gradient(135deg, #f0f4ff, #ffffff);
        }

        body {
            font-family: "Segoe UI", sans-serif;
            margin: 0;
            padding: 0;
            background: var(--bg-gradient);
            transition: background 0.5s ease;
        }

        header {
            text-align: center;
            padding: 30px 20px;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(8px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }

        header h1 {
            margin: 0;
            font-size: 2em;
            color: var(--accent-color);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin: 30px auto;
            max-width: 1200px;
            padding: 0 20px;
        }

        .stat {
            background: rgba(255, 255, 255, 0.8);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.08);
            text-align: center;
            backdrop-filter: blur(12px);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            opacity: 0;
            animation: fadeSlideUp 0.8s forwards;
        }

        .stat:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.1);
        }

        .stat:nth-child(1) {
            animation-delay: 0.1s;
            border-top: 4px solid var(--accent-color);
        }

        .stat:nth-child(2) {
            animation-delay: 0.2s;
            border-top: 4px solid var(--accent-color);
        }

        .stat:nth-child(3) {
            animation-delay: 0.3s;
            border-top: 4px solid var(--accent-color);
        }

        .stat:nth-child(4) {
            animation-delay: 0.4s;
            border-top: 4px solid var(--accent-color);
        }

        .stat:nth-child(5) {
            animation-delay: 0.5s;
            border-top: 4px solid var(--accent-color);
        }

        .stat:nth-child(6) {
            animation-delay: 0.6s;
            border-top: 4px solid var(--accent-color);
        }

        .stat:nth-child(7) {
            animation-delay: 0.7s;
            border-top: 4px solid var(--accent-color);
        }

        .stat h3 {
            margin: 0 0 10px;
            font-size: 1em;
            color: #555;
        }

        .stat span {
            font-size: 1.8em;
            font-weight: bold;
            color: #111827;
        }

        @keyframes fadeSlideUp {
            0% {
                opacity: 0;
                transform: translateY(20px);
            }

            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .charts {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 30px;
            margin: 50px auto;
            max-width: 1200px;
            padding: 0 20px;
        }

        @media (max-width: 1000px) {
            .charts {
                grid-template-columns: 1fr;
            }
        }

        .chart-wrapper {
            background: rgba(255, 255, 255, 0.85);
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.08);
            backdrop-filter: blur(12px);
            animation: fadeSlideUp 1s forwards;
            opacity: 0;
        }

        .recent-logs ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .recent-logs li {
            border-bottom: 1px solid #eee;
            padding: 6px 0;
            opacity: 0;
            animation: fadeSlideUp 0.7s forwards;
        }

        .quick-actions a {
            display: inline-block;
            margin: 10px;
            padding: 12px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
            transition: transform 0.3s ease, background 0.3s ease;
        }

        .quick-actions a:hover {
            transform: translateY(-3px) scale(1.05);
            background: linear-gradient(135deg, var(--accent-color), #1e40af);
        }

        .palette-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.3);
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

        .recent-logs {
            margin: 60px auto;
            max-width: 700px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(14px);
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
            transition: all 0.4s ease;
        }

        .recent-logs h3 {
            text-align: center;
            color: #1f2937;
            margin-top: 0;
            margin-bottom: 20px;
            font-weight: 700;
        }

        .log-list {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 300px;
            overflow-y: auto;
        }

        .log-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255, 255, 255, 0.8);
            margin-bottom: 12px;
            padding: 14px 20px;
            border-radius: 12px;
            color: #1f2937;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .log-list li:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }

        .log-list li:last-child {
            margin-bottom: 0;
        }

        .log-list .log-name {
            flex: 1;
            font-weight: 700;
            color: #374151;
        }

        .log-list .log-matric {
            font-weight: 500;
            color: #6366f1;
        }

        .empty-log {
            text-align: center;
            color: #6b7280;
            font-style: italic;
        }
        .log-list li {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    background: rgba(255, 255, 255, 0.8);
    margin-bottom: 12px;
    padding: 14px 20px;
    border-radius: 12px;
    color: #1f2937;
    font-weight: 600;
    transition: transform 0.2s, box-shadow 0.2s;
}

.log-list li:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
}

.log-main {
    display: flex;
    justify-content: space-between;
    width: 100%;
}

.log-name {
    font-weight: 700;
    color: #374151;
}

.log-matric {
    font-weight: 500;
    color: #6366f1;
}

.log-course {
    margin-top: 4px;
    font-size: 14px;
    color: #6b7280;
    font-style: italic;
}

.empty-log {
    text-align: center;
    color: #6b7280;
    font-style: italic;
}

    </style>
</head>

<body>
    <button class="palette-toggle"><i class='bx bx-palette'></i></button>

    <header>
        <h1><i class='bx bxs-dashboard'></i> Admin Dashboard</h1>
    </header>

    <div class="stats">
        <div class="stat">
            <h3><i class='bx bx-calendar'></i> Total Attendance</h3><span><?= array_sum($dailyCounts) ?></span>
        </div>
        <div class="stat">
            <h3><i class='bx bx-book'></i> Courses</h3><span><?= count($courseCounts) ?></span>
        </div>
        <div class="stat">
            <h3><i class='bx bx-error'></i> Failed</h3><span><?= array_sum($failedCounts) ?></span>
        </div>
        <div class="stat">
            <h3><i class='bx bx-pin'></i> Active Course</h3><span><?= htmlspecialchars($activeCourse) ?></span>
        </div>
        <div class="stat">
            <h3><i class='bx bx-support'></i> Tickets</h3><span><?= $newSupportCount ?></span>
        </div>
        <div class="stat">
            <h3><i class='bx bx-link'></i> Fingerprints</h3><span><?= $fingerprintCount ?></span>
        </div>
        <div class="stat">
            <h3><i class='bx bx-user'></i> Students</h3><span><?= count($uniqueStudents) ?></span>
        </div>
    </div>

    <div class="charts">
        <div class="chart-wrapper"><canvas id="attendanceChart"></canvas></div>
        <div class="chart-wrapper"><canvas id="coursePieChart"></canvas></div>
        <div class="chart-wrapper"><canvas id="failedChart"></canvas></div>
    </div>

    <div class="recent-logs">
        <h3><i class='bx bx-time'></i> Recent Activity (Last 2 Days)</h3>
        <ul class="log-list">
            <?php if (!empty($recentLogs)): ?>
                    <?php foreach ($recentLogs as $log): ?>
                        <?php
                        $parts = array_map('trim', explode('|', $log));
                        $name = isset($parts[0]) ? strtoupper($parts[0]) : 'Unknown';
                        $matric = isset($parts[1]) ? $parts[1] : 'N/A';
                        $macRegex = '/([0-9a-f]{2}[:\\-]){5}[0-9a-f]{2}/i';
                        if (isset($parts[5]) && preg_match($macRegex, $parts[5])) {
                            $course = isset($parts[8]) ? $parts[8] : 'General';
                        } else {
                            $course = isset($parts[7]) ? $parts[7] : 'General';
                        }
                        ?>
                    <li>
                        <div class="log-main">
                            <span class="log-name"><?= htmlspecialchars($name) ?></span>
                            <span class="log-matric"><?= htmlspecialchars($matric) ?></span>
                        </div>
                        <div class="log-course"><?= htmlspecialchars($course) ?></div>
                    </li>
                <?php endforeach; ?>
            <?php else: ?>
                <li class="empty-log">No recent logs.</li>
            <?php endif; ?>
        </ul>
    </div>




    <div class="quick-actions" style="margin: 50px auto; max-width: 900px; text-align: center;">
        <h3><i class='bx bx-cog'></i> Quick Actions</h3>
        <a href="index.php?page=logs" style="background: #3b82f6; color: white;"><i class='bx bx-file'></i> Logs</a>
        <a href="index.php?page=support_tickets" style="background: #10b981; color: white;"><i class='bx bx-support'></i> Support</a>
        <a href="index.php?page=unlink_fingerprint" style="background: #f59e0b; color: white;"><i class='bx bx-link'></i> Fingerprints</a>
        <a href="./logs/export_simple.php" style="background: #ef4444; color: white;"><i class='bx bx-download'></i> Export</a>
    </div>

    <script>
        const palettes = [{
                color: '#3b82f6',
                bg: 'linear-gradient(135deg, #3b82f6, #ef4444)'
            },
            {
                color: '#10b981',
                bg: 'linear-gradient(135deg, #10b981, #f59e0b)'
            },
            {
                color: '#facc15',
                bg: 'linear-gradient(135deg, #facc15, #8b5cf6)'
            },
            {
                color: '#06b6d4',
                bg: 'linear-gradient(135deg, #06b6d4, #f97316)'
            },
            {
                color: '#ec4899',
                bg: 'linear-gradient(135deg, #ec4899, #6366f1)'
            },
            {
                color: '#000000',
                bg: 'linear-gradient(135deg, #000000, #f59e0b)'
            },
            {
                color: '#84cc16',
                bg: 'linear-gradient(135deg, #84cc16, #3b82f6)'
            }
        ];

        let current = 0;
        document.querySelector('.palette-toggle').addEventListener('click', () => {
            current = (current + 1) % palettes.length;
            document.documentElement.style.setProperty('--accent-color', palettes[current].color);
            document.documentElement.style.setProperty('--bg-gradient', palettes[current].bg);
            localStorage.setItem('adminPalette', current);
        });
        document.addEventListener('DOMContentLoaded', () => {
            const saved = localStorage.getItem('adminPalette');
            if (saved !== null) {
                current = parseInt(saved);
                document.documentElement.style.setProperty('--accent-color', palettes[current].color);
                document.documentElement.style.setProperty('--bg-gradient', palettes[current].bg);
            }
        });

        new Chart(document.getElementById('attendanceChart').getContext('2d'), {
            type: 'line',
            data: {
                labels: <?= json_encode(array_keys($dailyCounts)) ?>,
                datasets: [{
                    label: 'Attendance',
                    data: <?= json_encode(array_values($dailyCounts)) ?>,
                    backgroundColor: 'rgba(59, 130, 246, 0.2)',
                    borderColor: 'rgba(59, 130, 246, 1)',
                    tension: 0.3,
                    fill: true,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });

        new Chart(document.getElementById('coursePieChart').getContext('2d'), {
            type: 'pie',
            data: {
                labels: <?= json_encode(array_keys($courseCounts)) ?>,
                datasets: [{
                    label: 'Courses',
                    data: <?= json_encode(array_values($courseCounts)) ?>,
                    backgroundColor: ['#3b82f6', '#ef4444', '#facc15', '#10b981', '#8b5cf6', '#06b6d4', '#f472b6']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });

        new Chart(document.getElementById('failedChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_keys($failedCounts)) ?>,
                datasets: [{
                    label: 'Failed',
                    data: <?= json_encode(array_values($failedCounts)) ?>,
                    backgroundColor: 'rgba(239, 68, 68, 0.7)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    </script>
</body>

</html>