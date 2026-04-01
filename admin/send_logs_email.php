<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_logged_in'])) {
  header('Location: login.php');
  exit;
}
require_once __DIR__ . '/includes/csrf.php';
csrf_token();

require_once __DIR__ . '/../storage_helpers.php';
app_storage_init();

// send_logs_email.php - redesigned to show selectable log files grouped by date+course

$logsDir = app_storage_file('logs');
$exportDir = app_storage_file('backups');
if (!is_dir($exportDir)) @mkdir($exportDir, 0755, true);

// Load .env helper
function load_env_vars($path)
{
  $env = [];
  if (file_exists($path)) {
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $l) {
      $t = trim($l);
      if ($t === '' || strpos($t, '#') === 0 || strpos($t, '=') === false) continue;
      list($k, $v) = explode('=', $t, 2);
      $env[trim($k)] = trim(trim($v), "\"'");
    }
  }
  return $env;
}
$ENV = load_env_vars(__DIR__ . '/../.env');

// Get default recipient from settings
$defaultRecipient = '';
try {
  $adminSettings = file_exists(__DIR__ . '/settings.json') ? (json_decode(file_get_contents(__DIR__ . '/settings.json'), true) ?: []) : [];
  $defaultRecipient = $adminSettings['auto_send']['recipient'] ?? ($ENV['AUTO_SEND_RECIPIENT'] ?? '');
} catch (\Throwable $e) { /* ignore */
}

// Build groups: parse all log files and group entries by date+course
$groups = [];
if (is_dir($logsDir)) {
  $it = new DirectoryIterator($logsDir);
  foreach ($it as $f) {
    if ($f->isFile()) {
      $fn = $f->getFilename();
      if (preg_match('/\.(php|css)$/i', $fn)) continue; // skip helpers
      $lines = @file($logsDir . '/' . $fn, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
      foreach ($lines as $ln) {
        $parts = array_map('trim', explode('|', $ln));
        $parts = array_pad($parts, 10, '');
        // extract date and course
        $dt = $parts[6] ?? '';
        $dateOnly = null;
        if ($dt && preg_match('/(20\d{2}-\d{2}-\d{2})/', $dt, $md)) $dateOnly = $md[1];
        if (!$dateOnly && preg_match('/(20\d{2}-\d{2}-\d{2})/', $fn, $mf)) $dateOnly = $mf[1];
        if (!$dateOnly) $dateOnly = date('Y-m-d');
        $course = ($parts[8] ?? '') !== '' ? $parts[8] : 'Unknown';
        $key = $dateOnly . '|' . $course;
        if (!isset($groups[$key])) $groups[$key] = ['date' => $dateOnly, 'course' => $course, 'entries' => 0, 'failed' => 0, 'files' => []];
        $groups[$key]['entries']++;
        $txt = strtolower($ln);
        if (strpos($txt, 'failed') !== false || strpos($txt, 'invalid') !== false) $groups[$key]['failed']++;
        if (!in_array($fn, $groups[$key]['files'])) $groups[$key]['files'][] = $fn;
      }
    }
  }
}
uasort($groups, function ($a, $b) {
  return strcmp($b['date'] . $b['course'], $a['date'] . $a['course']);
});

$success = '';
$error = '';
$exportPath = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_logs'])) {
  if (!csrf_check_request()) {
    $error = 'Invalid CSRF token.';
  }

  // Get selected groups
  $selectedKeys = isset($_POST['selected_groups']) && is_array($_POST['selected_groups']) ? $_POST['selected_groups'] : [];
  $recipient = trim($_POST['recipient'] ?? '');
  $format = $_POST['format'] ?? 'csv';
  $cols = isset($_POST['cols']) && is_array($_POST['cols']) ? $_POST['cols'] : ['name', 'matric', 'action', 'datetime', 'course'];

  if (empty($error) && empty($selectedKeys)) {
    $error = 'Please select at least one log group to send.';
  } elseif (empty($error) && !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    $error = 'Please enter a valid recipient email address.';
  } else {
    // collect all rows from selected groups
    $allRows = [];
    foreach ($selectedKeys as $gkey) {
      if (!isset($groups[$gkey])) continue;
      $group = $groups[$gkey];
      // read all files in this group
      foreach ($group['files'] as $fn) {
        $path = $logsDir . '/' . $fn;
        if (!is_readable($path)) continue;
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
          $parts = array_map('trim', explode('|', $line));
          $parts = array_pad($parts, 10, '');
          $row = [
            'name' => $parts[0],
            'matric' => $parts[1],
            'action' => $parts[2],
            'token' => $parts[3],
            'ip' => $parts[4],
            'status' => $parts[5],
            'datetime' => $parts[6],
            'user_agent' => $parts[7],
            'course' => $parts[8],
            'reason' => $parts[9]
          ];
          // check if row belongs to this group (date+course match)
          $dt = $row['datetime'] ?? '';
          $rowDate = null;
          if ($dt && preg_match('/(20\d{2}-\d{2}-\d{2})/', $dt, $md)) $rowDate = $md[1];
          $rowCourse = ($row['course'] ?? '') !== '' ? $row['course'] : 'Unknown';
          $rowKey = $rowDate . '|' . $rowCourse;
          if ($rowKey === $gkey) $allRows[] = $row;
        }
      }
    }

    if (empty($allRows)) {
      $error = 'No log entries found for the selected groups.';
    } else {
      // create CSV/PDF
      $safeCourse = preg_replace('/[^a-zA-Z0-9_-]/', '_', $groups[$selectedKeys[0]]['course'] ?? 'logs');
      $dateForName = $groups[$selectedKeys[0]]['date'] ?? date('Y-m-d');
      $multiTag = count($selectedKeys) > 1 ? '_multi' : '';
      $baseName = "attendance_{$safeCourse}_{$dateForName}{$multiTag}";
      $csvPath = $exportDir . '/' . $baseName . '.csv';

      $fh = fopen($csvPath, 'w');
      if (!$fh) {
        $error = 'Server cannot create export file.';
      } else {
        $headerMap = ['name' => 'Name', 'matric' => 'Matric', 'action' => 'Action', 'token' => 'Token', 'ip' => 'IP', 'status' => 'Status', 'datetime' => 'Datetime', 'user_agent' => 'UserAgent', 'course' => 'Course', 'reason' => 'Reason'];
        $header = [];
        foreach ($cols as $c) if (isset($headerMap[$c])) $header[] = $headerMap[$c];
        fputcsv($fh, $header);
        foreach ($allRows as $r) {
          $out = [];
          foreach ($cols as $c) $out[] = $r[$c] ?? '';
          fputcsv($fh, $out);
        }
        fclose($fh);
        $exportPath = $csvPath;

        // PDF generation if requested
        if ($format === 'pdf') {
          if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
            if (class_exists('Dompdf\\Dompdf')) {
              try {
                $dompdf = new \Dompdf\Dompdf();
                $html = '<html><head><meta charset="utf-8"><style>table{border-collapse:collapse;width:100%;}td,th{border:1px solid #ddd;padding:4px;font-size:10px;}th{background:#f3f4f6;text-align:left;}</style></head><body>';
                $html .= '<h2>Attendance Export</h2><p>Groups: ' . count($selectedKeys) . ' | Entries: ' . count($allRows) . '</p>';
                $html .= '<table><thead><tr>';
                foreach ($header as $h) $html .= '<th>' . htmlspecialchars($h) . '</th>';
                $html .= '</tr></thead><tbody>';
                foreach ($allRows as $r) {
                  $html .= '<tr>';
                  foreach ($cols as $c) $html .= '<td>' . htmlspecialchars(mb_substr($r[$c] ?? '', 0, 500)) . '</td>';
                  $html .= '</tr>';
                }
                $html .= '</tbody></table></body></html>';
                $dompdf->setPaper('A4', 'landscape');
                $dompdf->loadHtml($html);
                $dompdf->render();
                $pdfPath = $exportDir . '/' . $baseName . '.pdf';
                file_put_contents($pdfPath, $dompdf->output());
                $exportPath = $pdfPath;
              } catch (\Exception $e) {
                $error = 'PDF generation failed: ' . $e->getMessage();
              }
            }
          }
        }

        // Send email via PHPMailer using .env for SMTP
        $sent = false;
        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
          require_once __DIR__ . '/../vendor/autoload.php';
          if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            try {
              $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

              // SMTP config from .env only
              $smtpHost = $ENV['SMTP_HOST'] ?? '';
              if ($smtpHost) {
                $mail->isSMTP();
                $mail->Host = $smtpHost;
                $mail->Port = intval($ENV['SMTP_PORT'] ?? 587);
                $secure = $ENV['SMTP_SECURE'] ?? '';
                if ($secure) $mail->SMTPSecure = $secure;
                $mail->SMTPAuth = true;
                $mail->Username = $ENV['SMTP_USER'] ?? '';
                $mail->Password = $ENV['SMTP_PASS'] ?? '';
              }

              // From address from .env, from name from settings
              $settings = [];
              if (file_exists(__DIR__ . '/settings.json')) {
                $settings = json_decode(file_get_contents(__DIR__ . '/settings.json'), true) ?: [];
              }
              $fromEmail = $ENV['FROM_EMAIL'] ?? 'no-reply@example.com';
              $fromName = $settings['smtp']['from_name'] ?? ($ENV['FROM_NAME'] ?? 'Attendance System');

              $mail->setFrom($fromEmail, $fromName);
              $mail->addAddress($recipient);
              $mail->Subject = 'Attendance Export ' . $dateForName . (count($selectedKeys) > 1 ? ' (multiple groups)' : '');
              $mail->Body = 'Attached attendance export generated from ' . count($selectedKeys) . ' group(s) with ' . count($allRows) . ' entries.';

              if (file_exists($exportPath)) {
                $mail->addAttachment($exportPath, basename($exportPath));
              }

              $mail->send();
              $sent = true;
              $success = 'Email sent successfully with export attachment!';
            } catch (\Exception $e) {
              $error = 'Email sending failed: ' . $e->getMessage();
            }
          }
        }

        if (!$sent && !$error) {
          $success = 'Export created: ' . basename($exportPath) . '. Email sending not available (check .env SMTP settings or install PHPMailer).';
        }
      }
    }
  }
}
?>

<div style="max-width:1200px;margin:0 auto;">
  <div style="margin-bottom:24px;">
    <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;">
      <span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;">forward_to_inbox</span>Export & Email Logs
    </h2>
    <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">Select log groups by date and course, choose columns, and send as CSV or PDF report.</p>
  </div>

  <?php if ($error): ?>
    <div style="background:var(--error-container);color:var(--error);padding:12px 16px;border-radius:12px;font-weight:600;font-size:0.9rem;margin-bottom:24px;display:flex;align-items:center;gap:8px;">
      <span class="material-symbols-outlined">error</span>
      <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div style="background:var(--success-container);color:var(--success);padding:12px 16px;border-radius:12px;font-weight:600;font-size:0.9rem;margin-bottom:24px;display:flex;align-items:center;gap:8px;">
      <span class="material-symbols-outlined">check_circle</span>
      <?= htmlspecialchars($success) ?>
    </div>
    <?php if ($exportPath && file_exists($exportPath)): ?>
      <div style="margin-bottom:24px;">
        <a href="download_backup.php?file=<?php echo urlencode(basename($exportPath)); ?>" download class="st-btn st-btn-primary" style="text-decoration:none;display:inline-flex;">
          <span class="material-symbols-outlined">download</span> Download Export
        </a>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="st-card" style="padding:24px;">
    <form method="post">
      <?php csrf_field(); ?>

      <!-- Delivery Settings -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;margin-bottom:24px;">
        <div>
          <label style="display:block;font-weight:600;color:var(--on-surface-variant);margin-bottom:8px;font-size:0.9rem;">Recipient Email</label>
          <input type="email" name="recipient" required class="st-input" value="<?= htmlspecialchars($defaultRecipient) ?>" placeholder="admin@example.com" />
        </div>
        <div>
          <label style="display:block;font-weight:600;color:var(--on-surface-variant);margin-bottom:8px;font-size:0.9rem;">Export Format</label>
          <div style="position:relative;">
            <select name="format" class="st-input" style="appearance:none;padding-right:36px;cursor:pointer;">
              <option value="csv">CSV (Spreadsheet)</option>
              <option value="pdf">PDF (Report)</option>
            </select>
            <span class="material-symbols-outlined" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--on-surface-variant);pointer-events:none;">expand_more</span>
          </div>
        </div>
      </div>

      <!-- Columns Selection -->
      <div style="margin-bottom:32px;">
        <label style="display:block;font-weight:600;color:var(--on-surface);margin-bottom:12px;font-size:0.95rem;padding-bottom:8px;border-bottom:1px solid var(--outline-variant);">Data Columns to Include</label>
        <div style="display:flex;gap:16px;flex-wrap:wrap;">
          <label style="display:flex;align-items:center;gap:6px;font-size:0.85rem;color:var(--on-surface);cursor:pointer;background:var(--surface-container-low);padding:6px 12px;border-radius:20px;border:1px solid var(--outline-variant);"><input type="checkbox" name="cols[]" value="name" checked> Name</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:0.85rem;color:var(--on-surface);cursor:pointer;background:var(--surface-container-low);padding:6px 12px;border-radius:20px;border:1px solid var(--outline-variant);"><input type="checkbox" name="cols[]" value="matric" checked> Matric</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:0.85rem;color:var(--on-surface);cursor:pointer;background:var(--surface-container-low);padding:6px 12px;border-radius:20px;border:1px solid var(--outline-variant);"><input type="checkbox" name="cols[]" value="action" checked> Action</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:0.85rem;color:var(--on-surface);cursor:pointer;background:var(--surface-container-low);padding:6px 12px;border-radius:20px;border:1px solid var(--outline-variant);"><input type="checkbox" name="cols[]" value="datetime" checked> Datetime</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:0.85rem;color:var(--on-surface);cursor:pointer;background:var(--surface-container-low);padding:6px 12px;border-radius:20px;border:1px solid var(--outline-variant);"><input type="checkbox" name="cols[]" value="course" checked> Course</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:0.85rem;color:var(--on-surface);cursor:pointer;background:var(--surface-container-low);padding:6px 12px;border-radius:20px;border:1px solid var(--outline-variant);"><input type="checkbox" name="cols[]" value="status"> Status</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:0.85rem;color:var(--on-surface);cursor:pointer;background:var(--surface-container-low);padding:6px 12px;border-radius:20px;border:1px solid var(--outline-variant);"><input type="checkbox" name="cols[]" value="reason"> Reason</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:0.85rem;color:var(--on-surface);cursor:pointer;background:var(--surface-container-low);padding:6px 12px;border-radius:20px;border:1px solid var(--outline-variant);"><input type="checkbox" name="cols[]" value="ip"> IP Address</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:0.85rem;color:var(--on-surface);cursor:pointer;background:var(--surface-container-low);padding:6px 12px;border-radius:20px;border:1px solid var(--outline-variant);"><input type="checkbox" name="cols[]" value="token"> Token Hash</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:0.85rem;color:var(--on-surface);cursor:pointer;background:var(--surface-container-low);padding:6px 12px;border-radius:20px;border:1px solid var(--outline-variant);"><input type="checkbox" name="cols[]" value="user_agent"> User Agent</label>
        </div>
      </div>

      <!-- Log Groups Table -->
      <div style="margin-bottom:24px;">
        <label style="display:block;font-weight:600;color:var(--on-surface);margin-bottom:4px;font-size:0.95rem;">Available Log Groups</label>
        <p style="color:var(--on-surface-variant);font-size:0.85rem;margin:0 0 12px;">Select one or multiple groups (date + course combination) to export.</p>

        <div style="border:1px solid var(--outline-variant);border-radius:12px;overflow:hidden;">
          <table class="st-table" style="width:100%;min-width:600px;margin:0;">
            <thead>
              <tr>
                <th style="width:40px;text-align:center;"><input type="checkbox" id="select-all" onclick="document.querySelectorAll('input[name=\'selected_groups[]\']').forEach(c=>c.checked=this.checked)"></th>
                <th>Date</th>
                <th>Course</th>
                <th style="text-align:right;">Entries</th>
                <th style="text-align:right;">Failed</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($groups)): ?>
                <tr>
                  <td colspan="5" style="text-align:center;padding:32px;color:var(--on-surface-variant);">No log groups found</td>
                </tr>
              <?php else: ?>
                <?php foreach ($groups as $gkey => $g): ?>
                  <tr>
                    <td style="text-align:center;">
                      <input type="checkbox" name="selected_groups[]" value="<?= htmlspecialchars($gkey) ?>">
                    </td>
                    <td style="font-weight:600;"><?= htmlspecialchars($g['date']) ?></td>
                    <td><span class="st-chip st-chip-primary"><?= htmlspecialchars($g['course']) ?></span></td>
                    <td style="text-align:right;;font-family:monospace;font-size:0.9rem;"><?= (int)$g['entries'] ?></td>
                    <td style="text-align:right;;font-family:monospace;font-size:0.9rem;">
                      <?php if ($g['failed'] > 0): ?>
                        <span style="color:var(--error);font-weight:600;"><?= (int)$g['failed'] ?></span>
                      <?php else: ?>
                        <span style="color:var(--on-surface-variant);"><?= (int)$g['failed'] ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div style="display:flex;align-items:center;gap:16px;">
        <button type="submit" name="send_logs" class="st-btn st-btn-primary" style="padding:12px 24px;">
          <span class="material-symbols-outlined">send</span> Create & Send Export
        </button>
        <span style="font-size:0.8rem;color:var(--on-surface-variant);"><span class="material-symbols-outlined" style="font-size:1rem;vertical-align:middle;">info</span> Ensure SMTP is configured in <code>.env</code></span>
      </div>

    </form>
  </div>
</div>
