<?php
// This file can be rendered standalone or embedded inside the admin layout.
require_once __DIR__ . '/session_bootstrap.php';
admin_configure_session();
if (empty($_SESSION['admin_logged_in'])) {
  header('Location: login.php');
  exit;
}

require_once __DIR__ . '/includes/csrf.php';
csrf_token();
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/cache_helpers.php';

$announcementFile = admin_storage_migrate_file('announcement.json');
$announcementHistoryFile = admin_storage_migrate_file('announcement_history.json');
$announcementHistoryLimit = 25;
$successMsg = "";
$errorMsg = "";

$announcement = ['message' => '', 'enabled' => false, 'severity' => 'info', 'updated_at' => null];
$announcementHistory = [];

if (file_exists($announcementFile)) {
  $announcementData = admin_cached_json_file('announcement', $announcementFile, [], 15);
  if (is_array($announcementData)) {
    $announcement = array_merge($announcement, $announcementData);
    $announcement['severity'] = in_array(($announcement['severity'] ?? 'info'), ['info', 'warning', 'urgent'], true) ? (string)$announcement['severity'] : 'info';
  }
}

if (file_exists($announcementHistoryFile)) {
  $historyData = json_decode((string)@file_get_contents($announcementHistoryFile), true);
  if (is_array($historyData)) {
    $announcementHistory = $historyData;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check_request()) {
    $errorMsg = "Invalid CSRF token.";
  }

  $message = trim($_POST['message'] ?? '');
  $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'on';
  $severity = strtolower(trim((string)($_POST['severity'] ?? 'info')));
  if (!in_array($severity, ['info', 'warning', 'urgent'], true)) {
    $severity = 'info';
  }

  $previousSignature = json_encode([
    'message' => (string)($announcement['message'] ?? ''),
    'enabled' => !empty($announcement['enabled']),
    'severity' => (string)($announcement['severity'] ?? 'info')
  ], JSON_UNESCAPED_SLASHES);

  $announcement['message'] = $message;
  $announcement['enabled'] = $enabled;
  $announcement['severity'] = $severity;
  $announcement['updated_at'] = date('c');

  $newSignature = json_encode([
    'message' => $message,
    'enabled' => $enabled,
    'severity' => $severity
  ], JSON_UNESCAPED_SLASHES);

  if (empty($errorMsg)) {
    if (file_put_contents($announcementFile, json_encode($announcement, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)) {
      if ($newSignature !== $previousSignature) {
        array_unshift($announcementHistory, [
          'timestamp' => date('Y-m-d H:i:s'),
          'enabled' => $enabled,
          'severity' => $severity,
          'message' => $message
        ]);
        $announcementHistory = array_values(array_slice($announcementHistory, 0, $announcementHistoryLimit));
        @file_put_contents($announcementHistoryFile, json_encode($announcementHistory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
      }
      require_once __DIR__ . '/state_helpers.php';
      if (function_exists('admin_log_action')) {
          $status = $enabled ? 'Enabled' : 'Disabled';
          admin_log_action('Settings', 'Announcement Updated', "Announcement $status (Severity: $severity). Message: $message");
      }
      $successMsg = "Announcement updated successfully.";
    } else {
      $errorMsg = "Failed to save announcement.";
    }
  }
}

// detect if included by index.php (embedded) or accessed directly
$embedded = (basename($_SERVER['SCRIPT_NAME']) === 'index.php' || isset($page));

// render the inner form markup
function render_announcement_form($announcement, $announcementHistory, $announcementHistoryLimit, $successMsg, $errorMsg, $embedded)
{
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

            <p style="font-weight:700;color:var(--on-surface);margin:16px 0 8px;display:flex;align-items:center;gap:8px;">
              <span class="material-symbols-outlined" style="font-size:1.1rem;">priority_high</span> Severity
            </p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
              <?php
              $currentSeverity = in_array(($announcement['severity'] ?? 'info'), ['info', 'warning', 'urgent'], true) ? $announcement['severity'] : 'info';
              $severityOptions = [
                'info' => ['label' => 'Info', 'bg' => '#eef6ff', 'line' => '#cfe1f5', 'text' => '#1d4f80'],
                'warning' => ['label' => 'Warning', 'bg' => '#fff8e8', 'line' => '#f5dfad', 'text' => '#8a5a00'],
                'urgent' => ['label' => 'Urgent', 'bg' => '#ffeef0', 'line' => '#f5c2c8', 'text' => '#9f1d2c'],
              ];
              foreach ($severityOptions as $value => $meta):
              ?>
                <label style="display:inline-flex;align-items:center;gap:6px;padding:8px 10px;border-radius:10px;border:1px solid <?= $meta['line'] ?>;background:<?= $meta['bg'] ?>;color:<?= $meta['text'] ?>;font-weight:700;font-size:0.86rem;cursor:pointer;">
                  <input type="radio" name="severity" value="<?= $value ?>" <?= $currentSeverity === $value ? 'checked' : '' ?> style="margin:0;">
                  <?= $meta['label'] ?>
                </label>
              <?php endforeach; ?>
            </div>

            <button type="submit" class="btn-save-announcement">
              <span class="material-symbols-outlined" style="font-size:1.1rem;">save</span> Save Announcement
            </button>
          </form>
        </div>

        <div class="st-card" style="margin-top:18px;">
          <h3 style="margin:0 0 10px;font-size:1rem;font-weight:800;color:var(--on-surface);display:flex;align-items:center;gap:8px;">
            <span class="material-symbols-outlined" style="font-size:1.05rem;">history</span>
            Announcement History (Last <?= (int)$announcementHistoryLimit ?>)
          </h3>
          <?php if (!empty($announcementHistory)): ?>
            <div style="overflow-x:auto;">
              <table class="st-table" style="width:100%;font-size:0.86rem;">
                <thead>
                  <tr>
                    <th style="text-align:left;">Time</th>
                    <th style="text-align:left;">Status</th>
                    <th style="text-align:left;">Severity</th>
                    <th style="text-align:left;">Message</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($announcementHistory as $row): ?>
                    <?php
                    $rowSeverity = in_array(($row['severity'] ?? 'info'), ['info', 'warning', 'urgent'], true) ? $row['severity'] : 'info';
                    $rowEnabled = !empty($row['enabled']);
                    ?>
                    <tr>
                      <td><?= htmlspecialchars((string)($row['timestamp'] ?? '-')) ?></td>
                      <td><?= $rowEnabled ? 'Enabled' : 'Disabled' ?></td>
                      <td style="text-transform:capitalize;"><?= htmlspecialchars($rowSeverity) ?></td>
                      <td style="max-width:340px;white-space:pre-wrap;"><?= htmlspecialchars((string)($row['message'] ?? '')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <p style="margin:0;color:var(--on-surface-variant);font-size:0.88rem;">No announcement history yet.</p>
          <?php endif; ?>
        </div>
      </div>

      <?php
      if (!$embedded) {
        echo '</div></body></html>';
      }
      ?>
      <script>
        (function() {
          var eBtn = document.getElementById('enableBtn');
          var dBtn = document.getElementById('disableBtn');

          function setActiveState(enabled) {
            if (!eBtn || !dBtn) return;
            if (enabled) {
              eBtn.classList.add('active');
              dBtn.classList.remove('active');
              document.getElementById('enabled').checked = true;
            } else {
              dBtn.classList.add('active');
              eBtn.classList.remove('active');
              document.getElementById('enabled').checked = false;
            }
          }
          if (eBtn) eBtn.addEventListener('click', function() {
            setActiveState(true);
          });
          if (dBtn) dBtn.addEventListener('click', function() {
            setActiveState(false);
          });
        })();
      </script>
      <?php if ($successMsg !== '' || $errorMsg !== ''): ?>
        <script>
          window.adminAlert(
            <?= json_encode($successMsg !== '' ? 'Success' : 'Error') ?>,
            <?= json_encode($successMsg !== '' ? $successMsg : $errorMsg) ?>,
            <?= json_encode($successMsg !== '' ? 'success' : 'error') ?>
          );
        </script>
      <?php endif; ?>
    <?php
    return ob_get_clean();
  }

  echo render_announcement_form($announcement, $announcementHistory, $announcementHistoryLimit, $successMsg, $errorMsg, $embedded);
