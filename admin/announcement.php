<?php
// This file can be rendered standalone or embedded inside the admin layout.
if (session_status() === PHP_SESSION_NONE) session_start();
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
    $message = trim($_POST['message'] ?? '');
    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'on';

    $announcement['message'] = $message;
    $announcement['enabled'] = $enabled;

    if (file_put_contents($announcementFile, json_encode($announcement, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
        $successMsg = "Announcement updated successfully.";
    } else {
        $errorMsg = "Failed to save announcement.";
    }
}

// detect if included by index.php (embedded) or accessed directly
$embedded = (basename($_SERVER['SCRIPT_NAME']) === 'index.php' || isset($page));

// render the inner form markup
function render_announcement_form($announcement, $successMsg, $errorMsg, $embedded){
    ob_start();
    if (!$embedded) {
        ?>
        <!doctype html>
        <html>
        <head>
            <title>Manage Announcement</title>
            <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
            <style>
                /* minimal standalone styles - embedded pages use admin/style.css */
                body{font-family: 'Segoe UI',sans-serif;background:#f4f7fb;margin:0;padding:24px}
                .container{max-width:760px;margin:0 auto;background:#fff;padding:28px;border-radius:12px;box-shadow:0 10px 30px rgba(2,6,23,0.06)}
            </style>
        </head>
        <body>
        <div class="container">
        <?php
    } else {
        echo '<div class="card">';
        echo '<div class="card-header"><h3>Manage Announcement</h3></div><div class="card-body">';
    }

    if ($successMsg){
        echo '<div class="msg success">'.htmlspecialchars($successMsg).'</div>';
    } elseif ($errorMsg){
        echo '<div class="msg error">'.htmlspecialchars($errorMsg).'</div>';
    }

    ?>
    <form method="post">
        <label for="message"><i class='bx bx-message-square-detail'></i> Announcement Message</label>
        <textarea id="message" name="message" class="announcement-textarea" placeholder="Enter your announcement here..."><?= htmlspecialchars($announcement['message']) ?></textarea>

        <div class="toggle-buttons" style="margin:18px 0;display:flex;gap:12px;">
            <button type="button" id="enableBtn" class="btn-announcement btn-enable <?= $announcement['enabled'] ? 'active' : '' ?>">
                <i class='bx bx-check-circle'></i> Enable
            </button>
            <button type="button" id="disableBtn" class="btn-announcement btn-disable <?= !$announcement['enabled'] ? 'active' : '' ?>">
                <i class='bx bx-x-circle'></i> Disable
            </button>
        </div>

        <input type="checkbox" id="enabled" name="enabled" style="display:none;" <?= $announcement['enabled'] ? 'checked' : '' ?>>

    <button type="submit" class="btn btn-primary btn-save-announcement"><i class='bx bx-save'></i> Save Announcement</button>
    </form>

    <?php
    if (!$embedded) {
        echo '</div></div></body></html>';
    } else {
        echo '</div></div>'; // close card-body and card
    }

    // small script
    ?>
    <script>
    (function(){
        var eBtn = document.getElementById('enableBtn');
        var dBtn = document.getElementById('disableBtn');
        function setActiveState(enabled){
            if (!eBtn||!dBtn) return;
            if (enabled){ eBtn.classList.add('active'); dBtn.classList.remove('active'); document.getElementById('enabled').checked = true; }
            else { dBtn.classList.add('active'); eBtn.classList.remove('active'); document.getElementById('enabled').checked = false; }
        }
        if (eBtn) eBtn.addEventListener('click', function(){ setActiveState(true); });
        if (dBtn) dBtn.addEventListener('click', function(){ setActiveState(false); });
    })();
    </script>
    <?php
    return ob_get_clean();
}

echo render_announcement_form($announcement, $successMsg, $errorMsg, $embedded);

