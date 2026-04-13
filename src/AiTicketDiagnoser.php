<?php

require_once __DIR__ . '/../admin/runtime_storage.php';
require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/AiRulebook.php';

class AiTicketDiagnoser
{
  private static function loadCourseValidationState($course)
  {
    $course = trim((string)$course);
    $course = $course !== '' ? $course : 'General';

    $courseFile = admin_course_storage_migrate_file('course.json');
    $activeFile = admin_course_storage_migrate_file('active_course.json');

    $rows = [];
    if (file_exists($courseFile)) {
      $decoded = json_decode((string)@file_get_contents($courseFile), true);
      if (is_array($decoded)) {
        $rows = $decoded;
      }
    }

    $catalog = [];
    foreach ($rows as $row) {
      $name = trim((string)$row);
      if ($name === '') {
        continue;
      }
      $catalog[$name] = true;
    }
    if (empty($catalog)) {
      $catalog['General'] = true;
    } elseif (!isset($catalog['General'])) {
      $catalog['General'] = true;
    }

    $catalogNames = array_keys($catalog);
    $courseNormMap = [];
    foreach ($catalogNames as $catalogName) {
      $courseNormMap[strtolower($catalogName)] = $catalogName;
    }

    $requestedCourseNorm = strtolower($course);
    $courseExists = isset($courseNormMap[$requestedCourseNorm]);

    $activeCourse = 'General';
    if (file_exists($activeFile)) {
      $activeData = json_decode((string)@file_get_contents($activeFile), true);
      if (is_array($activeData)) {
        $candidate = trim((string)($activeData['course'] ?? ''));
        if ($candidate !== '') {
          $activeCourse = $candidate;
        }
      }
    }

    $activeCourseNorm = strtolower($activeCourse);
    if (!isset($courseNormMap[$activeCourseNorm])) {
      $activeCourse = 'General';
      $activeCourseNorm = 'general';
    }

    return [
      'requested_course' => $course,
      'exists' => $courseExists,
      'is_active' => ($requestedCourseNorm === $activeCourseNorm),
      'active_course' => $activeCourse,
      'catalog' => $catalogNames,
    ];
  }

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

    $fpMatch = false;
    $ipMatch = false;

    $checkinCount = 0;
    $checkoutCount = 0;
    $fraudFlags = [];
    $courseScopedEntryCount = 0;
    $sharedFingerprintMatrics = [];
    $sharedIpMatrics = [];
    $sharedFingerprintIpMatrics = [];

    foreach ($lines as $line) {
      $fields = array_map('trim', explode('|', (string)$line));
      if (!isset($fields[1], $fields[2])) {
        continue;
      }

      $lineMatric = (string)$fields[1];
      $lineAction = strtolower((string)$fields[2]);
      $lineFingerprint = isset($fields[3]) ? trim((string)$fields[3]) : '';
      $lineIp = isset($fields[4]) ? trim((string)$fields[4]) : '';

      $lineCourse = isset($fields[8]) ? strtolower(trim((string)$fields[8])) : 'general';
      if ($courseNorm !== '' && $lineCourse !== $courseNorm) {
        continue;
      }

      $courseScopedEntryCount++;

      if ($fingerprint !== '' && $lineFingerprint !== '' && $lineFingerprint === $fingerprint) {
        $fpMatch = true;
        if ($lineMatric !== '' && $lineMatric !== $matric) {
          $sharedFingerprintMatrics[$lineMatric] = true;
        }
      }

      if ($ip !== '' && $lineIp !== '' && $lineIp === $ip) {
        $ipMatch = true;
        if ($lineMatric !== '' && $lineMatric !== $matric) {
          $sharedIpMatrics[$lineMatric] = true;
        }
      }

      if (
        $fingerprint !== '' && $lineFingerprint !== '' && $lineFingerprint === $fingerprint
        && $ip !== '' && $lineIp !== '' && $lineIp === $ip
        && $lineMatric !== '' && $lineMatric !== $matric
      ) {
        $sharedFingerprintIpMatrics[$lineMatric] = true;
      }

      if ($matric !== '' && $lineMatric !== $matric) {
        continue;
      }

      if ($lineAction === 'checkin') {
        $checkinCount++;
      } elseif ($lineAction === 'checkout') {
        $checkoutCount++;
      }
    }

    if ($checkinCount > 1) {
      $fraudFlags[] = 'duplicate_checkin_sequence';
    }
    if ($checkoutCount > 1) {
      $fraudFlags[] = 'duplicate_checkout_sequence';
    }

    $sharedFingerprintMatrics = array_values(array_keys($sharedFingerprintMatrics));
    $sharedIpMatrics = array_values(array_keys($sharedIpMatrics));
    $sharedFingerprintIpMatrics = array_values(array_keys($sharedFingerprintIpMatrics));
    $deviceSharingRisk = !empty($sharedFingerprintMatrics) || !empty($sharedFingerprintIpMatrics);

    return [
      'date' => $date,
      'log_file' => $logFile,
      'course' => $course,
      'lines' => $lines,
      'courseScopedEntryCount' => $courseScopedEntryCount,
      'fpMatch' => $fpMatch,
      'ipMatch' => $ipMatch,
      'sharedFingerprintMatrics' => $sharedFingerprintMatrics,
      'sharedIpMatrics' => $sharedIpMatrics,
      'sharedFingerprintIpMatrics' => $sharedFingerprintIpMatrics,
      'deviceSharingRisk' => $deviceSharingRisk,
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
    $courseValidation = self::loadCourseValidationState($course);
    $logInfo = self::parseDailyLogStats($date, $matric, $fingerprint, $ip, $course);
    $revoked = self::loadRevoked();
    $revokedStatus = self::isRevoked($revoked, $fingerprint, $ip);
    $identityKeysPresent = ($fingerprint !== '' && $ip !== '');

    $issueType = $messageInfo['issue_type'];
    $classification = 'manual_review_required';
    $action = 'notify_and_resolve';
    $confidence = max(0.3, min(0.99, (float)$messageInfo['confidence']));
    $suggestedAdminAction = 'Review diagnostics and follow policy.';
    $reason = 'Default review path.';
    $hasCheckin = !empty($logInfo['checkinCount']);
    $hasCheckout = !empty($logInfo['checkoutCount']);

    $facts = [
      'course_exists' => !empty($courseValidation['exists']),
      'course_is_active' => !empty($courseValidation['is_active']),
      'identity_keys_present' => $identityKeysPresent,
      'device_sharing_risk' => !empty($logInfo['deviceSharingRisk']),
      'revoked' => (bool)$revokedStatus,
      'fp_match' => !empty($logInfo['fpMatch']),
      'ip_match' => !empty($logInfo['ipMatch']),
      'requested_action' => $requestedAction,
      'has_checkin' => $hasCheckin,
      'has_checkout' => $hasCheckout,
    ];

    $rulebookOutcome = class_exists('AiRulebook') ? AiRulebook::evaluate($facts) : [];
    $matchedRuleId = (string)($rulebookOutcome['matched_rule_id'] ?? '');
    $rulebookVersion = (string)($rulebookOutcome['rulebook_version'] ?? '');

    if (!empty($rulebookOutcome)) {
      $classification = (string)($rulebookOutcome['classification'] ?? $classification);
      $action = (string)($rulebookOutcome['action'] ?? $action);
      $confidence = isset($rulebookOutcome['confidence']) ? (float)$rulebookOutcome['confidence'] : $confidence;
      $suggestedAdminAction = (string)($rulebookOutcome['suggested_admin_action'] ?? $suggestedAdminAction);
      $reason = (string)($rulebookOutcome['reason'] ?? $reason);
    }

    if (!empty($rulebookOutcome)) {
      // Rulebook has first priority and fully defines classification/action for matched conditions.
    } elseif (empty($courseValidation['exists'])) {
      $classification = 'invalid_course_reference';
      $action = 'deny_and_review';
      $confidence = 0.99;
      $suggestedAdminAction = 'Requested course is not in course.json. Reject automated attendance write and ask student to submit ticket with a valid configured course.';
      $reason = 'Ticket references a course that does not exist in configured course catalog.';
    } elseif (empty($courseValidation['is_active'])) {
      $classification = 'inactive_course_reference';
      $action = 'deny_and_review';
      $confidence = 0.96;
      $suggestedAdminAction = sprintf('Requested course exists but is not currently active. Active course is "%s". Require admin/manual validation before any attendance write.', (string)($courseValidation['active_course'] ?? 'General'));
      $reason = 'Ticket course does not match currently active course for attendance session.';
    } elseif ($revokedStatus) {
      $classification = 'blocked_revoked_device';
      $action = 'deny_and_notify';
      $confidence = 0.99;
      $suggestedAdminAction = 'No override by AI. Admin-only re-enable flow if justified.';
      $reason = 'Fingerprint/IP/MAC appears in revoked list for this date/course context.';
    } elseif (!empty($logInfo['fraudFlags'])) {
      $classification = 'duplicate_or_fraudulent_sequence';
      $action = 'deny_and_notify';
      $confidence = 0.96;
      $suggestedAdminAction = 'Investigate repeated sequence before approving any manual changes.';
      $reason = 'Detected duplicate check-in/check-out sequence in same-day, same-course logs.';
    } elseif (!empty($logInfo['deviceSharingRisk'])) {
      $classification = 'policy_device_sharing_risk';
      $action = 'manual_review_only';
      $confidence = 0.97;
      $suggestedAdminAction = 'Policy risk: same device fingerprint/IP appears tied to another matric in this course today. Require manual verification; do not auto-fix attendance.';
      $reason = 'Same-day, same-course device signature overlaps with another matric.';
    } elseif ($requestedAction === 'checkin' && $hasCheckin) {
      $classification = 'duplicate_submission_attempt';
      $action = 'deny_and_notify';
      $confidence = 0.93;
      $suggestedAdminAction = 'Check-in already exists for this matric and course today. Deny duplicate check-in.';
      $reason = 'Requested check-in already recorded in same-day, same-course logs.';
    } elseif ($requestedAction === 'checkout' && $hasCheckout) {
      $classification = 'duplicate_submission_attempt';
      $action = 'deny_and_notify';
      $confidence = 0.93;
      $suggestedAdminAction = 'Checkout already exists for this matric and course today. Deny duplicate checkout.';
      $reason = 'Requested checkout already recorded in same-day, same-course logs.';
    } elseif ($requestedAction === 'checkout' && !$hasCheckin) {
      $classification = 'manual_review_required';
      $action = 'deny_and_review';
      $confidence = 0.95;
      $suggestedAdminAction = 'Checkout requested without prior check-in for this course today. Keep manual review and deny auto-checkout.';
      $reason = 'Course-scoped dependency failed: no check-in exists for requested checkout.';
    } elseif ($requestedAction === '' && !empty($logInfo['attendanceCycleComplete'])) {
      $classification = 'duplicate_submission_attempt';
      $action = 'deny_and_notify';
      $confidence = 0.91;
      $suggestedAdminAction = 'Attendance cycle already completed for this course today. No further attendance write should be added.';
      $reason = 'Check-in and checkout already exist in same-day, same-course logs.';
    } elseif ($logInfo['fpMatch'] && $logInfo['ipMatch']) {
      $classification = 'legitimate_session_issue';
      $action = 'auto_fix_add_attendance';
      $confidence = 0.95;
      $suggestedAdminAction = 'No immediate action required unless student reports repeated failure. Auto-fix is safe under course-scoped guardrails.';
      $reason = 'Fingerprint and IP match same-day, same-course logs and requested action is policy-safe.';
    } elseif ($logInfo['fpMatch'] && !$logInfo['ipMatch']) {
      $classification = 'network_ip_rotation';
      $action = 'guide_and_admin_review';
      $confidence = 0.74;
      $suggestedAdminAction = 'Review if identity appears valid despite IP change for this course and date.';
      $reason = 'Fingerprint matched but IP mismatch indicates network change within same-day, same-course context.';
    } elseif (!$logInfo['fpMatch'] && !$logInfo['ipMatch']) {
      $classification = 'new_or_suspicious_device';
      $action = 'verify_and_admin_review';
      $confidence = 0.68;
      $suggestedAdminAction = 'Require identity/device verification before manual attendance for this course.';
      $reason = 'No fingerprint or IP match in same-day, same-course logs.';
    } elseif ($logInfo['attendanceAlreadyRecorded']) {
      $classification = 'manual_review_required';
      $action = 'guide_and_admin_review';
      $confidence = 0.72;
      $suggestedAdminAction = 'Attendance exists for this course today. Confirm whether student needs checkout guidance or duplicate closure.';
      $reason = 'Attendance activity exists in same-day, same-course logs but requested action was not explicit.';
    }

    return [
      'ticket_timestamp' => (string)($ticket['timestamp'] ?? ''),
      'course' => $course,
      'course_exists' => !empty($courseValidation['exists']),
      'course_is_active' => !empty($courseValidation['is_active']),
      'active_course' => (string)($courseValidation['active_course'] ?? 'General'),
      'requested_action' => $requestedAction,
      'identity_keys_present' => $identityKeysPresent,
      'issue_type' => $issueType,
      'classification' => $classification,
      'action' => $action,
      'reason' => $reason,
      'rulebook_applied' => !empty($rulebookOutcome),
      'matched_rule_id' => $matchedRuleId,
      'rulebook_version' => $rulebookVersion,
      'confidence' => $confidence,
      'fpMatch' => (bool)$logInfo['fpMatch'],
      'ipMatch' => (bool)$logInfo['ipMatch'],
      'revoked' => (bool)$revokedStatus,
      'attendanceAlreadyRecorded' => (bool)$logInfo['attendanceAlreadyRecorded'],
      'attendanceCycleComplete' => (bool)$logInfo['attendanceCycleComplete'],
      'courseScopedEntryCount' => (int)($logInfo['courseScopedEntryCount'] ?? 0),
      'deviceSharingRisk' => !empty($logInfo['deviceSharingRisk']),
      'sharedFingerprintMatrics' => $logInfo['sharedFingerprintMatrics'] ?? [],
      'sharedIpMatrics' => $logInfo['sharedIpMatrics'] ?? [],
      'sharedFingerprintIpMatrics' => $logInfo['sharedFingerprintIpMatrics'] ?? [],
      'checkinCount' => (int)$logInfo['checkinCount'],
      'checkoutCount' => (int)$logInfo['checkoutCount'],
      'fraudFlags' => $logInfo['fraudFlags'],
      'suggested_admin_action' => $suggestedAdminAction,
      'diagnosed_at' => date('c')
    ];
  }
}
