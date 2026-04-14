<?php

require_once __DIR__ . '/AiServiceIdentity.php';
require_once __DIR__ . '/AiCapabilityChecker.php';
require_once __DIR__ . '/AiTicketDiagnoser.php';
require_once __DIR__ . '/AiAnnouncementService.php';
require_once __DIR__ . '/AiAdminChatAssistant.php';
require_once __DIR__ . '/AiProviderClient.php';
require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/../admin/runtime_storage.php';
require_once __DIR__ . '/../admin/state_helpers.php';
require_once __DIR__ . '/../admin/includes/ticket_helpers.php';
require_once __DIR__ . '/../admin/includes/hybrid_admin_read.php';
require_once __DIR__ . '/../env_helpers.php';

class AiTicketAutomationEngine
{
  private $serviceId = 'system_ai_operator';

  private static function loadAdminSettings()
  {
    $settingsFile = admin_storage_migrate_file('settings.json');
    $keyFile = admin_storage_migrate_file('.settings_key');
    if (!file_exists($settingsFile)) {
      return [];
    }

    $raw = @file_get_contents($settingsFile);
    $decoded = json_decode((string)$raw, true);
    if (is_array($decoded)) {
      return $decoded;
    }

    if (!file_exists($keyFile) || strpos((string)$raw, 'ENC:') !== 0) {
      return [];
    }

    $key = trim((string)@file_get_contents($keyFile));
    $blob = base64_decode(substr((string)$raw, 4), true);
    $keyRaw = base64_decode($key, true);
    if ($blob === false || $keyRaw === false || strlen($blob) < 17) {
      return [];
    }

    $iv = substr($blob, 0, 16);
    $ct = substr($blob, 16);
    $plain = openssl_decrypt($ct, 'AES-256-CBC', $keyRaw, OPENSSL_RAW_DATA, $iv);
    $decoded = json_decode((string)$plain, true);
    return is_array($decoded) ? $decoded : [];
  }

  private static function autoSendConfig()
  {
    $settings = self::loadAdminSettings();
    $recipient = trim((string)($settings['auto_send']['recipient'] ?? ''));
    $format = strtolower(trim((string)($settings['auto_send']['format'] ?? 'csv')));
    return [
      'enabled' => !empty($settings['auto_send']['enabled']),
      'recipient' => $recipient,
      'recipient_valid' => (bool)filter_var($recipient, FILTER_VALIDATE_EMAIL),
      'format' => in_array($format, ['csv', 'pdf'], true) ? $format : 'csv',
    ];
  }

  public static function diagnosticsFile()
  {
    return admin_storage_migrate_file('ai_ticket_diagnostics.json');
  }

  public static function autoSendTrackerFile()
  {
    return admin_storage_migrate_file('ai_auto_send_tracker.json');
  }

  public static function runtimeStateFile()
  {
    return admin_storage_migrate_file('ai_ticket_processor_runtime.json');
  }

  private function canProcessTickets()
  {
    $identity = AiServiceIdentity::load($this->serviceId);
    if (!$identity || $identity->canLogin()) {
      return ['ok' => false, 'error' => 'invalid_ai_identity'];
    }

    if (!ai_can($this->serviceId, 'ticket.read') || !ai_can($this->serviceId, 'ticket.diagnose')) {
      return ['ok' => false, 'error' => 'insufficient_ai_capabilities'];
    }

    return ['ok' => true];
  }

  public function processUnresolvedTickets($limit = 200)
  {
    $gate = $this->canProcessTickets();
    if (empty($gate['ok'])) {
      return $gate;
    }

    $limit = max(1, min(1000, (int)$limit));
    $tickets = ticket_read_all();
    $processed = 0;
    $results = [];

    foreach ($tickets as $ticket) {
      if ($processed >= $limit) break;
      if (!is_array($ticket)) continue;
      if (!empty($ticket['resolved'])) continue;

      $result = $this->processOne($ticket);
      $results[] = $result;
      $processed++;
    }

    return [
      'ok' => true,
      'service' => $this->serviceId,
      'processed' => $processed,
      'results' => $results,
      'processed_at' => date('c')
    ];
  }

  public function processTicket(array $ticket)
  {
    $gate = $this->canProcessTickets();
    if (empty($gate['ok'])) {
      return $gate;
    }

    if (!empty($ticket['resolved'])) {
      return ['ok' => false, 'error' => 'ticket_already_resolved'];
    }

    $timestamp = trim((string)($ticket['timestamp'] ?? ''));
    if ($timestamp === '') {
      return ['ok' => false, 'error' => 'missing_ticket_timestamp'];
    }

    $result = $this->processOne($ticket);
    return [
      'ok' => true,
      'service' => $this->serviceId,
      'processed' => 1,
      'results' => [$result],
      'processed_at' => date('c')
    ];
  }

  public function processTicketByTimestamp($ticketTimestamp)
  {
    $ticketTimestamp = trim((string)$ticketTimestamp);
    if ($ticketTimestamp === '') {
      return ['ok' => false, 'error' => 'missing_ticket_timestamp'];
    }

    $tickets = ticket_read_all();
    foreach ($tickets as $ticket) {
      if (!is_array($ticket)) {
        continue;
      }
      if ((string)($ticket['timestamp'] ?? '') !== $ticketTimestamp) {
        continue;
      }
      if (!empty($ticket['resolved'])) {
        return ['ok' => false, 'error' => 'ticket_already_resolved'];
      }
      return $this->processTicket($ticket);
    }

    return [
      'ok' => false,
      'error' => 'ticket_not_found',
      'ticket_timestamp' => $ticketTimestamp
    ];
  }

  public function autoProcessPending($limit = 20, $context = 'general', $cooldownSeconds = 20)
  {
    $limit = max(1, min(1000, (int)$limit));
    $context = trim((string)$context) !== '' ? trim((string)$context) : 'general';
    $cooldownSeconds = max(0, (int)$cooldownSeconds);
    $stateFile = self::runtimeStateFile();
    if (!file_exists($stateFile)) {
      @file_put_contents($stateFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    $fp = fopen($stateFile, 'c+');
    if (!$fp) {
      return $this->processUnresolvedTickets($limit);
    }

    if (!flock($fp, LOCK_EX)) {
      fclose($fp);
      return ['ok' => false, 'error' => 'runtime_lock_failed'];
    }

    rewind($fp);
    $raw = stream_get_contents($fp);
    $state = json_decode($raw ?: '[]', true);
    if (!is_array($state)) {
      $state = [];
    }

    $entry = isset($state[$context]) && is_array($state[$context]) ? $state[$context] : [];
    $lastRunAt = strtotime((string)($entry['last_run_at'] ?? '')) ?: 0;
    $now = time();
    if ($cooldownSeconds > 0 && $lastRunAt > 0 && ($now - $lastRunAt) < $cooldownSeconds) {
      flock($fp, LOCK_UN);
      fclose($fp);
      return [
        'ok' => true,
        'skipped' => true,
        'reason' => 'cooldown_active',
        'context' => $context,
        'last_run_at' => $entry['last_run_at'] ?? null
      ];
    }

    $state[$context] = [
      'last_run_at' => date('c'),
      'status' => 'running',
      'limit' => $limit,
    ];
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $result = $this->processUnresolvedTickets($limit);

    $fp = fopen($stateFile, 'c+');
    if ($fp && flock($fp, LOCK_EX)) {
      rewind($fp);
      $raw = stream_get_contents($fp);
      $state = json_decode($raw ?: '[]', true);
      if (!is_array($state)) {
        $state = [];
      }
      $state[$context] = [
        'last_run_at' => date('c'),
        'status' => !empty($result['ok']) ? 'completed' : 'failed',
        'processed' => (int)($result['processed'] ?? 0),
        'error' => (string)($result['error'] ?? ''),
      ];
      rewind($fp);
      ftruncate($fp, 0);
      fwrite($fp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      fflush($fp);
      flock($fp, LOCK_UN);
      fclose($fp);
    }

    return $result;
  }

  private function processOne(array $ticket)
  {
    $diag = AiTicketDiagnoser::diagnose($ticket);
    $timestamp = (string)($ticket['timestamp'] ?? '');
    $fingerprint = (string)($ticket['fingerprint'] ?? '');
    $name = (string)($ticket['name'] ?? 'Student');
    $matric = (string)($ticket['matric'] ?? '');
    $ip = (string)($ticket['ip'] ?? '');
    $course = trim((string)($ticket['course'] ?? 'General'));
    $course = $course !== '' ? $course : 'General';
    $activeCourseLabel = trim((string)($diag['active_course'] ?? ''));
    if (
      ($course === 'General' || strtolower($course) === 'ai ticket processor' || strtolower($course) === 'sentinel ai')
      && $activeCourseLabel !== ''
    ) {
      $course = $activeCourseLabel;
    }
    $requestedAction = strtolower(trim((string)($ticket['requested_action'] ?? '')));
    if (!in_array($requestedAction, ['checkin', 'checkout'], true)) {
      $requestedAction = '';
    }

    $announcementMessage = '';
    $announcementSeverity = 'info';
    $attendanceAdded = false;
    $autoTokenClear = ['performed' => false, 'tokens_cleared' => 0, 'reason' => 'not_attempted'];
    $adminSuggestion = (string)($diag['suggested_admin_action'] ?? 'Review if needed.');
    $aiSuggestion = AiProviderClient::suggestTicketResolution($ticket, $diag);
    $aiFingerprintResponse = AiProviderClient::suggestFingerprintResponse($ticket, $diag);
    if (!empty($aiSuggestion['ok']) && trim((string)($aiSuggestion['suggestion'] ?? '')) !== '') {
      $adminSuggestion = trim((string)$aiSuggestion['suggestion']);
    }

    if ($diag['classification'] === 'invalid_course_reference') {
      $announcementMessage = 'We received your support ticket, but the course in this request is not recognized in the attendance system. The support team is reviewing it now.';
      $announcementSeverity = 'warning';
      $adminSuggestion = 'Do not write attendance yet. Confirm the correct course with the student; if the ticket course is wrong, update guidance only. If the student meant another configured active course, continue with normal guarded manual attendance checks there.';
    } elseif ($diag['classification'] === 'inactive_course_reference') {
      $announcementMessage = sprintf('We received your support ticket for %s. That course is not active for attendance right now, so the support team is reviewing the course setup before the next attendance step.', $course);
      $announcementSeverity = 'warning';
      $adminSuggestion = sprintf('AI suggestion: if %s should be used for this student, activate that course first. Current active course is "%s". After activation, Sentinel may add guarded manual attendance for this ticket only if identity matches, the device is not blocked/shared, and no duplicate %s already exists.', $course, $activeCourseLabel !== '' ? $activeCourseLabel : 'General', $requestedAction !== '' ? $requestedAction : 'attendance');
    } elseif ($diag['classification'] === 'fingerprint_conflict_rig_attempt') {
      $announcementMessage = 'Attendance request is blocked due to fingerprint conflict risk. Admin identity verification is required before any update.';
      $announcementSeverity = 'urgent';
    } elseif ($diag['classification'] === 'blocked_revoked_device') {
      $autoTokenClear = $this->maybeClearBlockedTokenFromTicket($ticket, $diag);
      if (!empty($autoTokenClear['performed'])) {
        $announcementMessage = 'Your token/session block request was verified and cleared. Refresh and retry attendance on the same device.';
        $announcementSeverity = 'info';
        $adminSuggestion = 'Token block cleared automatically from support ticket conditions (tab-fencing/inactivity request matched).';
      } else {
        $announcementMessage = 'Access denied: this device/session is revoked. Contact admin for re-enable review.';
        $announcementSeverity = 'urgent';
      }
    } elseif ($diag['classification'] === 'duplicate_submission_attempt' || $diag['classification'] === 'duplicate_or_fraudulent_sequence') {
      $announcementMessage = 'Attendance is already recorded for today. Duplicate submissions are blocked.';
      $announcementSeverity = 'warning';
    } elseif ($diag['classification'] === 'policy_device_sharing_risk') {
      $announcementMessage = 'Attendance request is under manual review due to a same-device policy check for this course today. Admin verification is required before any update.';
      $announcementSeverity = 'warning';
    } elseif ($diag['classification'] === 'legitimate_session_issue') {
      if (ai_can($this->serviceId, 'ticket.add_attendance')) {
        $actionToAdd = $requestedAction !== '' ? $requestedAction : 'checkin';

        $hasCheckin = !empty($diag['checkinCount']);
        $hasCheckout = !empty($diag['checkoutCount']);

        $canWrite = true;
        if ($requestedAction === '') {
          $canWrite = false;
          $adminSuggestion = 'Auto-write blocked: the failed attendance action is not explicit. Review the ticket and use guarded manual attendance only after confirming whether the student needs check-in or check-out.';
        }
        if (empty($diag['identity_keys_present'])) {
          $canWrite = false;
          $adminSuggestion = 'Auto-write blocked: ticket fingerprint/IP identity keys are missing.';
        }
        if (!empty($diag['deviceSharingRisk'])) {
          $canWrite = false;
          $adminSuggestion = 'Auto-write blocked: fingerprint conflict risk detected across matrics.';
        }
        if (empty($diag['course_exists']) || empty($diag['course_is_active'])) {
          $canWrite = false;
          $adminSuggestion = 'Auto-write blocked: course must exist and be active before attendance write.';
        }
        if ($actionToAdd === 'checkin' && $hasCheckin) {
          $canWrite = false;
          $adminSuggestion = 'Course-aware guard: check-in already exists for this course today.';
        }
        if ($actionToAdd === 'checkout' && (!$hasCheckin || $hasCheckout)) {
          $canWrite = false;
          $adminSuggestion = !$hasCheckin
            ? 'Course-aware guard: checkout denied because no prior check-in exists for this course.'
            : 'Course-aware guard: checkout already exists for this course today.';
        }

        if ($canWrite) {
          $attendanceAdded = ticket_append_attendance_log(
            $name,
            $matric,
            $actionToAdd,
            'AI auto-fix: legitimate session issue',
            $course,
            $fingerprint !== '' ? $fingerprint : 'AI_AUTO',
            $ip !== '' ? $ip : '127.0.0.1',
            'UNKNOWN'
          );
        }
        if ($attendanceAdded) {
          $announcementMessage = sprintf('Issue resolved: your %s for %s was fixed automatically. Please refresh and continue.', $actionToAdd, $course);
          $announcementSeverity = 'info';
          $adminSuggestion = sprintf('Sentinel registered a guarded manual attendance %s for %s and resolved the ticket.', $actionToAdd, $course);
        } else {
          $announcementMessage = 'We detected a valid session issue but could not auto-fix right now. Admin will review shortly.';
          $announcementSeverity = 'warning';
          if ($canWrite) {
            $adminSuggestion = sprintf('Sentinel was cleared to add guarded manual attendance for %s, but the write failed. Admin should retry via manual attendance for %s and then resolve the ticket.', $actionToAdd, $course);
          }
        }
      } else {
        $announcementMessage = 'Issue identified. Admin review is required before attendance update.';
        $announcementSeverity = 'warning';
        $adminSuggestion = sprintf('Identity checks are good, but Sentinel lacks attendance-write capability here. Admin should use Manual Attendance for a guarded %s on %s if all other policy checks remain clear.', $requestedAction !== '' ? $requestedAction : 'attendance update', $course);
      }
    } elseif ($diag['classification'] === 'network_ip_rotation') {
      $announcementMessage = 'We detected a network/IP change. Please keep one network and contact admin if issue persists.';
      $announcementSeverity = 'warning';
    } else {
      $verificationLink = trim((string)app_env_value('AI_VERIFICATION_LINK', ''));
      $announcementMessage = 'We detected a new or unverified device. Please verify with admin before retrying attendance.';
      if ($verificationLink !== '') {
        $announcementMessage .= ' Verification link: ' . $verificationLink;
      }
      $announcementSeverity = 'warning';
    }

    if (empty($autoTokenClear['performed']) && !empty($aiFingerprintResponse['ok']) && trim((string)($aiFingerprintResponse['suggestion'] ?? '')) !== '') {
      $announcementMessage = trim((string)$aiFingerprintResponse['suggestion']);
    }

    $announcement = false;
    if (ai_can($this->serviceId, 'announcement.write_targeted') && $fingerprint !== '') {
      $announcement = AiAnnouncementService::pushTargeted($fingerprint, $announcementMessage, $announcementSeverity, [
        'created_for_ticket' => $timestamp,
        'matric' => $matric,
        'classification' => $diag['classification'],
        'confidence' => $diag['confidence'],
        'course' => $course,
        'requested_action' => $requestedAction,
        'active_course' => (string)($diag['active_course'] ?? ''),
        'ticket_message' => (string)($ticket['message'] ?? ''),
      ]);
    }

    $resolved = false;
    $hybridResolved = false;
    if (ai_can($this->serviceId, 'ticket.resolve')) {
      $resolved = ticket_resolve_atomic($timestamp, [
        'ai_handled' => true,
        'ai_classification' => $diag['classification'],
        'ai_confidence' => $diag['confidence'],
        'ai_reason' => $diag['reason'],
        'ai_suggested_admin_action' => $adminSuggestion,
        'ai_handled_at' => date('c')
      ]);

      // Keep hybrid admin views in sync when HYBRID_MODE + HYBRID_ADMIN_READ are enabled.
      if ($resolved && function_exists('hybrid_mark_support_ticket_resolved')) {
        $hybridResolved = (bool)hybrid_mark_support_ticket_resolved($timestamp);
      }
    }

    $this->appendDiagnostics([
      'ticket_timestamp' => $timestamp,
      'name' => $name,
      'matric' => $matric,
      'fingerprint' => $fingerprint,
      'ip' => $ip,
      'course' => $course,
      'course_exists' => !empty($diag['course_exists']),
      'course_is_active' => !empty($diag['course_is_active']),
      'active_course' => (string)($diag['active_course'] ?? 'General'),
      'identity_keys_present' => !empty($diag['identity_keys_present']),
      'requested_action' => $requestedAction,
      'issue_type' => $diag['issue_type'],
      'classification' => $diag['classification'],
      'decision_reason' => $diag['reason'],
      'rulebook_applied' => !empty($diag['rulebook_applied']),
      'matched_rule_id' => (string)($diag['matched_rule_id'] ?? ''),
      'rulebook_version' => (string)($diag['rulebook_version'] ?? ''),
      'confidence' => $diag['confidence'],
      'fpMatch' => $diag['fpMatch'],
      'ipMatch' => $diag['ipMatch'],
      'revoked' => $diag['revoked'],
      'deviceSharingRisk' => !empty($diag['deviceSharingRisk']),
      'sharedFingerprintMatrics' => $diag['sharedFingerprintMatrics'] ?? [],
      'sharedIpMatrics' => $diag['sharedIpMatrics'] ?? [],
      'sharedFingerprintIpMatrics' => $diag['sharedFingerprintIpMatrics'] ?? [],
      'suggested_admin_action' => $adminSuggestion,
      'attendance_added' => $attendanceAdded,
      'ticket_resolved' => $resolved,
      'ticket_resolved_hybrid' => $hybridResolved,
      'announcement_sent' => (bool)$announcement,
      'announcement_id' => is_array($announcement) ? ($announcement['id'] ?? null) : null,
      'token_auto_clear_performed' => !empty($autoTokenClear['performed']),
      'token_auto_clear_count' => (int)($autoTokenClear['tokens_cleared'] ?? 0),
      'token_auto_clear_reason' => (string)($autoTokenClear['reason'] ?? ''),
      'ai_provider' => (string)($aiSuggestion['provider'] ?? 'rules'),
      'ai_model' => (string)($aiSuggestion['model'] ?? 'rules-v1'),
      'ai_latency_ms' => (int)($aiSuggestion['latency_ms'] ?? 0),
      'site_context_enabled' => !empty($aiSuggestion['site_context_enabled']),
      'site_context_version' => (string)($aiSuggestion['site_context_version'] ?? 'site-context-v1'),
      'site_context_source_count' => (int)($aiSuggestion['site_context_source_count'] ?? 0),
      'site_context_indexed_pages' => (int)($aiSuggestion['site_context_indexed_pages'] ?? 0),
      'site_context_last_scan_at' => (string)($aiSuggestion['site_context_last_scan_at'] ?? ''),
      'fingerprint_ai_provider' => (string)($aiFingerprintResponse['provider'] ?? 'rules'),
      'fingerprint_ai_model' => (string)($aiFingerprintResponse['model'] ?? 'rules-fingerprint-v1'),
      'fingerprint_ai_latency_ms' => (int)($aiFingerprintResponse['latency_ms'] ?? 0),
      'fingerprint_site_context_enabled' => !empty($aiFingerprintResponse['site_context_enabled']),
      'fingerprint_site_context_source_count' => (int)($aiFingerprintResponse['site_context_source_count'] ?? 0),
      'processed_at' => date('c')
    ]);

    if ($attendanceAdded) {
      $this->maybeTriggerAutoSend((string)$matric);
    }

    if (function_exists('admin_log_action')) {
      admin_log_action('AI_Operator', 'Ticket Automated', sprintf(
        'Ticket %s => %s (confidence=%.2f, resolved=%s, announcement=%s)',
        $timestamp,
        $diag['classification'],
        (float)$diag['confidence'],
        $resolved ? 'yes' : 'no',
        $announcement ? 'yes' : 'no'
      ));
    }

    if (
      ai_can($this->serviceId, 'chat.admin_assist')
      && (float)$diag['confidence'] >= 0.90
      && in_array($diag['classification'], ['blocked_revoked_device', 'duplicate_or_fraudulent_sequence', 'policy_device_sharing_risk', 'fingerprint_conflict_rig_attempt'], true)
    ) {
      $chatReply = AiProviderClient::suggestAdminChatReply(
        sprintf('Generate admin insight for classification=%s reason=%s', (string)$diag['classification'], (string)$diag['reason']),
        [
          'fingerprint' => $fingerprint,
          'matric' => $matric,
          'course' => $course,
          'requested_action' => $requestedAction,
          'classification' => (string)$diag['classification'],
          'confidence' => (float)$diag['confidence'],
          'fpMatch' => !empty($diag['fpMatch']),
          'ipMatch' => !empty($diag['ipMatch']),
          'revoked' => !empty($diag['revoked']),
          'checkinCount' => (int)($diag['checkinCount'] ?? 0),
          'checkoutCount' => (int)($diag['checkoutCount'] ?? 0),
        ]
      );

      $chatMsg = !empty($chatReply['ok']) && trim((string)($chatReply['suggestion'] ?? '')) !== ''
        ? trim((string)$chatReply['suggestion'])
        : sprintf(
          'AI diagnostic: %s for matric %s (ticket %s). Reason: %s',
          $diag['classification'],
          $matric !== '' ? $matric : 'unknown',
          $timestamp !== '' ? $timestamp : 'unknown',
          (string)$diag['reason']
        );

      AiAdminChatAssistant::postInsight($chatMsg, [
        'confidence' => $diag['confidence'],
        'ticket_timestamp' => $timestamp,
        'classification' => $diag['classification'],
        'ai_provider' => (string)($chatReply['provider'] ?? 'rules'),
        'ai_model' => (string)($chatReply['model'] ?? 'rules-chat-v1'),
        'ai_latency_ms' => (int)($chatReply['latency_ms'] ?? 0),
        'site_context_enabled' => !empty($chatReply['site_context_enabled']),
        'site_context_source_count' => (int)($chatReply['site_context_source_count'] ?? 0),
        'site_context_indexed_pages' => (int)($chatReply['site_context_indexed_pages'] ?? 0),
      ]);
    }

    return [
      'ticket_timestamp' => $timestamp,
      'classification' => $diag['classification'],
      'confidence' => $diag['confidence'],
      'resolved' => $resolved,
      'hybrid_resolved' => $hybridResolved,
      'announcement_sent' => (bool)$announcement,
      'attendance_added' => $attendanceAdded,
      'token_auto_clear_performed' => !empty($autoTokenClear['performed']),
      'token_auto_clear_count' => (int)($autoTokenClear['tokens_cleared'] ?? 0)
    ];
  }

  private function maybeClearBlockedTokenFromTicket(array $ticket, array $diag)
  {
    if (empty($diag['classification']) || (string)$diag['classification'] !== 'blocked_revoked_device') {
      return ['performed' => false, 'tokens_cleared' => 0, 'reason' => 'classification_not_blocked'];
    }

    $allowAutoClear = trim((string)app_env_value('AI_AUTO_CLEAR_TOKENS_FROM_TICKETS', '1')) !== '0';
    if (!$allowAutoClear) {
      return ['performed' => false, 'tokens_cleared' => 0, 'reason' => 'feature_disabled'];
    }

    $message = strtolower(trim((string)($ticket['message'] ?? '')));
    $fingerprint = trim((string)($ticket['fingerprint'] ?? ''));
    $ip = trim((string)($ticket['ip'] ?? ''));

    if ($fingerprint === '' && $ip === '') {
      return ['performed' => false, 'tokens_cleared' => 0, 'reason' => 'missing_identity_keys'];
    }

    $isUnblockRequest = (bool)preg_match('/\b(clear|unblock|re-?enable|reset|remove|release|allow|open)\b/i', $message);
    $isTokenOrFencingContext = (bool)preg_match('/\b(token|session|inactivity|tab|fencing|blocked|revoked|locked|lockout|timeout)\b/i', $message);
    $issueType = strtolower(trim((string)($diag['issue_type'] ?? '')));
    $issueCompatible = in_array($issueType, ['revoked_or_blocked_complaint', 'session_or_token_expired', 'general_system_complaint'], true);

    if (!$isUnblockRequest || !$isTokenOrFencingContext || !$issueCompatible) {
      return ['performed' => false, 'tokens_cleared' => 0, 'reason' => 'conditions_not_met'];
    }

    $blockedLog = app_storage_file('logs/blocked_tokens.log');
    $revokedFile = admin_storage_migrate_file('revoked.json', app_storage_file('revoked.json'));

    if (!file_exists($blockedLog) || !file_exists($revokedFile)) {
      return ['performed' => false, 'tokens_cleared' => 0, 'reason' => 'required_files_missing'];
    }

    $lines = @file($blockedLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    if (empty($lines)) {
      return ['performed' => false, 'tokens_cleared' => 0, 'reason' => 'no_blocked_token_rows'];
    }

    $candidateTokens = [];
    foreach ($lines as $ln) {
      $parts = array_map('trim', explode('|', (string)$ln));
      $rowToken = (string)($parts[1] ?? '');
      $rowFingerprint = (string)($parts[2] ?? '');
      $rowIp = (string)($parts[3] ?? '');
      if ($rowToken === '') {
        continue;
      }

      $matchFingerprint = ($fingerprint !== '' && $rowFingerprint !== '' && $rowFingerprint === $fingerprint);
      $matchIp = ($ip !== '' && $rowIp !== '' && $rowIp === $ip);
      if ($matchFingerprint || $matchIp) {
        $candidateTokens[$rowToken] = true;
      }
    }

    if (empty($candidateTokens)) {
      return ['performed' => false, 'tokens_cleared' => 0, 'reason' => 'no_matching_tokens'];
    }

    $revoked = json_decode((string)@file_get_contents($revokedFile), true);
    if (!is_array($revoked)) {
      $revoked = ['tokens' => [], 'ips' => [], 'macs' => []];
    }
    foreach (['tokens', 'ips', 'macs'] as $bucket) {
      if (!isset($revoked[$bucket]) || !is_array($revoked[$bucket])) {
        $revoked[$bucket] = [];
      }
    }

    $cleared = 0;
    foreach (array_keys($candidateTokens) as $token) {
      if (array_key_exists($token, $revoked['tokens'])) {
        unset($revoked['tokens'][$token]);
        $cleared++;
      }
    }

    if ($cleared <= 0) {
      return ['performed' => false, 'tokens_cleared' => 0, 'reason' => 'tokens_not_in_revoked_bucket'];
    }

    @file_put_contents($revokedFile, json_encode($revoked, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

    $kept = [];
    foreach ($lines as $ln) {
      $parts = array_map('trim', explode('|', (string)$ln));
      $rowToken = (string)($parts[1] ?? '');
      if ($rowToken !== '' && isset($candidateTokens[$rowToken])) {
        continue;
      }
      $kept[] = $ln;
    }
    @file_put_contents($blockedLog, implode(PHP_EOL, $kept) . (empty($kept) ? '' : PHP_EOL), LOCK_EX);

    if (function_exists('admin_log_action')) {
      admin_log_action('AI_Operator', 'Support Token Auto-Clear', sprintf(
        'Cleared %d token revocation(s) from support ticket conditions (classification=%s).',
        $cleared,
        (string)$diag['classification']
      ));
    }

    return ['performed' => true, 'tokens_cleared' => $cleared, 'reason' => 'cleared_matching_tokens'];
  }

  private function appendDiagnostics(array $entry)
  {
    $file = self::diagnosticsFile();
    if (!file_exists($file)) {
      file_put_contents($file, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
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
    if (count($rows) > 1000) {
      $rows = array_slice($rows, 0, 1000);
    }

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return true;
  }

  private function maybeTriggerAutoSend($matric)
  {
    $matric = trim((string)$matric);
    if ($matric === '' || !ai_can($this->serviceId, 'logs.export')) {
      return;
    }

    $autoSend = self::autoSendConfig();
    if (empty($autoSend['enabled']) || empty($autoSend['recipient_valid'])) {
      return;
    }

    $today = date('Y-m-d');
    $stats = AiTicketDiagnoser::parseDailyLogStats($today, $matric, '', '');
    if (empty($stats['attendanceCycleComplete'])) {
      return;
    }

    $markerKey = $today . '|' . $matric;
    $trackerFile = self::autoSendTrackerFile();

    if (!file_exists($trackerFile)) {
      file_put_contents($trackerFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    $fp = fopen($trackerFile, 'c+');
    if (!$fp) {
      return;
    }
    if (!flock($fp, LOCK_EX)) {
      fclose($fp);
      return;
    }

    rewind($fp);
    $raw = stream_get_contents($fp);
    $tracker = json_decode($raw ?: '[]', true);
    if (!is_array($tracker)) $tracker = [];

    if (isset($tracker[$markerKey])) {
      flock($fp, LOCK_UN);
      fclose($fp);
      return;
    }

    $tracker[$markerKey] = [
      'triggered_at' => date('c'),
      'matric' => $matric,
      'date' => $today,
      'status' => 'queued'
    ];

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../admin/auto_send_logs.php') . ' ' . escapeshellarg($today);
    $output = [];
    $code = 1;
    @exec($cmd, $output, $code);
    $tracker[$markerKey]['status'] = $code === 0 ? 'success' : 'failed';
    $tracker[$markerKey]['exit_code'] = $code;
    $tracker[$markerKey]['output'] = implode("\n", array_slice($output, 0, 20));

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($tracker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
  }
}
