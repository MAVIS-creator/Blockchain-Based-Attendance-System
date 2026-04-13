<?php

require_once __DIR__ . '/../admin/runtime_storage.php';

class AiAnnouncementService
{
  public static function buildStudentSafeMessage($classification, array $meta = [])
  {
    $classification = strtolower(trim((string)$classification));
    $course = trim((string)($meta['course'] ?? ''));
    $requestedAction = strtolower(trim((string)($meta['requested_action'] ?? '')));
    $activeCourse = trim((string)($meta['active_course'] ?? ''));
    $ticketMessage = trim((string)($meta['ticket_message'] ?? ''));

    $courseLabel = $course !== '' ? $course : 'this course';
    $activeCourseLabel = $activeCourse !== '' ? $activeCourse : 'the current attendance session';
    $actionLabel = $requestedAction === 'checkout'
      ? 'check-out'
      : ($requestedAction === 'checkin' ? 'check-in' : 'attendance');

    switch ($classification) {
      case 'inactive_course_reference':
        return sprintf(
          'Your %s request for %s cannot continue because that course is not active for attendance right now. Please contact admin support to confirm the active course before retrying.',
          $actionLabel,
          $courseLabel
        );

      case 'invalid_course_reference':
        return sprintf(
          'We could not match %s to a valid course in the attendance system. Please check the course name and contact admin support if you still need help.',
          $courseLabel
        );

      case 'blocked_revoked_device':
        return 'This device is currently blocked for attendance access. Please contact admin support for identity verification before retrying.';

      case 'fingerprint_conflict_rig_attempt':
        return 'We detected a device identity conflict on this request. Admin support needs to verify your identity before attendance can continue.';

      case 'policy_device_sharing_risk':
        return sprintf(
          'Your %s request for %s is under review because this device was flagged for a policy check. Please wait for admin verification.',
          $actionLabel,
          $courseLabel
        );

      case 'duplicate_submission_attempt':
      case 'duplicate_or_fraudulent_sequence':
        return sprintf(
          'An attendance record for %s appears to exist already for today. If you still believe there is an issue, contact admin support.',
          $courseLabel
        );

      case 'legitimate_session_issue':
        return sprintf(
          'We received your support ticket about %s for %s and we are checking it now. Please refresh shortly and stay on the same browser session.',
          $actionLabel,
          $courseLabel
        );

      case 'network_ip_rotation':
        return sprintf(
          'Your support ticket suggests a network change affected your %s request for %s. Please stay on one network and retry, or wait for admin review.',
          $actionLabel,
          $courseLabel
        );

      case 'new_or_suspicious_device':
        return sprintf(
          'Your support ticket for %s was flagged because this device looks new or unverified. Please contact admin support for a quick identity check before retrying.',
          $courseLabel
        );

      case 'manual_review_required':
        if ($ticketMessage !== '') {
          return sprintf(
            'We received your support ticket about %s. Admin support is reviewing it and will guide the next step for your attendance request.',
            $courseLabel
          );
        }
        return 'We received your support ticket and admin support is reviewing it. Please wait for the next update on this device.';

      default:
        return sprintf(
          'We received your support ticket about %s for %s and it is under review. Please wait for the next update on this device.',
          $actionLabel,
          $courseLabel
        );
    }
  }

  public static function normalizeStudentTargetedMessage($message, $classification = '', array $meta = [])
  {
    $message = trim((string)$message);
    $message = preg_replace('/\s+/', ' ', $message ?? '');
    $message = trim((string)$message, " \t\n\r\0\x0B\"'");

    $classification = strtolower(trim((string)$classification));
    $fallback = self::buildStudentSafeMessage($classification, $meta);
    $course = trim((string)($meta['course'] ?? ''));
    $requestedAction = strtolower(trim((string)($meta['requested_action'] ?? '')));

    if ($message === '') {
      return $fallback;
    }

    $adminOnlyPatterns = [
      '/\badmin\s+sidebar\b/i',
      '/\bdashboard\b/i',
      '/\bindex\.php\?page=/i',
      '/\bset\s+active\s+course\b/i',
      '/\bfollow\s+these\s+steps\b/i',
      '/\bclick\s+on\b/i',
      '/\bselect\s+the\b/i',
      '/\bsave\s+the\s+changes\b/i',
      '/\bgo\s+to\s+the\b/i',
      '/\bstep\s*\d+\b/i',
      '/\boption\s+in\s+the\s+admin\b/i',
      '/\bplease\s+go\s+to\b/i',
    ];

    foreach ($adminOnlyPatterns as $pattern) {
      if (preg_match($pattern, $message)) {
        return $fallback;
      }
    }

    if (function_exists('mb_strlen') ? mb_strlen($message) > 260 : strlen($message) > 260) {
      return $fallback;
    }

    $mustReferenceIssue = in_array($classification, [
      'inactive_course_reference',
      'invalid_course_reference',
      'legitimate_session_issue',
      'network_ip_rotation',
      'policy_device_sharing_risk',
    ], true);
    if ($mustReferenceIssue) {
      $mentionsCourse = $course === '' || stripos($message, $course) !== false;
      $mentionsAction = $requestedAction === ''
        || ($requestedAction === 'checkin' && preg_match('/check[\s-]?in/i', $message))
        || ($requestedAction === 'checkout' && preg_match('/check[\s-]?out/i', $message));

      if (!$mentionsCourse || !$mentionsAction) {
        return $fallback;
      }
    }

    return $message;
  }

  public static function targetedFile()
  {
    return admin_storage_migrate_file('announcement_targets.json');
  }

  public static function pushTargeted($fingerprint, $message, $severity = 'info', array $meta = [])
  {
    $fingerprint = trim((string)$fingerprint);
    $message = self::normalizeStudentTargetedMessage(
      $message,
      (string)($meta['classification'] ?? ''),
      $meta
    );
    $severity = strtolower(trim((string)$severity));
    if (!in_array($severity, ['info', 'warning', 'urgent'], true)) {
      $severity = 'info';
    }
    if ($fingerprint === '' || $message === '') {
      return false;
    }

    $file = self::targetedFile();
    if (!file_exists($file)) {
      file_put_contents($file, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    $entry = [
      'id' => 'ai_' . date('YmdHis') . '_' . substr(md5($fingerprint . $message), 0, 8),
      'enabled' => true,
      'severity' => $severity,
      'message' => $message,
      'target_fingerprint' => $fingerprint,
      'auto_generated_by' => 'system_ai_operator',
      'updated_at' => date('c'),
    ];

    foreach ($meta as $k => $v) {
      $entry[$k] = $v;
    }

    $fp = fopen($file, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) {
      fclose($fp);
      return false;
    }

    rewind($fp);
    $raw = stream_get_contents($fp);
    $rows = json_decode($raw ?: '[]', true);
    if (!is_array($rows)) $rows = [];

    array_unshift($rows, $entry);
    if (count($rows) > 500) {
      $rows = array_slice($rows, 0, 500);
    }

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $entry;
  }

  public static function latestForFingerprint($fingerprint)
  {
    $fingerprint = trim((string)$fingerprint);
    if ($fingerprint === '') {
      return null;
    }

    $file = self::targetedFile();
    if (!file_exists($file)) {
      return null;
    }

    $rows = json_decode((string)@file_get_contents($file), true);
    if (!is_array($rows)) {
      return null;
    }

    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      if (empty($row['enabled'])) continue;
      if ((string)($row['target_fingerprint'] ?? '') !== $fingerprint) continue;
      return $row;
    }

    return null;
  }
}
