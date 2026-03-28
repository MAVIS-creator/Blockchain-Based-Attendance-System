<?php
$courseFile = __DIR__ . '/course.json';
$activeFile = __DIR__ . '/active_course.json';

// Load courses
$courses = file_exists($courseFile) ? json_decode(file_get_contents($courseFile), true) : [];
if (!is_array($courses)) $courses = [];

// Load current active course
$activeCourse = "";
if (file_exists($activeFile)) {
    $activeData = json_decode(file_get_contents($activeFile), true);
    if (is_array($activeData) && isset($activeData['course'])) {
        $activeCourse = $activeData['course'];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['disable'])) {
        // Disable active course by saving empty JSON
        file_put_contents($activeFile, json_encode(['course' => ''], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    $selected = $_POST['active_course'] ?? '';
    if (in_array($selected, $courses)) {
        file_put_contents($activeFile, json_encode(['course' => $selected], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Set Active Course</title>
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        :root {
            --accent-color: #3b82f6;
            --accent-gradient: linear-gradient(135deg, #3b82f6, #1e40af);
        }

        body {
            font-family: "Segoe UI", sans-serif;
            background: linear-gradient(135deg, #f0f4f8, #e2e8f0);
            margin: 0;
            padding: 0;
            transition: background 0.4s ease;
        }

        .palette-switcher {
            position: fixed;
            top: 20px;
            right: 20px;
            display: flex;
            gap: 8px;
            z-index: 1000;
        }

        .palette-btn {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 2px solid white;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .palette-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }

        .set-active-form {
            max-width: 520px;
            margin: 80px auto;
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(14px);
            padding: 36px;
            border-radius: 18px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.1);
            border: 2px solid var(--accent-color);
            transition: all 0.4s ease;
        }

        .set-active-form h2 {
            text-align: center;
            font-size: 1.7rem;
            color: var(--accent-color);
            margin-bottom: 1.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .set-active-form select,
        .set-active-form button {
            width: 100%;
            padding: 14px;
            margin-bottom: 18px;
            font-size: 1rem;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            transition: all 0.3s ease;
        }

        .set-active-form select:focus {
            border-color: var(--accent-color);
            outline: none;
            box-shadow: 0 0 6px var(--accent-color);
        }

        .set-active-form button {
            background: var(--accent-gradient);
            color: white;
            font-weight: 600;
            border: none;
            cursor: pointer;
            letter-spacing: 0.5px;
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.3);
        }

        .set-active-form button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(30, 64, 175, 0.4);
        }

        .disable-btn {
            background: linear-gradient(135deg, #dc3545, #b02a37) !important;
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.3) !important;
        }

        .status-message {
            text-align: center;
            margin-top: 25px;
            padding: 14px;
            font-size: 1rem;
            border-radius: 12px;
            background: #ffffff;
            backdrop-filter: blur(4px);
            box-shadow: 0 6px 18px rgba(0,0,0,0.05);
            color: #1f2937;
            transition: all 0.4s ease;
        }

        .status-message strong {
            color: #111827;
        }

        .status-message.error {
            background: #ffeaea;
            color: #b91c1c;
        }

        label {
            font-weight: 600;
            margin-bottom: 6px;
            display: block;
            color: #374151;
        }
    </style>
</head>
<body>

<!-- Palette Switcher -->
<div class="palette-switcher">
    <div class="palette-btn" style="background: #3b82f6;" onclick="changePalette('#3b82f6', 'linear-gradient(135deg, #3b82f6, #1e40af)')"></div>
    <div class="palette-btn" style="background: #22c55e;" onclick="changePalette('#22c55e', 'linear-gradient(135deg, #22c55e, #15803d)')"></div>
    <div class="palette-btn" style="background: #ec4899;" onclick="changePalette('#ec4899', 'linear-gradient(135deg, #ec4899, #be185d)')"></div>
    <div class="palette-btn" style="background: #f97316;" onclick="changePalette('#f97316', 'linear-gradient(135deg, #f97316, #c2410c)')"></div>
</div>

<form method="post" class="set-active-form">
    <h2><i class='bx bx-pin'></i> Set Active Course</h2>

    <label for="active_course">Select a Course</label>
    <select name="active_course" id="active_course" required>
        <option value="">-- Choose Course --</option>
        <?php foreach ($courses as $course): ?>
            <option value="<?= htmlspecialchars($course) ?>" <?= $course === $activeCourse ? 'selected' : '' ?>>
                <?= htmlspecialchars($course) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="submit"><i class='bx bx-check-shield'></i> Set Active</button>
    <button type="submit" name="disable" value="1" class="disable-btn"><i class='bx bx-block'></i> Disable All Courses</button>
</form>

<?php if ($activeCourse): ?>
    <div class="status-message">
        <i class='bx bx-check-circle' style="color: green;"></i> Current Active Course: <strong><?= htmlspecialchars($activeCourse) ?></strong>
    </div>
<?php else: ?>
    <div class="status-message error">
        <i class='bx bx-error'></i> No active course selected (all are currently disabled).
    </div>
<?php endif; ?>

<script>
    function changePalette(accent, gradient) {
        document.documentElement.style.setProperty('--accent-color', accent);
        document.documentElement.style.setProperty('--accent-gradient', gradient);
    }
</script>

</body>
</html>
