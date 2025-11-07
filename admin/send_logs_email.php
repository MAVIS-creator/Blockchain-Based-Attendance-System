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
    $type = $_POST['type'] ?? 'date';
    $value = trim($_POST['value'] ?? '');
    $recipient = trim($_POST['email'] ?? '');

    if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)){
        $error = 'Please provide a valid recipient email.';
    } else {
        // gather files matching
        $matches = [];
        if ($type === 'date'){
            // match files that contain the date string (YYYY-MM-DD or YYYYMMDD)
            foreach ($available as $fn){
                if (strpos($fn, $value) !== false) $matches[] = $logsDir . '/' . $fn;
            }
        } else {
            // match files that contain the course code
            foreach ($available as $fn){
                if (stripos($fn, $value) !== false) $matches[] = $logsDir . '/' . $fn;
            }
        }

    if (empty($matches)){
      $error = 'No matching log files or lines found for the given filter.';
    } else {
      // Build CSV from matched files/lines. CSV is more user-friendly than a raw ZIP.
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

      // For date filter we read files that match the date and include all their lines
      if ($type === 'date'){
        foreach ($matches as $m){
          if (!is_readable($m)) continue;
          $lines = file($m, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
          foreach ($lines as $ln){
            $rows[] = $parse_line($ln);
          }
        }
      } else {
        // course: scan files and include only lines that mention the course (case-insensitive)
        foreach ($available as $fn){
          $path = $logsDir . '/' . $fn;
          if (!is_readable($path)) continue;
          $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
          foreach ($lines as $ln){
            if (stripos($ln, $value) !== false){
              $rows[] = $parse_line($ln);
            }
          }
        }
      }

      if (empty($rows)){
        $error = 'No log entries matched the filter.';
      } else {
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
          if (file_exists(__DIR__ . '/vendor/autoload.php')){
            require_once __DIR__ . '/vendor/autoload.php';
            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')){
              try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                // note: SMTP should be configured by an admin for reliable delivery
                $mail->setFrom('no-reply@example.com', 'Attendance System');
                $mail->addAddress($recipient);
                $mail->Subject = "Attendance export: {$safeVal} - {$dateForName}";
                $mail->Body = "Please find attached the attendance export for {$safeVal} on {$dateForName}.";
                $mail->addAttachment($csvPath, $csvName);
                $mail->send();
                $sent = true;
                $success = 'Email sent with attendance CSV attached.';
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

  <div style="margin-top:12px;color:#6b7280;font-size:0.9rem;">Tip: If automatic email sending doesn't work you can download the generated CSV using the Download link and send it from your email account. For automatic delivery, ask your system administrator to configure the server's email/SMTP settings — I can provide step-by-step instructions if you want.</div>
      </form>
    </div>
  </div>
</body>
</html>