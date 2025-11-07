<?php
// Simple CLI test harness for CSRF protection
// Usage: php csrf_test.php [base_url]
// Example: php csrf_test.php http://localhost/Blockchain-Based-Attendance-System/admin

$base = $argv[1] ?? 'http://localhost/Blockchain-Based-Attendance-System/admin';
$cookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bba_csrf_test_cookies.txt';
$username = $argv[2] ?? 'Mavis';
$password = $argv[3] ?? '.*123$<>Callmelater.,12';
$verbose = true;

function curl_request($method, $url, $opts = []) {
    global $cookie;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    if (isset($opts['headers'])) curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (isset($opts['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
    }
    $resp = curl_exec($ch);
    if ($resp === false) $err = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return ['raw'=>$resp, 'info'=>$info, 'error'=>$err ?? null];
}

function logmsg($s){ global $verbose; if ($verbose) echo $s . PHP_EOL; }

// cleanup cookie file
@unlink($cookie);

logmsg("Base URL: $base");

// 1) Login to admin
logmsg("[1] Logging in as $username ...");
$loginUrl = rtrim($base, '/') . '/login.php';
$post = http_build_query(['username'=>$username, 'password'=>$password]);
$r = curl_request('POST', $loginUrl, ['body'=>$post, 'headers'=>['Content-Type: application/x-www-form-urlencoded']]);
if ($r['error']) { echo "ERROR: curl error during login: {$r['error']}\n"; exit(2); }
$code = $r['info']['http_code'] ?? 0;
logmsg("Login HTTP code: $code");
if ($code >= 400) { echo "Login failed (HTTP $code). Ensure the web server is running and base URL is correct.\n"; exit(2); }

// 2) Fetch the admin index page to extract window.ADMIN_CSRF_TOKEN
logmsg("[2] Fetching index to extract CSRF token...");
$indexUrl = rtrim($base, '/') . '/index.php';
$r = curl_request('GET', $indexUrl);
if ($r['error']) { echo "ERROR: curl error fetching index: {$r['error']}\n"; exit(2); }
$body = $r['raw'];
// split headers/body
$parts = preg_split("/\r?\n\r?\n/", $body, 2);
$html = $parts[1] ?? $body;
$token = null;
if (preg_match('/window\.ADMIN_CSRF_TOKEN\s*=\s*(?:JSON\.parse\(|)\s*(["\'])(.*?)\1/', $html, $m)) {
    $token = $m[2];
} else if (preg_match('/window\.ADMIN_CSRF_TOKEN\s*=\s*([^;\n]+)/', $html, $m)) {
    // fallback: parse json literal
    $raw = trim($m[1]);
    $decoded = json_decode($raw, true);
    if ($decoded !== null) $token = $decoded;
}
if (!$token) {
    logmsg("WARN: CSRF token not found in index page. Trying settings.php...");
    $r2 = curl_request('GET', rtrim($base, '/') . '/settings.php');
    $parts = preg_split("/\r?\n\r?\n/", $r2['raw'], 2);
    $html = $parts[1] ?? $r2['raw'];
    if (preg_match('/window\.ADMIN_CSRF_TOKEN\s*=\s*(["\'])(.*?)\1/', $html, $m)) $token = $m[2];
}
if (!$token) {
    echo "ERROR: Could not extract ADMIN_CSRF_TOKEN. Ensure you are logged in and header.php is rendering the token.\n";
    exit(2);
}
logmsg("Extracted CSRF token: " . ($token ? substr($token,0,8) . '...' : 'empty'));

// helper to POST JSON to chat_post
function post_json($path, $payload) {
    global $base;
    $url = rtrim($base, '/') . '/' . ltrim($path, '/');
    $json = json_encode($payload);
    return curl_request('POST', $url, ['body'=>$json, 'headers'=>['Content-Type: application/json']]);
}

// 3) Test chat_post without token -> expect failure (csrf_failed)
logmsg("[3] Testing chat_post.php without token (expect csrf failure)...");
$r = post_json('chat_post.php', ['message'=>'test without token']);
$code = $r['info']['http_code'] ?? 0;
$body = $r['raw'] ?? '';
$parts = preg_split("/\r?\n\r?\n/", $body, 2);
$respBody = $parts[1] ?? $body;
$ok_no_token = false;
$json = json_decode($respBody, true);
if (is_array($json) && ((isset($json['error']) && $json['error']==='csrf_failed') || (isset($json['ok']) && $json['ok']===false && (strpos($respBody,'csrf_failed')!==false)))) {
    $ok_no_token = true; // server correctly rejected
}
if ($ok_no_token) logmsg("PASS: chat_post rejected request without token."); else { echo "FAIL: chat_post did NOT reject request without token. Response HTTP $code body: $respBody\n"; exit(3); }

// 4) Test chat_post with token -> expect success
logmsg("[4] Testing chat_post.php WITH token (expect success)...");
$r = post_json('chat_post.php', ['message'=>'test with token', 'csrf_token'=>$token]);
$parts = preg_split("/\r?\n\r?\n/", $r['raw'], 2);
$respBody = $parts[1] ?? $r['raw'];
$json = json_decode($respBody, true);
if (is_array($json) && (isset($json['ok']) && ($json['ok']===true || $json['ok']=='true'))) {
    logmsg("PASS: chat_post accepted request with token.");
} else {
    echo "FAIL: chat_post did not accept request with token. Response: $respBody\n"; exit(4);
}

// 5) Test revoke_entry without token -> expect failure
logmsg("[5] Testing revoke_entry.php without token (expect csrf failure)...");
$revokeUrl = rtrim($base, '/') . '/revoke_entry.php';
$postFields = http_build_query(['token'=>'test-token-123','days'=>1]);
$r = curl_request('POST', $revokeUrl, ['body'=>$postFields, 'headers'=>['Content-Type: application/x-www-form-urlencoded']]);
$parts = preg_split("/\r?\n\r?\n/", $r['raw'], 2);
$respBody = $parts[1] ?? $r['raw'];
$json = json_decode($respBody, true);
$ok_revoke_no_token = false;
if (is_array($json) && isset($json['message']) && $json['message']==='csrf_failed') $ok_revoke_no_token = true;
if ($ok_revoke_no_token) logmsg("PASS: revoke_entry rejected request without token."); else { echo "FAIL: revoke_entry did NOT reject request without token. Response: $respBody\n"; exit(5); }

// 6) Test revoke_entry with token -> expect success
logmsg("[6] Testing revoke_entry.php WITH token (expect success)...");
$postFields = http_build_query(['token'=>'test-token-123','days'=>1,'csrf_token'=>$token]);
$r = curl_request('POST', $revokeUrl, ['body'=>$postFields, 'headers'=>['Content-Type: application/x-www-form-urlencoded']]);
$parts = preg_split("/\r?\n\r?\n/", $r['raw'], 2);
$respBody = $parts[1] ?? $r['raw'];
$json = json_decode($respBody, true);
if (is_array($json) && isset($json['ok']) && $json['ok']===true) {
    logmsg("PASS: revoke_entry accepted request with token.");
} else {
    echo "FAIL: revoke_entry did not accept request with token. Response: $respBody\n"; exit(6);
}

logmsg("All CSRF tests passed.");
exit(0);
