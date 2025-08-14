<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$announcementFile = __DIR__ . '/announcement.json';
$successMsg = "";
$errorMsg = "";

$announcement = ['message' => '', 'enabled' => false];

if (file_exists($announcementFile)) {
    $announcementData = json_decode(file_get_contents($announcementFile), true);
    if (is_array($announcementData)) {
        $announcement = $announcementData;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim($_POST['message']);
    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'on';

    $announcement['message'] = $message;
    $announcement['enabled'] = $enabled;

    if (file_put_contents($announcementFile, json_encode($announcement, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
        $successMsg = "Announcement updated successfully.";
    } else {
        $errorMsg = "Failed to save announcement.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Announcement (God Mode)</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --main-gradient: linear-gradient(135deg, #3b82f6, #6366f1);
            --background: linear-gradient(135deg, #f0f4f8, #e2ebf0);
        }
        body {
            font-family: "Segoe UI", sans-serif;
            background: var(--background);
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            transition: all 0.6s ease;
        }
        .palette-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--main-gradient);
            color: #fff;
            border: none;
            padding: 10px 14px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
            transition: all 0.3s;
            z-index: 1000;
        }
        .palette-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.25);
        }
        .container {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(16px);
            padding: 50px 60px;
            border-radius: 16px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            max-width: 650px;
            width: 100%;
            transition: all 0.5s;
        }
        h2 {
            text-align: center;
            margin-bottom: 30px;
            color: #1f2937;
            font-weight: 700;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
        }
        textarea {
            width: 100%;
            min-height: 180px;
            padding: 18px;
            border: 1px solid #ddd;
            border-radius: 12px;
            resize: vertical;
            margin-bottom: 25px;
            font-size: 1.05rem;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        textarea:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 12px rgba(99, 102, 241, 0.3);
        }
        .toggle-buttons {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 20px;
        }
        .toggle-buttons button {
            flex: 1;
            padding: 14px;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .toggle-buttons button.active {
            background: var(--main-gradient);
            color: #fff;
            transform: scale(1.05);
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }
        .toggle-buttons button:hover {
            opacity: 0.9;
        }
        .save-btn {
            width: 100%;
            padding: 16px;
            background: var(--main-gradient);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1.2rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }
        .save-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
        }
        .msg {
            padding: 14px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 25px;
            font-weight: 500;
            font-size: 1rem;
        }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>

<button class="palette-btn" onclick="switchPalette()"><i class='bx bx-color-fill'></i> Switch Theme</button>

<div class="container">
    <h2><i class='bx bx-megaphone'></i> Manage Announcement</h2>

    <?php if ($successMsg): ?>
        <script>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?= addslashes($successMsg) ?>',
                confirmButtonColor: '#6366f1'
            });
        </script>
    <?php elseif ($errorMsg): ?>
        <script>
            Swal.fire({
                icon: 'error',
                title: 'Oops!',
                text: '<?= addslashes($errorMsg) ?>',
                confirmButtonColor: '#ef4444'
            });
        </script>
    <?php endif; ?>

    <form method="post">
        <label for="message"><i class='bx bx-message-square-detail'></i> Announcement Message</label>
        <textarea id="message" name="message" placeholder="Enter your announcement here..."><?= htmlspecialchars($announcement['message']) ?></textarea>

        <div class="toggle-buttons">
            <button type="button" id="enableBtn" class="<?= $announcement['enabled'] ? 'active' : '' ?>">
                <i class='bx bx-check-circle'></i> Enable
            </button>
            <button type="button" id="disableBtn" class="<?= !$announcement['enabled'] ? 'active' : '' ?>">
                <i class='bx bx-x-circle'></i> Disable
            </button>
        </div>

        <input type="checkbox" id="enabled" name="enabled" style="display:none;" <?= $announcement['enabled'] ? 'checked' : '' ?>>

        <button type="submit" class="save-btn"><i class='bx bx-save'></i> Save Announcement</button>
    </form>
</div>

<script>
document.getElementById('enableBtn').onclick = function() {
    setActiveState(true);
};
document.getElementById('disableBtn').onclick = function() {
    setActiveState(false);
};
function setActiveState(enabled) {
    if (enabled) {
        document.getElementById('enableBtn').classList.add('active');
        document.getElementById('disableBtn').classList.remove('active');
        document.getElementById('enabled').checked = true;
    } else {
        document.getElementById('disableBtn').classList.add('active');
        document.getElementById('enableBtn').classList.remove('active');
        document.getElementById('enabled').checked = false;
    }
}
let paletteIndex = 0;
const palettes = [
    {gradient: "linear-gradient(135deg, #3b82f6, #6366f1)", bg: "linear-gradient(135deg, #f0f4f8, #e2ebf0)"},
    {gradient: "linear-gradient(135deg, #ec4899, #f43f5e)", bg: "linear-gradient(135deg, #fce7f3, #fcdce3)"},
    {gradient: "linear-gradient(135deg, #10b981, #059669)", bg: "linear-gradient(135deg, #d1fae5, #a7f3d0)"},
    {gradient: "linear-gradient(135deg, #f97316, #ea580c)", bg: "linear-gradient(135deg, #ffe7d9, #ffedd5)"},
    {gradient: "linear-gradient(135deg, #8b5cf6, #6d28d9)", bg: "linear-gradient(135deg, #ede9fe, #e0e7ff)"}
];
function switchPalette() {
    paletteIndex = (paletteIndex + 1) % palettes.length;
    document.documentElement.style.setProperty('--main-gradient', palettes[paletteIndex].gradient);
    document.documentElement.style.setProperty('--background', palettes[paletteIndex].bg);
}
</script>
</body>
</html>
