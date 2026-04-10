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

  public static function diagnosticsFile()
  {
    return admin_storage_migrate_file('ai_ticket_diagnostics.json');
  }

  public static function autoSendTrackerFile()
  {
    return admin_storage_migrate_file('ai_auto_send_tracker.json');
  }

  public function processUnresolvedTickets($limit = 200)
  {
    $identity = AiServiceIdentity::load($this->serviceId);
    if (!$identity || $identity->canLogin()) {
      return ['ok' => false, 'error' => 'invalid_ai_identity'];
    }

    if (!ai_can($this->serviceId, 'ticket.read') || !ai_can($this->serviceId, 'ticket.diagnose')) {
      return ['ok' => false, 'error' => 'insufficient_ai_capabilities'];
    }

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
    $requestedAction = strtolower(trim((string)($ticket['requested_action'] ?? '')));
    if (!in_array($requestedAction, ['checkin', 'checkout'], true)) {
      $requestedAction = '';
    }

    $announcementMessage = '';
    $announcementSeverity = 'info';
    $attendanceAdded = false;
    $adminSuggestion = (string)($diag['suggested_admin_action'] ?? 'Review if needed.');
    $aiSuggestion = AiProviderClient::suggestTicketResolution($ticket, $diag);
    $aiFingerprintResponse = AiProviderClient::suggestFingerprintResponse($ticket, $diag);
    if (!empty($aiSuggestion['ok']) && trim((string)($aiSuggestion['suggestion'] ?? '')) !== '') {
      $adminSuggestion = trim((string)$aiSuggestion['suggestion']);
    }

    if ($diag['classification'] === 'blocked_revoked_device') {
      $announcementMessage = 'Access denied: this device/session is revoked. Contact admin for re-enable review.';
      $announcementSeverity = 'urgent';
    } elseif ($diag['classification'] === 'duplicate_submission_attempt' || $diag['classification'] === 'duplicate_or_fraudulent_sequence') {
      $announcementMessage = 'Attendance is already recorded for today. Duplicate submissions are blocked.';
      $announcementSeverity = 'warning';
    } elseif ($diag['classification'] === 'legitimate_session_issue') {
      if (ai_can($this->serviceId, 'ticket.add_attendance')) {
        $actionToAdd = $requestedAction !== '' ? $requestedAction : 'checkin';

        $hasCheckin = !empty($diag['checkinCount']);
        $hasCheckout = !empty($diag['checkoutCount']);

        if ($requestedAction === '') {
          if ($hasCheckin && !$hasCheckout) {
            $actionToAdd = 'checkout';
          } elseif (!$hasCheckin) {
            $actionToAdd = 'checkin';
          }
        }

        $canWrite = true;
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
        } else {
          $announcementMessage = 'We detected a valid session issue but could not auto-fix right now. Admin will review shortly.';
          $announcementSeverity = 'warning';
          if ($canWrite) {
            $adminSuggestion = 'Auto-fix write failed; manual check recommended.';
          }
        }
      } else {
        $announcementMessage = 'Issue identified. Admin review is required before attendance update.';
        $announcementSeverity = 'warning';
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

    if (!empty($aiFingerprintResponse['ok']) && trim((string)($aiFingerprintResponse['suggestion'] ?? '')) !== '') {
      $announcementMessage = trim((string)$aiFingerprintResponse['suggestion']);
    }

    $announcement = false;
    if (ai_can($this->serviceId, 'announcement.write_targeted') && $fingerprint !== '') {
      $announcement = AiAnnouncementService::pushTargeted($fingerprint, $announcementMessage, $announcementSeverity, [
        'created_for_ticket' => $timestamp,
        'matric' => $matric,
        'classification' => $diag['classification'],
        'confidence' => $diag['confidence'],
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
      'requested_action' => $requestedAction,
      'issue_type' => $diag['issue_type'],
      'classification' => $diag['classification'],
      'decision_reason' => $diag['reason'],
      'confidence' => $diag['confidence'],
      'fpMatch' => $diag['fpMatch'],
      'ipMatch' => $diag['ipMatch'],
      'revoked' => $diag['revoked'],
      'suggested_admin_action' => $adminSuggestion,
      'attendance_added' => $attendanceAdded,
      'ticket_resolved' => $resolved,
      'ticket_resolved_hybrid' => $hybridResolved,
      'announcement_sent' => (bool)$announcement,
      'announcement_id' => is_array($announcement) ? ($announcement['id'] ?? null) : null,
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
      && in_array($diag['classification'], ['blocked_revoked_device', 'duplicate_or_fraudulent_sequence'], true)
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
      'attendance_added' => $attendanceAdded
    ];
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

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../admin/auto_send_logs.php') . ' ' . escapeshellarg($today) . ' --force';
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
