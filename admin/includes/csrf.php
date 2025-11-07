<?php
// Simple CSRF helper for admin pages
if (session_status() === PHP_SESSION_NONE) session_start();

/**
 * Return (and create if missing) a CSRF token stored in session.
 * Tokens expire after a short TTL.
 */
function csrf_token($regenerate = false) {
    $ttl = 60 * 60 * 4; // 4 hours
    if (!isset($_SESSION['_csrf'])) $_SESSION['_csrf'] = [];
    if (!$regenerate && !empty($_SESSION['_csrf']['token']) && !empty($_SESSION['_csrf']['expires']) && $_SESSION['_csrf']['expires'] > time()) {
        return $_SESSION['_csrf']['token'];
    }
    try { $tok = bin2hex(random_bytes(24)); } catch (Exception $e) { $tok = bin2hex(openssl_random_pseudo_bytes(24)); }
    $_SESSION['_csrf']['token'] = $tok;
    $_SESSION['_csrf']['expires'] = time() + $ttl;
    return $tok;
}

/**
 * Echo a hidden input field for forms
 */
function csrf_field() {
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Validate request for non-GET methods.
 * Accepts token from header X-CSRF-Token, POST field csrf_token or JSON body {csrf_token:...}
 */
function csrf_check_request() {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') return true;

    $token = '';
    // header (common names)
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
    elseif (!empty($_SERVER['HTTP_X_ADMIN_CSRF'])) $token = $_SERVER['HTTP_X_ADMIN_CSRF'];

    // POST / form
    if (!$token && isset($_POST['csrf_token'])) $token = $_POST['csrf_token'];

    // JSON body
    if (!$token) {
        $raw = @file_get_contents('php://input');
        if ($raw) {
            $json = json_decode($raw, true);
            if (is_array($json) && isset($json['csrf_token'])) $token = $json['csrf_token'];
        }
    }

    if (!$token) return false;
    if (empty($_SESSION['_csrf']['token'])) return false;
    if (!hash_equals($_SESSION['_csrf']['token'], (string)$token)) return false;
    if (empty($_SESSION['_csrf']['expires']) || $_SESSION['_csrf']['expires'] < time()) return false;
    return true;
}
<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// Return or create CSRF token stored in session
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        try { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }
        catch (Exception $e) { $_SESSION['csrf_token'] = bin2hex(mt_rand()); }
    }
    return (string) $_SESSION['csrf_token'];
}

// Validate token from various possible places: header X-CSRF-Token, POST field, or JSON body
function csrf_check_request(): bool {
    $expected = csrf_token();
    // header
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if ($header && hash_equals($expected, (string)$header)) return true;
    // POST form
    if (!empty($_POST['csrf_token']) && hash_equals($expected, (string)$_POST['csrf_token'])) return true;
    // JSON body
    $raw = @file_get_contents('php://input');
    if ($raw) {
        $d = json_decode($raw, true);
        if (is_array($d) && !empty($d['csrf_token']) && hash_equals($expected, (string)$d['csrf_token'])) return true;
    }
    return false;
}

// Helper to output a hidden input field for forms
function csrf_input_field(): string {
    $t = htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE);
    return "<input type=\"hidden\" name=\"csrf_token\" value=\"{$t}\">";
}

?>
