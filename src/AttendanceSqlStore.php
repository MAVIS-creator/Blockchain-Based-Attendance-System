<?php
declare(strict_types=1);

/**
 * Persist a server-validated attendance event and its outbox job together.
 * Identity tokens and idempotency keys are represented only by hashes in SQL.
 * The caller must authorize the student, course, attendance window and device.
 *
 * @param array{day:string,course:string,matric:string,action:string,name:string,fingerprint_hash:?string,ip:?string,device:string,token:string} $event
 * @return array{id:string,replayed:bool}
 */
function bbas_record_sql_attendance(PDO $db, array $event, string $requestKey): array
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $event['day']);
    if ($date === false || $date->format('Y-m-d') !== $event['day'] ||
        !preg_match('/\A\d{6,20}\z/', $event['matric']) ||
        !in_array($event['action'], ['checkin', 'checkout'], true) ||
        !preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', $requestKey) ||
        trim($event['course']) === '' || mb_strlen($event['course']) > 191 ||
        trim($event['name']) === '' || mb_strlen($event['name']) > 255) {
        throw new InvalidArgumentException('Invalid validated attendance event');
    }
    $eventKey = [$event['day'], $event['course'], $event['matric'], $event['action']];
    $deviceHash = $event['device'] !== '' ? hash('sha256', $event['device']) : null;
    $tokenHash = $event['token'] !== '' ? hash('sha256', $event['token']) : null;
    $receiptKey = hash('sha256', $event['matric'] . "\0" . $requestKey);
    // IP may change while a student is retrying after a network interruption.
    $requestHash = hash('sha256', json_encode([
        ...$eventKey, $event['name'], $event['fingerprint_hash'], $deviceHash, $tokenHash,
    ], JSON_THROW_ON_ERROR));

    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO bbas_attendance_receipts (receipt_key,request_hash)
            VALUES (UNHEX(?),UNHEX(?)) ON DUPLICATE KEY UPDATE receipt_key=receipt_key')
            ->execute([$receiptKey, $requestHash]);
        $lookup = $db->prepare('SELECT HEX(request_hash) AS request_hash,event_id
            FROM bbas_attendance_receipts WHERE receipt_key=UNHEX(?) FOR UPDATE');
        $lookup->execute([$receiptKey]);
        $receipt = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!hash_equals($requestHash, strtolower($receipt['request_hash']))) {
            throw new DomainException('IDEMPOTENCY_KEY_CONFLICT');
        }
        if ($receipt['event_id'] !== null) {
            $db->commit();
            return ['id' => (string)$receipt['event_id'], 'replayed' => true];
        }

        if ($event['action'] === 'checkout') {
            $checkin = $db->prepare("SELECT id FROM bbas_attendance_events
                WHERE event_day=? AND course=? AND matric=? AND action='checkin' FOR UPDATE");
            $checkin->execute(array_slice($eventKey, 0, 3));
            if ($checkin->fetchColumn() === false) {
                throw new DomainException('NO_CHECKIN');
            }
        }

        try {
            $db->prepare('INSERT INTO bbas_attendance_events
                (event_day,course,matric,action,name,fingerprint_hash,client_ip)
                VALUES (?,?,?,?,?,?,?)')->execute([
                    ...$eventKey, $event['name'], $event['fingerprint_hash'], $event['ip'],
                ]);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            throw new DomainException('DUPLICATE_ATTENDANCE', 0, $e);
        }
        $eventId = $db->lastInsertId();

        $claim = $db->prepare('INSERT INTO bbas_attendance_device_claims
            (event_day,course,action,identity_kind,identity_hash,event_id)
            VALUES (?,?,?,?,UNHEX(?),?)');
        foreach (['device' => $deviceHash, 'token' => $tokenHash] as $kind => $hash) {
            if ($hash === null) continue;
            try {
                $claim->execute([$event['day'], $event['course'], $event['action'], $kind, $hash, $eventId]);
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
                throw new DomainException('DUPLICATE_DEVICE', 0, $e);
            }
        }

        $db->prepare('INSERT INTO bbas_attendance_outbox (event_id) VALUES (?)')->execute([$eventId]);
        $db->prepare('UPDATE bbas_attendance_receipts SET event_id=? WHERE receipt_key=UNHEX(?)')
            ->execute([$eventId, $receiptKey]);
        $db->commit();
        return ['id' => $eventId, 'replayed' => false];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
