<?php
declare(strict_types=1);
// CLI-only, deliberately never enables SQL primary without migration review.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/../src/AiSqlJobQueue.php';
require_once __DIR__ . '/../admin/log_helpers.php';
app_storage_init();
$apply = in_array('--apply', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);
if ($apply === $dryRun) {
    fwrite(STDERR, "Usage: php tools/import_attendance_mysql.php --dry-run|--apply\n");
    exit(2);
}
$dir = app_storage_file('logs');
$rows = []; $invalid = []; $duplicate = []; $found = [];
foreach (glob($dir . '/????-??-??.log') ?: [] as $file) {
    $date = basename($file, '.log');
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
        $invalid[] = basename($file);
        continue;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $lineNumber => $line) {
        $entry = admin_parse_attendance_line($line);
        $matric = (string)($entry['matric'] ?? '');
        $action = strtolower((string)($entry['action'] ?? ''));
        $course = (string)($entry['course'] ?? '');
        $name = (string)($entry['name'] ?? '');
        $ip = (string)($entry['ip'] ?? '');
        if (!preg_match('/^\d{6,20}$/D', $matric) ||
            !in_array($action, ['checkin','checkout'], true) ||
            $name === '' || $course === '' || mb_strlen($course) > 191 ||
            mb_strlen($name) > 255 || strlen($ip) > 45) {
            $invalid[] = basename($file) . ':' . ($lineNumber + 1);
            continue;
        }
        $key = $date . '|' . mb_strtolower($course) . '|' . $matric . '|' . $action;
        if (isset($found[$key])) {
            $duplicate[] = basename($file) . ':' . ($lineNumber + 1);
            continue;
        }
        $found[$key] = true;
        $fingerprint = (string)($entry['fingerprint'] ?? '');
        $rows[] = [
            'day'=>$date,'course'=>$course,
            'matric'=>$matric,'action'=>$action,'name'=>$name,
            'fp'=>$fingerprint !== '' ? hash('sha256',$fingerprint) : null,
            'ip'=>$ip
        ];
    }
}
$summary=['valid_rows'=>count($rows),'invalid_rows'=>count($invalid),
 'duplicate_rows'=>count($duplicate),'mode'=>$apply?'apply':'dry-run'];
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($invalid || $duplicate) {
    fwrite(STDERR, "Review invalid/duplicate legacy records; import aborted.\n");
    exit(3);
}
if (!$apply) exit(0);
$db = bbas_ai_db();
$db->beginTransaction();
try {
    $insert = $db->prepare("INSERT INTO bbas_attendance_events
      (event_day,course,matric,action,name,fingerprint_hash,client_ip,source)
      VALUES (:day,:course,:matric,:action,:name,:fp,:ip,'legacy_import')");
    foreach ($rows as $row) $insert->execute($row);
    $db->commit();
    echo "Imported " . count($rows) . " rows. Compare exports and chain before any cutover.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "SQL import rolled back; check credentials/schema/duplicate records.\n");
    exit(4);
}
