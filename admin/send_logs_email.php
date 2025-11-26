<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// send_logs_email.php - redesigned to show selectable log files grouped by date+course

$logsDir = __DIR__ . '/logs';
$exportDir = __DIR__ . '/backups';
if (!is_dir($exportDir)) @mkdir($exportDir, 0755, true);

// Load .env helper
function load_env_vars($path){
    $env = [];
    if (file_exists($path)){
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $l){
            $t = trim($l);
            if ($t === '' || strpos($t,'#') === 0 || strpos($t,'=') === false) continue;
            list($k,$v) = explode('=',$t,2);
            $env[trim($k)] = trim(trim($v),"\"'");
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
} catch (\Throwable $e) { /* ignore */ }

// Build groups: parse all log files and group entries by date+course
$groups = [];
if (is_dir($logsDir)){
    $it = new DirectoryIterator($logsDir);
    foreach ($it as $f){
        if ($f->isFile()){
            $fn = $f->getFilename();
            if (preg_match('/\.(php|css)$/i',$fn)) continue; // skip helpers
            $lines = @file($logsDir . '/' . $fn, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $ln){
                $parts = array_map('trim', explode('|',$ln));
                $parts = array_pad($parts,10,'');
                // extract date and course
                $dt = $parts[6] ?? ''; $dateOnly = null;
                if ($dt && preg_match('/(20\d{2}-\d{2}-\d{2})/',$dt,$md)) $dateOnly = $md[1];
                if (!$dateOnly && preg_match('/(20\d{2}-\d{2}-\d{2})/',$fn,$mf)) $dateOnly = $mf[1];
                if (!$dateOnly) $dateOnly = date('Y-m-d');
                $course = ($parts[8] ?? '') !== '' ? $parts[8] : 'Unknown';
                $key = $dateOnly . '|' . $course;
                if (!isset($groups[$key])) $groups[$key] = ['date'=>$dateOnly,'course'=>$course,'entries'=>0,'failed'=>0,'files'=>[]];
                $groups[$key]['entries']++;
                $txt = strtolower($ln);
                if (strpos($txt,'failed')!==false || strpos($txt,'invalid')!==false) $groups[$key]['failed']++;
                if (!in_array($fn, $groups[$key]['files'])) $groups[$key]['files'][] = $fn;
            }
        }
    }
}
uasort($groups, function($a,$b){ return strcmp($b['date'].$b['course'], $a['date'].$a['course']); });

$success = '';
$error = '';
$exportPath = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_logs'])){
    // Get selected groups
    $selectedKeys = isset($_POST['selected_groups']) && is_array($_POST['selected_groups']) ? $_POST['selected_groups'] : [];
    $recipient = trim($_POST['recipient'] ?? '');
    $format = $_POST['format'] ?? 'csv';
    $cols = isset($_POST['cols']) && is_array($_POST['cols']) ? $_POST['cols'] : ['name','matric','action','datetime','course'];
    
    if (empty($selectedKeys)) {
        $error = 'Please select at least one log group to send.';
    } elseif (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid recipient email address.';
    } else {
        // collect all rows from selected groups
        $allRows = [];
        foreach ($selectedKeys as $gkey){
            if (!isset($groups[$gkey])) continue;
            $group = $groups[$gkey];
            // read all files in this group
            foreach ($group['files'] as $fn){
                $path = $logsDir . '/' . $fn;
                if (!is_readable($path)) continue;
                $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line){
                    $parts = array_map('trim', explode('|',$line));
                    $parts = array_pad($parts,10,'');
                    $row = [
                        'name'=>$parts[0],'matric'=>$parts[1],'action'=>$parts[2],'token'=>$parts[3],
                        'ip'=>$parts[4],'status'=>$parts[5],'datetime'=>$parts[6],'user_agent'=>$parts[7],
                        'course'=>$parts[8],'reason'=>$parts[9]
                    ];
                    // check if row belongs to this group (date+course match)
                    $dt = $row['datetime'] ?? '';
                    $rowDate = null;
                    if ($dt && preg_match('/(20\d{2}-\d{2}-\d{2})/',$dt,$md)) $rowDate = $md[1];
                    $rowCourse = ($row['course'] ?? '') !== '' ? $row['course'] : 'Unknown';
                    $rowKey = $rowDate . '|' . $rowCourse;
                    if ($rowKey === $gkey) $allRows[] = $row;
                }
            }
        }
        
        if (empty($allRows)){
            $error = 'No log entries found for the selected groups.';
        } else {
            // create CSV/PDF
            $safeCourse = preg_replace('/[^a-zA-Z0-9_-]/','_', $groups[$selectedKeys[0]]['course'] ?? 'logs');
            $dateForName = $groups[$selectedKeys[0]]['date'] ?? date('Y-m-d');
            $multiTag = count($selectedKeys) > 1 ? '_multi' : '';
            $baseName = "attendance_{$safeCourse}_{$dateForName}{$multiTag}";
            $csvPath = $exportDir . '/' . $baseName . '.csv';
            
            $fh = fopen($csvPath,'w');
            if (!$fh){
                $error = 'Server cannot create export file.';
            } else {
                $headerMap = ['name'=>'Name','matric'=>'Matric','action'=>'Action','token'=>'Token','ip'=>'IP','status'=>'Status','datetime'=>'Datetime','user_agent'=>'UserAgent','course'=>'Course','reason'=>'Reason'];
                $header = []; foreach ($cols as $c) if (isset($headerMap[$c])) $header[] = $headerMap[$c];
                fputcsv($fh,$header);
                foreach ($allRows as $r){
                    $out = []; foreach ($cols as $c) $out[] = $r[$c] ?? ''; fputcsv($fh,$out);
                }
                fclose($fh);
                $exportPath = $csvPath;
                
                // PDF generation if requested
                if ($format === 'pdf'){
                    if (file_exists(__DIR__ . '/../vendor/autoload.php')){
                        require_once __DIR__ . '/../vendor/autoload.php';
                        if (class_exists('Dompdf\\Dompdf')){
                            try {
                                $dompdf = new \Dompdf\Dompdf();
                                $html = '<html><head><meta charset="utf-8"><style>table{border-collapse:collapse;width:100%;}td,th{border:1px solid #ddd;padding:4px;font-size:10px;}th{background:#f3f4f6;text-align:left;}</style></head><body>';
                                $html .= '<h2>Attendance Export</h2><p>Groups: '.count($selectedKeys).' | Entries: '.count($allRows).'</p>';
                                $html .= '<table><thead><tr>'; foreach ($header as $h) $html .= '<th>'.htmlspecialchars($h).'</th>'; $html .= '</tr></thead><tbody>';
                                foreach ($allRows as $r){ 
                                    $html .= '<tr>'; 
                                    foreach ($cols as $c) $html .= '<td>'.htmlspecialchars(mb_substr($r[$c] ?? '',0,500)).'</td>'; 
                                    $html .= '</tr>'; 
                                }
                                $html .= '</tbody></table></body></html>';
                                $dompdf->setPaper('A4','landscape');
                                $dompdf->loadHtml($html);
                                $dompdf->render();
                                $pdfPath = $exportDir . '/' . $baseName . '.pdf';
                                file_put_contents($pdfPath,$dompdf->output());
                                $exportPath = $pdfPath;
                            } catch (\Exception $e){
                                $error = 'PDF generation failed: '.$e->getMessage();
                            }
                        }
                    }
                }
                
                // Send email via PHPMailer using .env for SMTP
                $sent = false;
                if (file_exists(__DIR__ . '/../vendor/autoload.php')){
                    require_once __DIR__ . '/../vendor/autoload.php';
                    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')){
                        try {
                            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                            
                            // SMTP config from .env only
                            $smtpHost = $ENV['SMTP_HOST'] ?? '';
                            if ($smtpHost){
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
                                $settings = json_decode(file_get_contents(__DIR__ . '/settings.json'),true) ?: [];
                            }
                            $fromEmail = $ENV['FROM_EMAIL'] ?? 'no-reply@example.com';
                            $fromName = $settings['smtp']['from_name'] ?? ($ENV['FROM_NAME'] ?? 'Attendance System');
                            
                            $mail->setFrom($fromEmail, $fromName);
                            $mail->addAddress($recipient);
                            $mail->Subject = 'Attendance Export ' . $dateForName . (count($selectedKeys)>1 ? ' (multiple groups)' : '');
                            $mail->Body = 'Attached attendance export generated from ' . count($selectedKeys) . ' group(s) with '.count($allRows).' entries.';
                            
                            if (file_exists($exportPath)) {
                                $mail->addAttachment($exportPath, basename($exportPath));
                            }
                            
                            $mail->send();
                            $sent = true;
                            $success = 'Email sent successfully with export attachment!';
                        } catch (\Exception $e){
                            $error = 'Email sending failed: '.$e->getMessage();
                        }
                    }
                }
                
                if (!$sent && !$error){
                    $success = 'Export created: '.basename($exportPath).'. Email sending not available (check .env SMTP settings or install PHPMailer).';
                }
            }
        }
    }
}
}

// Minimal UI
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Send Logs via Email</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .log-table { width:100%; border-collapse:collapse; font-size:13px; margin-top:12px; }
    .log-table th, .log-table td { padding:8px; border:1px solid #ddd; }
    .log-table th { background:#f3f4f6; font-weight:600; text-align:left; }
    .log-table tr:hover { background:#f9fafb; }
    .btn { padding:6px 12px; border-radius:6px; border:none; cursor:pointer; font-size:13px; }
    .btn-primary { background:#3b82f6; color:#fff; }
    .btn-success { background:#059669; color:#fff; }
    .msg { padding:12px; border-radius:6px; margin-bottom:12px; }
    .msg.error { background:#fee; color:#c00; }
    .msg.success { background:#d1fae5; color:#065f46; }
    .form-control { padding:8px; border:1px solid #d1d5db; border-radius:6px; width:100%; }
    .card { max-width:1200px; margin:20px auto; background:#fff; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
    .card-header { padding:16px; border-bottom:1px solid #e5e7eb; }
    .card-header h3 { margin:0; }
    .card-body { padding:16px; }
  </style>
</head>
<body>
  <div class="card">
    <div class="card-header"><h3>Send Logs via Email</h3></div>
    <div class="card-body">
      <?php if ($error): ?>
        <div class="msg error"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="msg success"><?php echo htmlspecialchars($success); ?></div>
        <?php if ($exportPath && file_exists($exportPath)): ?>
          <a href="backups/<?php echo basename($exportPath); ?>" download class="btn btn-primary">Download Export</a>
        <?php endif; ?>
      <?php endif; ?>

      <form method="post">
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:16px;">
          <div>
            <label style="display:block; font-weight:600; margin-bottom:4px;">Recipient Email</label>
            <input type="email" name="recipient" required class="form-control" value="<?=htmlspecialchars($defaultRecipient)?>" />
          </div>
          <div>
            <label style="display:block; font-weight:600; margin-bottom:4px;">Format</label>
            <select name="format" class="form-control">
              <option value="csv">CSV (Spreadsheet)</option>
              <option value="pdf">PDF (Report)</option>
            </select>
          </div>
        </div>

        <fieldset style="margin-bottom:16px; padding:12px; border:1px solid #e5e7eb; border-radius:6px;">
          <legend style="font-weight:600; font-size:14px;">Select Columns to Export</legend>
          <div style="display:flex; gap:12px; flex-wrap:wrap; font-size:13px;">
            <label><input type="checkbox" name="cols[]" value="name" checked> Name</label>
            <label><input type="checkbox" name="cols[]" value="matric" checked> Matric</label>
            <label><input type="checkbox" name="cols[]" value="action" checked> Action</label>
            <label><input type="checkbox" name="cols[]" value="datetime" checked> Datetime</label>
            <label><input type="checkbox" name="cols[]" value="course" checked> Course</label>
            <label><input type="checkbox" name="cols[]" value="user_agent"> User Agent</label>
            <label><input type="checkbox" name="cols[]" value="ip"> IP</label>
            <label><input type="checkbox" name="cols[]" value="reason"> Reason</label>
            <label><input type="checkbox" name="cols[]" value="status"> Status</label>
            <label><input type="checkbox" name="cols[]" value="token"> Token</label>
          </div>
        </fieldset>

        <h4 style="margin-bottom:8px;">Available Log Groups (Date + Course)</h4>
        <p style="color:#6b7280; font-size:13px; margin-bottom:12px;">
          Select one or multiple groups to send. Each group represents all log entries for a specific date and course combination.
        </p>

        <table class="log-table">
          <thead>
            <tr>
              <th style="width:40px;"><input type="checkbox" id="select-all" onclick="document.querySelectorAll('input[name=\'selected_groups[]\']').forEach(c=>c.checked=this.checked)"></th>
              <th>Date</th>
              <th>Course</th>
              <th style="text-align:right;">Entries</th>
              <th style="text-align:right;">Failed</th>
              <th>Source Files</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($groups)): ?>
              <tr><td colspan="6" style="text-align:center; color:#6b7280; padding:20px;">No log groups found</td></tr>
            <?php else: ?>
              <?php foreach ($groups as $gkey => $g): ?>
                <tr>
                  <td style="text-align:center;">
                    <input type="checkbox" name="selected_groups[]" value="<?=htmlspecialchars($gkey)?>">
                  </td>
                  <td><?=htmlspecialchars($g['date'])?></td>
                  <td><?=htmlspecialchars($g['course'])?></td>
                  <td style="text-align:right;"><?= (int)$g['entries'] ?></td>
                  <td style="text-align:right; color:<?= $g['failed'] > 0 ? '#dc2626' : '#6b7280' ?>;">
                    <?= (int)$g['failed'] ?>
                  </td>
                  <td style="font-size:11px; color:#6b7280;">
                    <?=htmlspecialchars(implode(', ', array_slice($g['files'],0,3)))?>
                    <?php if (count($g['files']) > 3): ?>
                      <span>+<?= count($g['files']) - 3 ?> more</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <div style="margin-top:16px; display:flex; gap:10px; align-items:center;">
          <button type="submit" name="send_logs" class="btn btn-primary">
            Create & Send Selected Groups
          </button>
        </div>

        <div style="margin-top:12px; padding:12px; background:#f9fafb; border-radius:6px; font-size:13px; color:#6b7280;">
          <strong>Note:</strong> SMTP settings are configured in <code>.env</code> file. If email sending fails, the export will be created and you can download it manually.
          To automate sending, use <code>auto_send_logs.php</code> scheduled via Task Scheduler or cron.
        </div>
      </form>
    </div>
  </div>
</body>
</html>
