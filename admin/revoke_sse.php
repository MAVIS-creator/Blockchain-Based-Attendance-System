<?php
// Retired: the former public SSE stream disclosed every revocation and
// occupied a PHP worker indefinitely. Student self-check now uses scoped POST.
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['ok' => false, 'error' => 'revocation_stream_retired']);
