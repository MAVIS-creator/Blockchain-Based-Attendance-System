<?php

// Auto-prepend timing bootstrap for all web requests.
// This file is loaded by Apache via .htaccess (auto_prepend_file).

if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
  return;
}

require_once __DIR__ . '/request_timing.php';

if (function_exists('request_timing_auto_start')) {
  request_timing_auto_start();
}
