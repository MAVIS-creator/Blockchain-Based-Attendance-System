<?php
declare(strict_types=1);
// CLI-only, deliberately never enables SQL primary without migration review.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/../src/AiSqlJobQueue.php';
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
    if (!DateTimeImmutable::createFromFormat('!Y-m-d', $date)) continue;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $lineNumber => $line) {
        $fields = array_map('trim', explode('|', $line));
        $matric = (string)($fields[1] ?? '');
        $action = strtolower((string)($fields[2] ?? ''));
        $course = (string)($fields[8] ?? 'General');
        $name = (string)($fields[0] ?? '');
        if (!preg_match('/^\d{6,20}$/', $matric) ||
            !in_array($action, ['checkin','checkout'], true) ||
            $name === '' || $course === '') {
            $invalid[] = basename($file) . ':' . ($lineNumber + 1);
            continue;
        }
        $key = $date . '|' . strtolower($course) . '|' . $matric . '|' . $action;
        if (isset($found[$key])) {
            $duplicate[] = basename($file) . ':' . ($lineNumber + 1);
            continue;
        }
        $found[$key] = true;
        $fingerprint = (string)($fields[3] ?? '');
        $rows[] = [
            'day'=>$date,'course'=>mb_substr($course,0,191),
            'matric'=>$matric,'action'=>$action,'name'=>mb_substr($name,0,255),
            'fp'=>$fingerprint !== '' ? hash('sha256',$fingerprint) : null,
            'ip'=>substr((string)($fields[4] ?? ''),0,45)
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
