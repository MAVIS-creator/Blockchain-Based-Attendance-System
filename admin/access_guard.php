<?php
declare(strict_types=1);

// Required by embedded admin views as well as direct requests.
// The admin/index.php router alone does not protect direct .php URLs.
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/state_helpers.php';

if (!function_exists('bbas_admin_require_page')) {
    function bbas_admin_require_page(string $pageId): void
    {
        if (empty($_SESSION['admin_logged_in'])) {
            http_response_code(403);
            header('Cache-Control: no-store');
            exit('Access denied');
        }
        $role = (string)($_SESSION['admin_role'] ?? 'admin');
        if ($role === 'superadmin') return;
        $permissions = admin_load_permissions_cached(5);
        $allowed = is_array($permissions) ? ($permissions[$role] ?? []) : [];
        if (!is_array($allowed) || !in_array($pageId, $allowed, true)) {
            http_response_code(403);
            header('Cache-Control: no-store');
            exit('Not authorized for this admin page');
        }
    }
}
