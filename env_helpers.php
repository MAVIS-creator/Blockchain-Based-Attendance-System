<?php

if (!function_exists('app_is_local_environment')) {
  function app_is_local_environment()
  {
    $appEnv = strtolower((string)(getenv('APP_ENV') ?: ''));
    if (in_array($appEnv, ['local', 'development', 'dev'], true)) {
      return true;
    }

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $serverAddr = strtolower((string)($_SERVER['SERVER_ADDR'] ?? ''));
    $remoteAddr = strtolower((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    foreach ([$host, $serverAddr, $remoteAddr] as $value) {
      if ($value === '') continue;
      if ($value === 'localhost' || $value === '127.0.0.1' || $value === '::1') {
        return true;
      }
      if (strpos($value, 'localhost:') === 0 || strpos($value, '127.0.0.1:') === 0 || strpos($value, '[::1]:') === 0) {
        return true;
      }
    }

    return PHP_SAPI === 'cli' && $appEnv !== 'production';
  }
}

if (!function_exists('app_load_env_file')) {
  function app_load_env_file($path)
  {
    $env = [];
    if (!is_string($path) || $path === '' || !file_exists($path)) {
      return $env;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
      $line = trim((string)$line);
      if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
      list($k, $v) = explode('=', $line, 2);
      $env[trim($k)] = trim(trim($v), "\"'");
    }

    return $env;
  }
}

if (!function_exists('app_load_env_layers')) {
  function app_load_env_layers($baseEnvPath)
  {
    $env = app_load_env_file($baseEnvPath);
    $baseDir = dirname((string)$baseEnvPath);
    $localPath = $baseDir . DIRECTORY_SEPARATOR . '.env.local';

    $appEnv = strtolower((string)($env['APP_ENV'] ?? getenv('APP_ENV') ?: ''));
    $shouldLoadLocal = app_is_local_environment() || in_array($appEnv, ['local', 'development', 'dev'], true);

    if ($shouldLoadLocal && file_exists($localPath)) {
      $env = array_merge($env, app_load_env_file($localPath));
    }

    return $env;
  }
}

if (!function_exists('app_env_value')) {
  function app_env_value($key, $default = null, $baseEnvPath = null)
  {
    $val = getenv($key);
    if ($val !== false && $val !== '') return $val;

    static $cache = [];
    $path = $baseEnvPath ?: (__DIR__ . DIRECTORY_SEPARATOR . '.env');
    if (!isset($cache[$path])) {
      $cache[$path] = app_load_env_layers($path);
    }

    return array_key_exists($key, $cache[$path]) ? $cache[$path][$key] : $default;
  }
}

