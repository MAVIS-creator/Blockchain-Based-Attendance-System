<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// send_logs_email.php
// Small admin utility to collect logs by date or course, compress them and either send via PHPMailer or provide a downloadable ZIP.

$logsDir = __DIR__ . '/logs';
$exportDir = __DIR__ . '/backups';
if (!is_dir($exportDir)) @mkdir($exportDir, 0755, true);

// discover log files and basic metadata
$available = [];
if (is_dir($logsDir)){
    $it = new DirectoryIterator($logsDir);
    foreach ($it as $f){
        if ($f->isFile()){
            $fn = $f->getFilename();
            // skip php helper scripts and css
            if (preg_match('/\.php$/i',$fn) || preg_match('/\.css$/i',$fn)) continue;
            $full = $logsDir . '/' . $fn;
            $meta = [
                'filename' => $fn,
                'path' => $full,
                'size' => filesize($full),
                'date' => null,
                'courses' => [],
                'entries' => 0,
                'failed' => 0
            ];
            // attempt date from filename (YYYY-MM-DD)
            if (preg_match('/(20\d{2}-\d{2}-\d{2})/',$fn,$m)) $meta['date'] = $m[1];
            // light parse first 200 lines for courses and counts
            $lines = @file($full, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $meta['entries'] = count($lines);
            $courseSet = [];
            $maxScan = 200; $scanned = 0;
            foreach ($lines as $ln){
                $parts = array_map('trim', explode('|',$ln));
                if (isset($parts[8]) && $parts[8] !== '') $courseSet[$parts[8]] = true;
                $failed = false;
                $txt = strtolower($ln);
                if (strpos($txt,'failed') !== false || strpos($txt,'invalid') !== false) $failed = true;
                if ($failed) $meta['failed']++;
                if (!$meta['date'] && isset($parts[6]) && preg_match('/(20\d{2}-\d{2}-\d{2})/',$parts[6],$md)) $meta['date'] = $md[1];
                $scanned++; if ($scanned >= $maxScan) break;
            }
            $meta['courses'] = array_keys($courseSet);
            $available[] = $meta;
        }
    }

        // Build groups (Date + Course) across all logs
        $groupSummary = [];
        foreach ($available as $m){
            $lines = @file($m['path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $ln){
                $parts = array_map('trim', explode('|',$ln));
                $parts = array_pad($parts,10,'');
                $dt = $parts[6]; $dateOnly = null; if ($dt && preg_match('/(20\d{2}-\d{2}-\d{2})/',$dt,$md)) $dateOnly = $md[1]; else $dateOnly = ($m['date'] ?? date('Y-m-d'));
                $course = $parts[8] !== '' ? $parts[8] : 'unknown';
                $key = $dateOnly . '|' . $course;
                if (!isset($groupSummary[$key])) $groupSummary[$key] = ['date'=>$dateOnly,'course'=>$course,'total'=>0,'failed'=>0,'files'=>[]];
                $groupSummary[$key]['total']++;
                $txt = strtolower($ln); if (strpos($txt,'failed') !== false || strpos($txt,'invalid') !== false) $groupSummary[$key]['failed']++;
                $groupSummary[$key]['files'][$m['filename']] = true;
            }
        }
}

$success = '';
$error = '';
$zipPath = '';

// defaults from settings and .env for convenience
$defaultRecipient = '';
try {
    $adminSettings = file_exists(__DIR__ . '/settings.json') ? (json_decode(file_get_contents(__DIR__ . '/settings.json'), true) ?: []) : [];
    $defaultRecipient = $adminSettings['auto_send']['recipient'] ?? '';
    $envPath = __DIR__ . '/../.env';
    if (file_exists($envPath)){
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $l){
            $t = trim($l);
            if ($t === '' || strpos($t,'#') === 0 || strpos($t,'=') === false) continue;
            list($k,$v) = explode('=',$t,2);
            if (trim($k) === 'AUTO_SEND_RECIPIENT' && !$defaultRecipient) $defaultRecipient = trim(trim($v),"\"'");
        }
    }
} catch (\Throwable $e) { /* ignore */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    // inputs
    $recipient = trim($_POST['email'] ?? '');
    $format = $_POST['format'] ?? 'csv';
    $log_kind = $_POST['log_kind'] ?? 'all';
    $date_from = trim($_POST['date_from'] ?? '');
    $date_to = trim($_POST['date_to'] ?? '');
    $time_from = trim($_POST['time_from'] ?? '');
    $time_to = trim($_POST['time_to'] ?? '');
    $courseFilter = trim($_POST['course'] ?? '');
    $cols = isset($_POST['cols']) && is_array($_POST['cols']) ? $_POST['cols'] : ['name','matric','action','datetime','course'];
        $selectedFiles = isset($_POST['selected_files']) && is_array($_POST['selected_files']) ? $_POST['selected_files'] : [];
        $singleSend = isset($_POST['single_file']) ? trim($_POST['single_file']) : '';
        $selectedGroups = isset($_POST['selected_groups']) && is_array($_POST['selected_groups']) ? $_POST['selected_groups'] : [];
        $singleGroup = isset($_POST['single_group']) ? trim($_POST['single_group']) : '';
    if ($singleSend !== '') $selectedFiles = [$singleSend];
        if ($singleGroup !== '') $selectedGroups = [$singleGroup];

    if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)){
        $error = 'Please provide a valid recipient email.';
    } else {
        // map metadata by filename for quick lookups
        $metaIndex = [];
        foreach ($available as $m) $metaIndex[$m['filename']] = $m;

        // if no selection, default to all files (unless groups are selected)
        if (empty($selectedFiles) && empty($selectedGroups)) $selectedFiles = array_keys($metaIndex);

        // sanitize selection
        $validSelection = [];
        foreach ($selectedFiles as $sf){
            if (isset($metaIndex[$sf])) $validSelection[] = $sf;
        }
        $selectedFiles = $validSelection;

            if (empty($selectedFiles) && empty($selectedGroups)){
                $error = 'No log files or groups selected.';
            } else {
            // parse lines from selected files
            $rows = [];
                $filesToRead = $selectedFiles;
                // when groups are selected, include all files from those groups for filtering below
                if (!empty($selectedGroups)){
                    foreach ($selectedGroups as $gkey){ if (isset($groupSummary[$gkey])){ $filesToRead = array_unique(array_merge($filesToRead, array_keys($groupSummary[$gkey]['files']))); } }
                }
                foreach ($filesToRead as $fn){
                $path = $metaIndex[$fn]['path'];
                if (!is_readable($path)) continue;
                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line){
                    $parts = array_map('trim', explode('|',$line));
                    $parts = array_pad($parts,10,'');
                        $row = [
                        'name'=>$parts[0],'matric'=>$parts[1],'action'=>$parts[2],'token'=>$parts[3],'ip'=>$parts[4],'status'=>$parts[5],'datetime'=>$parts[6],'user_agent'=>$parts[7],'course'=>$parts[8],'reason'=>$parts[9]
                        ];
                        // if group selection present, keep only matching groups
                        if (!empty($selectedGroups)){
                            $dateOnly = null; if ($row['datetime'] && preg_match('/(20\d{2}-\d{2}-\d{2})/',$row['datetime'],$md)) $dateOnly = $md[1]; else $dateOnly = null;
                            $grpKey = ($dateOnly ?: '') . '|' . ($row['course'] !== '' ? $row['course'] : 'unknown');
                            if (!in_array($grpKey, $selectedGroups, true)) continue;
                        }
                        $rows[] = $row;
                }
            }
            if (empty($rows)){
                $error = 'Selected files contained no log entries.';
            } else {
                // filtering
                $filtered = [];
                foreach ($rows as $r){
                    $dt = $r['datetime'] ? strtotime($r['datetime']) : false;
                    if ($date_from){
                        $fromTs = strtotime($date_from . ' ' . ($time_from ?: '00:00:00'));
                        if ($dt === false || $dt < $fromTs) continue;
                    }
                    if ($date_to){
                        $toTs = strtotime($date_to . ' ' . ($time_to ?: '23:59:59'));
                        if ($dt === false || $dt > $toTs) continue;
                    }
                    if ($courseFilter){
                        if (stripos($r['course'] ?? '', $courseFilter) === false) continue;
                    }
                    $lineText = implode(' | ',$r);
                    $isFailed = (stripos($lineText,'failed') !== false || stripos($lineText,'invalid') !== false);
                    if ($log_kind === 'failed' && !$isFailed) continue;
                    if ($log_kind === 'successful' && $isFailed) continue;
                    $filtered[] = $r;
                }
                if (empty($filtered)){
                    $error = 'No log entries matched the filters.';
                } else {
                    $rows = $filtered;
                    // naming
                    $safeCourse = preg_replace('/[^a-zA-Z0-9_-]/','_', $courseFilter ?: 'logs');
                    if ($date_from && $date_to) $dateForName = $date_from . '_to_' . $date_to;
                    elseif ($date_from) $dateForName = $date_from; else {
                        $d = $rows[0]['datetime'] ?? '';
                        if (preg_match('/(\d{4}-\d{2}-\d{2})/',$d,$md)) $dateForName = $md[1]; else $dateForName = date('Y-m-d');
                    }
                    $multiTag = count($selectedFiles) > 1 ? '_multi' : '';
                    $baseName = "attendance_{$safeCourse}_{$dateForName}{$multiTag}";
                    $csvPath = $exportDir . '/' . $baseName . '.csv';
                    $fh = fopen($csvPath,'w');
                    if (!$fh){
                        $error = 'Server cannot create export file.';
                    } else {
                        $headerMap = ['name'=>'Name','matric'=>'Matric','action'=>'Action','token'=>'Token','ip'=>'IP','status'=>'Status','datetime'=>'Datetime','user_agent'=>'UserAgent','course'=>'Course','reason'=>'Reason'];
                        $header = []; foreach ($cols as $c) if (isset($headerMap[$c])) $header[] = $headerMap[$c];
                        fputcsv($fh,$header);
                        foreach ($rows as $r){
                            $out = []; foreach ($cols as $c) $out[] = $r[$c] ?? ''; fputcsv($fh,$out);
                        }
                        fclose($fh);
                        $generatedPath = $csvPath; $mailerInfo='';
                        if ($format === 'pdf'){
                            if (file_exists(__DIR__ . '/vendor/autoload.php')){ require_once __DIR__ . '/vendor/autoload.php'; if (class_exists('Dompdf\\Dompdf')){
                                try { $dompdf = new \Dompdf\Dompdf(); $html = '<html><head><meta charset="utf-8"><style>table{border-collapse:collapse;width:100%;}td,th{border:1px solid #ddd;padding:4px;font-size:11px;}th{background:#f3f4f6;text-align:left;}</style></head><body>';
                                    $html .= '<h2>Attendance export</h2><p>Files: '.htmlspecialchars(implode(',',$selectedFiles)).'</p>';
                                    $html .= '<table><thead><tr>'; foreach ($header as $h) $html .= '<th>'.htmlspecialchars($h).'</th>'; $html .= '</tr></thead><tbody>';
                                    foreach ($rows as $r){ $html .= '<tr>'; foreach ($cols as $c) $html .= '<td>'.htmlspecialchars(mb_substr($r[$c] ?? '',0,500)).'</td>'; $html .= '</tr>'; }
                                    $html .= '</tbody></table></body></html>'; $dompdf->setPaper('A4','landscape'); $dompdf->loadHtml($html); $dompdf->render(); $pdfPath = $exportDir . '/' . $baseName . '.pdf'; file_put_contents($pdfPath,$dompdf->output()); $generatedPath = $pdfPath; }
                                catch (\Exception $e){ $mailerInfo .= 'PDF generation failed: '.$e->getMessage(); }
                            } else { $mailerInfo .= 'dompdf missing. '; }
                            } else { if ($format === 'pdf') $mailerInfo .= 'Composer autoload missing. '; }
                        // send email via PHPMailer using .env + settings (env for SMTP details)
                        }
                        $sent=false; if (file_exists(__DIR__ . '/vendor/autoload.php')){ require_once __DIR__ . '/vendor/autoload.php'; if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')){
                            try { $mail = new \PHPMailer\PHPMailer\PHPMailer(true); $settings=[]; if (file_exists(__DIR__ . '/settings.json')) $settings = json_decode(file_get_contents(__DIR__ . '/settings.json'),true) ?: [];
                                // load .env
                                $env=[]; $envPath = __DIR__ . '/../.env'; if (file_exists($envPath)){ $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); foreach ($lines as $l){ $t=trim($l); if ($t===''||strpos($t,'#')===0||strpos($t,'=')===false) continue; list($k,$v)=explode('=',$t,2); $env[trim($k)]=trim(trim($v),"\"'"); }}
                                $smtpHost = $env['SMTP_HOST'] ?? $settings['smtp']['host'] ?? ''; if ($smtpHost){ $mail->isSMTP(); $mail->Host=$smtpHost; $mail->Port=intval($env['SMTP_PORT'] ?? $settings['smtp']['port'] ?? 587); $secure=$env['SMTP_SECURE'] ?? $settings['smtp']['secure'] ?? ''; if ($secure) $mail->SMTPSecure=$secure; $mail->SMTPAuth=true; $mail->Username=$env['SMTP_USER'] ?? $settings['smtp']['user'] ?? ''; $mail->Password=$env['SMTP_PASS'] ?? $settings['smtp']['pass'] ?? ''; }
                                $fromEmail = $env['FROM_EMAIL'] ?? $settings['smtp']['from_email'] ?? 'no-reply@example.com'; $fromName = $settings['smtp']['from_name'] ?? ($env['FROM_NAME'] ?? 'Attendance System');
                                $mail->setFrom($fromEmail, $fromName); $mail->addAddress($recipient);
                                $mail->Subject = 'Attendance export ' . $dateForName . (count($selectedFiles)>1 ? ' (multiple files)' : '');
                                $mail->Body = 'Attached attendance export generated from ' . count($selectedFiles) . ' file(s).';
                                if (file_exists($generatedPath)) $mail->addAttachment($generatedPath, basename($generatedPath));
                                $mail->send(); $sent=true; $success='Email sent with export.';
                            } catch (\Exception $e){ $error='PHPMailer failed: '.$e->getMessage(); }
                        } else { $mailerInfo .= 'PHPMailer missing. '; } } else { $mailerInfo .= 'Composer autoload missing. '; }
                        if (!$sent){ $success = 'Export created: '.basename($generatedPath).'. '.($mailerInfo ? $mailerInfo : ''); $zipPath = $generatedPath; }
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
                <input type="email" name="email" required class="form-control" value="<?=htmlspecialchars($defaultRecipient)?>" />
                <div style="display:flex;gap:12px;margin-top:12px;align-items:center;flex-wrap:wrap;">
                    <div style="flex:1;min-width:180px;">
                        <label style="display:block;font-size:0.85rem;color:#555;">Format</label>
                        <select name="format" class="form-control"><option value="csv">CSV (spreadsheet)</option><option value="pdf">PDF (readable report)</option></select>
                    </div>
                    <div style="flex:1;min-width:180px;">
                        <label style="display:block;font-size:0.85rem;color:#555;">Log type</label>
                        <select name="log_kind" class="form-control"><option value="all">All</option><option value="successful">Successful only</option><option value="failed">Failed only</option></select>
                    </div>
                    <div style="flex:1;min-width:180px;">
                        <label style="display:block;font-size:0.85rem;color:#555;">Course filter (optional)</label>
                        <input type="text" name="course" class="form-control" placeholder="Course code" />
                    </div>
                </div>
                <div style="display:flex;gap:12px;margin-top:12px;flex-wrap:wrap;">
                    <div><label style="display:block;font-size:0.85rem;color:#555;">Date from</label><input type="date" name="date_from" class="form-control" style="min-width:150px;"></div>
                    <div><label style="display:block;font-size:0.85rem;color:#555;">Time from</label><input type="time" name="time_from" class="form-control" style="min-width:120px;"></div>
                    <div><label style="display:block;font-size:0.85rem;color:#555;">Date to</label><input type="date" name="date_to" class="form-control" style="min-width:150px;"></div>
                    <div><label style="display:block;font-size:0.85rem;color:#555;">Time to</label><input type="time" name="time_to" class="form-control" style="min-width:120px;"></div>
                </div>
                <fieldset style="margin-top:14px;padding:10px;border:1px solid #e5e7eb;border-radius:6px;">
                    <legend style="font-weight:600;font-size:0.9rem;">Columns</legend>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;font-size:0.85rem;">
                        <label><input type="checkbox" name="cols[]" value="name" checked> Name</label>
                        <label><input type="checkbox" name="cols[]" value="matric" checked> Matric</label>
                        <label><input type="checkbox" name="cols[]" value="action" checked> Action</label>
                        <label><input type="checkbox" name="cols[]" value="datetime" checked> Datetime</label>
                        <label><input type="checkbox" name="cols[]" value="course" checked> Course</label>
                        <label><input type="checkbox" name="cols[]" value="user_agent"> User Agent</label>
                        <label><input type="checkbox" name="cols[]" value="ip"> IP</label>
                        <label><input type="checkbox" name="cols[]" value="reason"> Reason</label>
                        <label><input type="checkbox" name="cols[]" value="status"> Status</label>
                    </div>
                </fieldset>
                <h4 style="margin-top:18px;">Log files</h4>
                <p style="color:#6b7280;font-size:0.85rem;margin-top:-6px;">Filter first, then select one or multiple files. Use the per-file Send button for an immediate single export.</p>
                <table style="width:100%;border-collapse:collapse;font-size:12px;">
                    <thead><tr style="background:#f3f4f6;">
                        <th style="padding:6px;border:1px solid #ddd;">Select</th>
                        <th style="padding:6px;border:1px solid #ddd;">Filename</th>
                        <th style="padding:6px;border:1px solid #ddd;">Date</th>
                        <th style="padding:6px;border:1px solid #ddd;">Courses</th>
                        <th style="padding:6px;border:1px solid #ddd;">Entries</th>
                        <th style="padding:6px;border:1px solid #ddd;">Failed</th>
                        <th style="padding:6px;border:1px solid #ddd;">Actions</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($available as $m): ?>
                            <tr>
                                <td style="padding:4px;border:1px solid #ddd;text-align:center;"><input type="checkbox" name="selected_files[]" value="<?=htmlspecialchars($m['filename'])?>"></td>
                                <td style="padding:4px;border:1px solid #ddd;white-space:nowrap;"><?=htmlspecialchars($m['filename'])?></td>
                                <td style="padding:4px;border:1px solid #ddd;"><?=htmlspecialchars($m['date'] ?? 'n/a')?></td>
                                <td style="padding:4px;border:1px solid #ddd;max-width:220px;"><?=htmlspecialchars(implode(', ',$m['courses']))?></td>
                                <td style="padding:4px;border:1px solid #ddd;text-align:right;"><?= (int)$m['entries'] ?></td>
                                <td style="padding:4px;border:1px solid #ddd;text-align:right;"><?= (int)$m['failed'] ?></td>
                                <td style="padding:4px;border:1px solid #ddd;">
                                    <button name="single_file" value="<?=htmlspecialchars($m['filename'])?>" style="padding:4px 8px;font-size:11px;background:#3b82f6;color:#fff;border:none;border-radius:4px;">Send this</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                                                <h4 style="margin-top:28px;">Date & Course Groups (overview)</h4>
                                <table style="width:100%;border-collapse:collapse;font-size:12px;">
                                    <thead><tr style="background:#f3f4f6;">
                                        <th style="padding:6px;border:1px solid #ddd;">Date</th>
                                        <th style="padding:6px;border:1px solid #ddd;">Course</th>
                                        <th style="padding:6px;border:1px solid #ddd;">Total Entries</th>
                                        <th style="padding:6px;border:1px solid #ddd;">Failed</th>
                                        <th style="padding:6px;border:1px solid #ddd;">Files Included</th>
                                                        <th style="padding:6px;border:1px solid #ddd;">Select</th>
                                                        <th style="padding:6px;border:1px solid #ddd;">Action</th>
                                    </tr></thead>
                                    <tbody>
                                                        <?php foreach ($groupSummary as $gkey => $g): ?>
                                            <tr>
                                                <td style="padding:4px;border:1px solid #ddd;white-space:nowrap;"><?=htmlspecialchars($g['date'])?></td>
                                                <td style="padding:4px;border:1px solid #ddd;"><?=htmlspecialchars($g['course'])?></td>
                                                <td style="padding:4px;border:1px solid #ddd;text-align:right;"><?= (int)$g['total'] ?></td>
                                                <td style="padding:4px;border:1px solid #ddd;text-align:right;"><?= (int)$g['failed'] ?></td>
                                                <td style="padding:4px;border:1px solid #ddd;max-width:260px;"><?=htmlspecialchars(implode(', ', array_keys($g['files'])))?></td>
                                                                <td style="padding:4px;border:1px solid #ddd;text-align:center;"><input type="checkbox" name="selected_groups[]" value="<?=htmlspecialchars($gkey)?>"></td>
                                                                <td style="padding:4px;border:1px solid #ddd;"><button name="single_group" value="<?=htmlspecialchars($gkey)?>" style="padding:4px 8px;font-size:11px;background:#059669;color:#fff;border:none;border-radius:4px;">Send group</button></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                <div style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button class="btn btn-primary" type="submit" style="background:#2563eb;color:#fff;padding:8px 14px;border:none;border-radius:6px;">Create & Send Selected</button>
                    <?php if ($zipPath && file_exists($zipPath)): ?>
                        <a class="btn" href="backups/<?php echo basename($zipPath); ?>" download style="padding:8px 14px;border:1px solid #2563eb;color:#2563eb;border-radius:6px;text-decoration:none;">Download Export</a>
                    <?php endif; ?>
                </div>
                <div style="margin-top:12px;color:#6b7280;font-size:0.9rem;">Tip: If automatic sending fails you can download the export and email manually. SMTP values come from <code>.env</code>. To automate daily sending use <code>auto_send_logs.php</code>.</div>
            </form>
    </div>
  </div>
</body>
</html>