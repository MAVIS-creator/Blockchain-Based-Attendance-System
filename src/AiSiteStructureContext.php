<?php

require_once __DIR__ . '/../env_helpers.php';

class AiSiteStructureContext
{
  private static $bundleCache = [];

  public static function clearCache()
  {
    self::$bundleCache = [];
    $file = self::cacheFile();
    if (file_exists($file)) {
      @unlink($file);
    }
  }

  public static function rebuild($audience = 'admin')
  {
    self::clearCache();
    return self::getBundle($audience);
  }

  public static function getCacheSnapshot()
  {
    $cache = self::loadCache();
    return is_array($cache) ? $cache : [];
  }

  public static function getBundle($audience = 'admin')
  {
    $audience = strtolower(trim((string)$audience));
    if ($audience === '') {
      $audience = 'admin';
    }

    if (!self::enabled()) {
      return [
        'enabled' => false,
        'context' => '',
        'meta' => self::baseMeta(),
      ];
    }

    if (isset(self::$bundleCache[$audience])) {
      return self::$bundleCache[$audience];
    }

    $cache = self::loadCache();
    $files = self::collectSourceFiles();
    $signature = self::buildSignature($files);
    $maxChars = self::maxChars();

    $payload = [];
    $generatedAt = date('c');

    if (is_array($cache) && ($cache['signature'] ?? '') === $signature && !empty($cache['payload']) && is_array($cache['payload'])) {
      $payload = $cache['payload'];
      $generatedAt = (string)($cache['generated_at'] ?? $generatedAt);
    } else {
      $payload = self::buildPayload($files);
      self::saveCache([
        'version' => 'site-context-v1',
        'generated_at' => $generatedAt,
        'signature' => $signature,
        'payload' => $payload,
      ]);
    }

    $context = self::renderContext($payload, $audience, $maxChars);
    $meta = [
      'version' => 'site-context-v1',
      'source_count' => (int)($payload['source_count'] ?? 0),
      'indexed_pages' => (int)($payload['indexed_pages'] ?? 0),
      'last_scan_at' => $generatedAt,
      'signature' => $signature,
      'max_chars' => $maxChars,
      'scan_enabled' => self::scanEnabled(),
    ];

    $bundle = [
      'enabled' => true,
      'context' => $context,
      'meta' => $meta,
    ];

    self::$bundleCache[$audience] = $bundle;
    return $bundle;
  }

  private static function baseMeta()
  {
    return [
      'version' => 'site-context-v1',
      'source_count' => 0,
      'indexed_pages' => 0,
      'last_scan_at' => null,
      'signature' => '',
      'max_chars' => self::maxChars(),
      'scan_enabled' => self::scanEnabled(),
    ];
  }

  private static function enabled()
  {
    $flag = strtolower(trim((string)app_env_value('AI_SITE_CONTEXT_ENABLED', 'true')));
    return in_array($flag, ['1', 'true', 'yes', 'on'], true);
  }

  private static function scanEnabled()
  {
    $flag = strtolower(trim((string)app_env_value('AI_SITE_CONTEXT_SCAN_ENABLED', 'true')));
    return in_array($flag, ['1', 'true', 'yes', 'on'], true);
  }

  private static function maxChars()
  {
    $n = (int)app_env_value('AI_SITE_CONTEXT_MAX_CHARS', '3000');
    if ($n < 800) {
      $n = 800;
    }
    if ($n > 12000) {
      $n = 12000;
    }
    return $n;
  }

  private static function maxFiles()
  {
    $n = (int)app_env_value('AI_SITE_CONTEXT_MAX_FILES', '350');
    if ($n < 30) {
      $n = 30;
    }
    if ($n > 3000) {
      $n = 3000;
    }
    return $n;
  }

  private static function includePatterns()
  {
    $raw = trim((string)app_env_value('AI_SITE_CONTEXT_SCAN_INCLUDE', '*.php,*.md'));
    $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), function ($v) {
      return $v !== '';
    }));
    return empty($parts) ? ['*.php', '*.md'] : $parts;
  }

  private static function excludePrefixes()
  {
    $raw = trim((string)app_env_value('AI_SITE_CONTEXT_SCAN_EXCLUDE', 'vendor/,storage/logs/,storage/backups/,admin/backups/,storage/admin/'));
    $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), function ($v) {
      return $v !== '';
    }));
    return $parts;
  }

  private static function projectRoot()
  {
    return realpath(__DIR__ . '/..') ?: dirname(__DIR__);
  }

  private static function cacheFile()
  {
    return self::projectRoot() . '/storage/admin/ai_site_context_cache.json';
  }

  private static function loadCache()
  {
    $file = self::cacheFile();
    if (!file_exists($file)) {
      return null;
    }

    $raw = @file_get_contents($file);
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : null;
  }

  private static function saveCache(array $cache)
  {
    $file = self::cacheFile();
    $dir = dirname($file);
    if (!is_dir($dir)) {
      @mkdir($dir, 0755, true);
    }

    @file_put_contents($file, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  }

  private static function collectSourceFiles()
  {
    $root = self::projectRoot();
    $files = [];

    $seed = [
      'README.md',
      'PROJECT_STRUCTURE.md',
      'index.php',
      'support.php',
      'admin/index.php',
      'admin/includes/sidebar.php',
    ];

    foreach ($seed as $rel) {
      $abs = $root . '/' . str_replace('\\', '/', $rel);
      if (file_exists($abs) && is_file($abs)) {
        $files[$rel] = $abs;
      }
    }

    if (!self::scanEnabled()) {
      ksort($files, SORT_STRING);
      return $files;
    }

    $includes = self::includePatterns();
    $excludes = self::excludePrefixes();

    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
      if (!$item->isFile()) {
        continue;
      }

      $absPath = (string)$item->getPathname();
      $rel = self::relativePath($absPath);
      if ($rel === '' || self::isExcluded($rel, $excludes)) {
        continue;
      }

      if (!self::matchesInclude($rel, $includes)) {
        continue;
      }

      $files[$rel] = $absPath;
      if (count($files) >= self::maxFiles()) {
        break;
      }
    }

    ksort($files, SORT_STRING);
    return $files;
  }

  private static function relativePath($absPath)
  {
    $root = rtrim(str_replace('\\', '/', self::projectRoot()), '/');
    $abs = str_replace('\\', '/', (string)$absPath);
    if (strpos($abs, $root . '/') === 0) {
      return substr($abs, strlen($root) + 1);
    }
    return '';
  }

  private static function isExcluded($relPath, array $excludes)
  {
    $rel = ltrim(str_replace('\\', '/', strtolower((string)$relPath)), '/');
    foreach ($excludes as $prefix) {
      $p = trim(strtolower(str_replace('\\', '/', (string)$prefix)));
      if ($p === '') {
        continue;
      }
      $p = trim($p, '/');
      if ($p === '') {
        continue;
      }
      if ($rel === $p || strpos($rel, $p . '/') === 0) {
        return true;
      }
    }
    return false;
  }

  private static function matchesInclude($relPath, array $includes)
  {
    $rel = str_replace('\\', '/', (string)$relPath);
    $base = basename($rel);

    foreach ($includes as $pattern) {
      $p = trim((string)$pattern);
      if ($p === '') {
        continue;
      }

      if (fnmatch($p, $base, FNM_CASEFOLD) || fnmatch($p, $rel, FNM_CASEFOLD)) {
        return true;
      }
    }

    return false;
  }

  private static function buildSignature(array $files)
  {
    $fingerprint = [];
    foreach ($files as $rel => $abs) {
      $fingerprint[$rel] = @filemtime($abs) ?: 0;
    }
    ksort($fingerprint, SORT_STRING);
    return sha1((string)json_encode($fingerprint));
  }

  private static function buildPayload(array $files)
  {
    $routes = [];
    $docs = [];
    $sidebarItems = [];

    foreach ($files as $rel => $abs) {
      $ext = strtolower((string)pathinfo($rel, PATHINFO_EXTENSION));
      $raw = (string)@file_get_contents($abs);
      if ($raw === '') {
        continue;
      }

      if ($rel === 'admin/includes/sidebar.php') {
        $sidebarItems = self::parseSidebarRoutes($raw);
      }

      if ($ext === 'php') {
        $entry = self::inferRouteEntry($rel, $raw);
        if (!empty($entry)) {
          $routes[] = $entry;
        }
      } elseif ($ext === 'md') {
        $docEntry = self::inferDocEntry($rel, $raw);
        if (!empty($docEntry)) {
          $docs[] = $docEntry;
        }
      }
    }

    $routes = self::dedupeByKey($routes, 'route');
    $docs = self::dedupeByKey($docs, 'file');

    return [
      'source_count' => count($files),
      'indexed_pages' => count($routes),
      'routes' => $routes,
      'sidebar' => $sidebarItems,
      'docs' => array_slice($docs, 0, 40),
    ];
  }

  private static function inferRouteEntry($rel, $raw)
  {
    $path = str_replace('\\', '/', $rel);
    $name = basename($path);

    if (strpos($path, 'vendor/') === 0) {
      return null;
    }

    $label = ucwords(str_replace(['_', '-'], ' ', pathinfo($name, PATHINFO_FILENAME)));
    $summary = self::extractSummaryLine($raw);

    if (strpos($path, 'admin/') === 0) {
      if ($name === 'index.php') {
        return [
          'route' => 'admin/index.php?page=<module>',
          'label' => 'Admin Router',
          'file' => $path,
          'summary' => $summary !== '' ? $summary : 'Admin entry router for page modules.',
          'scope' => 'admin',
        ];
      }

      return [
        'route' => $path,
        'label' => $label,
        'file' => $path,
        'summary' => $summary,
        'scope' => 'admin',
      ];
    }

    return [
      'route' => $path,
      'label' => $label,
      'file' => $path,
      'summary' => $summary,
      'scope' => 'public',
    ];
  }

  private static function inferDocEntry($rel, $raw)
  {
    $firstHeading = '';
    if (preg_match('/^\s*#{1,3}\s+(.+)$/m', $raw, $m)) {
      $firstHeading = trim((string)$m[1]);
    }

    return [
      'file' => str_replace('\\', '/', $rel),
      'title' => $firstHeading !== '' ? $firstHeading : basename($rel),
      'summary' => self::extractSummaryLine($raw),
    ];
  }

  private static function extractSummaryLine($raw)
  {
    $lines = preg_split('/\R/', (string)$raw) ?: [];
    foreach ($lines as $line) {
      $line = trim((string)$line);
      if ($line === '') {
        continue;
      }
      if (strpos($line, '<?php') === 0 || strpos($line, '//') === 0 || strpos($line, '/*') === 0 || strpos($line, '*') === 0 || strpos($line, '#') === 0 || strpos($line, '<') === 0) {
        continue;
      }
      $line = preg_replace('/\s+/', ' ', $line);
      return mb_substr((string)$line, 0, 160);
    }
    return '';
  }

  private static function parseSidebarRoutes($raw)
  {
    $items = [];

    if (preg_match_all('#href="index\.php\?page=([^"]+)"[^>]*>.*?<span class="label-text">([^<]+)</span>#si', (string)$raw, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $m) {
        $page = trim((string)($m[1] ?? ''));
        $label = trim((string)($m[2] ?? ''));
        if ($page === '') {
          continue;
        }
        $items[] = [
          'page' => $page,
          'label' => $label !== '' ? $label : $page,
          'route' => 'admin/index.php?page=' . $page,
        ];
      }
    }

    return self::dedupeByKey($items, 'route');
  }

  private static function dedupeByKey(array $rows, $key)
  {
    $seen = [];
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $k = (string)($row[$key] ?? '');
      if ($k === '' || isset($seen[$k])) {
        continue;
      }
      $seen[$k] = true;
      $out[] = $row;
    }
    return $out;
  }

  private static function renderContext(array $payload, $audience, $maxChars)
  {
    $lines = [];

    $lines[] = 'Response policy:';
    $lines[] = '- Answer only from listed routes/files.';
    $lines[] = '- Give direct path-based instructions when possible.';
    $lines[] = '- If a page/route is not listed, say unsure and ask for route confirmation.';
    $lines[] = '- Keep answers concise and operational.';

    $sidebar = is_array($payload['sidebar'] ?? null) ? $payload['sidebar'] : [];
    if (!empty($sidebar)) {
      $lines[] = '';
      $lines[] = 'Admin sidebar navigation:';
      foreach (array_slice($sidebar, 0, 60) as $item) {
        $lines[] = '- ' . (string)($item['label'] ?? 'Page') . ' => ' . (string)($item['route'] ?? '');
      }
    }

    $routes = is_array($payload['routes'] ?? null) ? $payload['routes'] : [];
    if (!empty($routes)) {
      $lines[] = '';
      $lines[] = 'Indexed routes/pages:';

      $filtered = [];
      foreach ($routes as $r) {
        $scope = (string)($r['scope'] ?? 'public');
        if ($audience === 'student_fingerprint_message' && $scope === 'admin') {
          continue;
        }
        $filtered[] = $r;
      }

      $limit = $audience === 'student_fingerprint_message' ? 60 : 120;
      foreach (array_slice($filtered, 0, $limit) as $route) {
        $summary = trim((string)($route['summary'] ?? ''));
        $line = '- ' . (string)($route['route'] ?? '') . ' (' . (string)($route['scope'] ?? 'public') . ')';
        if ($summary !== '') {
          $line .= ': ' . $summary;
        }
        $lines[] = $line;
      }
    }

    $docs = is_array($payload['docs'] ?? null) ? $payload['docs'] : [];
    if (!empty($docs)) {
      $lines[] = '';
      $lines[] = 'Key docs for structure/reference:';
      foreach (array_slice($docs, 0, 18) as $doc) {
        $lines[] = '- ' . (string)($doc['file'] ?? '') . ' :: ' . (string)($doc['title'] ?? '');
      }
    }

    $context = implode("\n", $lines);
    if (strlen($context) > $maxChars) {
      $context = substr($context, 0, $maxChars - 40) . "\n...[truncated for token budget]";
    }

    return $context;
  }
}
