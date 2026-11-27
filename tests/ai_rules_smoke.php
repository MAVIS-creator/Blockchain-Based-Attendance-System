<?php
require_once __DIR__ . '/../src/AiProviderClient.php';
putenv('AI_AUTOMATION_PROVIDER=rules');
$ticket=['course'=>'Test','matric'=>'1234567','fingerprint'=>'dummy','message'=>'Issue with class'];
$diag=['classification'=>'manual_review_required'];
$result=AiProviderClient::suggestTicketBundle($ticket,$diag);
if (!isset($result['admin']['suggestion'],$result['student']['suggestion']) ||
    $result['admin']['provider']!=='rules' || $result['student']['provider']!=='rules') {
    throw new RuntimeException('AI deterministic bundle failed');
}
echo "AI fallback bundle passed\n";
