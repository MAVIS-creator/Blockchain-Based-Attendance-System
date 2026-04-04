<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_logged_in'])) {
  header('Location: login.php');
  exit;
}
if (($_SESSION['admin_role'] ?? 'admin') !== 'superadmin') {
  echo '<div style="padding:20px"><h2>Access denied</h2><p>Only superadmin can access Patcher.</p></div>';
  return;
}
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/../env_helpers.php';
$csrf = csrf_token();
$projectRoot = realpath(__DIR__ . '/..');
$patcherEnv = app_load_env_layers(__DIR__ . '/../.env');

function patcher_allowed_extensions()
{
  return ['php', 'md', 'json', 'txt', 'js', 'css', 'html', 'env', 'sql', 'yml', 'yaml'];
}
function patcher_safe_rel_path($input)
{
  $p = ltrim(str_replace('\\', '/', trim((string)$input)), '/');
  return ($p === '' || strpos($p, '..') !== false) ? '' : $p;
}
function patcher_extension_allowed($rel)
{
  if (in_array(strtolower((string)$rel), ['.env', '.env.local'], true)) return true;
  return in_array(strtolower(pathinfo((string)$rel, PATHINFO_EXTENSION)), patcher_allowed_extensions(), true);
}
function patcher_absolute_path($root, $rel)
{
  $rel = patcher_safe_rel_path($rel);
  return $rel === '' ? '' : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}
function patcher_run_cmd($command, $cwd = null, $stdin = '')
{
  $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
  $p = @proc_open($command, $spec, $pipes, $cwd ?: null);
  if (!is_resource($p)) return ['ok' => false, 'out' => '', 'err' => 'Failed to start process.'];
  fwrite($pipes[0], (string)$stdin);
  fclose($pipes[0]);
  $out = stream_get_contents($pipes[1]);
  $err = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exit = proc_close($p);
  return ['ok' => $exit === 0, 'out' => (string)$out, 'err' => (string)$err];
}
function patcher_list_files($root, $max = 1400)
{
  $res = [];
  $skip = ['.git', 'vendor', 'node_modules'];
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
  foreach ($it as $f) {
    $full = $f->getPathname();
    $rel = str_replace('\\', '/', ltrim(substr($full, strlen($root)), '\\/'));
    foreach ($skip as $sd) {
      if (preg_match('#(^|/)' . preg_quote($sd, '#') . '(/|$)#', $rel)) continue 2;
    }
    if ($f->isDir()) continue;
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if (!in_array($ext, patcher_allowed_extensions(), true) && !in_array(strtolower($rel), ['.env', '.env.local'], true)) continue;
    $res[] = ['path' => $rel, 'size' => (int)$f->getSize(), 'mtime' => (int)$f->getMTime()];
    if (count($res) >= $max) break;
  }
  usort($res, fn($a, $b) => strcmp($a['path'], $b['path']));
  return $res;
}
function patcher_ai_enabled($env)
{
  return in_array(strtolower((string)($env['PATCHER_AI_ENABLED'] ?? 'true')), ['1', 'true', 'yes', 'on'], true);
}
function patcher_extract_json_block($text)
{
  $text = trim((string)$text);
  $d = json_decode($text, true);
  if (is_array($d)) return $d;
  if (preg_match('/```json\s*(\{.*\})\s*```/is', $text, $m)) {
    $d = json_decode($m[1], true);
    if (is_array($d)) return $d;
  }
  $s = strpos($text, '{');
  $e = strrpos($text, '}');
  if ($s !== false && $e !== false && $e > $s) {
    $d = json_decode(substr($text, $s, $e - $s + 1), true);
    if (is_array($d)) return $d;
  }
  return null;
}
function patcher_redact_secrets_text($text)
{
  $text = (string)$text;
  $patterns = [
    '/(Bearer\s+)[A-Za-z0-9._\-]+/i' => '$1***REDACTED***',
    '/(sk-(?:or-v1|live|test)-)[A-Za-z0-9]+/i' => '$1***REDACTED***',
    '/(AIza)[A-Za-z0-9_\-]{20,}/i' => '$1***REDACTED***',
    '/((?:api[_-]?key|token|secret|password|pass|private[_-]?key)\s*[=:]\s*)([^\r\n]+)/i' => '$1***REDACTED***',
  ];
  foreach ($patterns as $rx => $replacement) {
    $text = preg_replace($rx, $replacement, $text);
  }
  return $text;
}
function patcher_prompt_safe_text($text)
{
  return patcher_redact_secrets_text((string)$text);
}
function patcher_ai_normalize_result($decoded, $fallbackText = '')
{
  $decoded = is_array($decoded) ? $decoded : [];
  $risk = strtolower(trim((string)($decoded['risk'] ?? 'medium')));
  if (!in_array($risk, ['low', 'medium', 'high'], true)) $risk = 'medium';

  $checks = array_values(array_filter(array_map(function ($item) {
    return trim((string)$item);
  }, is_array($decoded['checks'] ?? null) ? $decoded['checks'] : []), fn($v) => $v !== ''));

  $notes = array_values(array_filter(array_map(function ($item) {
    return trim((string)$item);
  }, is_array($decoded['notes'] ?? null) ? $decoded['notes'] : []), fn($v) => $v !== ''));

  $fixOptions = array_values(array_filter(array_map(function ($item) {
    return trim((string)$item);
  }, is_array($decoded['fix_options'] ?? null) ? $decoded['fix_options'] : []), fn($v) => $v !== ''));

  $tradeoffs = array_values(array_filter(array_map(function ($item) {
    return trim((string)$item);
  }, is_array($decoded['tradeoffs'] ?? null) ? $decoded['tradeoffs'] : []), fn($v) => $v !== ''));

  $result = [
    'summary' => trim((string)($decoded['summary'] ?? 'AI analysis completed')),
    'explanation' => trim((string)($decoded['explanation'] ?? '')),
    'root_cause' => trim((string)($decoded['root_cause'] ?? '')),
    'risk' => $risk,
    'checks' => $checks,
    'fix_options' => $fixOptions,
    'tradeoffs' => $tradeoffs,
    'patch_preview' => trim((string)($decoded['patch_preview'] ?? '')),
    'target_file' => patcher_safe_rel_path((string)($decoded['target_file'] ?? '')),
    'improved_code' => (string)($decoded['improved_code'] ?? ''),
    'notes' => $notes,
    'schema_valid' => true,
  ];

  $required = ['summary', 'explanation', 'patch_preview'];
  foreach ($required as $key) {
    if ($result[$key] === '') {
      $result['schema_valid'] = false;
      break;
    }
  }

  if ($result['explanation'] === '') {
    $result['explanation'] = trim((string)$fallbackText) !== '' ? trim((string)$fallbackText) : 'No explanation returned by provider.';
  }
  if ($result['root_cause'] === '') {
    $result['root_cause'] = $result['explanation'];
  }
  if (empty($result['fix_options'])) {
    $result['fix_options'] = ['Apply the minimal patch to ' . ($result['target_file'] !== '' ? $result['target_file'] : 'the suggested file') . ' and validate with listed checks.'];
  }
  if (empty($result['tradeoffs'])) {
    $result['tradeoffs'] = ['Minimal edits reduce regression risk but may not address broader architectural cleanup in this pass.'];
  }
  if ($result['patch_preview'] === '') {
    $result['patch_preview'] = trim((string)$fallbackText) !== '' ? trim((string)$fallbackText) : '(No patch preview returned by provider)';
  }

  return $result;
}
function patcher_ai_prompt($path, $issue, $content)
{
  $content = patcher_prompt_safe_text($content);
  return "You are an API-backed patch assistant for a PHP repository. Return strict JSON only. Schema: {\"summary\":\"short title\",\"explanation\":\"why this matters\",\"root_cause\":\"primary cause\",\"fix_options\":[\"option\"],\"tradeoffs\":[\"tradeoff\"],\"risk\":\"low|medium|high\",\"checks\":[\"step\"],\"patch_preview\":\"preview\",\"improved_code\":\"full improved file content\"}. Guidance: minimal production-safe edits; preserve storage helpers, CSRF, SweetAlert UX, Azure Web App Linux and localhost support. Target file: {$path}. Issue: {$issue}. Current file:
```text
{$content}
```";
}
function patcher_ai_provider($env, $requested = '')
{
  $provider = strtolower(trim((string)$requested));
  if ($provider === '') $provider = strtolower(trim((string)($env['PATCHER_AI_PROVIDER'] ?? 'auto')));
  return in_array($provider, ['openrouter', 'gemini', 'auto'], true) ? $provider : 'auto';
}
function patcher_ai_model($env, $provider, $requested = '')
{
  $requested = trim((string)$requested);
  if ($requested !== '') return $requested;
  if ($provider === 'auto') {
    $primary = strtolower(trim((string)($env['PATCHER_AI_AUTO_PRIMARY'] ?? 'gemini')));
    if (!in_array($primary, ['openrouter', 'gemini'], true)) $primary = 'gemini';
    $provider = $primary;
  }
  if ($provider === 'gemini') return trim((string)($env['PATCHER_GEMINI_MODEL'] ?? 'gemini-2.0-flash'));
  return trim((string)($env['PATCHER_OPENROUTER_MODEL'] ?? 'openrouter/free'));
}
function patcher_ai_auto_strategy($env)
{
  $mode = strtolower(trim((string)($env['PATCHER_AI_AUTO_STRATEGY'] ?? 'balanced')));
  return in_array($mode, ['balanced', 'quality', 'speed', 'cost'], true) ? $mode : 'balanced';
}
function patcher_ai_provider_available($env, $provider)
{
  if ($provider === 'gemini') return trim((string)($env['PATCHER_GEMINI_API_KEY'] ?? '')) !== '';
  return trim((string)($env['PATCHER_OPENROUTER_API_KEY'] ?? '')) !== '';
}
function patcher_ai_pick_order($env, $issue = '', $scanMode = 'standard', $requestedModel = '')
{
  $issue = strtolower((string)$issue);
  $scanMode = strtolower((string)$scanMode);
  $strategy = patcher_ai_auto_strategy($env);
  $primary = strtolower(trim((string)($env['PATCHER_AI_AUTO_PRIMARY'] ?? 'gemini')));
  $fallback = strtolower(trim((string)($env['PATCHER_AI_AUTO_FALLBACK'] ?? 'openrouter')));
  if (!in_array($primary, ['openrouter', 'gemini'], true)) $primary = 'gemini';
  if (!in_array($fallback, ['openrouter', 'gemini'], true) || $fallback === $primary) {
    $fallback = $primary === 'gemini' ? 'openrouter' : 'gemini';
  }

  $qualityHints = ['security', 'architecture', 'refactor', 'multi-file', 'multi file', 'complex', 'hybrid', 'session'];
  $looksDeep = $scanMode === 'deep';
  foreach ($qualityHints as $hint) {
    if (strpos($issue, $hint) !== false) {
      $looksDeep = true;
      break;
    }
  }

  if ($strategy === 'quality' || ($strategy === 'balanced' && $looksDeep)) {
    $primary = 'gemini';
    $fallback = 'openrouter';
  } elseif ($strategy === 'speed' || $strategy === 'cost') {
    if ($primary === 'gemini') {
      $primary = 'openrouter';
      $fallback = 'gemini';
    }
  }

  $order = [
    ['provider' => $primary, 'model' => patcher_ai_model($env, $primary, $requestedModel)],
    ['provider' => $fallback, 'model' => patcher_ai_model($env, $fallback, $requestedModel)],
  ];

  return array_values(array_filter($order, function ($item) use ($env) {
    return patcher_ai_provider_available($env, $item['provider']);
  }));
}
function patcher_http_json($url, $headers, $payload)
{
  $body = json_encode($payload);
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => 90]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['ok' => $err === '' && $status >= 200 && $status < 300, 'status' => $status, 'body' => (string)$raw, 'error' => (string)$err];
  }
  $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => 90, 'ignore_errors' => true]]);
  $raw = @file_get_contents($url, false, $context);
  $status = 0;
  foreach (($http_response_header ?? []) as $header) {
    if (preg_match('#HTTP/\S+\s+(\d{3})#', $header, $m)) {
      $status = (int)$m[1];
      break;
    }
  }
  return ['ok' => $raw !== false && $status >= 200 && $status < 300, 'status' => $status, 'body' => (string)$raw, 'error' => $raw === false ? 'HTTP request failed.' : ''];
}
function patcher_ai_status_payload($env)
{
  $provider = patcher_ai_provider($env);
  $openrouterKey = trim((string)($env['PATCHER_OPENROUTER_API_KEY'] ?? ''));
  $geminiKey = trim((string)($env['PATCHER_GEMINI_API_KEY'] ?? ''));
  $hasAny = ($openrouterKey !== '') || ($geminiKey !== '');
  $providerReady = $provider === 'auto' ? $hasAny : ($provider === 'gemini' ? $geminiKey !== '' : $openrouterKey !== '');
  $strategy = patcher_ai_auto_strategy($env);
  $message = 'AI assistant disabled by config.';
  if (patcher_ai_enabled($env)) {
    if (!$hasAny) $message = 'No AI API key configured.';
    elseif ($provider === 'auto') $message = 'Auto provider enabled (' . $strategy . ' strategy).';
    elseif ($providerReady) $message = 'Configured for ' . ucfirst($provider) . ' API.';
    else $message = 'Selected provider ' . ucfirst($provider) . ' is missing its API key.';
  }
  return ['ok' => true, 'enabled' => patcher_ai_enabled($env), 'online' => $providerReady, 'provider' => $provider, 'strategy' => $strategy, 'available_providers' => ['openrouter' => $openrouterKey !== '', 'gemini' => $geminiKey !== ''], 'default_model' => patcher_ai_model($env, $provider), 'message' => $message];
}
function patcher_ai_generate_result($env, $provider, $model, $prompt)
{
  if ($provider === 'gemini') {
    $key = trim((string)($env['PATCHER_GEMINI_API_KEY'] ?? ''));
    if ($key === '') return ['ok' => false, 'message' => 'Gemini API key is missing.'];
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);
    $payload = ['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]], 'generationConfig' => ['temperature' => 0.2, 'responseMimeType' => 'application/json']];
    $res = patcher_http_json($url, ['Content-Type: application/json'], $payload);
    if (!$res['ok']) return ['ok' => false, 'message' => 'Gemini request failed.', 'output' => trim($res['body'] . "\n" . $res['error'])];
    $decoded = json_decode($res['body'], true);
    $raw = '';
    foreach (($decoded['candidates'][0]['content']['parts'] ?? []) as $part) {
      if (isset($part['text'])) $raw .= $part['text'];
    }
    return ['ok' => $raw !== '', 'raw' => trim($raw ?: $res['body'])];
  }
  $key = trim((string)($env['PATCHER_OPENROUTER_API_KEY'] ?? ''));
  if ($key === '') return ['ok' => false, 'message' => 'OpenRouter API key is missing.'];
  $url = trim((string)($env['PATCHER_OPENROUTER_API_URL'] ?? 'https://openrouter.ai/api/v1/chat/completions'));
  $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key, 'HTTP-Referer: ' . (trim((string)($env['APP_URL'] ?? 'http://localhost')) ?: 'http://localhost'), 'X-Title: Blockchain Attendance Patcher'];
  $payload = ['model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]], 'temperature' => 0.2];
  $res = patcher_http_json($url, $headers, $payload);
  if (!$res['ok']) return ['ok' => false, 'message' => 'OpenRouter request failed.', 'output' => trim($res['body'] . "\n" . $res['error'])];
  $decoded = json_decode($res['body'], true);
  $raw = (string)($decoded['choices'][0]['message']['content'] ?? '');
  return ['ok' => $raw !== '', 'raw' => trim($raw ?: $res['body'])];
}
function patcher_ai_generate_with_fallback($env, $provider, $model, $prompt, $issue = '', $scanMode = 'standard')
{
  $timeline = [];
  $attempts = [];
  $fallbackReason = '';

  if ($provider === 'auto') {
    $attempts = patcher_ai_pick_order($env, $issue, $scanMode, $model);
    if (empty($attempts)) {
      return ['ok' => false, 'message' => 'No available AI provider keys for auto mode.', 'timeline' => [['step' => 'Provider selection', 'status' => 'error', 'detail' => 'No provider key configured for auto mode.']]];
    }
    $timeline[] = ['step' => 'Provider selection', 'status' => 'success', 'detail' => 'Auto selected candidate order: ' . implode(' -> ', array_map(fn($a) => $a['provider'] . ':' . $a['model'], $attempts))];
  } else {
    $attempts[] = ['provider' => $provider, 'model' => patcher_ai_model($env, $provider, $model)];
  }

  $first = true;
  foreach ($attempts as $attempt) {
    $timeline[] = ['step' => 'AI request', 'status' => 'running', 'detail' => 'Calling ' . ucfirst($attempt['provider']) . ' (' . $attempt['model'] . ')'];
    $res = patcher_ai_generate_result($env, $attempt['provider'], $attempt['model'], $prompt);
    if ($res['ok']) {
      $timeline[] = ['step' => 'AI request', 'status' => 'success', 'detail' => 'Received response from ' . ucfirst($attempt['provider']) . '.'];
      return [
        'ok' => true,
        'raw' => $res['raw'] ?? '',
        'provider' => $attempt['provider'],
        'model' => $attempt['model'],
        'fallback_used' => !$first,
        'fallback_reason' => !$first ? $fallbackReason : '',
        'timeline' => $timeline,
      ];
    }
    $fallbackReason = trim((string)($res['message'] ?? 'Provider request failed.'));
    $timeline[] = ['step' => 'AI request', 'status' => 'error', 'detail' => $fallbackReason . ($first && count($attempts) > 1 ? ' Retrying with fallback provider...' : '')];
    $first = false;
  }

  return ['ok' => false, 'message' => 'All AI provider attempts failed.', 'fallback_reason' => $fallbackReason, 'timeline' => $timeline];
}
function patcher_issue_candidate_files($root, $issue, $scanMode = 'standard')
{
  $issueLower = strtolower((string)$issue);
  $scanMode = strtolower((string)$scanMode);
  $max = $scanMode === 'deep' ? 22 : ($scanMode === 'quick' ? 8 : 14);
  $files = patcher_list_files($root, 1800);
  $scored = [];
  $keywords = array_values(array_filter(preg_split('/[^a-z0-9_]+/i', $issueLower), fn($k) => strlen($k) >= 4));

  foreach ($files as $file) {
    $path = strtolower((string)$file['path']);
    $score = 0;
    foreach ($keywords as $kw) {
      if (strpos($path, $kw) !== false) $score += 4;
    }
    if (strpos($issueLower, 'hybrid') !== false && (strpos($path, 'hybrid') !== false || strpos($path, '.env') !== false || strpos($path, 'settings.php') !== false)) $score += 6;
    if (strpos($issueLower, 'profile') !== false && strpos($path, 'profile') !== false) $score += 5;
    if (strpos($issueLower, 'account') !== false && strpos($path, 'accounts') !== false) $score += 5;
    if (strpos($issueLower, 'mobile') !== false && (strpos($path, '.css') !== false || strpos($path, 'header.php') !== false || strpos($path, 'navbar.php') !== false)) $score += 5;
    if (strpos($path, 'admin/') === 0) $score += 1;
    if ($score > 0) $scored[] = ['path' => $file['path'], 'score' => $score];
  }

  usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
  $picked = array_slice(array_unique(array_map(fn($s) => $s['path'], $scored)), 0, $max);

  if (empty($picked)) {
    $fallback = ['admin/settings.php', 'admin/patcher.php', '.env', 'env_helpers.php'];
    foreach ($fallback as $f) {
      $abs = patcher_absolute_path($root, $f);
      if (is_file($abs)) $picked[] = $f;
      if (count($picked) >= min(6, $max)) break;
    }
  }

  return $picked;
}
function patcher_candidate_reasons($issue, array $candidates)
{
  $issueLower = strtolower((string)$issue);
  $reasons = [];
  foreach ($candidates as $path) {
    $pathLower = strtolower((string)$path);
    $hits = [];
    if (strpos($pathLower, 'admin/') === 0) $hits[] = 'admin-scope';
    if (strpos($issueLower, 'hybrid') !== false && (strpos($pathLower, 'hybrid') !== false || strpos($pathLower, '.env') !== false || strpos($pathLower, 'settings.php') !== false)) $hits[] = 'hybrid-keyword';
    if (strpos($issueLower, 'profile') !== false && strpos($pathLower, 'profile') !== false) $hits[] = 'profile-keyword';
    if (strpos($issueLower, 'account') !== false && strpos($pathLower, 'accounts') !== false) $hits[] = 'accounts-keyword';
    if (strpos($issueLower, 'mobile') !== false && (strpos($pathLower, '.css') !== false || strpos($pathLower, 'header.php') !== false || strpos($pathLower, 'navbar.php') !== false)) $hits[] = 'mobile-ui-keyword';
    if (empty($hits)) $hits[] = 'generic-fallback';
    $reasons[] = ['path' => $path, 'reasons' => $hits];
  }
  return $reasons;
}
function patcher_confidence_from_result(array $normalized, array $meta = [])
{
  $score = 55;
  $checks = is_array($normalized['checks'] ?? null) ? count($normalized['checks']) : 0;
  if ($checks >= 3) $score += 12;
  if (($normalized['schema_valid'] ?? false) === true) $score += 10;
  if (trim((string)($normalized['improved_code'] ?? '')) !== '') $score += 12;
  if (!empty($meta['fallback_used'])) $score -= 8;
  $risk = strtolower((string)($normalized['risk'] ?? 'medium'));
  if ($risk === 'high') $score -= 6;
  if ($risk === 'low') $score += 4;

  if ($score < 0) $score = 0;
  if ($score > 100) $score = 100;
  $level = $score >= 80 ? 'high' : ($score >= 60 ? 'medium' : 'low');
  return ['score' => $score, 'level' => $level];
}
function patcher_ai_jobs_dir($root)
{
  $dir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'patcher_jobs';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  return $dir;
}
function patcher_ai_job_path($root, $jobId)
{
  $safe = preg_replace('/[^a-f0-9]/', '', strtolower((string)$jobId));
  if ($safe === '') return '';
  return patcher_ai_jobs_dir($root) . DIRECTORY_SEPARATOR . 'job_' . $safe . '.json';
}
function patcher_ai_job_save($root, array $job)
{
  $path = patcher_ai_job_path($root, $job['id'] ?? '');
  if ($path === '') return false;
  
  $json = json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
      error_log("Failed to encode job " . ($job['id'] ?? '') . ": " . json_last_error_msg());
      // attempt safe encode
      $json = json_encode(['id' => $job['id'], 'status' => 'error', 'message' => 'Internal JSON encode error: ' . json_last_error_msg()]);
  }
  return @file_put_contents($path, (string)$json, LOCK_EX) !== false;
}
function patcher_ai_job_load($root, $jobId)
{
  $path = patcher_ai_job_path($root, $jobId);
  if ($path === '' || !is_file($path)) return null;
  $raw = @file_get_contents($path);
  $job = json_decode((string)$raw, true);
  return is_array($job) ? $job : null;
}
function patcher_ai_job_response(array $job)
{
  $out = [
    'ok' => ($job['status'] ?? '') !== 'error',
    'job_id' => (string)($job['id'] ?? ''),
    'status' => (string)($job['status'] ?? 'running'),
    'message' => (string)($job['message'] ?? ''),
    'timeline' => is_array($job['timeline'] ?? null) ? $job['timeline'] : [],
  ];
  if (($job['status'] ?? '') === 'done') {
    $out['provider'] = $job['provider'] ?? '';
    $out['model'] = $job['model'] ?? '';
    $out['fallback_used'] = !empty($job['fallback_used']);
    $out['fallback_reason'] = (string)($job['fallback_reason'] ?? '');
    $out['scan_mode'] = (string)($job['scan_mode'] ?? 'standard');
    $out['candidates'] = is_array($job['candidates'] ?? null) ? $job['candidates'] : [];
    $out['candidate_reasons'] = is_array($job['candidate_reasons'] ?? null) ? $job['candidate_reasons'] : [];
    $out['confidence'] = is_array($job['confidence'] ?? null) ? $job['confidence'] : ['score' => 0, 'level' => 'low'];
    $out['result'] = is_array($job['result'] ?? null) ? $job['result'] : [];
    $out['target_file'] = (string)($job['target_file'] ?? '');
    $out['raw'] = (string)($job['raw'] ?? '');
  }
  return $out;
}
function patcher_ai_job_advance(array $job, $env, $projectRoot)
{
  $stage = (int)($job['stage'] ?? 0);
  $issue = (string)($job['issue'] ?? '');
  $provider = (string)($job['provider'] ?? 'auto');
  $model = (string)($job['model'] ?? '');
  $scanMode = (string)($job['scan_mode'] ?? 'standard');

  if (($job['status'] ?? '') !== 'running') return $job;

  if ($stage === 0) {
    $candidates = patcher_issue_candidate_files($projectRoot, $issue, $scanMode);
    if (empty($candidates)) {
      $job['status'] = 'error';
      $job['message'] = 'Could not find any candidate files for analysis.';
      return $job;
    }
    $job['candidates'] = $candidates;
    $job['candidate_reasons'] = patcher_candidate_reasons($issue, $candidates);
    $job['timeline'][] = ['step' => 'File discovery', 'status' => 'success', 'detail' => 'Selected ' . count($candidates) . ' file(s): ' . implode(', ', array_slice($candidates, 0, 6)) . (count($candidates) > 6 ? ' ...' : '')];
    $job['timeline'][] = ['step' => 'Context assembly', 'status' => 'running', 'detail' => 'Reading bounded snippets from candidate files.'];
    $job['message'] = 'Files selected. Building context...';
    $job['stage'] = 1;
    return $job;
  }

  if ($stage === 1) {
    $snippets = [];
    foreach (($job['candidates'] ?? []) as $path) {
      $snippet = patcher_read_file_snippet($projectRoot, $path, $scanMode === 'deep' ? 18000 : 9000);
      if ($snippet !== '') $snippets[$path] = $snippet;
    }
    if (empty($snippets)) {
      $job['status'] = 'error';
      $job['message'] = 'Candidate files were found but readable snippets were empty.';
      return $job;
    }
    $job['snippets'] = $snippets;
    $job['timeline'][] = ['step' => 'Context assembly', 'status' => 'success', 'detail' => 'Prepared ' . count($snippets) . ' snippet(s) for AI analysis.'];
    $job['timeline'][] = ['step' => 'AI reasoning', 'status' => 'running', 'detail' => 'Generating diagnosis and patch suggestion.'];
    $job['message'] = 'Context ready. Calling AI provider...';
    $job['stage'] = 2;
    return $job;
  }

  if ($stage === 2) {
    $prompt = patcher_issue_prompt($issue, is_array($job['snippets'] ?? null) ? $job['snippets'] : [], $scanMode);
    $ai = patcher_ai_generate_with_fallback($env, $provider, $model, $prompt, $issue, $scanMode);
    $job['timeline'] = array_merge(is_array($job['timeline'] ?? null) ? $job['timeline'] : [], $ai['timeline'] ?? []);
    if (!$ai['ok']) {
      $job['status'] = 'error';
      $job['message'] = (string)($ai['message'] ?? 'AI analysis failed.');
      return $job;
    }

    $decoded = patcher_extract_json_block($ai['raw'] ?? '');
    if (!is_array($decoded)) {
      $decoded = [
        'summary' => 'AI analysis completed',
        'explanation' => trim((string)($ai['raw'] ?? '')),
        'root_cause' => trim((string)($ai['raw'] ?? '')),
        'fix_options' => [],
        'tradeoffs' => [],
        'risk' => 'medium',
        'checks' => [],
        'patch_preview' => trim((string)($ai['raw'] ?? '')),
        'target_file' => $job['candidates'][0] ?? '',
        'improved_code' => '',
        'notes' => [],
      ];
    }

    $normalized = patcher_ai_normalize_result($decoded, (string)($ai['raw'] ?? ''));
    $confidence = patcher_confidence_from_result($normalized, ['fallback_used' => !empty($ai['fallback_used'])]);
    $target = patcher_safe_rel_path($normalized['target_file'] ?? '');
    if ($target === '' || !patcher_extension_allowed($target)) {
      $target = $job['candidates'][0] ?? '';
    }

    $job['timeline'][] = ['step' => 'AI reasoning', 'status' => 'success', 'detail' => 'Analysis completed using ' . ucfirst((string)($ai['provider'] ?? $provider)) . ' (' . (string)($ai['model'] ?? patcher_ai_model($env, $provider, $model)) . ').'];
    $job['provider'] = $ai['provider'] ?? $provider;
    $job['model'] = $ai['model'] ?? patcher_ai_model($env, $provider, $model);
    $job['fallback_used'] = !empty($ai['fallback_used']);
    $job['fallback_reason'] = (string)($ai['fallback_reason'] ?? '');
    $job['result'] = $normalized;
    $job['confidence'] = $confidence;
    $job['target_file'] = $target;
    $job['raw'] = trim((string)($ai['raw'] ?? ''));
    $job['status'] = 'done';
    $job['message'] = 'Analysis complete.';
    $job['stage'] = 3;
    unset($job['snippets']);
    return $job;
  }

  return $job;
}
function patcher_ai_apply_dir($root)
{
  $dir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'patcher_apply_stages';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  return $dir;
}
function patcher_ai_backup_dir($root)
{
  $dir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'patcher_backups';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  return $dir;
}
function patcher_ai_stage_path($root, $stageId)
{
  $safe = preg_replace('/[^a-f0-9]/', '', strtolower((string)$stageId));
  if ($safe === '') return '';
  return patcher_ai_apply_dir($root) . DIRECTORY_SEPARATOR . 'stage_' . $safe . '.json';
}
function patcher_ai_stage_save($root, array $stage)
{
  $path = patcher_ai_stage_path($root, $stage['id'] ?? '');
  if ($path === '') return false;
  return @file_put_contents($path, json_encode($stage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}
function patcher_ai_stage_load($root, $stageId)
{
  $path = patcher_ai_stage_path($root, $stageId);
  if ($path === '' || !is_file($path)) return null;
  $raw = @file_get_contents($path);
  $stage = json_decode((string)$raw, true);
  return is_array($stage) ? $stage : null;
}
function patcher_ai_stage_delete($root, $stageId)
{
  $path = patcher_ai_stage_path($root, $stageId);
  if ($path !== '' && is_file($path)) @unlink($path);
}
function patcher_ai_backup_rel_path($rel, $stageId)
{
  $safeRel = preg_replace('/[^a-z0-9._\/-]+/i', '_', str_replace(['/', '\\'], '_', (string)$rel));
  $safeRel = trim(preg_replace('/_+/', '_', $safeRel), '_');
  if ($safeRel === '') $safeRel = 'patch';
  return date('Y/m/d') . '/' . $safeRel . '__' . substr(preg_replace('/[^a-f0-9]/', '', strtolower((string)$stageId)), 0, 16) . '.bak';
}
function patcher_ai_stage_response(array $stage)
{
  return [
    'ok' => true,
    'stage_id' => (string)($stage['id'] ?? ''),
    'path' => (string)($stage['path'] ?? ''),
    'backup_path' => (string)($stage['backup_path'] ?? ''),
    'snapshot' => is_array($stage['snapshot'] ?? null) ? $stage['snapshot'] : [],
    'diff' => is_array($stage['diff'] ?? null) ? $stage['diff'] : [],
    'message' => (string)($stage['message'] ?? ''),
  ];
}
function patcher_ai_release_dir($root)
{
  $dir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'patcher_releases';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  return $dir;
}
function patcher_ai_release_path($root, $releaseId)
{
  $safe = preg_replace('/[^a-f0-9]/', '', strtolower((string)$releaseId));
  if ($safe === '') return '';
  return patcher_ai_release_dir($root) . DIRECTORY_SEPARATOR . 'release_' . $safe . '.json';
}
function patcher_ai_release_save($root, array $release)
{
  $path = patcher_ai_release_path($root, $release['id'] ?? '');
  if ($path === '') return false;
  return @file_put_contents($path, json_encode($release, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}
function patcher_ai_release_load($root, $releaseId)
{
  $path = patcher_ai_release_path($root, $releaseId);
  if ($path === '' || !is_file($path)) return null;
  $raw = @file_get_contents($path);
  $release = json_decode((string)$raw, true);
  return is_array($release) ? $release : null;
}
function patcher_ai_release_delete($root, $releaseId)
{
  $path = patcher_ai_release_path($root, $releaseId);
  if ($path !== '' && is_file($path)) @unlink($path);
}
function patcher_ai_release_list($root, $limit = 25)
{
  $dir = patcher_ai_release_dir($root);
  $items = [];
  foreach (glob($dir . DIRECTORY_SEPARATOR . 'release_*.json') ?: [] as $file) {
    $raw = @file_get_contents($file);
    $item = json_decode((string)$raw, true);
    if (is_array($item)) $items[] = $item;
  }
  usort($items, fn($a, $b) => (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0));
  return array_slice($items, 0, $limit);
}
function patcher_ai_release_response(array $release)
{
  return [
    'ok' => true,
    'release_id' => (string)($release['id'] ?? ''),
    'status' => (string)($release['status'] ?? 'pending'),
    'path' => (string)($release['path'] ?? ''),
    'backup_path' => (string)($release['backup_path'] ?? ''),
    'snapshot' => is_array($release['snapshot'] ?? null) ? $release['snapshot'] : [],
    'diff' => is_array($release['diff'] ?? null) ? $release['diff'] : [],
    'review' => is_array($release['review'] ?? null) ? $release['review'] : [],
    'validation' => is_array($release['validation'] ?? null) ? $release['validation'] : [],
    'created_at' => (int)($release['created_at'] ?? time()),
    'updated_at' => (int)($release['updated_at'] ?? time()),
    'message' => (string)($release['message'] ?? ''),
  ];
}
function patcher_ai_validate_release_content($root, $rel, $content)
{
  $rel = patcher_safe_rel_path($rel);
  $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
  $result = [
    'ok' => true,
    'checks' => [],
    'warnings' => [],
    'message' => 'No pre-apply validation required.',
  ];

  if ($rel === '' || !patcher_extension_allowed($rel)) {
    return ['ok' => false, 'checks' => [], 'warnings' => [], 'message' => 'Invalid target path for validation.'];
  }

  if ($ext === 'php') {
    $tmp = tempnam(sys_get_temp_dir(), 'patcher_');
    if ($tmp === false) {
      return ['ok' => false, 'checks' => [], 'warnings' => [], 'message' => 'Unable to create temporary file for linting.'];
    }
    $tmpPhp = $tmp . '.php';
    @rename($tmp, $tmpPhp);
    if (@file_put_contents($tmpPhp, (string)$content, LOCK_EX) === false) {
      @unlink($tmpPhp);
      return ['ok' => false, 'checks' => [], 'warnings' => [], 'message' => 'Unable to write temporary file for linting.'];
    }
    $lint = patcher_run_cmd('php -l ' . escapeshellarg($tmpPhp), $root);
    @unlink($tmpPhp);
    $result['checks'][] = ['name' => 'php -l', 'ok' => !empty($lint['ok']), 'output' => trim((string)($lint['out'] ?? '') . "\n" . (string)($lint['err'] ?? ''))];
    $result['ok'] = !empty($lint['ok']);
    $result['message'] = $result['ok'] ? 'PHP lint passed.' : 'PHP lint failed.';
    if (!$result['ok']) {
      $result['warnings'][] = 'Release approval is blocked until lint passes.';
    }
    return $result;
  }

  $result['checks'][] = ['name' => 'basic-content', 'ok' => trim((string)$content) !== '', 'output' => trim((string)$content) !== '' ? 'Content present.' : 'Content is empty.'];
  $result['ok'] = trim((string)$content) !== '';
  $result['message'] = $result['ok'] ? 'Basic validation passed.' : 'Basic validation failed.';
  if (!$result['ok']) $result['warnings'][] = 'Release approval is blocked until the suggested content is not empty.';
  return $result;
}
function patcher_ai_snapshot_info($root, $rel, $content, $backupRelPath = '')
{
  $abs = patcher_absolute_path($root, $rel);
  $current = is_file($abs) ? (string)@file_get_contents($abs) : '';
  $currentBytes = strlen($current);
  $currentSha1 = $current !== '' ? sha1($current) : '';
  $newBytes = strlen((string)$content);
  $newSha1 = sha1((string)$content);
  $linesBefore = $current === '' ? 0 : substr_count($current, "\n") + 1;
  $linesAfter = $content === '' ? 0 : substr_count((string)$content, "\n") + 1;
  return [
    'target' => $rel,
    'backup_path' => $backupRelPath,
    'current_bytes' => $currentBytes,
    'current_sha1' => $currentSha1,
    'new_bytes' => $newBytes,
    'new_sha1' => $newSha1,
    'lines_before' => $linesBefore,
    'lines_after' => $linesAfter,
    'line_delta' => $linesAfter - $linesBefore,
  ];
}
function patcher_read_file_snippet($root, $rel, $maxBytes = 12000)
{
  $abs = patcher_absolute_path($root, $rel);
  if (!is_file($abs) || !patcher_extension_allowed($rel)) return '';
  $content = (string)@file_get_contents($abs);
  if ($content === '') return '';
  if (strlen($content) > $maxBytes) {
    if (function_exists('mb_substr')) {
        $content = mb_substr($content, 0, $maxBytes, 'UTF-8') . "\n\n... (truncated)";
    } else {
        $content = substr($content, 0, $maxBytes) . "\n\n... (truncated)";
    }
  }
  // Ensure valid UTF-8
  if (!mb_check_encoding($content, 'UTF-8')) {
      $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
  }
  return $content;
}
function patcher_issue_prompt($issue, array $fileSnippets, $scanMode)
{
  $bundle = [];
  foreach ($fileSnippets as $path => $snippet) {
    $bundle[] = "FILE: {$path}\n```text\n" . patcher_prompt_safe_text($snippet) . "\n```";
  }
  $joined = implode("\n\n", $bundle);
  return "You are a senior PHP debugging assistant for a production attendance system. Analyze the issue and return STRICT JSON only with schema: {\"summary\":\"short\",\"explanation\":\"detailed analysis\",\"root_cause\":\"primary root cause\",\"fix_options\":[\"possible fix\"],\"tradeoffs\":[\"tradeoff\"],\"risk\":\"low|medium|high\",\"checks\":[\"verification step\"],\"patch_preview\":\"short preview of intended patch\",\"target_file\":\"best target file path\",\"improved_code\":\"full replacement code for target file when possible\",\"notes\":[\"extra notes\"]}. Keep changes minimal and safe. Issue: {$issue}. Scan mode: {$scanMode}. Context files:\n\n{$joined}";
}
function patcher_parse_status_lines($raw)
{
  $items = [];
  foreach (preg_split('/\r?\n/', trim((string)$raw)) as $line) {
    if ($line === '') continue;
    $items[] = ['code' => trim(substr($line, 0, 2)), 'path' => trim(substr($line, 3))];
  }
  return array_values(array_filter($items, fn($i) => $i['path'] !== ''));
}
function patcher_recent_changes($root)
{
  $status = patcher_run_cmd('git status --short --untracked-files=all', $root);
  $hist = patcher_run_cmd('git log --date=relative --pretty=format:"__COMMIT__|%h|%ar|%s" --name-status -n 12 --', $root);
  $history = [];
  $current = null;
  foreach (preg_split('/\r?\n/', trim((string)$hist['out'])) as $line) {
    if ($line === '') continue;
    if (strpos($line, '__COMMIT__|') === 0) {
      if ($current) $history[] = $current;
      $p = explode('|', $line, 4);
      $current = ['hash' => $p[1] ?? '', 'age' => $p[2] ?? '', 'subject' => $p[3] ?? '', 'files' => []];
      continue;
    }
    if ($current && preg_match('/^([A-Z?]{1,2})\s+(.+)$/', $line, $m)) $current['files'][] = ['code' => $m[1], 'path' => $m[2]];
  }
  if ($current) $history[] = $current;
  return ['ok' => $status['ok'] && $hist['ok'], 'status' => patcher_parse_status_lines($status['out']), 'history' => $history, 'raw_status' => trim($status['out'] . "\n" . $status['err'])];
}
function patcher_terminal_command_allowed($command)
{
  $cmd = trim((string)$command);
  if ($cmd === '') return false;
  if (preg_match('/[;&|`<>]/', $cmd)) return false;
  return (bool)preg_match('/^git\s+(status|pull|fetch|log|diff|show|branch|checkout|switch|restore|add|commit)(\s+.*)?$/i', $cmd);
}

if (isset($_GET['api'])) {
  header('Content-Type: application/json');
  $action = (string)$_GET['api'];
  if (!in_array($action, ['list_files', 'ai_status'], true) && !csrf_check_request()) {
    echo json_encode(['ok' => false, 'message' => 'Invalid CSRF token.']);
    exit;
  }
  if ($action === 'list_files') {
    echo json_encode(['ok' => true, 'files' => patcher_list_files($projectRoot)]);
    exit;
  }
  if ($action === 'ai_status') {
    echo json_encode(patcher_ai_status_payload($patcherEnv));
    exit;
  }
  if ($action === 'read_file') {
    $rel = patcher_safe_rel_path($_POST['path'] ?? '');
    $target = patcher_absolute_path($projectRoot, $rel);
    if ($rel === '' || !patcher_extension_allowed($rel) || !is_file($target)) {
      echo json_encode(['ok' => false, 'message' => 'File not found or disallowed.']);
      exit;
    }
    if (filesize($target) > 1024 * 1024) {
      echo json_encode(['ok' => false, 'message' => 'File too large to open here (max 1MB).']);
      exit;
    }
    echo json_encode(['ok' => true, 'path' => $rel, 'content' => (string)file_get_contents($target)]);
    exit;
  }
  if ($action === 'save_file' || $action === 'apply_ai_patch') {
    $rel = patcher_safe_rel_path($_POST['path'] ?? '');
    $content = (string)($_POST['content'] ?? '');
    $target = patcher_absolute_path($projectRoot, $rel);
    if ($rel === '' || !patcher_extension_allowed($rel) || !is_file($target)) {
      echo json_encode(['ok' => false, 'message' => 'File not found or disallowed.']);
      exit;
    }
    $snapshot = null;
    if ($action === 'apply_ai_patch') {
      $stageId = trim((string)($_POST['stage_id'] ?? ''));
      $stage = $stageId !== '' ? patcher_ai_stage_load($projectRoot, $stageId) : null;
      if (!is_array($stage) || (string)($stage['path'] ?? '') !== $rel) {
        echo json_encode(['ok' => false, 'message' => 'Apply stage not found or does not match the selected file.']);
        exit;
      }
      $backupRel = (string)($stage['backup_path'] ?? patcher_ai_backup_rel_path($rel, $stageId));
      $backupAbs = patcher_absolute_path($projectRoot, $backupRel);
      $backupDir = dirname($backupAbs);
      if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true)) {
        echo json_encode(['ok' => false, 'message' => 'Failed to create backup snapshot folder.']);
        exit;
      }
      $current = (string)@file_get_contents($target);
      if (@file_put_contents($backupAbs, $current, LOCK_EX) === false) {
        echo json_encode(['ok' => false, 'message' => 'Failed to create backup snapshot.']);
        exit;
      }
      $snapshot = patcher_ai_snapshot_info($projectRoot, $rel, $content, $backupRel);
      $snapshot['stage_id'] = $stageId;
      $snapshot['backup_abs'] = $backupAbs;
      $snapshot['backup_bytes'] = strlen($current);
      $snapshot['backup_sha1'] = sha1($current);
      patcher_ai_stage_delete($projectRoot, $stageId);
    }
    if (@file_put_contents($target, $content, LOCK_EX) === false) {
      echo json_encode(['ok' => false, 'message' => 'Failed to save file.']);
      exit;
    }
    $payload = ['ok' => true, 'message' => ($action === 'save_file' ? 'Saved: ' : 'AI patch applied to ') . $rel];
    if ($snapshot) {
      $payload['snapshot'] = $snapshot;
      $payload['message'] .= ' Backup snapshot created.';
    }
    echo json_encode($payload);
    exit;
  }
  if ($action === 'create_file') {
    $rel = patcher_safe_rel_path($_POST['path'] ?? '');
    $content = (string)($_POST['content'] ?? '');
    $target = patcher_absolute_path($projectRoot, $rel);
    if ($rel === '' || !patcher_extension_allowed($rel)) {
      echo json_encode(['ok' => false, 'message' => 'Invalid or disallowed file path.']);
      exit;
    }
    if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) {
      echo json_encode(['ok' => false, 'message' => 'Failed to create parent folder.']);
      exit;
    }
    if (file_exists($target)) {
      echo json_encode(['ok' => false, 'message' => 'File already exists.']);
      exit;
    }
    if (@file_put_contents($target, $content, LOCK_EX) === false) {
      echo json_encode(['ok' => false, 'message' => 'Failed to create file.']);
      exit;
    }
    echo json_encode(['ok' => true, 'message' => 'Created: ' . $rel]);
    exit;
  }
  if ($action === 'create_folder') {
    $rel = patcher_safe_rel_path($_POST['path'] ?? '');
    $target = patcher_absolute_path($projectRoot, $rel);
    if ($rel === '' || (!is_dir($target) && !@mkdir($target, 0755, true))) {
      echo json_encode(['ok' => false, 'message' => 'Failed to create folder.']);
      exit;
    }
    echo json_encode(['ok' => true, 'message' => 'Folder ready: ' . $rel]);
    exit;
  }
  if ($action === 'git_status') {
    $res = patcher_run_cmd('git status --short', $projectRoot);
    echo json_encode(['ok' => $res['ok'], 'message' => $res['ok'] ? 'Git status completed.' : 'git status failed.', 'output' => trim($res['out'] . "\n" . $res['err'])]);
    exit;
  }
  if ($action === 'run_terminal') {
    $command = trim((string)($_POST['command'] ?? ''));
    if (!patcher_terminal_command_allowed($command)) {
      echo json_encode(['ok' => false, 'message' => 'Only safe git commands are allowed in terminal (status, pull, fetch, log, diff, show, branch, checkout, switch, restore, add, commit).']);
      exit;
    }
    $res = patcher_run_cmd($command, $projectRoot);
    echo json_encode(['ok' => $res['ok'], 'message' => $res['ok'] ? 'Command completed.' : 'Command failed.', 'command' => $command, 'output' => trim($res['out'] . "\n" . $res['err'])]);
    exit;
  }
  if ($action === 'recent_changes') {
    echo json_encode(patcher_recent_changes($projectRoot));
    exit;
  }
  if ($action === 'revert_file') {
    $rel = patcher_safe_rel_path($_POST['path'] ?? '');
    if ($rel === '') {
      echo json_encode(['ok' => false, 'message' => 'Invalid file path.']);
      exit;
    }
    $res = patcher_run_cmd('git restore --source=HEAD -- ' . escapeshellarg($rel), $projectRoot);
    echo json_encode(['ok' => $res['ok'], 'message' => $res['ok'] ? 'Reverted ' . $rel . ' to HEAD.' : 'Revert failed for ' . $rel, 'output' => trim($res['out'] . "\n" . $res['err'])]);
    exit;
  }
  if ($action === 'git_pull') {
    $branch = trim((string)($_POST['branch'] ?? 'main'));
    if ($branch === '') $branch = 'main';
    $res = patcher_run_cmd('git pull --ff-only origin ' . escapeshellarg($branch), $projectRoot);
    echo json_encode(['ok' => $res['ok'], 'message' => $res['ok'] ? 'Git pull successful.' : 'git pull failed.', 'output' => trim($res['out'] . "\n" . $res['err'])]);
    exit;
  }
  if ($action === 'ai_analyze_issue_start') {
    if (!patcher_ai_enabled($patcherEnv)) {
      echo json_encode(['ok' => false, 'message' => 'AI assistant is disabled.']);
      exit;
    }
    $issue = trim((string)($_POST['issue'] ?? ''));
    $provider = patcher_ai_provider($patcherEnv, $_POST['provider'] ?? '');
    $model = trim((string)($_POST['model'] ?? ''));
    $scanMode = strtolower(trim((string)($_POST['scan_mode'] ?? 'standard')));
    if (!in_array($scanMode, ['quick', 'standard', 'deep'], true)) $scanMode = 'standard';
    if ($issue === '') {
      echo json_encode(['ok' => false, 'message' => 'Issue description is required.']);
      exit;
    }

    $jobId = bin2hex(random_bytes(8));
    $job = [
      'id' => $jobId,
      'status' => 'running',
      'stage' => 0,
      'issue' => $issue,
      'provider' => $provider,
      'model' => $model,
      'scan_mode' => $scanMode,
      'timeline' => [
        ['step' => 'Issue intake', 'status' => 'success', 'detail' => 'Issue received and validated.'],
        ['step' => 'File discovery', 'status' => 'running', 'detail' => 'Selecting candidate files based on issue keywords.'],
      ],
      'message' => 'Issue accepted. Starting analysis...',
      'created_at' => time(),
      'updated_at' => time(),
    ];
    if (!patcher_ai_job_save($projectRoot, $job)) {
      echo json_encode(['ok' => false, 'message' => 'Failed to create analysis job.']);
      exit;
    }
    echo json_encode(['ok' => true, 'job_id' => $jobId, 'status' => 'running', 'timeline' => $job['timeline'], 'message' => $job['message']]);
    exit;
  }
  if ($action === 'ai_analyze_issue_poll') {
    $jobId = trim((string)($_POST['job_id'] ?? ''));
    if ($jobId === '') {
      echo json_encode(['ok' => false, 'message' => 'Missing job id.']);
      exit;
    }
    $job = patcher_ai_job_load($projectRoot, $jobId);
    if (!is_array($job)) {
      echo json_encode(['ok' => false, 'message' => 'Analysis job not found or expired.']);
      exit;
    }
    if (($job['status'] ?? 'running') === 'running') {
      $job = patcher_ai_job_advance($job, $patcherEnv, $projectRoot);
      $job['updated_at'] = time();
      patcher_ai_job_save($projectRoot, $job);
    }
    echo json_encode(patcher_ai_job_response($job));
    exit;
  }
  if ($action === 'ai_analyze_issue') {
    if (!patcher_ai_enabled($patcherEnv)) {
      echo json_encode(['ok' => false, 'message' => 'AI assistant is disabled.']);
      exit;
    }
    $issue = trim((string)($_POST['issue'] ?? ''));
    $provider = patcher_ai_provider($patcherEnv, $_POST['provider'] ?? '');
    $model = trim((string)($_POST['model'] ?? ''));
    $scanMode = strtolower(trim((string)($_POST['scan_mode'] ?? 'standard')));
    if (!in_array($scanMode, ['quick', 'standard', 'deep'], true)) $scanMode = 'standard';

    if ($issue === '') {
      echo json_encode(['ok' => false, 'message' => 'Issue description is required.']);
      exit;
    }

    $timeline = [
      ['step' => 'Issue intake', 'status' => 'success', 'detail' => 'Issue received and validated.'],
      ['step' => 'File discovery', 'status' => 'running', 'detail' => 'Selecting candidate files based on issue keywords.'],
    ];

    $candidates = patcher_issue_candidate_files($projectRoot, $issue, $scanMode);
    if (empty($candidates)) {
      echo json_encode(['ok' => false, 'message' => 'Could not find any candidate files for analysis.', 'timeline' => $timeline]);
      exit;
    }

    $timeline[] = ['step' => 'File discovery', 'status' => 'success', 'detail' => 'Selected ' . count($candidates) . ' file(s): ' . implode(', ', array_slice($candidates, 0, 6)) . (count($candidates) > 6 ? ' ...' : '')];
    $timeline[] = ['step' => 'Context assembly', 'status' => 'running', 'detail' => 'Reading bounded snippets from candidate files.'];

    $snippets = [];
    foreach ($candidates as $path) {
      $snippet = patcher_read_file_snippet($projectRoot, $path, $scanMode === 'deep' ? 18000 : 9000);
      if ($snippet !== '') $snippets[$path] = $snippet;
    }

    if (empty($snippets)) {
      echo json_encode(['ok' => false, 'message' => 'Candidate files were found but readable snippets were empty.', 'timeline' => $timeline]);
      exit;
    }

    $timeline[] = ['step' => 'Context assembly', 'status' => 'success', 'detail' => 'Prepared ' . count($snippets) . ' snippet(s) for AI analysis.'];
    $timeline[] = ['step' => 'AI reasoning', 'status' => 'running', 'detail' => 'Generating diagnosis and patch suggestion.'];

    $prompt = patcher_issue_prompt($issue, $snippets, $scanMode);
    $ai = patcher_ai_generate_with_fallback($patcherEnv, $provider, $model, $prompt, $issue, $scanMode);
    $timeline = array_merge($timeline, $ai['timeline'] ?? []);
    if (!$ai['ok']) {
      echo json_encode(['ok' => false, 'message' => $ai['message'] ?? 'AI analysis failed.', 'timeline' => $timeline]);
      exit;
    }

    $decoded = patcher_extract_json_block($ai['raw'] ?? '');
    if (!is_array($decoded)) {
      $decoded = [
        'summary' => 'AI analysis completed',
        'explanation' => trim((string)($ai['raw'] ?? '')),
        'root_cause' => trim((string)($ai['raw'] ?? '')),
        'fix_options' => [],
        'tradeoffs' => [],
        'risk' => 'medium',
        'checks' => [],
        'patch_preview' => trim((string)($ai['raw'] ?? '')),
        'target_file' => $candidates[0] ?? '',
        'improved_code' => '',
        'notes' => [],
      ];
    }

    $target = patcher_safe_rel_path($decoded['target_file'] ?? '');
    if ($target === '' || !patcher_extension_allowed($target)) {
      $target = $candidates[0] ?? '';
    }

    $timeline[] = ['step' => 'AI reasoning', 'status' => 'success', 'detail' => 'Analysis completed using ' . ucfirst((string)($ai['provider'] ?? $provider)) . ' (' . (string)($ai['model'] ?? patcher_ai_model($patcherEnv, $provider, $model)) . ').'];

    $normalized = patcher_ai_normalize_result($decoded, (string)($ai['raw'] ?? ''));
    $confidence = patcher_confidence_from_result($normalized, ['fallback_used' => !empty($ai['fallback_used'])]);
    echo json_encode([
      'ok' => true,
      'provider' => $ai['provider'] ?? $provider,
      'model' => $ai['model'] ?? patcher_ai_model($patcherEnv, $provider, $model),
      'fallback_used' => !empty($ai['fallback_used']),
      'fallback_reason' => (string)($ai['fallback_reason'] ?? ''),
      'scan_mode' => $scanMode,
      'candidates' => $candidates,
      'candidate_reasons' => patcher_candidate_reasons($issue, $candidates),
      'confidence' => $confidence,
      'timeline' => $timeline,
      'result' => $normalized,
      'target_file' => $target,
      'raw' => trim((string)($ai['raw'] ?? '')),
    ]);
    exit;
  }
  if ($action === 'ai_stage_apply') {
    if (!patcher_ai_enabled($patcherEnv)) {
      echo json_encode(['ok' => false, 'message' => 'AI assistant is disabled.']);
      exit;
    }
    $rel = patcher_safe_rel_path($_POST['path'] ?? '');
    $content = (string)($_POST['content'] ?? '');
    if ($rel === '' || !patcher_extension_allowed($rel)) {
      echo json_encode(['ok' => false, 'message' => 'Select a valid file first.']);
      exit;
    }
    if ($content === '') {
      echo json_encode(['ok' => false, 'message' => 'Suggested content is empty.']);
      exit;
    }
    $target = patcher_absolute_path($projectRoot, $rel);
    if (!is_file($target)) {
      echo json_encode(['ok' => false, 'message' => 'Target file not found.']);
      exit;
    }
    $stageId = bin2hex(random_bytes(8));
    $backupRel = patcher_ai_backup_rel_path($rel, $stageId);
    $snapshot = patcher_ai_snapshot_info($projectRoot, $rel, $content, $backupRel);
    $stage = [
      'id' => $stageId,
      'path' => $rel,
      'backup_path' => $backupRel,
      'snapshot' => $snapshot,
      'diff' => [
        'changed_lines' => abs((int)$snapshot['line_delta']),
        'summary' => 'Staged apply will create a backup snapshot before overwrite.'
      ],
      'message' => 'Apply staged. Review the snapshot and confirm when ready.',
      'created_at' => time(),
    ];
    if (!patcher_ai_stage_save($projectRoot, $stage)) {
      echo json_encode(['ok' => false, 'message' => 'Failed to stage the apply preview.']);
      exit;
    }
    echo json_encode(patcher_ai_stage_response($stage));
    exit;
  }
  if ($action === 'ai_release_request_create') {
    if (!patcher_ai_enabled($patcherEnv)) {
      echo json_encode(['ok' => false, 'message' => 'AI assistant is disabled.']);
      exit;
    }
    $rel = patcher_safe_rel_path($_POST['path'] ?? '');
    $content = (string)($_POST['content'] ?? '');
    $stageId = trim((string)($_POST['stage_id'] ?? ''));
    $stage = $stageId !== '' ? patcher_ai_stage_load($projectRoot, $stageId) : null;
    if ($rel === '' || !patcher_extension_allowed($rel) || !is_file(patcher_absolute_path($projectRoot, $rel))) {
      echo json_encode(['ok' => false, 'message' => 'Select a valid file first.']);
      exit;
    }
    if (!is_array($stage) || (string)($stage['path'] ?? '') !== $rel || trim((string)($stage['backup_path'] ?? '')) === '') {
      echo json_encode(['ok' => false, 'message' => 'Staged preview is missing or does not match the selected file.']);
      exit;
    }
    if ($content === '') {
      echo json_encode(['ok' => false, 'message' => 'Suggested content is empty.']);
      exit;
    }

    $releaseId = bin2hex(random_bytes(8));
    $release = [
      'id' => $releaseId,
      'status' => 'pending',
      'path' => $rel,
      'content' => $content,
      'stage_id' => $stageId,
      'backup_path' => (string)$stage['backup_path'],
      'snapshot' => is_array($stage['snapshot'] ?? null) ? $stage['snapshot'] : patcher_ai_snapshot_info($projectRoot, $rel, $content, (string)$stage['backup_path']),
      'diff' => is_array($stage['diff'] ?? null) ? $stage['diff'] : [],
      'validation' => patcher_ai_validate_release_content($projectRoot, $rel, $content),
      'review' => [],
      'message' => 'Release request submitted for review.',
      'created_at' => time(),
      'updated_at' => time(),
    ];
    if (!patcher_ai_release_save($projectRoot, $release)) {
      echo json_encode(['ok' => false, 'message' => 'Failed to submit the release request.']);
      exit;
    }
    echo json_encode(patcher_ai_release_response($release));
    patcher_ai_stage_delete($projectRoot, $stageId);
    exit;
  }
  if ($action === 'ai_release_request_list') {
    echo json_encode(['ok' => true, 'requests' => array_map(fn($r) => patcher_ai_release_response($r), patcher_ai_release_list($projectRoot))]);
    exit;
  }
  if ($action === 'ai_release_request_review') {
    if (!patcher_ai_enabled($patcherEnv)) {
      echo json_encode(['ok' => false, 'message' => 'AI assistant is disabled.']);
      exit;
    }
    $releaseId = trim((string)($_POST['release_id'] ?? ''));
    $decision = strtolower(trim((string)($_POST['decision'] ?? '')));
    $release = $releaseId !== '' ? patcher_ai_release_load($projectRoot, $releaseId) : null;
    if (!is_array($release)) {
      echo json_encode(['ok' => false, 'message' => 'Release request not found.']);
      exit;
    }
    if (!in_array($decision, ['approve', 'reject'], true)) {
      echo json_encode(['ok' => false, 'message' => 'Invalid review decision.']);
      exit;
    }
    if ($decision === 'reject') {
      $release['status'] = 'rejected';
      $release['updated_at'] = time();
      $release['review'] = ['decision' => 'rejected', 'reviewed_at' => time()];
      patcher_ai_release_save($projectRoot, $release);
      patcher_ai_stage_delete($projectRoot, (string)($release['stage_id'] ?? ''));
      echo json_encode(['ok' => true, 'message' => 'Release request rejected.', 'request' => patcher_ai_release_response($release)]);
      exit;
    }

    $rel = (string)($release['path'] ?? '');
    $content = (string)($release['content'] ?? '');
    $target = patcher_absolute_path($projectRoot, $rel);
    if ($rel === '' || !is_file($target)) {
      echo json_encode(['ok' => false, 'message' => 'Target file not found.']);
      exit;
    }
    $validation = is_array($release['validation'] ?? null) ? $release['validation'] : patcher_ai_validate_release_content($projectRoot, $rel, $content);
    if (empty($validation['ok'])) {
      $release['status'] = 'blocked';
      $release['updated_at'] = time();
      $release['review'] = ['decision' => 'blocked', 'reviewed_at' => time(), 'validation' => $validation];
      $release['validation'] = $validation;
      patcher_ai_release_save($projectRoot, $release);
      echo json_encode(['ok' => false, 'message' => (string)($validation['message'] ?? 'Pre-apply validation failed.'), 'request' => patcher_ai_release_response($release), 'validation' => $validation]);
      exit;
    }
    $backupRel = (string)($release['backup_path'] ?? patcher_ai_backup_rel_path($rel, (string)($release['stage_id'] ?? $releaseId)));
    $backupAbs = patcher_absolute_path($projectRoot, $backupRel);
    $backupDir = dirname($backupAbs);
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true)) {
      echo json_encode(['ok' => false, 'message' => 'Failed to create backup snapshot folder.']);
      exit;
    }
    $current = (string)@file_get_contents($target);
    if (@file_put_contents($backupAbs, $current, LOCK_EX) === false) {
      echo json_encode(['ok' => false, 'message' => 'Failed to create backup snapshot.']);
      exit;
    }
    if (@file_put_contents($target, $content, LOCK_EX) === false) {
      echo json_encode(['ok' => false, 'message' => 'Failed to apply approved release.']);
      exit;
    }
    $release['status'] = 'approved';
    $release['updated_at'] = time();
    $release['review'] = ['decision' => 'approved', 'reviewed_at' => time(), 'backup_path' => $backupRel, 'backup_bytes' => strlen($current), 'backup_sha1' => sha1($current)];
    $release['message'] = 'Approved release applied with backup snapshot.';
    patcher_ai_release_save($projectRoot, $release);
    patcher_ai_stage_delete($projectRoot, (string)($release['stage_id'] ?? ''));
    echo json_encode(['ok' => true, 'message' => 'Release approved and applied.', 'request' => patcher_ai_release_response($release), 'backup' => $release['review']]);
    exit;
  }
  if ($action === 'ai_generate') {
    if (!patcher_ai_enabled($patcherEnv)) {
      echo json_encode(['ok' => false, 'message' => 'AI assistant is disabled.']);
      exit;
    }
    $rel = patcher_safe_rel_path($_POST['path'] ?? '');
    $issue = trim((string)($_POST['issue'] ?? ''));
    $content = (string)($_POST['content'] ?? '');
    $provider = patcher_ai_provider($patcherEnv, $_POST['provider'] ?? '');
    $model = patcher_ai_model($patcherEnv, $provider, $_POST['model'] ?? '');
    if ($rel === '' || !patcher_extension_allowed($rel)) {
      echo json_encode(['ok' => false, 'message' => 'Select a valid file first.']);
      exit;
    }
    if ($issue === '') {
      echo json_encode(['ok' => false, 'message' => 'Issue description is required.']);
      exit;
    }
    if ($content === '') {
      $target = patcher_absolute_path($projectRoot, $rel);
      if (!is_file($target)) {
        echo json_encode(['ok' => false, 'message' => 'Target file not found.']);
        exit;
      }
      $content = (string)file_get_contents($target);
    }
    $result = patcher_ai_generate_with_fallback($patcherEnv, $provider, $model, patcher_ai_prompt($rel, $issue, $content), $issue, 'standard');
    if (!$result['ok']) {
      echo json_encode(['ok' => false, 'message' => $result['message'] ?? 'AI request failed.', 'output' => $result['output'] ?? ($result['raw'] ?? ''), 'timeline' => $result['timeline'] ?? []]);
      exit;
    }
    $decoded = patcher_extract_json_block($result['raw']);
    $normalized = patcher_ai_normalize_result($decoded, (string)($result['raw'] ?? ''));
    echo json_encode(['ok' => true, 'provider' => $result['provider'] ?? $provider, 'model' => $result['model'] ?? $model, 'fallback_used' => !empty($result['fallback_used']), 'fallback_reason' => (string)($result['fallback_reason'] ?? ''), 'timeline' => $result['timeline'] ?? [], 'result' => $normalized, 'raw' => trim((string)$result['raw'])]);
    exit;
  }
  echo json_encode(['ok' => false, 'message' => 'Unknown API action.']);
  exit;
}
$localMode = app_local_mode_enabled(__DIR__ . '/../.env');
$patcherAiStatus = patcher_ai_status_payload($patcherEnv);
?>
          <div style="padding:8px 16px;border-bottom:1px solid rgba(100,130,180,0.1);font-size:11px;color:#7a8ab5;">
            Use the file explorer to open files. Editor actions remain available in the toolbar below.
          </div>
<style>
  body.admin-page-patcher .layout,
  body.admin-page-patcher .main-content,
  body.admin-page-patcher .content-wrapper {
    margin: 0 !important;
    padding: 0 !important;
    max-width: none !important;
    width: 100% !important;
  }

  body.admin-page-patcher .sidebar,
  body.admin-page-patcher .desktop-navbar,
  body.admin-page-patcher .page-header,
  body.admin-page-patcher footer {
    display: none !important;
  }

  #patcherStudio {
    --header-height: 56px;
    --panel-width: 320px;
    --terminal-h: 240px;
    min-height: 100vh;
    overflow: hidden;
    background: #0a0e1f;
    color: #d1d8ff;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Inter, sans-serif;
    font-size: 13px;
  }

  #patcherStudio * {
    box-sizing: border-box;
  }

  .ps-root {
    display: flex;
    flex-direction: column;
    height: 100vh;
    max-height: 100vh;
  }

  .ps-header {
    height: var(--header-height);
    background: rgba(6, 8, 18, 0.95);
    border-bottom: 1px solid rgba(100, 130, 180, 0.15);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 20px;
    gap: 20px;
    flex-shrink: 0;
    backdrop-filter: blur(12px);
  }

  .ps-header-title {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
  }

  .ps-header-title h1 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
    color: #f0f5ff;
    letter-spacing: -0.5px;
  }

  .ps-header-title .ps-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    background: rgba(78, 222, 163, 0.1);
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    color: #4edea3;
  }

  .ps-header-title .ps-status::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #4edea3;
    box-shadow: 0 0 8px rgba(78, 222, 163, 0.6);
  }

  .ps-header-actions {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .ps-main {
    display: flex;
    flex: 1;
    min-height: 0;
    overflow: hidden;
  }

  .ps-sidebar {
    width: 50px;
    background: rgba(8, 12, 28, 0.8);
    border-right: 1px solid rgba(100, 130, 180, 0.1);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    padding: 12px 0;
    flex-shrink: 0;
  }

  .ps-sidebar-btn {
    width: 40px;
    height: 40px;
    border: none;
    background: transparent;
    color: #7a8ab5;
    cursor: pointer;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    font-size: 20px;
  }

  .ps-sidebar-btn:hover {
    background: rgba(100, 130, 180, 0.1);
    color: #a0b0e0;
  }

  .ps-sidebar-btn.active {
    background: rgba(100, 180, 255, 0.15);
    color: #90b0ff;
  }

  .ps-content {
    display: flex;
    flex: 1;
    min-width: 0;
    overflow: hidden;
  }

  .ps-panel {
    background: rgba(10, 18, 40, 0.6);
    border-right: 1px solid rgba(100, 130, 180, 0.1);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    position: relative;
  }

  .ps-panel.left {
    width: var(--panel-width);
    flex-shrink: 0;
  }

  .ps-panel.right {
    width: var(--panel-width);
    flex-shrink: 0;
    border-right: none;
    border-left: 1px solid rgba(100, 130, 180, 0.1);
  }

  .ps-panel.hidden {
    display: none;
  }

  .ps-panel-header {
    padding: 14px 16px;
    border-bottom: 1px solid rgba(100, 130, 180, 0.1);
    flex-shrink: 0;
  }

  .ps-panel-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
  }

  .ps-panel-title h2 {
    margin: 0;
    font-size: 13px;
    font-weight: 700;
    color: #d1d8ff;
    letter-spacing: 0.5px;
  }

  .ps-panel-hint {
    font-size: 11px;
    color: #7a8ab5;
    margin-top: 4px;
  }

  .ps-panel-tool {
    display: flex;
    gap: 6px;
  }

  .ps-panel-body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 12px 8px;
  }

  .ps-view {
    display: none;
    height: 100%;
  }

  .ps-view.active {
    display: block;
  }

  .ps-editor-area {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 0;
    overflow: hidden;
    background: #060e21;
  }

  .ps-editor-header {
    padding: 10px 16px;
    background: rgba(6, 14, 33, 0.8);
    border-bottom: 1px solid rgba(100, 130, 180, 0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
  }

  .ps-editor-label {
    font-size: 12px;
    color: #d1d8ff;
    font-weight: 600;
  }

  .ps-editor-meta {
    font-size: 11px;
    color: #7a8ab5;
    margin-left: 8px;
  }

  .ps-editor-view {
    display: flex;
    flex: 1;
    min-height: 0;
    overflow: hidden;
  }

  .ps-gutter {
    width: 48px;
    background: rgba(6, 14, 33, 0.6);
    color: #4a5a7f;
    font-family: "JetBrains Mono", "Courier New", monospace;
    font-size: 12px;
    line-height: 1.5;
    padding: 12px 0;
    text-align: right;
    user-select: none;
    border-right: 1px solid rgba(100, 130, 180, 0.08);
    overflow: hidden;
  }

  .ps-gutter div {
    height: 1.5em;
    padding-right: 8px;
  }

  .ps-editor {
    flex: 1;
    padding: 12px 16px;
    background: #060e21;
    color: #d1d8ff;
    font-family: "JetBrains Mono", "Courier New", monospace;
    font-size: 13px;
    line-height: 1.6;
    border: none;
    outline: none;
    resize: none;
    tab-size: 2;
  }

  .ps-split-handle {
    width: 6px;
    background: transparent;
    cursor: col-resize;
    flex-shrink: 0;
    transition: background 0.2s;
  }

  .ps-split-handle:hover {
    background: rgba(100, 180, 255, 0.2);
  }

  .ps-footer {
    background: rgba(6, 8, 18, 0.9);
    border-top: 1px solid rgba(100, 130, 180, 0.1);
    padding: 10px 16px;
    flex-shrink: 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
  }

  .ps-terminal-input {
    flex: 1;
    padding: 8px 12px;
    background: rgba(10, 18, 40, 0.8);
    border: 1px solid rgba(100, 130, 180, 0.2);
    border-radius: 6px;
    color: #d1d8ff;
    font-family: "JetBrains Mono", monospace;
    font-size: 12px;
    outline: none;
    transition: border-color 0.2s;
  }

  .ps-terminal-input:focus {
    border-color: rgba(100, 180, 255, 0.5);
  }

  .ps-btn {
    padding: 7px 14px;
    border: 1px solid transparent;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    background: transparent;
    color: #d1d8ff;
  }

  .ps-btn span {
    font-size: 16px;
  }

  .ps-btn.primary {
    background: linear-gradient(135deg, #90c0ff 0%, #6eaaff 100%);
    color: #0a1428;
  }

  .ps-btn.primary:hover {
    opacity: 0.9;
  }

  .ps-btn.secondary {
    background: rgba(100, 130, 180, 0.15);
    border-color: rgba(100, 130, 180, 0.3);
    color: #a0b0e0;
  }

  .ps-btn.secondary:hover {
    background: rgba(100, 130, 180, 0.25);
  }

  .ps-btn.danger {
    background: rgba(255, 150, 130, 0.15);
    border-color: rgba(255, 150, 130, 0.3);
    color: #ffb4ab;
  }

  .ps-btn.danger:hover {
    background: rgba(255, 150, 130, 0.25);
  }

  .ps-btn.success {
    background: rgba(78, 222, 163, 0.15);
    border-color: rgba(78, 222, 163, 0.3);
    color: #4edea3;
  }

  .ps-btn.success:hover {
    background: rgba(78, 222, 163, 0.25);
  }

  .ps-btn.xs {
    padding: 4px 10px;
    font-size: 11px;
  }

  .ps-btn.xs span {
    font-size: 14px;
  }

  .ps-split-handle {
    width: 6px;
    background: transparent;
    cursor: col-resize;
    flex-shrink: 0;
    transition: background 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: visible;
  }

  .ps-split-handle:hover {
    background: rgba(100, 180, 255, 0.2);
  }

  .ps-split-handle button {
    width: 24px;
    height: 24px;
    margin-left: -9px;
    border: 1px solid rgba(100, 130, 180, 0.24);
    border-radius: 999px;
    background: rgba(6, 14, 33, 0.95);
    color: #90c0ff;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    cursor: pointer;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.24);
  }

  .ps-split-handle button:hover {
    background: rgba(100, 180, 255, 0.18);
  }

  .ps-split-handle button span {
    font-size: 16px;
    line-height: 1;
  }

  .ps-input,
  .ps-textarea {
    width: 100%;
    padding: 8px 12px;
    background: rgba(10, 18, 40, 0.8);
    border: 1px solid rgba(100, 130, 180, 0.2);
    border-radius: 6px;
    color: #d1d8ff;
    font-family: inherit;
    font-size: 12px;
    outline: none;
    transition: border-color 0.2s;
  }

  .ps-input:focus,
  .ps-textarea:focus {
    border-color: rgba(100, 180, 255, 0.5);
  }

  .ps-textarea {
    min-height: 80px;
    resize: vertical;
  }

  .ps-label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    color: #8a96b8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
  }

  .ps-form-group {
    margin-bottom: 12px;
  }

  .ps-file-item,
  .ps-folder-item {
    padding: 8px 10px;
    margin: 2px 4px;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 6px;
    color: #a8b8d8;
    cursor: pointer;
    text-align: left;
    transition: all 0.15s;
    font-size: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .ps-file-item:hover,
  .ps-folder-item:hover {
    background: rgba(100, 130, 180, 0.1);
    color: #d1d8ff;
  }

  .ps-file-item.active {
    background: rgba(100, 180, 255, 0.15);
    color: #90c0ff;
    border-color: rgba(100, 180, 255, 0.3);
  }

  .ps-folder-item {
    font-weight: 600;
    background: rgba(80, 100, 140, 0.08);
  }

  .ps-console {
    background: rgba(6, 14, 33, 0.8);
    border-top: 1px solid rgba(100, 130, 180, 0.1);
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    max-height: var(--terminal-h);
    overflow: hidden;
  }

  .ps-console-output {
    flex: 1;
    overflow-y: auto;
    padding: 12px 16px;
    font-family: "JetBrains Mono", monospace;
    font-size: 12px;
    line-height: 1.5;
    color: #9ab0d0;
    white-space: pre-wrap;
    word-break: break-word;
  }

  .ps-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    padding: 0 16px;
  }

  .ps-toolbar-group {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 0 8px;
    margin: 0 4px;
    border-right: 1px solid rgba(100, 130, 180, 0.15);
  }

  .ps-toolbar-group:last-child {
    border-right: none;
  }

  .ps-quick-open {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    padding: 8px 0;
  }

  .ps-quick-chip {
    padding: 6px 12px;
    background: rgba(100, 130, 180, 0.12);
    border: 1px solid rgba(100, 130, 180, 0.2);
    border-radius: 6px;
    color: #a8b8d8;
    cursor: pointer;
    font-size: 11px;
    font-weight: 600;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
  }

  .ps-quick-chip:hover {
    background: rgba(100, 130, 180, 0.2);
    color: #d1d8ff;
  }

  .ps-output {
    background: rgba(8, 20, 50, 0.6);
    border: 1px solid rgba(100, 130, 180, 0.15);
    border-radius: 6px;
    padding: 10px 12px;
    min-height: 60px;
    font-family: "JetBrains Mono", monospace;
    font-size: 11px;
    color: #9ab0d0;
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 200px;
    overflow-y: auto;
  }

  .ps-item {
    background: rgba(20, 35, 70, 0.5);
    border: 1px solid rgba(100, 130, 180, 0.1);
    border-radius: 6px;
    padding: 10px;
    margin-bottom: 8px;
    font-size: 12px;
  }

  .ps-tab-strip {
    display: flex;
    gap: 6px;
    margin: 8px 0 10px;
    flex-wrap: wrap;
  }

  .ps-tab-btn {
    border: 1px solid rgba(100, 130, 180, 0.22);
    background: rgba(100, 130, 180, 0.1);
    color: #a8b8d8;
    border-radius: 999px;
    padding: 4px 9px;
    font-size: 11px;
    cursor: pointer;
    transition: all .18s ease;
  }

  .ps-tab-btn.active {
    background: rgba(100, 180, 255, 0.2);
    border-color: rgba(100, 180, 255, 0.4);
    color: #d8e7ff;
  }

  .ps-tab-panel {
    display: none;
    font-size: 11px;
    color: #a8b8d8;
    line-height: 1.55;
  }

  .ps-tab-panel.active {
    display: block;
  }

  .ps-info {
    font-size: 11px;
    color: #7a8ab5;
  }

  .ps-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: #6a7a95;
    font-size: 12px;
    text-align: center;
  }

  .ps-hidden {
    display: none !important;
  }

  @media (max-width: 1200px) {
    #patcherStudio {
      --panel-width: 280px;
    }
  }

  @media (max-width: 900px) {
    .ps-panel.right {
      display: none;
    }

    .ps-split-handle {
      display: none;
    }
  }
</style>
<div id="patcherStudio">
  <div class="ps-root">
    <header class="ps-header">
      <div class="ps-header-title">
        <h1>📝 Patcher</h1>
        <span class="ps-status" id="aiStatus">Checking...</span>
      </div>
      <div class="ps-header-actions">
        <button class="ps-btn secondary xs" type="button" id="btnBackDashboard"><span class="material-symbols-outlined">arrow_back</span>Dashboard</button>
      </div>
    </header>

    <div class="ps-main">
      <aside class="ps-sidebar">
        <button class="ps-sidebar-btn active" type="button" data-panel="explorer" title="Explorer"><span class="material-symbols-outlined">folder_open</span></button>
        <button class="ps-sidebar-btn" type="button" data-panel="search" title="Search"><span class="material-symbols-outlined">search</span></button>
        <button class="ps-sidebar-btn" type="button" data-panel="changes" title="Changes"><span class="material-symbols-outlined">history</span></button>
      </aside>

      <div class="ps-content">
        <section class="ps-panel left" id="leftPanel">
          <div class="ps-panel-header">
            <div class="ps-panel-title">
              <div>
                <h2 id="panelTitle">Explorer</h2>
                <div class="ps-panel-hint" id="panelHint">Browse repository files</div>
              </div>
              <div class="ps-panel-tool">
                <button class="ps-btn secondary xs" type="button" id="btnRefresh"><span class="material-symbols-outlined">refresh</span></button>
                <button class="ps-btn secondary xs" type="button" id="btnCollapseLeft"><span class="material-symbols-outlined">navigate_before</span></button>
              </div>
            </div>
          </div>

          <div class="ps-panel-body">
            <div class="ps-view active" data-panel="explorer">
              <div style="margin-bottom:10px;">
                <input id="fileSearch" class="ps-input" type="text" placeholder="Search files...">
              </div>
              <div id="fileList" class="ps-filelist"></div>
            </div>
            <div class="ps-view" data-panel="search">
              <div style="margin-bottom:10px;">
                <input id="searchInput" class="ps-input" type="text" placeholder="Find by path...">
              </div>
              <div id="searchResults" class="ps-filelist"></div>
            </div>
            <div class="ps-view" data-panel="changes">
              <div id="changesPanel" style="height:100%;overflow-y:auto;"></div>
            </div>
          </div>
        </section>

        <div class="ps-split-handle" id="leftHandle"></div>

        <div class="ps-editor-area">
          <div class="ps-editor-header">
            <div>
              <span class="ps-editor-label" id="currentFileLabel">No file open</span>
              <span class="ps-editor-meta" id="editorMeta">Select a file to edit</span>
            </div>
            <div class="ps-toolbar">
              <div class="ps-toolbar-group">
                <button class="ps-btn secondary xs" type="button" id="btnNewFile"><span class="material-symbols-outlined">note_add</span></button>
                <button class="ps-btn secondary xs" type="button" id="btnNewFolder"><span class="material-symbols-outlined">create_new_folder</span></button>
              </div>
              <div class="ps-toolbar-group">
                <button class="ps-btn secondary xs" type="button" id="btnRevertFile"><span class="material-symbols-outlined">undo</span></button>
                <button class="ps-btn success xs" type="button" id="btnSaveFile"><span class="material-symbols-outlined">save</span></button>
              </div>
              <div class="ps-toolbar-group">
                <button class="ps-btn secondary xs" type="button" id="btnGitStatus"><span class="material-symbols-outlined">branch</span></button>
                <button class="ps-btn primary xs" type="button" id="btnGitPull"><span class="material-symbols-outlined">download</span></button>
              </div>
            </div>
          </div>

          <div class="ps-editor-view">
            <div class="ps-gutter" id="editorGutter"></div>
            <textarea id="editor" class="ps-editor" spellcheck="false" placeholder="Open a file to start editing..."></textarea>
          </div>

          <div class="ps-console" id="terminalPanel">
            <div style="padding:10px 16px;border-bottom:1px solid rgba(100,130,180,0.1);">
              <div style="display:flex;justify-content:space-between;align-items:center;">
                <span style="font-weight:600;font-size:12px;color:#d1d8ff;">Output Console</span>
                <button class="ps-btn secondary xs" type="button" id="btnToggleTerminal">
                  <span class="material-symbols-outlined" id="terminalToggleIcon">unfold_less</span>
                </button>
              </div>
            </div>
            <div style="display:flex;gap:8px;padding:8px 16px;border-bottom:1px solid rgba(100,130,180,0.1);">
              <input id="terminalCommand" class="ps-terminal-input" type="text" placeholder="git status, git pull, git log...">
              <button class="ps-btn primary xs" type="button" id="btnRunTerminal"><span class="material-symbols-outlined">play_arrow</span></button>
            </div>
            <div class="ps-console-output" id="consoleOutput">Ready. Use terminal for git commands.</div>
          </div>
        </div>

        <div class="ps-split-handle" id="rightHandle">
          <button type="button" id="btnCollapseAiHandle" aria-label="Toggle AI assistant panel" title="Toggle AI assistant panel">
            <span class="material-symbols-outlined" id="btnCollapseAiHandleIcon">chevron_right</span>
          </button>
        </div>

        <section class="ps-panel right" id="aiPanel">
          <div class="ps-panel-header">
            <div class="ps-panel-title">
              <div>
                <h2>AI Assistant</h2>
                <div class="ps-panel-hint">Powered by OpenRouter/Gemini</div>
              </div>
              <button class="ps-btn secondary xs" type="button" id="btnCollapseAi" aria-label="Collapse AI assistant" title="Collapse AI assistant"><span class="material-symbols-outlined" id="btnCollapseAiIcon">chevron_right</span></button>
            </div>
          </div>

          <div class="ps-panel-body">
            <div class="ps-form-group">
              <label class="ps-label">Target File</label>
              <input id="aiTargetFile" class="ps-input" type="text" readonly placeholder="Open file first">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
              <div class="ps-form-group">
                <label class="ps-label">Provider</label>
                <select id="aiProvider" class="ps-input">
                  <option value="auto">Auto (best available)</option>
                  <option value="openrouter">OpenRouter</option>
                  <option value="gemini">Gemini</option>
                </select>
              </div>
              <div class="ps-form-group">
                <label class="ps-label">Model</label>
                <input id="aiModel" class="ps-input" type="text" list="aiModelPresets" value="<?= htmlspecialchars($patcherAiStatus['default_model']) ?>" placeholder="Model name">
                <datalist id="aiModelPresets">
                  <option value="openrouter/free">
                  <option value="gemini-2.0-flash">
                  <option value="gemini-2.5-flash">
                  <option value="gemini-2.5-pro">
                </datalist>
              </div>
            </div>

            <div class="ps-form-group">
              <label class="ps-label">Scan Mode</label>
              <select id="aiScanMode" class="ps-input">
                <option value="quick">Quick</option>
                <option value="standard" selected>Standard</option>
                <option value="deep">Deep</option>
              </select>
            </div>

            <div class="ps-form-group">
              <label class="ps-label">Issue Description</label>
              <textarea id="aiIssue" class="ps-textarea" placeholder="What fix or improvement do you need?"></textarea>
            </div>

            <div style="display:flex;gap:8px;margin-bottom:12px;">
              <button class="ps-btn primary" type="button" id="btnAiGenerate" style="flex:1"><span class="material-symbols-outlined">sparkles</span>Analyze Issue</button>
              <button class="ps-btn secondary" type="button" id="btnAiApply"><span class="material-symbols-outlined">edit</span></button>
              <button class="ps-btn success" type="button" id="btnAiApplyFile"><span class="material-symbols-outlined">check</span></button>
            </div>

            <div class="ps-item">
              <div class="ps-label">Processing Timeline</div>
              <div id="aiTimeline" class="ps-info" style="max-height:120px;overflow-y:auto;">Timeline steps will appear here.</div>
            </div>

            <div class="ps-item">
              <div class="ps-label">Diagnosis</div>
              <div id="aiExplanation" class="ps-info">No analysis yet</div>
              <div class="ps-tab-strip">
                <button class="ps-tab-btn active" type="button" data-diag-tab="root">Root Cause</button>
                <button class="ps-tab-btn" type="button" data-diag-tab="fixes">Fix Options</button>
                <button class="ps-tab-btn" type="button" data-diag-tab="tradeoffs">Tradeoffs</button>
              </div>
              <div id="aiTabRoot" class="ps-tab-panel active">No root-cause details yet.</div>
              <div id="aiTabFixes" class="ps-tab-panel">No fix options yet.</div>
              <div id="aiTabTradeoffs" class="ps-tab-panel">No tradeoffs yet.</div>
            </div>

            <div class="ps-item">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <div class="ps-label">Metrics</div>
                <div style="display:flex;gap:8px;align-items:center;">
                  <span id="aiProviderMeta" style="font-size:11px;color:#90c0ff;">Provider: n/a</span>
                  <span id="aiRisk" style="font-size:11px;color:#4edea3;">Risk: n/a</span>
                </div>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;font-size:11px;">
                <div style="text-align:center;color:#7a8ab5;">Checks<br><strong id="aiChecksCount" style="font-size:14px;color:#d1d8ff;">0</strong></div>
                <div style="text-align:center;color:#7a8ab5;">Size<br><strong id="aiPatchSize" style="font-size:14px;color:#d1d8ff;">0</strong></div>
                <div style="text-align:center;color:#7a8ab5;">File<br><strong id="aiTargetShort" style="font-size:12px;color:#d1d8ff;">-</strong></div>
                <div style="text-align:center;color:#7a8ab5;">Confidence<br><strong id="aiConfidence" style="font-size:14px;color:#90c0ff;">n/a</strong></div>
              </div>
            </div>

            <div class="ps-item">
              <div class="ps-label">Why files were scanned</div>
              <details style="font-size:11px;color:#a8b8d8;">
                <summary style="cursor:pointer;color:#90c0ff;">Show file-selection reasons</summary>
                <div id="aiScanReasons" style="margin-top:8px;">No file-selection rationale yet.</div>
              </details>
            </div>

            <div class="ps-item">
              <div class="ps-label">Patch Preview</div>
              <div id="aiPatchPreview" class="ps-info" style="max-height:120px;overflow-y:auto;">Preview here</div>
            </div>

            <div class="ps-item">
              <div class="ps-label">Diff View</div>
              <div id="aiDiffPreview" class="ps-info" style="max-height:120px;overflow-y:auto;">Diff here</div>
            </div>

            <div class="ps-item">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:8px;">
                <div class="ps-label" style="margin:0;">Staged Apply Preview</div>
                <button class="ps-btn secondary xs" type="button" id="btnAiCancelStage">Cancel</button>
              </div>
              <div id="aiStagePreview" class="ps-info">No staged apply yet.</div>
            </div>

            <div class="ps-item">
              <div class="ps-label">Release Review Queue</div>
              <div id="aiReleaseQueue" class="ps-info">No release requests yet.</div>
            </div>

            <div id="aiChecks" style="font-size:11px;color:#a8b8d8;margin-top:12px;"></div>
          </div>
        </section>
      </div>
    </div>
  </div>
</div>
<script>
  (() => {
    const csrf = <?= json_encode($csrf) ?>,
      dashboardUrl = 'index.php?page=dashboard',
      shell = document.getElementById('patcherStudio');

    const state = {
      files: [],
      currentPath: '',
      currentContent: '',
      suggestedContent: '',
      aiJobId: '',
      aiBusy: false,
      aiDiagTab: 'root',
      aiApplyStage: null,
      aiReleaseRequests: [],
      dirty: false,
      aiOpen: true,
      leftOpen: true,
      terminalOpen: true,
      currentPanel: 'explorer'
    };

    const DOM = {
      fileList: document.getElementById('fileList'),
      searchResults: document.getElementById('searchResults'),
      editor: document.getElementById('editor'),
      editorGutter: document.getElementById('editorGutter'),
      fileSearch: document.getElementById('fileSearch'),
      searchInput: document.getElementById('searchInput'),
      currentFileLabel: document.getElementById('currentFileLabel'),
      editorMeta: document.getElementById('editorMeta'),
      consoleOutput: document.getElementById('consoleOutput'),
      aiTargetFile: document.getElementById('aiTargetFile'),
      aiProvider: document.getElementById('aiProvider'),
      aiModel: document.getElementById('aiModel'),
      aiScanMode: document.getElementById('aiScanMode'),
      aiIssue: document.getElementById('aiIssue'),
      aiTimeline: document.getElementById('aiTimeline'),
      aiExplanation: document.getElementById('aiExplanation'),
      aiTabRoot: document.getElementById('aiTabRoot'),
      aiTabFixes: document.getElementById('aiTabFixes'),
      aiTabTradeoffs: document.getElementById('aiTabTradeoffs'),
      aiPatchPreview: document.getElementById('aiPatchPreview'),
      aiStagePreview: document.getElementById('aiStagePreview'),
      aiReleaseQueue: document.getElementById('aiReleaseQueue'),
      aiChecks: document.getElementById('aiChecks'),
      aiChecksCount: document.getElementById('aiChecksCount'),
      aiPatchSize: document.getElementById('aiPatchSize'),
      aiTargetShort: document.getElementById('aiTargetShort'),
      aiConfidence: document.getElementById('aiConfidence'),
      aiScanReasons: document.getElementById('aiScanReasons'),
      aiProviderMeta: document.getElementById('aiProviderMeta'),
      aiRisk: document.getElementById('aiRisk'),
      aiDiffPreview: document.getElementById('aiDiffPreview'),
      btnAiCancelStage: document.getElementById('btnAiCancelStage'),
      changesPanel: document.getElementById('changesPanel'),
      panelTitle: document.getElementById('panelTitle'),
      panelHint: document.getElementById('panelHint'),
      aiPanel: document.getElementById('aiPanel'),
      leftPanel: document.getElementById('leftPanel'),
      terminalPanel: document.getElementById('terminalPanel'),
      terminalCommand: document.getElementById('terminalCommand'),
      leftHandle: document.getElementById('leftHandle'),
      rightHandle: document.getElementById('rightHandle'),
      btnCollapseAi: document.getElementById('btnCollapseAi'),
      btnCollapseAiIcon: document.getElementById('btnCollapseAiIcon'),
      btnCollapseAiHandle: document.getElementById('btnCollapseAiHandle'),
      btnCollapseAiHandleIcon: document.getElementById('btnCollapseAiHandleIcon'),
      aiStatus: document.getElementById('aiStatus')
    };

    function setDiagTab(tab) {
      state.aiDiagTab = ['root', 'fixes', 'tradeoffs'].includes(tab) ? tab : 'root';
      document.querySelectorAll('[data-diag-tab]').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-diag-tab') === state.aiDiagTab);
      });
      DOM.aiTabRoot.classList.toggle('active', state.aiDiagTab === 'root');
      DOM.aiTabFixes.classList.toggle('active', state.aiDiagTab === 'fixes');
      DOM.aiTabTradeoffs.classList.toggle('active', state.aiDiagTab === 'tradeoffs');
    }

    function bulletList(items, emptyText) {
      const list = Array.isArray(items) ? items.filter(Boolean) : [];
      if (!list.length) return `<div>${e(emptyText)}</div>`;
      return `<ul style="margin:0;padding-left:16px;">${list.map(item => `<li style="margin:0 0 6px;">${e(item)}</li>`).join('')}</ul>`;
    }

    function renderStagePreview(stage) {
      if (!stage) {
        DOM.aiStagePreview.innerHTML = 'No staged apply yet.';
        document.getElementById('btnAiApplyFile').innerHTML = '<span class="material-symbols-outlined">check</span>Stage Review';
        return;
      }
      const snap = stage.snapshot || {};
      const diff = stage.diff || {};
      DOM.aiStagePreview.innerHTML = `
        <div style="margin-bottom:6px;color:#90c0ff;font-weight:700;">Snapshot ready for confirmation</div>
        <div>Target: <strong>${e(stage.path || '-')}</strong></div>
        <div>Backup: <strong>${e(stage.backup_path || '-')}</strong></div>
        <div>Current size: <strong>${e(String(snap.current_bytes ?? 0))}</strong> bytes</div>
        <div>Suggested size: <strong>${e(String(snap.new_bytes ?? 0))}</strong> bytes</div>
        <div>Line delta: <strong>${e(String(snap.line_delta ?? 0))}</strong></div>
        <div>Changed lines: <strong>${e(String(diff.changed_lines ?? 0))}</strong></div>
        <div style="margin-top:6px;color:#7a8ab5;">${e(diff.summary || 'Backup snapshot will be created before the patch is written.')}</div>
      `;
      document.getElementById('btnAiApplyFile').innerHTML = '<span class="material-symbols-outlined">check</span>Submit for Review';
    }

    function renderReleaseQueue(items) {
      const list = Array.isArray(items) ? items : [];
      state.aiReleaseRequests = list;
      if (!list.length) {
        DOM.aiReleaseQueue.innerHTML = 'No release requests yet.';
        return;
      }
      DOM.aiReleaseQueue.innerHTML = list.map(req => {
        const snap = req.snapshot || {};
        const review = req.review || {};
        const validation = req.validation || {};
        const status = String(req.status || 'pending');
        const statusColor = status === 'approved' ? '#4edea3' : (status === 'rejected' ? '#ffb4ab' : '#90c0ff');
        const buttons = status === 'pending'
          ? `<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;"><button class="ps-btn success xs" type="button" data-release-approve="${e(req.release_id)}">Approve & Apply</button><button class="ps-btn danger xs" type="button" data-release-reject="${e(req.release_id)}">Reject</button></div>`
          : `<div style="margin-top:8px;color:${statusColor};font-size:11px;">Reviewed: ${e(status)}</div>`;
        const validationColor = validation.ok === false ? '#ffb4ab' : '#4edea3';
        const validationText = validation.message || (validation.ok === false ? 'Pre-apply validation failed.' : 'Pre-apply validation passed.');
        const validationChecks = Array.isArray(validation.checks) && validation.checks.length
          ? `<div style="margin-top:4px;font-size:11px;">Checks: ${validation.checks.map(check => `${e(check.name || 'check')}:${e(check.ok ? 'pass' : 'fail')}`).join(' • ')}</div>`
          : '';
        return `<div class="ps-item" style="margin:8px 0 0;"><div style="display:flex;justify-content:space-between;gap:8px;align-items:center;"><strong>${e(req.path || '-')}</strong><span style="font-size:11px;color:${statusColor};text-transform:uppercase;">${e(status)}</span></div><div style="margin-top:4px;font-size:11px;">Release ID: ${e(req.release_id || '-')}</div><div style="margin-top:4px;font-size:11px;">Backup: ${e(req.backup_path || '-')}</div><div style="margin-top:4px;font-size:11px;">Current bytes: ${e(String(snap.current_bytes ?? 0))} • Suggested bytes: ${e(String(snap.new_bytes ?? 0))}</div><div style="margin-top:4px;font-size:11px;">Line delta: ${e(String(snap.line_delta ?? 0))}</div><div style="margin-top:4px;font-size:11px;color:${validationColor};">Validation: ${e(validationText)}</div>${validationChecks}${review.decision ? `<div style="margin-top:4px;font-size:11px;">Review decision: ${e(review.decision)}</div>` : ''}${buttons}</div>`;
      }).join('');
    }

    function log(msg) {
      const ts = new Date().toLocaleTimeString();
      DOM.consoleOutput.textContent = `[${ts}] ${msg}\n` + DOM.consoleOutput.textContent;
    }

    function e(text) {
      return String(text).replace(/[&<>\"]/g, ch => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;'
      } [ch]));
    }

    function updateLineNumbers() {
      const lines = Math.max((DOM.editor.value.match(/\n/g) || []).length + 1, 1);
      DOM.editorGutter.innerHTML = Array.from({
        length: lines
      }, (_, i) => `<div>${i+1}</div>`).join('');
      DOM.editorGutter.scrollTop = DOM.editor.scrollTop;
    }

    function setDirty(v) {
      state.dirty = !!v;
      const label = DOM.currentFileLabel.nextElementSibling;
      if (label) label.textContent = state.dirty ? '(unsaved)' : '';
    }

    function renderDiff(oldText, newText) {
      if (!newText) {
        DOM.aiDiffPreview.textContent = 'Diff will appear here after AI generates a proposal.';
        return;
      }
      const a = String(oldText || '').split('\n'),
        b = String(newText || '').split('\n'),
        max = Math.max(a.length, b.length),
        rows = [];
      for (let i = 0; i < max; i++) {
        if (a[i] === b[i]) continue;
        if (typeof a[i] !== 'undefined') rows.push(`<div style="background:rgba(147,0,10,.18);padding:4px 8px;border-radius:8px;margin-bottom:4px;color:#ffb4ab;">- ${e(a[i])}</div>`);
        if (typeof b[i] !== 'undefined') rows.push(`<div style="background:rgba(0,104,70,.22);padding:4px 8px;border-radius:8px;margin-bottom:4px;color:#4edea3;">+ ${e(b[i])}</div>`);
      }
      DOM.aiDiffPreview.innerHTML = rows.length ? rows.join('') : '<div>No diff detected.</div>';
    }

    function renderTimeline(items) {
      const list = Array.isArray(items) ? items : [];
      if (!list.length) {
        DOM.aiTimeline.innerHTML = 'Timeline steps will appear here.';
        return;
      }
      DOM.aiTimeline.innerHTML = list.map(step => {
        const status = (step.status || 'info').toLowerCase();
        const color = status === 'success' ? '#4edea3' : (status === 'error' ? '#ffb4ab' : (status === 'running' ? '#90c0ff' : '#a8b8d8'));
        return `<div style="margin-bottom:8px;padding:7px 8px;border-radius:8px;background:rgba(100,130,180,.08);border:1px solid rgba(100,130,180,.12);"><div style="font-weight:700;color:${color};font-size:11px;text-transform:uppercase;letter-spacing:.04em;">${e(step.step || 'Step')} • ${e(status)}</div><div style="margin-top:4px;color:#a8b8d8;">${e(step.detail || '')}</div></div>`;
      }).join('');
    }

    function sleep(ms) {
      return new Promise(resolve => setTimeout(resolve, ms));
    }

    async function applyAiAnalysisResult(data) {
      const r = data.result || {};
      if (data.target_file && state.currentPath !== data.target_file) {
        try {
          await openFile(data.target_file);
        } catch (err) {
          log('Unable to auto-open target file from analysis result.');
        }
      }
      state.suggestedContent = r.improved_code || '';
      DOM.aiExplanation.textContent = r.explanation || r.summary || 'No explanation';
      DOM.aiTabRoot.innerHTML = bulletList([r.root_cause || r.explanation || 'No root cause provided.'], 'No root-cause details yet.');
      DOM.aiTabFixes.innerHTML = bulletList(r.fix_options || [], 'No fix options yet.');
      DOM.aiTabTradeoffs.innerHTML = bulletList(r.tradeoffs || [], 'No tradeoffs yet.');
      DOM.aiPatchPreview.textContent = r.patch_preview || '(No patch preview)';
      state.aiApplyStage = null;
      renderStagePreview(null);
      renderDiff(state.currentContent, state.suggestedContent);
      DOM.aiRisk.textContent = 'Risk: ' + (r.risk || 'n/a');
      const providerMeta = `${data.provider || DOM.aiProvider.value}:${data.model || DOM.aiModel.value || 'default'}`;
      DOM.aiProviderMeta.textContent = `Provider: ${providerMeta}${data.fallback_used ? ' (fallback)' : ''}`;
      const checks = Array.isArray(r.checks) ? r.checks : [];
      DOM.aiChecksCount.textContent = String(checks.length);
      DOM.aiPatchSize.textContent = String((r.patch_preview || '').length);
      const confidence = data.confidence || {};
      const confidenceScore = Number.isFinite(Number(confidence.score)) ? Number(confidence.score) : null;
      const confidenceLevel = String(confidence.level || '').toLowerCase();
      DOM.aiConfidence.textContent = confidenceScore === null ? 'n/a' : `${confidenceScore}%`;
      DOM.aiConfidence.style.color = confidenceLevel === 'high' ? '#4edea3' : (confidenceLevel === 'low' ? '#ffb4ab' : '#90c0ff');

      const reasons = Array.isArray(data.candidate_reasons) ? data.candidate_reasons : [];
      DOM.aiScanReasons.innerHTML = reasons.length
        ? reasons.slice(0, 12).map(entry => {
            const p = e(entry.path || '-');
            const tags = Array.isArray(entry.reasons) ? entry.reasons.map(x => `<code style="background:rgba(100,130,180,.18);padding:1px 5px;border-radius:5px;">${e(x)}</code>`).join(' ') : '<code>unknown</code>';
            return `<div style="margin-top:6px;"><strong>${p}</strong><br><span style="opacity:.9;">${tags}</span></div>`;
          }).join('')
        : 'No file-selection rationale returned.';

      const fallbackNote = data.fallback_reason ? `<div style="margin-top:8px;color:#ffb4ab;">Fallback reason: ${e(data.fallback_reason)}</div>` : '';
      const schemaNote = r.schema_valid === false ? '<div style="margin-top:8px;color:#ffd580;">Schema note: AI response was normalized for safety.</div>' : '';
      DOM.aiChecks.innerHTML = (checks.map(item => `<div style="margin-top:6px;">- ${e(item)}</div>`).join('') || '<div>No checks</div>') + fallbackNote + schemaNote;
      log(`AI analysis generated via ${providerMeta}${data.fallback_used ? ' (fallback used)' : ''}`);
    }

    async function api(action, payload = {}, method = 'POST') {
      const opts = {
        method,
        headers: {}
      };
      if (method === 'POST') {
        const body = new URLSearchParams();
        Object.entries(payload).forEach(([k, v]) => body.append(k, v == null ? '' : String(v)));
        body.append('csrf_token', csrf);
        opts.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
        opts.body = body.toString();
      }
      const res = await fetch(`patcher.php?api=${encodeURIComponent(action)}`, opts);
      return res.json();
    }

    function groups(query) {
      const map = new Map();
      state.files.filter(f => !query || f.path.toLowerCase().includes(query)).forEach(file => {
        const idx = file.path.lastIndexOf('/'),
          folder = idx >= 0 ? file.path.slice(0, idx) : '.',
          name = idx >= 0 ? file.path.slice(idx + 1) : file.path;
        if (!map.has(folder)) map.set(folder, []);
        map.get(folder).push({
          ...file,
          name
        });
      });
      return Array.from(map.entries()).sort((a, b) => a[0].localeCompare(b[0]));
    }

    function renderContainer(node, query) {
      const list = groups(query);
      node.innerHTML = '';
      if (!list.length) {
        node.innerHTML = '<div class="ps-empty">No files found</div>';
        return;
      }
      list.forEach(([folder, files]) => {
        const wrap = document.createElement('div');
        wrap.className = 'ps-folder';
        const head = document.createElement('button');
        head.type = 'button';
        head.className = 'ps-folder-item';
        head.textContent = `📁 ${e(folder)} (${files.length})`;
        head.style.fontWeight = '500';
        const body = document.createElement('div');
        body.className = 'ps-folder-files';
        files.forEach(file => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'ps-file-item' + (file.path === state.currentPath ? ' active' : '');
          btn.textContent = `${e(file.name)} (${Math.max(1, Math.round((file.size||0)/1024))}K)`;
          btn.addEventListener('click', () => guardUnsaved(() => openFile(file.path)));
          body.appendChild(btn);
        });
        head.addEventListener('click', () => {
          body.classList.toggle('ps-hidden');
        });
        wrap.append(head, body);
        node.appendChild(wrap);
      });
    }

    function renderFiles() {
      renderContainer(DOM.fileList, DOM.fileSearch.value.trim().toLowerCase());
      renderContainer(DOM.searchResults, DOM.searchInput.value.trim().toLowerCase());
    }

    async function loadFiles() {
      const data = await api('list_files', {}, 'GET');
      if (!data.ok) return log(data.message || 'Failed to load files');
      state.files = data.files || [];
      renderFiles();
      log(`Loaded ${state.files.length} files`);
    }

    async function openFile(path) {
      const data = await api('read_file', {
        path
      });
      if (!data.ok) return window.adminAlert('Open failed', data.message || 'Unable to open file', 'error');
      state.currentPath = data.path;
      state.currentContent = data.content || '';
      state.suggestedContent = '';
      DOM.editor.value = state.currentContent;
      DOM.currentFileLabel.textContent = data.path;
      DOM.aiTargetFile.value = data.path;
      DOM.aiTargetShort.textContent = data.path.split('/').pop();
      DOM.editorMeta.textContent = `${Math.max(1, DOM.editor.value.split('\n').length)} lines`;
      DOM.aiDiffPreview.textContent = 'Diff will appear here.';
      state.aiApplyStage = null;
      renderStagePreview(null);
      setDirty(false);
      updateLineNumbers();
      renderFiles();
      log(`Opened ${data.path}`);
    }

    async function saveCurrent(showAlert = true) {
      if (!state.currentPath) {
        if (showAlert) window.adminAlert('No file selected', 'Open a file first.', 'warning');
        return false;
      }
      const data = await api('save_file', {
        path: state.currentPath,
        content: DOM.editor.value
      });
      if (!data.ok) {
        if (showAlert) window.adminAlert('Save failed', data.message || 'Unable to save file.', 'error');
        return false;
      }
      state.currentContent = DOM.editor.value;
      setDirty(false);
      DOM.editorMeta.textContent = `${Math.max(1, DOM.editor.value.split('\n').length)} lines`;
      log(data.message || 'Saved');
      if (showAlert) window.adminAlert('Saved', data.message || 'File saved.', 'success');
      return true;
    }

    async function chooseUnsavedAction() {
      if (!state.dirty) return 'discard';
      const result = await new Promise(resolve => {
        const choice = confirm('You have unsaved changes. Save before continuing?');
        resolve(choice ? 'save' : 'discard');
      });
      return result;
    }

    async function guardUnsaved(fn) {
      if (!state.dirty) return fn();
      const action = await chooseUnsavedAction();
      if (action === 'cancel') return;
      if (action === 'save') {
        const saved = await saveCurrent(false);
        if (!saved) return;
      }
      return fn();
    }

    async function updateAiStatus() {
      const data = await api('ai_status', {}, 'GET');
      const online = !!(data && data.online);
      DOM.aiStatus.textContent = online ? '✓ Online' : '✕ Offline';
      DOM.aiStatus.style.color = online ? '#4edea3' : '#ffb4ab';
      if (data && data.provider) DOM.aiProvider.value = data.provider;
      if (data && data.default_model && !DOM.aiModel.value.trim()) DOM.aiModel.value = data.default_model;
      if (data && data.message) log(data.message);
    }

    async function generateAiProposal() {
      if (state.aiBusy) return;
      if (!DOM.aiIssue.value.trim()) return window.adminAlert('Issue required', 'Describe the issue or requested improvement.', 'warning');
      state.aiBusy = true;
      const btn = document.getElementById('btnAiGenerate');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined">sync</span>Analyzing...';
      }
      DOM.aiExplanation.textContent = 'Analyzing issue...';
      DOM.aiPatchPreview.textContent = 'Waiting for AI...';
      state.aiApplyStage = null;
      renderStagePreview(null);
      DOM.aiTabRoot.innerHTML = 'Analyzing root cause...';
      DOM.aiTabFixes.innerHTML = 'Analyzing fix options...';
      DOM.aiTabTradeoffs.innerHTML = 'Analyzing tradeoffs...';
      setDiagTab('root');
      DOM.aiConfidence.textContent = 'n/a';
      DOM.aiScanReasons.textContent = 'Collecting file-selection rationale...';
      renderTimeline([{ step: 'Issue intake', status: 'running', detail: 'Preparing issue context and requesting analysis.' }]);
      try {
        const start = await api('ai_analyze_issue_start', {
          issue: DOM.aiIssue.value.trim(),
          provider: DOM.aiProvider.value,
          model: DOM.aiModel.value.trim(),
          scan_mode: DOM.aiScanMode.value
        });
        if (!start.ok || !start.job_id) {
          renderTimeline(start.timeline || []);
          DOM.aiExplanation.textContent = start.message || 'Failed to start analysis job.';
          DOM.aiPatchPreview.textContent = '';
          window.adminAlert('AI request failed', start.message || 'Unable to start analysis.', 'error');
          return;
        }

        state.aiJobId = start.job_id;
        renderTimeline(start.timeline || []);
        let complete = false;
        for (let i = 0; i < 80; i++) {
          await sleep(650);
          const poll = await api('ai_analyze_issue_poll', {
            job_id: state.aiJobId
          });
          renderTimeline(poll.timeline || []);
          if (!poll.ok && poll.status !== 'error') {
            DOM.aiExplanation.textContent = poll.message || 'Polling failed.';
            window.adminAlert('AI request failed', poll.message || 'Failed while waiting for analysis.', 'error');
            break;
          }
          if (poll.status === 'running') {
            DOM.aiExplanation.textContent = poll.message || 'Analysis in progress...';
            continue;
          }
          if (poll.status === 'error') {
            DOM.aiExplanation.textContent = poll.message || 'AI analysis failed.';
            DOM.aiPatchPreview.textContent = '';
            window.adminAlert('AI analysis failed', poll.message || 'Analysis job ended with error.', 'error');
            break;
          }
          if (poll.status === 'done') {
            await applyAiAnalysisResult(poll);
            complete = true;
            break;
          }
        }
        if (!complete) {
          DOM.aiExplanation.textContent = 'Analysis timed out waiting for completion.';
          if (state.aiJobId) {
            window.adminAlert('Still processing', 'Analysis is taking longer than expected. You can click Analyze Issue again to retry.', 'warning');
          }
        }
      } finally {
        state.aiJobId = '';
        state.aiBusy = false;
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = '<span class="material-symbols-outlined">sparkles</span>Analyze Issue';
        }
      }
    }

    async function loadRecentChanges() {
      const data = await api('recent_changes', {});
      if (!data.ok) {
        DOM.changesPanel.innerHTML = `<div class="ps-empty">${e(data.message || 'Unable to load git changes')}</div>`;
        return;
      }
      const statusItems = Array.isArray(data.status) ? data.status : [],
        history = Array.isArray(data.history) ? data.history : [];
      const statusHtml = statusItems.length ? `<div>${statusItems.map(item=>`<div class="ps-item"><strong>${e(item.code)}</strong> ${e(item.path)}<br><button class="ps-btn secondary xs" type="button" data-open-change="${e(item.path)}">Open</button> <button class="ps-btn danger xs" type="button" data-revert-change="${e(item.path)}">Revert</button></div>`).join('')}</div>` : '<div style="color:#7a8ab5;font-size:11px;">No pending changes</div>';
      const historyHtml = history.length ? `<div>${history.slice(0,5).map(c=>`<div class="ps-item"><strong>${e(c.subject || c.hash || 'Commit')}</strong><br><span style="font-size:10px;color:#7a8ab5;">${e(c.hash || '')} • ${e(c.age || '')}</span></div>`).join('')}</div>` : '<div style="color:#7a8ab5;font-size:11px;">No history</div>';
      DOM.changesPanel.innerHTML = `<div><h3 style="margin:0 0 10px 0;font-size:12px;">Changes</h3>${statusHtml}<h3 style="margin:16px 0 10px 0;font-size:12px;">History</h3>${historyHtml}</div>`;
      DOM.changesPanel.querySelectorAll('[data-open-change]').forEach(btn => btn.addEventListener('click', () => guardUnsaved(() => openFile(btn.getAttribute('data-open-change')))));
      DOM.changesPanel.querySelectorAll('[data-revert-change]').forEach(btn => btn.addEventListener('click', () => revertFile(btn.getAttribute('data-revert-change'))));
      log('Loaded git changes');
    }

    async function loadReleaseQueue() {
      const data = await api('ai_release_request_list', {});
      if (!data.ok) {
        DOM.aiReleaseQueue.innerHTML = `<div style="color:#ffb4ab;">${e(data.message || 'Unable to load release queue')}</div>`;
        return;
      }
      renderReleaseQueue(data.requests || []);
    }

    async function revertFile(path = state.currentPath) {
      if (!path) return window.adminAlert('No file selected', 'Open a tracked file first.', 'warning');
      const ok = confirm(`Revert ${path} back to HEAD?`);
      if (!ok) return;
      const data = await api('revert_file', {
        path
      });
      if (!data.ok) return window.adminAlert('Revert failed', data.message || 'Unable to revert file.', 'error');
      log(data.message || 'File reverted');
      if (state.currentPath === path) await openFile(path);
      await loadRecentChanges();
      window.adminAlert('Reverted', 'File reverted to HEAD.', 'success');
    }

    function switchPanel(panel) {
      state.currentPanel = panel;
      document.querySelectorAll('.ps-sidebar-btn').forEach(btn => btn.classList.toggle('active', btn.getAttribute('data-panel') === panel));
      document.querySelectorAll('.ps-view').forEach(el => el.classList.toggle('active', el.getAttribute('data-panel') === panel));
      const titles = {
        explorer: ['Explorer', 'Browse repository files'],
        search: ['Search', 'Filter by path or name'],
        changes: ['Changes', 'Working tree and commits']
      };
      DOM.panelTitle.textContent = titles[panel][0];
      DOM.panelHint.textContent = titles[panel][1];
      if (panel === 'changes') loadRecentChanges();
    }

    function toggleLeftPanel() {
      state.leftOpen = !state.leftOpen;
      DOM.leftPanel.classList.toggle('hidden');
      document.getElementById('btnCollapseLeft').textContent = state.leftOpen ? '◄' : '▶';
    }

    function toggleRightPanel() {
      state.aiOpen = !state.aiOpen;
      DOM.aiPanel.classList.toggle('hidden');
      const icon = state.aiOpen ? 'chevron_right' : 'chevron_left';
      if (DOM.btnCollapseAiIcon) DOM.btnCollapseAiIcon.textContent = icon;
      if (DOM.btnCollapseAiHandleIcon) DOM.btnCollapseAiHandleIcon.textContent = icon;
      if (DOM.btnCollapseAi) {
        DOM.btnCollapseAi.setAttribute('aria-label', state.aiOpen ? 'Collapse AI assistant' : 'Open AI assistant');
        DOM.btnCollapseAi.setAttribute('title', state.aiOpen ? 'Collapse AI assistant' : 'Open AI assistant');
      }
      if (DOM.btnCollapseAiHandle) {
        DOM.btnCollapseAiHandle.setAttribute('aria-label', state.aiOpen ? 'Collapse AI assistant' : 'Open AI assistant');
        DOM.btnCollapseAiHandle.setAttribute('title', state.aiOpen ? 'Collapse AI assistant' : 'Open AI assistant');
      }
    }

    function toggleTerminal() {
      state.terminalOpen = !state.terminalOpen;
      DOM.terminalPanel.classList.toggle('ps-hidden');
      document.getElementById('terminalToggleIcon').textContent = state.terminalOpen ? 'unfold_less' : 'unfold_more';
    }

    async function runTerminalCommand() {
      const command = (DOM.terminalCommand?.value || '').trim();
      if (!command) {
        window.adminAlert('Terminal', 'Type a command first.', 'info');
        return;
      }
      log(`> ${command}`);
      const data = await api('run_terminal', {
        command
      });
      if (!data.ok) {
        log(data.message || 'Command failed');
        window.adminAlert('Command blocked', data.message || 'Unable to run command.', 'warning');
        return;
      }
      log(data.output || data.message || 'Done');
      DOM.terminalCommand.value = '';
      await loadRecentChanges();
    }

    async function goBackToDashboard() {
      await guardUnsaved(() => {
        window.location.href = dashboardUrl;
      });
    }

    // Event Listeners
    DOM.editor.addEventListener('input', () => {
      setDirty(state.currentPath && DOM.editor.value !== state.currentContent);
      DOM.editorMeta.textContent = `${Math.max(1, DOM.editor.value.split('\n').length)} lines`;
      updateLineNumbers();
    });

    DOM.editor.addEventListener('scroll', () => {
      DOM.editorGutter.scrollTop = DOM.editor.scrollTop;
    });

    DOM.fileSearch.addEventListener('input', renderFiles);
    DOM.searchInput.addEventListener('input', renderFiles);

    document.querySelectorAll('.ps-sidebar-btn').forEach(btn => {
      btn.addEventListener('click', () => switchPanel(btn.getAttribute('data-panel')));
    });

    document.getElementById('btnRefresh').addEventListener('click', loadFiles);
    document.getElementById('btnCollapseLeft').addEventListener('click', toggleLeftPanel);
    document.getElementById('btnCollapseAi').addEventListener('click', toggleRightPanel);
    document.getElementById('btnSaveFile').addEventListener('click', () => saveCurrent(true));
    document.getElementById('btnNewFile').addEventListener('click', async () => {
      const path = prompt('New file path:');
      if (!path) return;
      const data = await api('create_file', {
        path,
        content: ''
      });
      if (data.ok) {
        await loadFiles();
        await openFile(path);
      } else window.adminAlert('Create failed', data.message || 'Unable to create file.', 'error');
    });

    document.getElementById('btnNewFolder').addEventListener('click', async () => {
      const path = prompt('New folder path:');
      if (!path) return;
      const data = await api('create_folder', {
        path
      });
      if (data.ok) {
        await loadFiles();
        log(data.message || 'Folder created');
      } else window.adminAlert('Create failed', data.message || 'Unable to create folder.', 'error');
    });

    document.getElementById('btnRevertFile').addEventListener('click', () => revertFile());
    document.getElementById('btnGitStatus').addEventListener('click', async () => {
      const data = await api('git_status', {});
      log(data.output || data.message || 'Git status');
      switchPanel('changes');
    });

    document.getElementById('btnGitPull').addEventListener('click', async () => {
      const data = await api('git_pull', {
        branch: 'main'
      });
      log(data.output || data.message || 'Pull completed');
      await loadRecentChanges();
    });

    document.getElementById('btnToggleTerminal').addEventListener('click', toggleTerminal);
    document.getElementById('btnRunTerminal').addEventListener('click', runTerminalCommand);
    DOM.btnCollapseAiHandle?.addEventListener('click', toggleRightPanel);

    DOM.terminalCommand?.addEventListener('keydown', event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        runTerminalCommand();
      }
    });

    document.getElementById('btnAiGenerate').addEventListener('click', generateAiProposal);
    document.getElementById('btnAiApply').addEventListener('click', () => {
      if (!state.suggestedContent) return window.adminAlert('No AI code yet', 'Generate a proposal first.', 'warning');
      DOM.editor.value = state.suggestedContent;
      setDirty(DOM.editor.value !== state.currentContent);
      updateLineNumbers();
      renderDiff(state.currentContent, state.suggestedContent);
      log('Applied AI proposal to editor');
    });

    document.getElementById('btnAiApplyFile').addEventListener('click', async () => {
      if (!state.currentPath) return window.adminAlert('No target file', 'Open a file first.', 'warning');
      if (!state.suggestedContent) return window.adminAlert('No AI code yet', 'Generate a proposal first.', 'warning');

      if (!state.aiApplyStage) {
        const preview = await api('ai_stage_apply', {
          path: state.currentPath,
          content: state.suggestedContent
        });
        if (!preview.ok) return window.adminAlert('Stage failed', preview.message || 'Unable to stage apply.', 'error');
        state.aiApplyStage = preview;
        renderStagePreview(preview);
        log(preview.message || 'Apply staged');
        window.adminAlert('Staged', 'Backup snapshot preview is ready. Click Confirm Apply to continue.', 'success');
        return;
      }

      const data = await api('ai_release_request_create', {
        path: state.currentPath,
        content: state.suggestedContent,
        stage_id: state.aiApplyStage.stage_id
      });
      if (!data.ok) return window.adminAlert('Release failed', data.message || 'Unable to create release request.', 'error');
      state.aiReleaseRequests = [data, ...(state.aiReleaseRequests || [])];
      state.aiApplyStage = null;
      renderStagePreview(null);
      await loadReleaseQueue();
      log(`Release request submitted: ${data.release_id || 'n/a'}`);
      window.adminAlert('Submitted', 'Release request submitted for review.', 'success');
    });

    DOM.btnAiCancelStage?.addEventListener('click', () => {
      state.aiApplyStage = null;
      renderStagePreview(null);
      log('Cleared staged apply preview');
    });

    DOM.aiReleaseQueue.addEventListener('click', async event => {
      const approveId = event.target?.getAttribute?.('data-release-approve');
      const rejectId = event.target?.getAttribute?.('data-release-reject');
      const id = approveId || rejectId;
      if (!id) return;
      const decision = approveId ? 'approve' : 'reject';
      if (decision === 'approve' && !confirm('Approve this release and apply it to the file?')) return;
      if (decision === 'reject' && !confirm('Reject this release request?')) return;
      const data = await api('ai_release_request_review', {
        release_id: id,
        decision
      });
      if (!data.ok) return window.adminAlert('Review failed', data.message || 'Unable to review release request.', 'error');
      if (decision === 'approve' && data.request?.path && state.currentPath === data.request.path) {
        await openFile(state.currentPath);
      }
      await loadRecentChanges();
      await loadReleaseQueue();
      log(data.message || `Release ${decision}d`);
      window.adminAlert('Release review', data.message || 'Review completed.', 'success');
    });

    document.getElementById('btnBackDashboard').addEventListener('click', goBackToDashboard);

    document.querySelectorAll('[data-diag-tab]').forEach(btn => {
      btn.addEventListener('click', () => setDiagTab(btn.getAttribute('data-diag-tab')));
    });

    DOM.aiProvider.addEventListener('change', () => {
      if (DOM.aiProvider.value === 'gemini' && !DOM.aiModel.value.trim()) DOM.aiModel.value = 'gemini-2.0-flash';
      if (DOM.aiProvider.value === 'openrouter' && !DOM.aiModel.value.trim()) DOM.aiModel.value = 'openrouter/free';
      if (DOM.aiProvider.value === 'auto' && !DOM.aiModel.value.trim()) DOM.aiModel.value = '';
    });

    window.addEventListener('beforeunload', event => {
      if (!state.dirty) return;
      event.preventDefault();
      event.returnValue = '';
    });

    // Initialize
    loadFiles();
    loadRecentChanges();
    loadReleaseQueue();
    updateAiStatus();
    updateLineNumbers();
    setDiagTab('root');
    renderStagePreview(null);
    if (DOM.btnCollapseAiIcon) DOM.btnCollapseAiIcon.textContent = 'chevron_right';
    if (DOM.btnCollapseAiHandleIcon) DOM.btnCollapseAiHandleIcon.textContent = 'chevron_right';
  })();
</script>
