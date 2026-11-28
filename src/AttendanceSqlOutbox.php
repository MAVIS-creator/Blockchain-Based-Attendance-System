<?php
declare(strict_types=1);

const BBAS_ATTENDANCE_OUTBOX_MAX_ATTEMPTS = 5;
const BBAS_ATTENDANCE_OUTBOX_LEASE_SECONDS = 120;

/**
 * Claim one committed attendance event for projection. External work must run
 * after this transaction commits and use event_id as its idempotency key.
 *
 * @return ?array{event_id:string,attempts:int,token:string}
 */
function bbas_attendance_outbox_claim(PDO $db): ?array
{
    $db->beginTransaction();
    try {
        $expire = $db->prepare("UPDATE bbas_attendance_outbox
            SET status='dead', lease_token=NULL, lease_until=NULL
            WHERE status='processing' AND lease_until <= UTC_TIMESTAMP(6) AND attempts >= ?");
        $expire->execute([BBAS_ATTENDANCE_OUTBOX_MAX_ATTEMPTS]);
        $select = $db->prepare("SELECT event_id, attempts FROM bbas_attendance_outbox
            WHERE attempts < ? AND ((status='pending' AND available_at <= UTC_TIMESTAMP(6))
                OR (status='processing' AND lease_until <= UTC_TIMESTAMP(6)))
            ORDER BY available_at, event_id LIMIT 1 FOR UPDATE");
        $select->execute([BBAS_ATTENDANCE_OUTBOX_MAX_ATTEMPTS]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            $db->commit();
            return null;
        }
        $token = bin2hex(random_bytes(16));
        $claim = $db->prepare("UPDATE bbas_attendance_outbox
            SET status='processing', attempts=attempts+1, lease_token=?,
                lease_until=TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6))
            WHERE event_id=?");
        $claim->execute([$token, BBAS_ATTENDANCE_OUTBOX_LEASE_SECONDS, $row['event_id']]);
        $db->commit();
        return ['event_id'=>(string)$row['event_id'], 'attempts'=>(int)$row['attempts'] + 1, 'token'=>$token];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Apply completion or retry only while this exact lease is current.
 * Return false when an expired or superseded worker submits an acknowledgement.
 */
function bbas_attendance_outbox_finish(PDO $db, string $eventId, string $token, bool $success): bool
{
    if (!preg_match('/\A[1-9][0-9]{0,19}\z/', $eventId) || !preg_match('/\A[0-9a-f]{32}\z/', $token)) {
        return false;
    }
    $update = $db->prepare("UPDATE bbas_attendance_outbox
        SET status=CASE WHEN ?=1 THEN 'done' WHEN attempts>=? THEN 'dead' ELSE 'pending' END,
            available_at=TIMESTAMPADD(SECOND, CASE WHEN ?=1 THEN 0 ELSE 5 * POW(2,attempts) END, UTC_TIMESTAMP(6)),
            lease_token=NULL, lease_until=NULL
        WHERE event_id=? AND status='processing' AND lease_token=? AND lease_until>UTC_TIMESTAMP(6)");
    $update->execute([(int)$success, BBAS_ATTENDANCE_OUTBOX_MAX_ATTEMPTS, (int)$success, $eventId, $token]);
    return $update->rowCount() === 1;
}
