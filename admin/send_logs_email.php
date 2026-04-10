<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_logged_in'])) {
  header('Location: login.php');
  exit;
}
require_once __DIR__ . '/includes/csrf.php';
csrf_token();

require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/../env_helpers.php';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/cache_helpers.php';
require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/log_helpers.php';
app_storage_init();

// send_logs_email.php - redesigned to show selectable log files grouped by date+course

$logsDir = app_storage_file('logs');
$exportDir = app_storage_file('backups');
if (!is_dir($exportDir)) @mkdir($exportDir, 0755, true);

$ENV = app_load_env_layers(__DIR__ . '/../.env');

// Get default recipient from settings
$defaultRecipient = '';
try {
  $adminSettings = admin_load_settings_cached(15);
  $defaultRecipient = $adminSettings['auto_send']['recipient'] ?? ($ENV['AUTO_SEND_RECIPIENT'] ?? '');
} catch (\Throwable $e) { /* ignore */
}

$groups = admin_log_groups_summary(20);

$success = '';
$error = '';
$exportPath = '';

if (!function_exists('admin_load_settings_for_write')) {
  function admin_load_settings_for_write($settingsFile, $keyFile)
  {
    if (!file_exists($settingsFile)) {
      return [];
    }
    $raw = (string)@file_get_contents($settingsFile);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
      return $decoded;
    }
    if (strpos($raw, 'ENC:') === 0 && file_exists($keyFile)) {
      $key = trim((string)@file_get_contents($keyFile));
      if ($key !== '') {
        $blob = base64_decode(substr($raw, 4));
        $iv = substr((string)$blob, 0, 16);
        $ct = substr((string)$blob, 16);
        $plain = openssl_decrypt($ct, 'AES-256-CBC', base64_decode($key), OPENSSL_RAW_DATA, $iv);
        $decoded = json_decode((string)$plain, true);
        if (is_array($decoded)) {
          return $decoded;
        }
      }
    }
    return [];
  }
}

if (!function_exists('admin_save_settings_for_write')) {
  function admin_save_settings_for_write($settingsFile, $keyFile, array $settings)
  {
    $payload = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $encrypt = !empty($settings['encrypted_settings']);
    if ($encrypt) {
      $key = file_exists($keyFile) ? trim((string)@file_get_contents($keyFile)) : '';
      if ($key === '') {
        $key = base64_encode(random_bytes(32));
        @file_put_contents($keyFile, $key, LOCK_EX);
      }
      $iv = random_bytes(16);
      $ct = openssl_encrypt($payload, 'AES-256-CBC', base64_decode($key), OPENSSL_RAW_DATA, $iv);
      $payload = 'ENC:' . base64_encode($iv . $ct);
    }
    return @file_put_contents($settingsFile, $payload, LOCK_EX) !== false;
  }
}

if (!function_exists('admin_build_export_email_bodies')) {
  function admin_build_export_email_bodies($fromName, $dateForName, array $groupMeta, $entryCount, $format, $attachmentName)
  {
    $safeFrom = htmlspecialchars((string)$fromName, ENT_QUOTES, 'UTF-8');
    $safeDate = htmlspecialchars((string)$dateForName, ENT_QUOTES, 'UTF-8');
    $safeFormat = strtoupper(htmlspecialchars((string)$format, ENT_QUOTES, 'UTF-8'));
    $safeAttachment = htmlspecialchars((string)$attachmentName, ENT_QUOTES, 'UTF-8');
    $safeEntryCount = (int)$entryCount;
    $safeGroupCount = count($groupMeta);
    $generatedAt = date('Y-m-d H:i:s');

    $rowsHtml = '';
    foreach ($groupMeta as $g) {
      $rowsHtml .= '<tr>'
        . '<td style="padding:10px 12px;border-bottom:1px solid #e6ebff;font-size:13px;color:#24304a;">' . htmlspecialchars((string)($g['date'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td style="padding:10px 12px;border-bottom:1px solid #e6ebff;font-size:13px;color:#24304a;">' . htmlspecialchars((string)($g['course'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td style="padding:10px 12px;border-bottom:1px solid #e6ebff;font-size:13px;color:#24304a;text-align:right;">' . (int)($g['entries'] ?? 0) . '</td>'
        . '<td style="padding:10px 12px;border-bottom:1px solid #e6ebff;font-size:13px;color:#24304a;text-align:right;">' . (int)($g['failed'] ?? 0) . '</td>'
        . '</tr>';
    }
    if ($rowsHtml === '') {
      $rowsHtml = '<tr><td colspan="4" style="padding:12px;color:#52607d;font-size:13px;">No group details available.</td></tr>';
    }

    $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head><body style="margin:0;padding:0;background:#f3f6ff;font-family:Segoe UI,Arial,sans-serif;color:#111827;">'
      . '<div style="max-width:720px;margin:24px auto;padding:0 12px;">'
      . '<div style="background:linear-gradient(135deg,#1f5d99,#00457b);color:#ffffff;border-radius:16px 16px 0 0;padding:24px 22px;">'
      . '<div style="font-size:12px;letter-spacing:.08em;opacity:.9;text-transform:uppercase;">Blockchain Attendance System</div>'
      . '<h1 style="margin:8px 0 4px 0;font-size:24px;line-height:1.2;">Attendance Export Ready</h1>'
      . '<p style="margin:0;font-size:14px;opacity:.95;">Your automated log export has been generated and attached.</p>'
      . '</div>'
      . '<div style="background:#ffffff;border:1px solid #d9e1ff;border-top:0;border-radius:0 0 16px 16px;padding:22px;">'
      . '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">'
      . '<div style="background:#eef3ff;border:1px solid #d7e2ff;border-radius:12px;padding:10px 12px;min-width:140px;">'
      . '<div style="font-size:11px;color:#5b6b8c;text-transform:uppercase;letter-spacing:.06em;">Date</div>'
      . '<div style="font-size:14px;font-weight:700;color:#1f2a44;">' . $safeDate . '</div></div>'
      . '<div style="background:#eef3ff;border:1px solid #d7e2ff;border-radius:12px;padding:10px 12px;min-width:140px;">'
      . '<div style="font-size:11px;color:#5b6b8c;text-transform:uppercase;letter-spacing:.06em;">Groups</div>'
      . '<div style="font-size:14px;font-weight:700;color:#1f2a44;">' . $safeGroupCount . '</div></div>'
      . '<div style="background:#eef3ff;border:1px solid #d7e2ff;border-radius:12px;padding:10px 12px;min-width:140px;">'
      . '<div style="font-size:11px;color:#5b6b8c;text-transform:uppercase;letter-spacing:.06em;">Entries</div>'
      . '<div style="font-size:14px;font-weight:700;color:#1f2a44;">' . $safeEntryCount . '</div></div>'
      . '<div style="background:#eef3ff;border:1px solid #d7e2ff;border-radius:12px;padding:10px 12px;min-width:140px;">'
      . '<div style="font-size:11px;color:#5b6b8c;text-transform:uppercase;letter-spacing:.06em;">Format</div>'
      . '<div style="font-size:14px;font-weight:700;color:#1f2a44;">' . $safeFormat . '</div></div>'
      . '</div>'
      . '<p style="margin:0 0 14px 0;font-size:14px;line-height:1.55;color:#2e3a56;">Hello,</p>'
      . '<p style="margin:0 0 16px 0;font-size:14px;line-height:1.55;color:#2e3a56;">The latest attendance export has been generated by <strong>' . $safeFrom . '</strong>. The file <strong>' . $safeAttachment . '</strong> is attached to this email.</p>'
      . '<div style="border:1px solid #e6ebff;border-radius:12px;overflow:hidden;margin:14px 0 18px;">'
      . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
      . '<thead><tr style="background:#f7f9ff;">'
      . '<th style="text-align:left;padding:10px 12px;font-size:12px;color:#4b5a7a;border-bottom:1px solid #e6ebff;">Date</th>'
      . '<th style="text-align:left;padding:10px 12px;font-size:12px;color:#4b5a7a;border-bottom:1px solid #e6ebff;">Course</th>'
      . '<th style="text-align:right;padding:10px 12px;font-size:12px;color:#4b5a7a;border-bottom:1px solid #e6ebff;">Entries</th>'
      . '<th style="text-align:right;padding:10px 12px;font-size:12px;color:#4b5a7a;border-bottom:1px solid #e6ebff;">Failed</th>'
      . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table></div>'
      . '<p style="margin:0;font-size:12px;color:#64748b;">Generated at: ' . htmlspecialchars($generatedAt, ENT_QUOTES, 'UTF-8') . '</p>'
      . '</div>'
      . '<div style="text-align:center;padding:12px 10px;color:#7a859f;font-size:11px;">This is an automated message from Blockchain Attendance System.</div>'
      . '</div></body></html>';

    $text = "Attendance Export Ready\n"
      . "Date: " . $dateForName . "\n"
      . "Groups: " . $safeGroupCount . "\n"
      . "Entries: " . $safeEntryCount . "\n"
      . "Format: " . strtoupper((string)$format) . "\n"
      . "Attachment: " . $attachmentName . "\n\n"
      . "Group breakdown:\n";
    foreach ($groupMeta as $g) {
      $text .= "- " . ($g['date'] ?? '-') . " | " . ($g['course'] ?? 'Unknown') . " | entries=" . (int)($g['entries'] ?? 0) . " | failed=" . (int)($g['failed'] ?? 0) . "\n";
    }
    $text .= "\nGenerated at: " . $generatedAt . "\n";

    return ['html' => $html, 'text' => $text];
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_delivery_defaults'])) {
  if (!csrf_check_request()) {
    $error = 'Invalid CSRF token.';
  } else {
    $recipientInput = trim((string)($_POST['recipient'] ?? ''));
    $formatInput = strtolower(trim((string)($_POST['format'] ?? 'csv')));
    if ($recipientInput !== '' && !filter_var($recipientInput, FILTER_VALIDATE_EMAIL)) {
      $error = 'Please enter a valid recipient email address before saving defaults.';
    }
    if (!in_array($formatInput, ['csv', 'pdf'], true)) {
      $formatInput = 'csv';
    }
    if ($error === '') {
      $settingsFile = admin_settings_file();
      $keyFile = admin_settings_key_file();
      $settings = admin_load_settings_for_write($settingsFile, $keyFile);
      if (!is_array($settings)) {
        $settings = [];
      }
      if (!isset($settings['auto_send']) || !is_array($settings['auto_send'])) {
        $settings['auto_send'] = [];
      }
      $settings['auto_send']['recipient'] = $recipientInput;
      $settings['auto_send']['format'] = $formatInput;

      if (admin_save_settings_for_write($settingsFile, $keyFile, $settings)) {
        $success = 'Auto-send defaults saved. Recipient and format will prefill next time.';
        $defaultRecipient = $recipientInput;
      } else {
        $error = 'Unable to save defaults. Check file write permissions for admin settings storage.';
      }
    }
  }
}

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
        foreach (admin_cached_file_lines('send_logs_group_lines', $path, 15) as $line) {
          $parts = array_pad(array_map('trim', explode('|', $line)), 10, '');
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
        $selectedGroupMeta = [];
        foreach ($selectedKeys as $gkey) {
          if (!isset($groups[$gkey])) continue;
          $selectedGroupMeta[] = [
            'date' => $groups[$gkey]['date'] ?? $dateForName,
            'course' => $groups[$gkey]['course'] ?? 'Unknown',
            'entries' => (int)($groups[$gkey]['entries'] ?? 0),
            'failed' => (int)($groups[$gkey]['failed'] ?? 0),
          ];
        }
        $smtpHost = trim((string)($ENV['SMTP_HOST'] ?? ''));
        $smtpUser = trim((string)($ENV['SMTP_USER'] ?? ''));
        $smtpPass = trim((string)($ENV['SMTP_PASS'] ?? ''));
        $smtpConfigured = ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '');

        if ($smtpConfigured && file_exists(__DIR__ . '/../vendor/autoload.php')) {
          require_once __DIR__ . '/../vendor/autoload.php';
          if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            try {
              $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

              // SMTP config from .env only
              $mail->isSMTP();
              $mail->Host = $smtpHost;
              $mail->Port = intval($ENV['SMTP_PORT'] ?? 587);
              $secure = $ENV['SMTP_SECURE'] ?? '';
              if ($secure) $mail->SMTPSecure = $secure;
              $mail->SMTPAuth = true;
              $mail->Username = $smtpUser;
              $mail->Password = $smtpPass;

              // From address from .env, from name from settings
              $settings = [];
              $settings = admin_load_settings_cached(15);
              $fromEmail = $ENV['FROM_EMAIL'] ?? 'no-reply@example.com';
              $fromName = $settings['smtp']['from_name'] ?? ($ENV['FROM_NAME'] ?? 'Attendance System');

              $mail->setFrom($fromEmail, $fromName);
              $mail->addAddress($recipient);
              $mail->Subject = 'Attendance Export ' . $dateForName . (count($selectedKeys) > 1 ? ' (multiple groups)' : '');
              $bodies = admin_build_export_email_bodies(
                $fromName,
                $dateForName,
                $selectedGroupMeta,
                count($allRows),
                $format,
                basename($exportPath)
              );
              $mail->isHTML(true);
              $mail->Body = $bodies['html'];
              $mail->AltBody = $bodies['text'];

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
          $success = 'Export created: ' . basename($exportPath) . '. Email sending skipped (configure SMTP_HOST, SMTP_USER and SMTP_PASS in .env to enable delivery).';
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

  <?php if ($success && $exportPath && file_exists($exportPath)): ?>
    <div style="margin-bottom:24px;">
      <a href="download_backup.php?file=<?php echo urlencode(basename($exportPath)); ?>" download class="st-btn st-btn-primary" style="text-decoration:none;display:inline-flex;">
        <span class="material-symbols-outlined">download</span> Download Export
      </a>
    </div>
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
        <button type="submit" name="save_delivery_defaults" class="st-btn st-btn-secondary" style="padding:12px 24px;">
          <span class="material-symbols-outlined">save</span> Save Recipient/Format Defaults
        </button>
        <button type="submit" name="send_logs" class="st-btn st-btn-primary" style="padding:12px 24px;">
          <span class="material-symbols-outlined">send</span> Create & Send Export
        </button>
        <span style="font-size:0.8rem;color:var(--on-surface-variant);"><span class="material-symbols-outlined" style="font-size:1rem;vertical-align:middle;">info</span> Ensure SMTP is configured in <code>.env</code></span>
      </div>

    </form>
  </div>
</div>
<?php if ($error || $success): ?>
  <script>
    window.adminAlert(
      <?= json_encode($success ? 'Success' : 'Action failed') ?>,
      <?= json_encode($success ?: $error) ?>,
      <?= json_encode($success ? 'success' : 'error') ?>
    );
  </script>
<?php endif; ?>
