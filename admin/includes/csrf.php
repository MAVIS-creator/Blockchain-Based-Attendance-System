<?php
// Simple CSRF helper for admin pages
require_once __DIR__ . '/../session_bootstrap.php';

if (!defined('ADMIN_CSRF_COOKIE')) {
    define('ADMIN_CSRF_COOKIE', 'ATTENDANCE_ADMIN_CSRF');
}

if (!function_exists('csrf_cookie_is_secure')) {
    function csrf_cookie_is_secure()
    {
        $httpsForwarded = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $httpsNative = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
        return ($httpsForwarded || $httpsNative);
    }
}

if (!function_exists('csrf_cookie_set')) {
    function csrf_cookie_set($token, $ttl)
    {
        $expires = time() + max(60, (int)$ttl);
        setcookie(ADMIN_CSRF_COOKIE, (string)$token, [
            'expires' => $expires,
            'path' => '/',
            'domain' => '',
            'secure' => csrf_cookie_is_secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        // Keep request-scope visibility consistent after setcookie.
        $_COOKIE[ADMIN_CSRF_COOKIE] = (string)$token;
    }
}

if (!function_exists('csrf_expected_token')) {
    function csrf_expected_token()
    {
        if (!empty($_SESSION['_csrf']['token'])) {
            return (string)$_SESSION['_csrf']['token'];
        }
        if (!empty($_COOKIE[ADMIN_CSRF_COOKIE])) {
            return (string)$_COOKIE[ADMIN_CSRF_COOKIE];
        }
        if (!empty($_SESSION['csrf_token'])) {
            return (string)$_SESSION['csrf_token'];
        }
        return '';
    }
}

/**
 * Return (and create if missing) a CSRF token stored in session.
 * Tokens expire after a short TTL.
 */
if (!function_exists('csrf_token')) {
    function csrf_token($regenerate = false)
    {
        $ttl = 60 * 60 * 4; // 4 hours
        if (!isset($_SESSION['_csrf'])) $_SESSION['_csrf'] = [];
        if (!$regenerate) {
            if (!empty($_SESSION['_csrf']['token']) && !empty($_SESSION['_csrf']['expires']) && $_SESSION['_csrf']['expires'] > time()) {
                return $_SESSION['_csrf']['token'];
            }
            if (!empty($_COOKIE[ADMIN_CSRF_COOKIE])) {
                $tok = (string)$_COOKIE[ADMIN_CSRF_COOKIE];
                $_SESSION['_csrf']['token'] = $tok;
                $_SESSION['_csrf']['expires'] = time() + $ttl;
                $_SESSION['csrf_token'] = $tok;
                return $tok;
            }
        }
        try {
            $tok = bin2hex(random_bytes(24));
        } catch (Exception $e) {
            $tok = bin2hex(openssl_random_pseudo_bytes(24));
        }
        $_SESSION['_csrf']['token'] = $tok;
        $_SESSION['_csrf']['expires'] = time() + $ttl;
        // Backwards-compatible alias used by some pages
        $_SESSION['csrf_token'] = $tok;
        csrf_cookie_set($tok, $ttl);
        return $tok;
    }
}

/**
 * Echo a hidden input field for forms
 */
if (!function_exists('csrf_field')) {
    function csrf_field()
    {
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
    }
}

/**
 * Backwards-compatible helper used in some older files which expected
 * a function that returns the input HTML rather than echoing it.
 *
 * Returns the hidden input HTML string.
 */
if (!function_exists('csrf_input_field')) {
    function csrf_input_field()
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
    }
}

/**
 * Validate request for non-GET methods.
 * Accepts token from header X-CSRF-Token, POST field csrf_token or JSON body {csrf_token:...}
 */
if (!function_exists('csrf_check_request')) {
    function csrf_check_request()
    {
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

        $expected = csrf_expected_token();
        if ($expected === '') {
            // Session recovery can restore admin auth without CSRF state.
            // Rehydrate CSRF state from the submitted token for authenticated admin sessions.
            if (!empty($_SESSION['admin_logged_in'])) {
                $ttl = 60 * 60 * 4;
                if (!isset($_SESSION['_csrf']) || !is_array($_SESSION['_csrf'])) {
                    $_SESSION['_csrf'] = [];
                }
                $_SESSION['_csrf']['token'] = (string)$token;
                $_SESSION['_csrf']['expires'] = time() + $ttl;
                $_SESSION['csrf_token'] = (string)$token;
                csrf_cookie_set((string)$token, $ttl);
                return true;
            }
            return false;
        }
        if (!hash_equals($expected, (string)$token)) return false;

        if (!empty($_SESSION['_csrf']['expires']) && $_SESSION['_csrf']['expires'] < time()) {
            return false;
        }
        return true;
    }
}
