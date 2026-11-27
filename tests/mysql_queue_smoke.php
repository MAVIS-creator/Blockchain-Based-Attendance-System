<?php
declare(strict_types=1);
putenv('BBAS_AI_QUEUE_DRIVER=mysql');
putenv('BBAS_SQL_DSN=mysql:host=127.0.0.1;port=3306;dbname=bbas_ci;charset=utf8mb4');
putenv('BBAS_SQL_USER=bbas_ci');
putenv('BBAS_SQL_PASSWORD=ci_test_only');
require_once __DIR__ . '/../src/AiSqlJobQueue.php';
$db = bbas_ai_db();
$sql = file_get_contents(__DIR__ . '/../db/schema.mysql.sql');
foreach (explode(';', $sql) as $part) {
    if (trim($part) !== '') $db->exec($part);
}
$ticket = 'ci-' . bin2hex(random_bytes(6));
if (!bbas_ai_enqueue_mysql($ticket)) exit(1);
$claimed = bbas_ai_claim_mysql();
if (!$claimed || $claimed['timestamp'] !== $ticket || $claimed['attempts'] !== 1) {
    throw new RuntimeException('SQL ticket claim mismatch');
}
bbas_ai_finish_mysql($claimed, false, 'transient-test');
$check = $db->prepare('SELECT status,attempts FROM bbas_ai_jobs WHERE ticket_timestamp=?');
$check->execute([$ticket]);
$row = $check->fetch();
if (!$row || $row['status'] !== 'pending' || (int)$row['attempts'] !== 1) {
    throw new RuntimeException('SQL ticket retry state invalid');
}
$db->prepare('UPDATE bbas_ai_jobs SET available_at=UTC_TIMESTAMP(6) WHERE ticket_timestamp=?')->execute([$ticket]);
$claimed = bbas_ai_claim_mysql();
if (!$claimed || $claimed['attempts'] !== 2) {
    throw new RuntimeException('SQL ticket lease retry missing');
}
bbas_ai_finish_mysql($claimed, true);
$check->execute([$ticket]);
$row = $check->fetch();
if (!$row || $row['status'] !== 'done') throw new RuntimeException('SQL ticket acknowledgement missing');

if ($db->query('SELECT @@session.time_zone')->fetchColumn() !== '+00:00') {
    throw new RuntimeException('AI queue connection must use UTC');
}
$crashedTicket = $ticket . '-crash';
bbas_ai_enqueue_mysql($crashedTicket);
$firstWorker = bbas_ai_claim_mysql();
$expire = $db->prepare('UPDATE bbas_ai_jobs
    SET lease_until=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND), attempts=? WHERE ticket_timestamp=?');
$expire->execute([BBAS_AI_MAX_ATTEMPTS - 1, $crashedTicket]);
$secondWorker = bbas_ai_claim_mysql();
if (!$secondWorker || $secondWorker['attempts'] !== BBAS_AI_MAX_ATTEMPTS ||
    $firstWorker['token'] === $secondWorker['token']) {
    throw new RuntimeException('Expired worker lease was not reclaimed');
}
bbas_ai_finish_mysql($firstWorker, true);
$check->execute([$crashedTicket]);
if ($check->fetch()['status'] !== 'processing') {
    throw new RuntimeException('Stale worker acknowledged a reclaimed job');
}
$expire->execute([BBAS_AI_MAX_ATTEMPTS, $crashedTicket]);
if (bbas_ai_claim_mysql() !== null) {
    throw new RuntimeException('Exhausted worker lease was reclaimed');
}
$check->execute([$crashedTicket]);
if ($check->fetch()['status'] !== 'dead') {
    throw new RuntimeException('Exhausted worker lease did not reach the dead-letter state');
}

$day='2026-11-18';$course='CI Testing';$matric='1234567890';
$insert=$db->prepare("INSERT INTO bbas_attendance_events
(event_day,course,matric,action,name,source) VALUES (?,?,?,?,?,'ci_test')");
$insert->execute([$day,$course,$matric,'checkin','Test Student']);
$blocked=false;
try{$insert->execute([$day,$course,$matric,'checkin','Test Student']);}
catch(PDOException $e){$blocked=$e->getCode()==='23000';}
if (!$blocked) throw new RuntimeException('Attendance uniqueness constraint failed');
echo "MariaDB AI job leases and attendance uniqueness verified\n";
