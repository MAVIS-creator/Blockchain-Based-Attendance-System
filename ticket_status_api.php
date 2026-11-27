<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  header('Allow: POST');
  http_response_code(405);
  echo json_encode(['ticket_found' => false]);
  exit;
}

require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/request_guard.php';
require_once __DIR__ . '/admin/runtime_storage.php';

app_storage_init();
app_request_guard('ticket_status_api.php', 'public');

$capability = $_POST['capability'] ?? null;
if (is_string($capability) && preg_match('/\A[0-9a-f]{64}\z/', $capability)) {
  $hash = hash('sha256', $capability);
  $ticketsFile = admin_storage_migrate_file('support_tickets.json', app_storage_file('support_tickets.json'));
  $handle = is_file($ticketsFile) ? fopen($ticketsFile, 'rb') : false;
  if ($handle !== false) {
    $tickets = [];
    if (flock($handle, LOCK_SH)) {
      $tickets = json_decode(stream_get_contents($handle), true);
      flock($handle, LOCK_UN);
    }
    fclose($handle);

    foreach (is_array($tickets) ? $tickets : [] as $ticket) {
      $storedHash = $ticket['status_capability_hash'] ?? '';
      if (is_string($storedHash) && hash_equals($storedHash, $hash)) {
        $resolved = !empty($ticket['resolved']);
        echo json_encode([
          'ticket_found' => true,
          'status' => $resolved ? 'resolved' : 'pending_ai_review',
          'message' => $resolved ? 'Support has reviewed this ticket.' : 'Support is reviewing this ticket.',
        ]);
        exit;
      }
    }
  }
}

http_response_code(404);
echo json_encode(['ticket_found' => false, 'message' => 'Ticket status is unavailable.']);
