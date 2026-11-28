<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/AttendanceSqlStore.php';
require_once __DIR__ . '/../src/AiSqlJobQueue.php';

function demand(bool $valid, string $message): void
{
    if (!$valid) throw new RuntimeException($message);
}

function reject(callable $operation, string $expected): void
{
    try {
        $operation();
    } catch (DomainException $error) {
        demand($error->getMessage() === $expected, $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected ' . $expected);
}

$endpoint = bbas_ai_db();
$migration = file_get_contents(__DIR__ . '/../db/attendance-authority.mysql.sql');
foreach (explode(';', $migration) as $statement) {
    if (trim($statement) !== '') $endpoint->exec($statement);
}
$tables = ['bbas_attendance_events'=>2, 'bbas_attendance_outbox'=>2,
    'bbas_attendance_receipts'=>2, 'bbas_attendance_device_claims'=>4];
$before = [];
foreach ($tables as $table => $added) {
    $before[$table] = (int)$endpoint->query("SELECT COUNT(*) FROM $table")->fetchColumn();
}
$event = ['day'=>'2030-04-17','course'=>'SQL CI ' . bin2hex(random_bytes(4)),'matric'=>(string)random_int(10000000, 99999999),'action'=>'checkin',
    'name'=>'Synthetic Student','fingerprint_hash'=>str_repeat('a', 64),'ip'=>'192.0.2.1',
    'device'=>'test-device','token'=>'test-token'];
$checkout = array_replace($event, ['action'=>'checkout']);
$record = static fn(array $entry, string $key): array => bbas_record_sql_attendance($endpoint, $entry, $key);
reject(fn() => $record($checkout, 'sql-checkout-synthetic'), 'NO_CHECKIN');
$checkin = $record($event, 'sql-checkin-synthetic');
demand(!$checkin['replayed'], 'New check-in incorrectly reported as replay');
demand($record(array_replace($event, ['ip'=>'192.0.2.2']), 'sql-checkin-synthetic') ===
    ['id'=>$checkin['id'], 'replayed'=>true], 'Retry did not return the same receipt');
reject(fn() => $record(array_replace($event, ['name'=>'Different Student']), 'sql-checkin-synthetic'), 'IDEMPOTENCY_KEY_CONFLICT');
reject(fn() => $record($event, 'sql-duplicate-event-key'), 'DUPLICATE_ATTENDANCE');
reject(fn() => $record(array_replace($event, ['matric'=>'654321']), 'sql-duplicate-device-key'), 'DUPLICATE_DEVICE');
reject(fn() => $record(array_replace($event, ['matric'=>'654321','device'=>'other-device']), 'sql-duplicate-token-key'), 'DUPLICATE_DEVICE');
demand($record($checkout, 'sql-checkout-synthetic')['id'] !== $checkin['id'], 'Checkout did not persist');
foreach ($tables as $table => $added) {
    demand((int)$endpoint->query("SELECT COUNT(*) FROM $table")->fetchColumn() === $before[$table] + $added,
        "$table count is inconsistent");
}
echo "Transactional event, receipt, device uniqueness, checkout and outbox checks passed.\n";
