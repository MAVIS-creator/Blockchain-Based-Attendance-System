<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// send_logs_email.php
// Small admin utility to collect logs by date or course, compress them and either send via PHPMailer or provide a downloadable ZIP.

$logsDir = __DIR__ . '/logs';
$exportDir = __DIR__ . '/backups';
if (!is_dir($exportDir)) @mkdir($exportDir, 0755, true);

$available = [];
// scan logs directory for files (non-recursive)
if (is_dir($logsDir)){
    $it = new DirectoryIterator($logsDir);
    foreach ($it as $f){
        if ($f->isFile()) $available[] = $f->getFilename();
    }
}

$success = '';
$error = '';
$zipPath = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'){
  // inputs
  $recipient = trim($_POST['email'] ?? '');
  $format = $_POST['format'] ?? 'csv';
  $log_kind = $_POST['log_kind'] ?? 'all';
  $date_from = trim($_POST['date_from'] ?? '');
  $date_to = trim($_POST['date_to'] ?? '');
  $course = trim($_POST['course'] ?? '');
  $cols = isset($_POST['cols']) && is_array($_POST['cols']) ? $_POST['cols'] : ['name','matric','action','datetime','course'];

    if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)){
        $error = 'Please provide a valid recipient email.';
    } else {
        // Build rows from all log files and filter by inputs
        $rows = [];

        $parse_line = function($line){
        $parts = array_map('trim', explode('|', $line));
        // Normalize to expected columns
        $cols = array_pad($parts, 10, '');
        return [
          'name' => $cols[0],
          'matric' => $cols[1],
          'action' => $cols[2],
          'token' => $cols[3],
          'ip' => $cols[4],
          'status' => $cols[5],
          'datetime' => $cols[6],
          'user_agent' => $cols[7],
          'course' => $cols[8],
          'reason' => $cols[9]
        ];
      };
        // read all files and collect lines
        foreach ($available as $fn){
          $path = $logsDir . '/' . $fn;
          if (!is_readable($path)) continue;
          $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
          foreach ($lines as $ln){
            $rows[] = $parse_line($ln);
          }
        }

        if (empty($rows)){
          $error = 'No log files found.';
        } else {
          // apply filters: date range, course, log_kind
          $filtered = [];
          foreach ($rows as $r){
            // datetime parse
            $dt = null;
            if (!empty($r['datetime'])){
              // try common formats
              $dt = strtotime($r['datetime']);
            }
            // date range filter
            if ($date_from){
              $fromTs = strtotime($date_from.' 00:00:00');
              if ($dt === false || $dt < $fromTs) continue;
            }
            if ($date_to){
              $toTs = strtotime($date_to.' 23:59:59');
              if ($dt === false || $dt > $toTs) continue;
            }
            // course filter
            if ($course){
              if (stripos($r['course'] . ' ' . implode(' ', $r), $course) === false && stripos(implode('|',$r), $course) === false) continue;
            }
            // log kind filter
            $lineText = implode(' | ', $r);
            $isFailed = false;
            if (stripos($lineText,'failed') !== false || stripos($lineText,'invalid') !== false) $isFailed = true;
            if ($log_kind === 'failed' && !$isFailed) continue;
            if ($log_kind === 'successful' && $isFailed) continue;

            $filtered[] = $r;
          }

          if (empty($filtered)){
            $error = 'No log entries matched the filter.';
          } else {
            // use filtered rows going forward
            $rows = $filtered;
          }
        }
      }
        // determine filename components
        $safeVal = preg_replace('/[^a-zA-Z0-9_-]/', '_', $value ?: 'logs');
        // determine date for filename: use provided date or date from first row
        $dateForName = $value;
        if ($type !== 'date'){
          // try to extract YYYY-MM-DD from first row datetime
          $d = $rows[0]['datetime'] ?? '';
          if (preg_match('/(\d{4}-\d{2}-\d{2})/', $d, $mdate)){
            $dateForName = $mdate[1];
          } else {
            $dateForName = date('Y-m-d');
          }
        }

        $csvName = "attendance_{$safeVal}_{$dateForName}.csv";
        $csvPath = $exportDir . '/' . $csvName;

        $fh = fopen($csvPath, 'w');
        if (!$fh){
          $error = 'Could not create CSV file on server.';
        } else {
          // header
          fputcsv($fh, ['Name','Matric','Action','Token','IP','Status','Datetime','UserAgent','Course','Reason']);
          foreach ($rows as $r){
            fputcsv($fh, [$r['name'],$r['matric'],$r['action'],$r['token'],$r['ip'],$r['status'],$r['datetime'],$r['user_agent'],$r['course'],$r['reason']]);
          }
          fclose($fh);

        // attempt to send via PHPMailer if available
          $sent = false;
          $mailerInfo = '';
        $format = $_POST['format'] ?? 'csv';
          if (file_exists(__DIR__ . '/vendor/autoload.php')){
            require_once __DIR__ . '/vendor/autoload.php';
      // PDF generation support using Dompdf if requested
      $generatedPath = '';
      if ($format === 'pdf'){
        // try Dompdf
        if (class_exists('Dompdf\\Dompdf')){
          try {
            $dompdf = new \Dompdf\Dompdf();
            $html = '<html><head><meta charset="utf-8"><style>table{border-collapse:collapse;width:100%;}td,th{border:1px solid #ddd;padding:8px;font-size:12px;}th{background:#f3f4f6;text-align:left;}</style></head><body>';
            $html .= '<h2>Attendance export: '.htmlspecialchars($safeVal).' - '.htmlspecialchars($dateForName).'</h2>';
            $html .= '<table><thead><tr>';
            $cols = ['Name','Matric','Action','Token','IP','Status','Datetime','UserAgent','Course','Reason'];
            foreach ($cols as $c) $html .= '<th>'.htmlspecialchars($c).'</th>';
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $r){
              $html .= '<tr>';
              foreach (['name','matric','action','token','ip','status','datetime','user_agent','course','reason'] as $k){
                $html .= '<td>'.htmlspecialchars(mb_substr($r[$k] ?? '',0,500)).'</td>';
              }
              $html .= '</tr>';
            }
            $html .= '</tbody></table></body></html>';
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->loadHtml($html);
            $dompdf->render();
            $pdfName = "attendance_{$safeVal}_{$dateForName}.pdf";
            $pdfPath = $exportDir . '/' . $pdfName;
            file_put_contents($pdfPath, $dompdf->output());
            $generatedPath = $pdfPath;
          } catch (\Exception $e){
            $mailerInfo .= 'PDF generation failed: '. $e->getMessage() . ' ';
          }
        } else {
          $mailerInfo .= 'PDF generation not available (dompdf missing). ';
        }
      } else {
        $generatedPath = $csvPath;
      }
      if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')){
        try {
          $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
          // load admin settings and .env
          $adminSettings = [];
          if (file_exists(__DIR__ . '/settings.json')){
            $adminSettings = json_decode(file_get_contents(__DIR__ . '/settings.json'), true) ?: [];
          }
          $env = [];
          $envPath = __DIR__ . '/../.env';
          if (file_exists($envPath)){
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $l){
              if (strpos(trim($l),'#')===0) continue;
              if (!strpos($l,'=')) continue;
              list($k,$v) = explode('=', $l, 2);
              $env[trim($k)] = trim(trim($v),"\"'");
            }
          }

          // prefer .env SMTP settings, fallback to admin settings
          $smtpHost = $env['SMTP_HOST'] ?? $adminSettings['smtp']['host'] ?? '';
          if ($smtpHost){
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->Port = intval($env['SMTP_PORT'] ?? $adminSettings['smtp']['port'] ?? 587);
            $secure = $env['SMTP_SECURE'] ?? $adminSettings['smtp']['secure'] ?? '';
            if ($secure) $mail->SMTPSecure = $secure;
            $mail->SMTPAuth = true;
            $mail->Username = $env['SMTP_USER'] ?? $adminSettings['smtp']['user'] ?? '';
            $mail->Password = $env['SMTP_PASS'] ?? $adminSettings['smtp']['pass'] ?? '';
          }

          $fromEmail = $env['FROM_EMAIL'] ?? $adminSettings['smtp']['from_email'] ?? 'no-reply@example.com';
          $fromName = $env['FROM_NAME'] ?? $adminSettings['smtp']['from_name'] ?? 'Attendance System';
          $mail->setFrom($fromEmail, $fromName);
          $mail->addAddress($recipient);
          $mail->Subject = "Attendance export: {$safeVal} - {$dateForName}";
          $mail->Body = "Please find attached the attendance export for {$safeVal} on {$dateForName}.";
          if (!empty($generatedPath) && file_exists($generatedPath)) {
            $mail->addAttachment($generatedPath, basename($generatedPath));
          } else {
            if (isset($csvPath) && file_exists($csvPath)) $mail->addAttachment($csvPath, basename($csvPath));
          }
          $mail->send();
          $sent = true;
          $success = 'Email sent with attendance export attached.';
        } catch (\Exception $e){
          $error = 'PHPMailer failed to send: ' . $e->getMessage();
        }
      } else {
        $mailerInfo = 'PHPMailer not found in vendor; ask your system admin to run: composer require phpmailer/phpmailer';
      }
          } else {
            $mailerInfo = 'Automatic email not available: no composer autoloader (vendor/autoload.php).';
          }

          if (!$sent){
            $success = 'CSV created: ' . basename($csvPath) . '. ' . ($mailerInfo ? $mailerInfo : '');
            $zipPath = $csvPath; // reuse variable for download link display
          }
        }
      }
    }
    }
}

// minimal UI
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Send Logs via Email</title>
  <link rel="stylesheet" href="style.css">
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
      <?php endif; ?>

      <form method="post">
        <label>Recipient Email</label>
        <input type="email" name="email" required class="form-control" />

        <div style="display:flex;gap:12px;margin-top:12px;align-items:center;">
          <label style="flex:0 0 120px;">Format</label>
          <select name="format" class="form-control" style="flex:0 0 160px;">
            <option value="csv">CSV (spreadsheet)</option>
            <option value="pdf">PDF (readable report)</option>
          </select>

          <label style="flex:0 0 120px;text-align:right;">Log type</label>
          <select name="log_kind" class="form-control" style="flex:0 0 160px;">
            <option value="all">All</option>
            <option value="successful">Successful only</option>
            <option value="failed">Failed only</option>
          </select>
        </div>

        <div style="display:flex;gap:12px;margin-top:12px;">
          <label style="flex:0 0 120px;align-self:center;">Date or Range</label>
          <input type="date" name="date_from" class="form-control" style="flex:0 0 180px;"> 
          <input type="date" name="date_to" class="form-control" style="flex:0 0 180px;">
          <input type="text" name="course" placeholder="Course code (optional)" class="form-control" style="flex:1;" />
        </div>

        <div style="margin-top:12px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
          <label style="flex:0 0 120px;">Columns</label>
          <label><input type="checkbox" name="cols[]" value="name" checked> Name</label>
          <label><input type="checkbox" name="cols[]" value="matric" checked> Matric</label>
          <label><input type="checkbox" name="cols[]" value="action" checked> Action</label>
          <label><input type="checkbox" name="cols[]" value="datetime" checked> Datetime</label>
          <label><input type="checkbox" name="cols[]" value="course" checked> Course</label>
          <label><input type="checkbox" name="cols[]" value="user_agent"> User Agent</label>
          <label><input type="checkbox" name="cols[]" value="ip"> IP</label>
        </div>

        <div style="margin-top:16px;display:flex;gap:10px;align-items:center;">
          <button class="btn btn-primary" type="submit">Create & Send</button>
          <?php if ($zipPath && file_exists($zipPath)): ?>
            <a class="btn" href="backups/<?php echo basename($zipPath); ?>" download>Download</a>
          <?php endif; ?>
        </div>

  <div style="margin-top:12px;color:#6b7280;font-size:0.9rem;">Tip: If automatic email sending doesn't work you can download the generated CSV using the Download link and send it from your email account. For automatic delivery, ask your system administrator to configure the server's email/SMTP settings</div>
      </form>
    </div>
  </div>
</body>
</html>