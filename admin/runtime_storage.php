<?php

require_once __DIR__ . '/../storage_helpers.php';

app_storage_init();

if (!function_exists('admin_storage_file')) {
  function admin_storage_file($relative)
  {
    return app_storage_file('admin/' . ltrim((string)$relative, '/\\'));
  }
}

if (!function_exists('admin_storage_migrate_file')) {
  function admin_storage_migrate_file($relative, $legacyPath = null)
  {
    $legacy = $legacyPath;
    if ($legacy === null) {
      $legacy = __DIR__ . '/' . ltrim(str_replace('/', DIRECTORY_SEPARATOR, (string)$relative), DIRECTORY_SEPARATOR);
    }
    return app_storage_migrate_file('admin/' . ltrim((string)$relative, '/\\'), $legacy);
  }
}

if (!function_exists('admin_course_storage_file')) {
  function admin_course_storage_file($relative)
  {
    return app_storage_file('admin/courses/' . ltrim((string)$relative, '/\\'));
  }
}

if (!function_exists('admin_course_storage_migrate_file')) {
  function admin_course_storage_migrate_file($relative, $legacyPath = null)
  {
    $legacy = $legacyPath;
    if ($legacy === null) {
      $legacy = __DIR__ . '/courses/' . ltrim(str_replace('/', DIRECTORY_SEPARATOR, (string)$relative), DIRECTORY_SEPARATOR);
    }
    return app_storage_migrate_file('admin/courses/' . ltrim((string)$relative, '/\\'), $legacy);
  }
}
