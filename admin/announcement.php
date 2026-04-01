<?php
// This file can be rendered standalone or embedded inside the admin layout.
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_logged_in'])) {
  header('Location: login.php');
  exit;
}

require_once __DIR__ . '/includes/csrf.php';
csrf_token();

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
  if (!csrf_check_request()) {
    $errorMsg = "Invalid CSRF token.";
  }

    $message = trim($_POST['message'] ?? '');
    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'on';

  $announcement['message'] = $message;
  $announcement['enabled'] = $enabled;

  if (empty($errorMsg)) {
    if (file_put_contents($announcementFile, json_encode($announcement, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
      $successMsg = "Announcement updated successfully.";
    } else {
      $errorMsg = "Failed to save announcement.";
    }
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
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
            <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
            <link rel="stylesheet" href="style.css">
        </head>
        <body>
        <div style="max-width:760px;margin:32px auto;padding:0 16px;">
        <?php
    }
    ?>

    <div style="max-width:760px;margin:0 auto;">
      <div style="margin-bottom:24px;">
        <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;">
          <span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;">campaign</span>Manage Announcement
        </h2>
        <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">Toggle and update the student-facing announcement banner.</p>
      </div>

      <?php if ($successMsg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
      <?php elseif ($errorMsg): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
      <?php endif; ?>

      <div class="st-card">
        <form method="post">
          <?php csrf_field(); ?>
          <!-- Toggle -->
          <p style="font-weight:700;color:var(--on-surface);margin:0 0 12px;display:flex;align-items:center;gap:8px;">
            <span class="material-symbols-outlined" style="font-size:1.1rem;">toggle_on</span> Status
          </p>
          <div style="display:flex;gap:12px;margin-bottom:20px;">
            <button type="button" id="enableBtn" class="btn-announcement btn-enable <?= $announcement['enabled'] ? 'active' : '' ?>">
              <span class="material-symbols-outlined" style="font-size:1rem;">check_circle</span> Enable
            </button>
            <button type="button" id="disableBtn" class="btn-announcement btn-disable <?= !$announcement['enabled'] ? 'active' : '' ?>">
              <span class="material-symbols-outlined" style="font-size:1rem;">cancel</span> Disable
            </button>
          </div>
          <input type="checkbox" id="enabled" name="enabled" style="display:none;" <?= $announcement['enabled'] ? 'checked' : '' ?>>

          <!-- Message -->
          <p style="font-weight:700;color:var(--on-surface);margin:0 0 8px;display:flex;align-items:center;gap:8px;">
            <span class="material-symbols-outlined" style="font-size:1.1rem;">edit_note</span> Message
          </p>
          <textarea id="message" name="message" class="announcement-textarea" placeholder="Enter your announcement here..."><?= htmlspecialchars($announcement['message']) ?></textarea>

          <button type="submit" class="btn-save-announcement">
            <span class="material-symbols-outlined" style="font-size:1.1rem;">save</span> Save Announcement
          </button>
        </form>
      </div>
    </div>

    <?php
    if (!$embedded) {
        echo '</div></body></html>';
    }
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
