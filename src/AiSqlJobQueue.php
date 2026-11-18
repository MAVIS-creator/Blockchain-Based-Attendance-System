<?php
declare(strict_types=1);

require_once __DIR__ . '/../env_helpers.php';

function bbas_ai_queue_driver(): string
{
    $value = strtolower(trim((string)app_env_value('BBAS_AI_QUEUE_DRIVER', 'file')));
    return $value === 'mysql' ? 'mysql' : 'file';
}

function bbas_ai_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = trim((string)app_env_value('BBAS_SQL_DSN', ''));
    if ($dsn === '' || !str_starts_with($dsn, 'mysql:')) {
        throw new RuntimeException('BBAS_SQL_DSN must be a MariaDB/MySQL PDO DSN');
    }
    $user = (string)app_env_value('BBAS_SQL_USER', '');
    $pass = (string)app_env_value('BBAS_SQL_PASSWORD', '');
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 4,
    ]);
    return $pdo;
}

function bbas_ai_enqueue_mysql(string $timestamp): bool
{
    if ($timestamp === '') {
        return false;
    }
    $stmt = bbas_ai_db()->prepare(
        "INSERT IGNORE INTO bbas_ai_jobs (ticket_timestamp, status) VALUES (:stamp, 'pending')"
    );
    $stmt->execute(['stamp' => $timestamp]);
    return true; // duplicate ticket already queued/handled is not an error
}

/** Transactional lease claim. The next worker can reclaim expired leases. */
function bbas_ai_claim_mysql(): ?array
{
    $db = bbas_ai_db();
    $db->beginTransaction();
    try {
        $row = $db->query("SELECT id, ticket_timestamp, attempts FROM bbas_ai_jobs
            WHERE (status='pending' AND available_at<=UTC_TIMESTAMP(6))
               OR (status='processing' AND lease_until<UTC_TIMESTAMP(6))
            ORDER BY available_at ASC, id ASC LIMIT 1 FOR UPDATE")->fetch();
        if (!$row) {
            $db->commit();
            return null;
        }
        $token = bin2hex(random_bytes(16));
        $stmt = $db->prepare("UPDATE bbas_ai_jobs
            SET status='processing', attempts=attempts+1, lease_token=:token,
                lease_until=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 180 SECOND)
            WHERE id=:id");
        $stmt->execute(['token' => $token, 'id' => $row['id']]);
        $db->commit();
        return ['id' => (int)$row['id'], 'timestamp' => $row['ticket_timestamp'],
            'attempts' => (int)$row['attempts'] + 1, 'token' => $token];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function bbas_ai_finish_mysql(array $job, bool $ok, string $error = ''): void
{
    $tries = (int)$job['attempts'];
    $status = $ok ? 'done' : ($tries >= 5 ? 'dead' : 'pending');
    $delay = min(300, 5 * (2 ** min($tries, 6)));
    $stmt = bbas_ai_db()->prepare("UPDATE bbas_ai_jobs
        SET status=:state, lease_token=NULL, lease_until=NULL, last_error=:error,
        available_at=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL :delay SECOND)
        WHERE id=:id AND status='processing' AND lease_token=:token");
    $stmt->execute([
        'state' => $status, 'error' => $ok ? null : substr($error, 0, 255),
        'delay' => $ok ? 0 : $delay, 'id' => $job['id'], 'token' => $job['token']
    ]);
}
