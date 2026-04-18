<?php

header('Content-Type: application/json');

require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/request_guard.php';
require_once __DIR__ . '/admin/runtime_storage.php';
require_once __DIR__ . '/admin/state_helpers.php';

app_storage_init();
app_request_guard('ticket_status_api.php', 'public');

$fingerprint = trim((string)($_POST['fingerprint'] ?? $_GET['fingerprint'] ?? ''));
$ip = trim((string)($_POST['ip'] ?? $_GET['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')));

if ($fingerprint === '') {
  echo json_encode([
    'ticket_found' => false,
    'status' => 'missing_fingerprint',
    'message' => 'Fingerprint is required for ticket status lookup.',
  ]);
  exit;
}

$ticketsFile = admin_storage_migrate_file('support_tickets.json', app_storage_file('support_tickets.json'));
$tickets = file_exists($ticketsFile)
  ? json_decode((string)@file_get_contents($ticketsFile), true)
  : [];
if (!is_array($tickets)) {
  $tickets = [];
}

$matchedTicket = null;
for ($i = count($tickets) - 1; $i >= 0; $i--) {
  $ticket = $tickets[$i];
  if (!is_array($ticket)) continue;
  if ((string)($ticket['fingerprint'] ?? '') !== $fingerprint) continue;
  if ($ip !== '' && (string)($ticket['ip'] ?? '') !== $ip) continue;
  $matchedTicket = $ticket;
  break;
}

if (!$matchedTicket) {
  echo json_encode([
    'ticket_found' => false,
    'status' => 'not_found',
    'message' => 'No ticket found for this device.',
  ]);
  exit;
}

$diagnosticsFile = function_exists('ai_ticket_diagnostics_file')
  ? ai_ticket_diagnostics_file()
  : admin_storage_migrate_file('ai_ticket_diagnostics.json');
$diagnostics = file_exists($diagnosticsFile)
  ? json_decode((string)@file_get_contents($diagnosticsFile), true)
  : [];
if (!is_array($diagnostics)) $diagnostics = [];

$ticketTs = (string)($matchedTicket['timestamp'] ?? '');
$diagMatch = null;
foreach ($diagnostics as $diag) {
  if (!is_array($diag)) continue;
  if ((string)($diag['ticket_timestamp'] ?? '') !== $ticketTs) continue;
  $diagMatch = $diag;
  break;
}

$targetFile = function_exists('ai_targeted_announcements_file')
  ? ai_targeted_announcements_file()
  : admin_storage_migrate_file('announcement_targets.json');
$targetAnnouncements = file_exists($targetFile)
  ? json_decode((string)@file_get_contents($targetFile), true)
  : [];
if (!is_array($targetAnnouncements)) $targetAnnouncements = [];

$announcement = null;
foreach ($targetAnnouncements as $row) {
  if (!is_array($row)) continue;
  if (empty($row['enabled'])) continue;
  if ((string)($row['target_fingerprint'] ?? '') !== $fingerprint) continue;
  $announcement = [
    'message' => (string)($row['message'] ?? ''),
    'severity' => (string)($row['severity'] ?? 'info'),
    'updated_at' => $row['updated_at'] ?? null,
    'classification' => $row['classification'] ?? null,
  ];
  break;
}

$status = !empty($matchedTicket['resolved']) ? 'resolved' : 'pending_ai_review';
if ($diagMatch && !empty($diagMatch['ticket_resolved'])) {
  $status = 'resolved';
}

$guidance = 'Please contact admin support if the issue persists.';
if ($diagMatch) {
  $classification = (string)($diagMatch['classification'] ?? '');
  if ($classification === 'blocked_revoked_device') {
    $guidance = 'This device/session is currently revoked. Contact admin for re-enable review.';
  } elseif ($classification === 'policy_device_sharing_risk') {
    $guidance = 'Your request needs manual admin verification due to same-device policy checks for this course today.';
  } elseif ($classification === 'network_ip_rotation') {
    $guidance = 'Keep a stable network (avoid switching VPN/mobile/Wi-Fi) and retry.';
  } elseif ($classification === 'new_or_suspicious_device') {
    $guidance = 'Your device appears unrecognized. Identity verification is required.';
  } elseif ($classification === 'legitimate_session_issue') {
    $guidance = 'Session issue detected. Refresh and retry attendance.';
  }
}

echo json_encode([
  'ticket_found' => true,
  'status' => $status,
  'message' => $announcement['message'] ?? 'Your ticket is being processed by support automation.',
  'guidance' => $guidance,
  'estimated_time' => '5-10 minutes',
  'ticket' => [
    'timestamp' => $matchedTicket['timestamp'] ?? null,
    'resolved' => !empty($matchedTicket['resolved']),
    'name' => $matchedTicket['name'] ?? null,
    'matric' => $matchedTicket['matric'] ?? null,
  ],
  'diagnostics' => $diagMatch ? [
    'issue_type' => $diagMatch['issue_type'] ?? null,
    'classification' => $diagMatch['classification'] ?? null,
    'confidence' => $diagMatch['confidence'] ?? null,
    'fpMatch' => $diagMatch['fpMatch'] ?? null,
    'ipMatch' => $diagMatch['ipMatch'] ?? null,
    'revoked' => $diagMatch['revoked'] ?? null,
    'suggested_admin_action' => $diagMatch['suggested_admin_action'] ?? null,
  ] : null,
  'announcement' => $announcement,
], JSON_UNESCAPED_SLASHES);
