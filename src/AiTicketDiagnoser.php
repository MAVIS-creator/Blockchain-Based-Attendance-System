<?php

require_once __DIR__ . '/../admin/runtime_storage.php';
require_once __DIR__ . '/../storage_helpers.php';

class AiTicketDiagnoser
{
    public static function checkLogMatch(array $logLines, $needle, $index)
    {
        $needle = trim((string)$needle);
        if ($needle === '') {
            return false;
        }

        foreach ($logLines as $line) {
            $fields = array_map('trim', explode('|', (string)$line));
            if (isset($fields[$index]) && $fields[$index] === $needle) {
                return true;
            }
        }

        return false;
    }

    public static function parseDailyLogStats($date, $matric, $fingerprint, $ip, $course = '')
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date) ? $date : date('Y-m-d');
        $logFile = app_storage_file("logs/{$date}.log");
        $lines = file_exists($logFile)
            ? (@file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
            : [];

        $matric = trim((string)$matric);
        $fingerprint = trim((string)$fingerprint);
        $ip = trim((string)$ip);
        $course = trim((string)$course);
        $courseNorm = strtolower($course);

        $fpMatch = self::checkLogMatch($lines, $fingerprint, 3);
        $ipMatch = self::checkLogMatch($lines, $ip, 4);

        $checkinCount = 0;
        $checkoutCount = 0;
        $fraudFlags = [];

        foreach ($lines as $line) {
            $fields = array_map('trim', explode('|', (string)$line));
            if (!isset($fields[1], $fields[2])) {
                continue;
            }
            if ($matric !== '' && $fields[1] !== $matric) {
                continue;
            }

            $lineCourse = isset($fields[8]) ? strtolower(trim((string)$fields[8])) : 'general';
            if ($courseNorm !== '' && $lineCourse !== $courseNorm) {
                continue;
            }

            $action = strtolower((string)$fields[2]);
            if ($action === 'checkin') {
                $checkinCount++;
            } elseif ($action === 'checkout') {
                $checkoutCount++;
            }
        }

        if ($checkinCount > 1) {
            $fraudFlags[] = 'duplicate_checkin_sequence';
        }
        if ($checkoutCount > 1) {
            $fraudFlags[] = 'duplicate_checkout_sequence';
        }

        return [
            'date' => $date,
            'log_file' => $logFile,
            'course' => $course,
            'lines' => $lines,
            'fpMatch' => $fpMatch,
            'ipMatch' => $ipMatch,
            'checkinCount' => $checkinCount,
            'checkoutCount' => $checkoutCount,
            'attendanceAlreadyRecorded' => ($checkinCount > 0 || $checkoutCount > 0),
            'attendanceCycleComplete' => ($checkinCount > 0 && $checkoutCount > 0),
            'fraudFlags' => $fraudFlags,
        ];
    }

    public static function classifyMessage($message)
    {
        $message = trim((string)$message);
        $lower = strtolower($message);

        $patterns = [
            'duplicate_submission_attempt' => '/(duplicate|already|again|twice|double).*(checkin|checkout|attendance)|already\s+marked/i',
            'attendance_submission_failure' => '/(can\'t|cant|cannot|failed|fail|error|unable|won\'t).*(submit|mark|attendance|check[- ]?in|check[- ]?out)/i',
            'device_change_or_browser_mismatch' => '/(new\s+device|different\s+device|new\s+browser|another\s+browser|changed\s+browser|changed\s+phone|changed\s+laptop)/i',
            'network_or_ip_issue' => '/(ip|network|wifi|vpn|cellular|internet|connection)/i',
            'revoked_or_blocked_complaint' => '/(blocked|revoked|ban|banned|deny|denied|not allowed)/i',
            'session_or_token_expired' => '/(session|token|expired|timeout|timed\s*out)/i',
        ];

        foreach ($patterns as $type => $regex) {
            if (preg_match($regex, $message)) {
                return [
                    'issue_type' => $type,
                    'confidence' => 0.88,
                    'message_excerpt' => mb_substr($lower, 0, 180)
                ];
            }
        }

        return [
            'issue_type' => 'general_system_complaint',
            'confidence' => 0.45,
            'message_excerpt' => mb_substr($lower, 0, 180)
        ];
    }

    public static function loadRevoked()
    {
        $revokedFile = admin_storage_migrate_file('revoked.json', app_storage_file('revoked.json'));
        $raw = @file_get_contents($revokedFile);
        $revoked = json_decode((string)$raw, true);
        if (!is_array($revoked)) {
            return ['tokens' => [], 'ips' => [], 'macs' => []];
        }

        foreach (['tokens', 'ips', 'macs'] as $k) {
            if (!isset($revoked[$k]) || !is_array($revoked[$k])) {
                $revoked[$k] = [];
            }
        }

        return $revoked;
    }

    private static function revokedHasKey($bucket, $key)
    {
        if ($key === '' || !is_array($bucket)) {
            return false;
        }

        if (array_key_exists($key, $bucket)) {
            return true;
        }

        return in_array($key, $bucket, true);
    }

    public static function isRevoked(array $revoked, $fingerprint, $ip, $mac = '')
    {
        $fingerprint = trim((string)$fingerprint);
        $ip = trim((string)$ip);
        $mac = trim((string)$mac);

        return self::revokedHasKey($revoked['tokens'] ?? [], $fingerprint)
            || self::revokedHasKey($revoked['ips'] ?? [], $ip)
            || ($mac !== '' && self::revokedHasKey($revoked['macs'] ?? [], $mac));
    }

    public static function diagnose(array $ticket)
    {
        $matric = trim((string)($ticket['matric'] ?? ''));
        $fingerprint = trim((string)($ticket['fingerprint'] ?? ''));
        $ip = trim((string)($ticket['ip'] ?? ''));
        $message = (string)($ticket['message'] ?? '');
        $course = trim((string)($ticket['course'] ?? 'General'));
        $course = $course !== '' ? $course : 'General';
        $requestedAction = strtolower(trim((string)($ticket['requested_action'] ?? '')));
        if (!in_array($requestedAction, ['checkin', 'checkout'], true)) {
            $requestedAction = '';
        }
        $date = date('Y-m-d');

        $messageInfo = self::classifyMessage($message);
        $logInfo = self::parseDailyLogStats($date, $matric, $fingerprint, $ip, $course);
        $revoked = self::loadRevoked();
        $revokedStatus = self::isRevoked($revoked, $fingerprint, $ip);

        $issueType = $messageInfo['issue_type'];
        $classification = 'manual_review_required';
        $action = 'notify_and_resolve';
        $confidence = max(0.3, min(0.99, (float)$messageInfo['confidence']));
        $suggestedAdminAction = 'Review diagnostics and follow policy.';
        $reason = 'Default review path.';

        if ($revokedStatus) {
            $classification = 'blocked_revoked_device';
            $action = 'deny_and_notify';
            $confidence = 0.99;
            $suggestedAdminAction = 'No override by AI. Admin-only re-enable flow if justified.';
            $reason = 'Fingerprint/IP/MAC appears in revoked list.';
        } elseif (!empty($logInfo['fraudFlags'])) {
            $classification = 'duplicate_or_fraudulent_sequence';
            $action = 'deny_and_notify';
            $confidence = 0.96;
            $suggestedAdminAction = 'Investigate repeated sequence before approving any manual changes.';
            $reason = 'Detected duplicate check-in/check-out sequence in daily log.';
        } elseif ($logInfo['attendanceAlreadyRecorded']) {
            $classification = 'duplicate_submission_attempt';
            $action = 'deny_and_notify';
            $confidence = 0.93;
            $suggestedAdminAction = 'Attendance already present; no additional submission should be added.';
            $reason = 'Matric already has attendance activity today.';
        } elseif ($logInfo['fpMatch'] && $logInfo['ipMatch']) {
            $classification = 'legitimate_session_issue';
            $action = 'auto_fix_add_attendance';
            $confidence = 0.95;
            $suggestedAdminAction = 'No immediate action required unless student reports repeated failure.';
            $reason = 'Fingerprint and IP match logs and attendance not yet recorded.';
        } elseif ($logInfo['fpMatch'] && !$logInfo['ipMatch']) {
            $classification = 'network_ip_rotation';
            $action = 'guide_and_admin_review';
            $confidence = 0.74;
            $suggestedAdminAction = 'Review if identity appears valid despite IP change.';
            $reason = 'Fingerprint matched but IP mismatch indicates network change.';
        } elseif (!$logInfo['fpMatch'] && !$logInfo['ipMatch']) {
            $classification = 'new_or_suspicious_device';
            $action = 'verify_and_admin_review';
            $confidence = 0.68;
            $suggestedAdminAction = 'Require identity/device verification before manual attendance.';
            $reason = 'No fingerprint or IP match in daily logs.';
        }

        return [
            'ticket_timestamp' => (string)($ticket['timestamp'] ?? ''),
            'course' => $course,
            'requested_action' => $requestedAction,
            'issue_type' => $issueType,
            'classification' => $classification,
            'action' => $action,
            'reason' => $reason,
            'confidence' => $confidence,
            'fpMatch' => (bool)$logInfo['fpMatch'],
            'ipMatch' => (bool)$logInfo['ipMatch'],
            'revoked' => (bool)$revokedStatus,
            'attendanceAlreadyRecorded' => (bool)$logInfo['attendanceAlreadyRecorded'],
            'attendanceCycleComplete' => (bool)$logInfo['attendanceCycleComplete'],
            'checkinCount' => (int)$logInfo['checkinCount'],
            'checkoutCount' => (int)$logInfo['checkoutCount'],
            'fraudFlags' => $logInfo['fraudFlags'],
            'suggested_admin_action' => $suggestedAdminAction,
            'diagnosed_at' => date('c')
        ];
    }
}
