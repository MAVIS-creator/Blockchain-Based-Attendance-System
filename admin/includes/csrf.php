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
