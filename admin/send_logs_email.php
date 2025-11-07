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
            $error = 'No matching log files found for the given filter.';
        } else {
            // create a zip
            $timestamp = time();
            $safeVal = preg_replace('/[^a-zA-Z0-9_-]/', '_', $value ?: 'logs');
            $zipName = "logs_{$safeVal}_{$timestamp}.zip";
            $zipPath = $exportDir . '/' . $zipName;

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE) !== true){
                $error = 'Could not create ZIP archive on server.';
            } else {
                foreach ($matches as $m) {
                    $zip->addFile($m, basename($m));
                }
                $zip->close();

                // try to send with PHPMailer if available
                $sent = false;
                $mailerInfo = '';
                if (file_exists(__DIR__ . '/vendor/autoload.php')){
                    require_once __DIR__ . '/vendor/autoload.php';
                    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')){
                        try {
                            $mail = new PHPMailer\\PHPMailer\\PHPMailer(true);
                            // default: try using local mail for now; advise configuring SMTP
                            $mail->setFrom('no-reply@example.com', 'Attendance System');
                            $mail->addAddress($recipient);
                            $mail->Subject = 'Requested logs from Attendance System';
                            $mail->Body = "Attached are the requested logs.";
                            $mail->addAttachment($zipPath);
                            $mail->send();
                            $sent = true;
                            $success = 'Email sent with logs attached.';
                        } catch (Exception $e) {
                            $error = 'PHPMailer failed to send: ' . $e->getMessage();
                        }
                    } else {
                        $mailerInfo = 'PHPMailer not found in vendor; please run: composer require phpmailer/phpmailer';
                    }
                } else {
                    $mailerInfo = 'No composer autoloader found (vendor/autoload.php). To enable rich sending, run composer and install PHPMailer.';
                }

                if (!$sent){
                    // provide download link and instructions
                    $success = 'ZIP created: ' . basename($zipPath) . '. ' . ($mailerInfo ? $mailerInfo : '');
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

        <div style="display:flex;gap:12px;margin-top:12px;">
          <label style="flex:0 0 120px;align-self:center;">Filter by</label>
          <select name="type" class="form-control" style="flex:0 0 160px;">
            <option value="date">Date (YYYY-MM-DD or YYYYMMDD)</option>
            <option value="course">Course code</option>
          </select>
          <input type="text" name="value" placeholder="Enter date or course" class="form-control" style="flex:1;" />
        </div>

        <div style="margin-top:16px;display:flex;gap:10px;align-items:center;">
          <button class="btn btn-primary" type="submit">Create & Send</button>
          <?php if ($zipPath && file_exists($zipPath)): ?>
            <a class="btn" href="backups/<?php echo basename($zipPath); ?>" download>Download ZIP</a>
          <?php endif; ?>
        </div>

        <div style="margin-top:12px;color:#6b7280;font-size:0.9rem;">Tip: if email sending fails, install PHPMailer via Composer and configure SMTP settings in code or a local config.</div>
      </form>
    </div>
  </div>
</body>
</html>