<?php
$courseFile = __DIR__ . '/course.json';

// Load courses
$courses = file_exists($courseFile) ? json_decode(file_get_contents($courseFile), true) : [];
if (!is_array($courses)) $courses = [];

// Add course
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['course_name'])) {
        $newCourse = trim($_POST['course_name']);
        if ($newCourse !== '' && !in_array($newCourse, $courses, true)) {
            $courses[] = $newCourse;
            file_put_contents($courseFile, json_encode($courses, JSON_PRETTY_PRINT));
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    if (isset($_POST['remove_course'])) {
        $removeCourse = $_POST['remove_course'];
        $courses = array_values(array_filter($courses, fn($course) => $course !== $removeCourse));
        file_put_contents($courseFile, json_encode($courses, JSON_PRETTY_PRINT));
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Courses</title>
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        :root {
            --accent-color: #ef4444;
        }
        body {
            font-family: "Segoe UI", sans-serif;
            background: linear-gradient(120deg, #f9fafb, #ffffff);
            margin: 0;
            padding: 0;
        }
        .add-course-form, .course-list {
            max-width: 600px;
            margin: 50px auto;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            padding: 32px;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.06);
            animation: fadeIn 0.8s ease forwards;
            border: 2px solid var(--accent-color);
        }
        @keyframes fadeIn {
            from {opacity: 0; transform: translateY(20px);}
            to {opacity: 1; transform: translateY(0);}
        }
        h2, h3 {
            text-align: center;
            color: var(--accent-color);
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }
        label {
            font-weight: 600;
            margin-bottom: 6px;
            display: block;
            color: #374151;
        }
        input[type="text"] {
            width: 100%;
            padding: 12px;
            margin-bottom: 18px;
            border-radius: 10px;
            border: 1px solid #d1d5db;
            transition: all 0.2s;
        }
        input[type="text"]:focus {
            border-color: var(--accent-color);
            outline: none;
        }
        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--accent-color), #1e40af);
            color: white;
            font-weight: 600;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        }
        .course-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
        }
        .course-tag {
            background: linear-gradient(135deg, #e0f2fe, #bae6fd);
            color: #0369a1;
            padding: 8px 14px;
            border-radius: 30px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.04);
            animation: fadeInTag 0.5s ease forwards;
        }
        @keyframes fadeInTag {
            from {opacity: 0; transform: scale(0.9);}
            to {opacity: 1; transform: scale(1);}
        }
        .remove-btn {
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .remove-btn:hover {
            background: #b91c1c;
        }
        .palette-toggle {
            position: fixed;
            top: 15px;
            right: 15px;
            width: 40px;
            height: 40px;
            background: var(--accent-color);
            color: #fff;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            z-index: 9999;
        }
        .palette-toggle:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
        }
        .palette-toggle:active {
            transform: scale(0.95);
        }
    </style>
</head>
<body>

<button class="palette-toggle" title="Change Color"><i class='bx bx-palette'></i></button>

<form method="post" class="add-course-form">
    <h2><i class='bx bx-plus-circle'></i> Add New Course</h2>
    <label for="course_name">Course Name</label>
    <input type="text" id="course_name" name="course_name" placeholder="e.g., CSC101" required />
    <button type="submit"><i class='bx bx-add-to-queue'></i> Add Course</button>
</form>

<div class="course-list">
    <h3><i class='bx bx-book'></i> Available Courses</h3>
    <div class="course-tags">
        <?php if (!empty($courses)): ?>
            <?php foreach ($courses as $course): ?>
                <div class="course-tag">
                    <?= htmlspecialchars($course) ?>
                    <form method="post" onsubmit="return confirm('Are you sure you want to remove this course?');">
                        <input type="hidden" name="remove_course" value="<?= htmlspecialchars($course) ?>">
                        <button type="submit" class="remove-btn" title="Remove Course">&times;</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="color: #6b7280;">No courses added yet.</div>
        <?php endif; ?>
    </div>
</div>

<script>
const palettes = ['#ef4444', '#3b82f6', '#22c55e', '#f59e0b', '#8b5cf6', '#14b8a6'];
let current = 0;
document.querySelector('.palette-toggle').addEventListener('click', () => {
    current = (current + 1) % palettes.length;
    document.documentElement.style.setProperty('--accent-color', palettes[current]);
    localStorage.setItem('coursePalette', current);
});
document.addEventListener('DOMContentLoaded', () => {
    const saved = localStorage.getItem('coursePalette');
    if (saved !== null) {
        current = parseInt(saved);
        document.documentElement.style.setProperty('--accent-color', palettes[current]);
    }
});
</script>

</body>
</html>
