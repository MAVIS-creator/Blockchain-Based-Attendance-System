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
function patcher_ai_prompt($path, $issue, $content)
{
  return "You are an API-backed patch assistant for a PHP repository. Return strict JSON only. Schema: {\"summary\":\"short title\",\"explanation\":\"why this matters\",\"risk\":\"low|medium|high\",\"checks\":[\"step\"],\"patch_preview\":\"preview\",\"improved_code\":\"full improved file content\"}. Guidance: minimal production-safe edits; preserve storage helpers, CSRF, SweetAlert UX, Azure Web App Linux and localhost support. Target file: {$path}. Issue: {$issue}. Current file:
```text
{$content}
```";
}
function patcher_ai_provider($env, $requested = '')
{
  $provider = strtolower(trim((string)$requested));
  if ($provider === '') $provider = strtolower(trim((string)($env['PATCHER_AI_PROVIDER'] ?? 'openrouter')));
  return in_array($provider, ['openrouter', 'gemini'], true) ? $provider : 'openrouter';
}
function patcher_ai_model($env, $provider, $requested = '')
{
  $requested = trim((string)$requested);
  if ($requested !== '') return $requested;
  if ($provider === 'gemini') return trim((string)($env['PATCHER_GEMINI_MODEL'] ?? 'gemini-2.0-flash'));
  return trim((string)($env['PATCHER_OPENROUTER_MODEL'] ?? 'openrouter/free'));
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
  $providerReady = $provider === 'gemini' ? $geminiKey !== '' : $openrouterKey !== '';
  return ['ok' => true, 'enabled' => patcher_ai_enabled($env), 'online' => $providerReady, 'provider' => $provider, 'available_providers' => ['openrouter' => $openrouterKey !== '', 'gemini' => $geminiKey !== ''], 'default_model' => patcher_ai_model($env, $provider), 'message' => !patcher_ai_enabled($env) ? 'AI assistant disabled by config.' : (!$hasAny ? 'No AI API key configured.' : ($providerReady ? ('Configured for ' . ucfirst($provider) . ' API.') : ('Selected provider ' . ucfirst($provider) . ' is missing its API key.')))];
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
    if (@file_put_contents($target, $content, LOCK_EX) === false) {
      echo json_encode(['ok' => false, 'message' => 'Failed to save file.']);
      exit;
    }
    echo json_encode(['ok' => true, 'message' => ($action === 'save_file' ? 'Saved: ' : 'AI patch applied to ') . $rel]);
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
    $result = patcher_ai_generate_result($patcherEnv, $provider, $model, patcher_ai_prompt($rel, $issue, $content));
    if (!$result['ok']) {
      echo json_encode(['ok' => false, 'message' => $result['message'] ?? 'AI request failed.', 'output' => $result['output'] ?? ($result['raw'] ?? '')]);
      exit;
    }
    $decoded = patcher_extract_json_block($result['raw']);
    if (!is_array($decoded)) $decoded = ['summary' => 'AI response generated', 'explanation' => trim((string)$result['raw']), 'risk' => 'medium', 'checks' => [], 'patch_preview' => trim((string)$result['raw']), 'improved_code' => ''];
    echo json_encode(['ok' => true, 'provider' => $provider, 'model' => $model, 'result' => $decoded, 'raw' => trim((string)$result['raw'])]);
    exit;
  }
  echo json_encode(['ok' => false, 'message' => 'Unknown API action.']);
  exit;
}
$localMode = app_local_mode_enabled(__DIR__ . '/../.env');
$quickOpen = ['.env', '.env.local', 'hybrid_dual_write.php', 'replay_outbox.php', 'supabase/schema.sql'];
$patcherAiStatus = patcher_ai_status_payload($patcherEnv);
?>
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

  .ps-input, .ps-textarea {
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

  .ps-input:focus, .ps-textarea:focus {
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

  .ps-file-item, .ps-folder-item {
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

  .ps-file-item:hover, .ps-folder-item:hover {
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

          <div class="ps-quick-open" id="quickOpen" style="padding:8px 16px;border-bottom:1px solid rgba(100,130,180,0.1);">
            <?php foreach ($quickOpen as $file): ?>
              <button class="ps-quick-chip" type="button" data-open-file="<?= htmlspecialchars($file) ?>"><span class="material-symbols-outlined">description</span><?= htmlspecialchars(basename($file)) ?></button>
            <?php endforeach; ?>
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

        <div class="ps-split-handle" id="rightHandle"></div>

        <section class="ps-panel right" id="aiPanel">
          <div class="ps-panel-header">
            <div class="ps-panel-title">
              <div>
                <h2>AI Assistant</h2>
                <div class="ps-panel-hint">Powered by OpenRouter/Gemini</div>
              </div>
              <button class="ps-btn secondary xs" type="button" id="btnCollapseAi"><span class="material-symbols-outlined">navigate_after</span></button>
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
              <label class="ps-label">Issue Description</label>
              <textarea id="aiIssue" class="ps-textarea" placeholder="What fix or improvement do you need?"></textarea>
            </div>

            <div style="display:flex;gap:8px;margin-bottom:12px;">
              <button class="ps-btn primary" type="button" id="btnAiGenerate" style="flex:1"><span class="material-symbols-outlined">sparkles</span>Generate</button>
              <button class="ps-btn secondary" type="button" id="btnAiApply"><span class="material-symbols-outlined">edit</span></button>
              <button class="ps-btn success" type="button" id="btnAiApplyFile"><span class="material-symbols-outlined">check</span></button>
            </div>

            <div class="ps-item">
              <div class="ps-label">Analysis</div>
              <div id="aiExplanation" class="ps-info">No analysis yet</div>
            </div>

            <div class="ps-item">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <div class="ps-label">Metrics</div>
                <span id="aiRisk" style="font-size:11px;color:#4edea3;">Risk: n/a</span>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;font-size:11px;">
                <div style="text-align:center;color:#7a8ab5;">Checks<br><strong id="aiChecksCount" style="font-size:14px;color:#d1d8ff;">0</strong></div>
                <div style="text-align:center;color:#7a8ab5;">Size<br><strong id="aiPatchSize" style="font-size:14px;color:#d1d8ff;">0</strong></div>
                <div style="text-align:center;color:#7a8ab5;">File<br><strong id="aiTargetShort" style="font-size:12px;color:#d1d8ff;">-</strong></div>
              </div>
            </div>

            <div class="ps-item">
              <div class="ps-label">Patch Preview</div>
              <div id="aiPatchPreview" class="ps-info" style="max-height:120px;overflow-y:auto;">Preview here</div>
            </div>

            <div class="ps-item">
              <div class="ps-label">Diff View</div>
              <div id="aiDiffPreview" class="ps-info" style="max-height:120px;overflow-y:auto;">Diff here</div>
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
      aiIssue: document.getElementById('aiIssue'),
      aiExplanation: document.getElementById('aiExplanation'),
      aiPatchPreview: document.getElementById('aiPatchPreview'),
      aiChecks: document.getElementById('aiChecks'),
      aiChecksCount: document.getElementById('aiChecksCount'),
      aiPatchSize: document.getElementById('aiPatchSize'),
      aiTargetShort: document.getElementById('aiTargetShort'),
      aiRisk: document.getElementById('aiRisk'),
      aiDiffPreview: document.getElementById('aiDiffPreview'),
      changesPanel: document.getElementById('changesPanel'),
      panelTitle: document.getElementById('panelTitle'),
      panelHint: document.getElementById('panelHint'),
      aiPanel: document.getElementById('aiPanel'),
      leftPanel: document.getElementById('leftPanel'),
      terminalPanel: document.getElementById('terminalPanel'),
      terminalCommand: document.getElementById('terminalCommand'),
      leftHandle: document.getElementById('leftHandle'),
      rightHandle: document.getElementById('rightHandle'),
      aiStatus: document.getElementById('aiStatus')
    };

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
      }[ch]));
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
        map.get(folder).push({...file, name});
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
      const data = await api('read_file', {path});
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
      if (!state.currentPath) return window.adminAlert('No target file', 'Open a file before requesting AI help.', 'warning');
      if (!DOM.aiIssue.value.trim()) return window.adminAlert('Issue required', 'Describe the issue or requested improvement.', 'warning');
      DOM.aiExplanation.textContent = 'Generating proposal...';
      DOM.aiPatchPreview.textContent = 'Waiting for AI...';
      const data = await api('ai_generate', {
        path: state.currentPath,
        issue: DOM.aiIssue.value.trim(),
        content: DOM.editor.value,
        provider: DOM.aiProvider.value,
        model: DOM.aiModel.value.trim()
      });
      if (!data.ok) {
        DOM.aiExplanation.textContent = data.message || 'AI request failed.';
        DOM.aiPatchPreview.textContent = data.output || '';
        return window.adminAlert('AI request failed', data.message || 'The AI provider did not respond successfully.', 'error');
      }
      const r = data.result || {};
      state.suggestedContent = r.improved_code || '';
      DOM.aiExplanation.textContent = r.explanation || r.summary || 'No explanation';
      DOM.aiPatchPreview.textContent = r.patch_preview || '(No patch preview)';
      renderDiff(state.currentContent, state.suggestedContent);
      DOM.aiRisk.textContent = 'Risk: ' + (r.risk || 'n/a');
      const checks = Array.isArray(r.checks) ? r.checks : [];
      DOM.aiChecksCount.textContent = String(checks.length);
      DOM.aiPatchSize.textContent = String((r.patch_preview || '').length);
      DOM.aiChecks.innerHTML = checks.map(item => `<div style="margin-top:6px;">- ${e(item)}</div>`).join('') || '<div>No checks</div>';
      log(`AI proposal generated for ${state.currentPath}`);
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

    async function revertFile(path = state.currentPath) {
      if (!path) return window.adminAlert('No file selected', 'Open a tracked file first.', 'warning');
      const ok = confirm(`Revert ${path} back to HEAD?`);
      if (!ok) return;
      const data = await api('revert_file', {path});
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
      document.getElementById('btnCollapseAi').textContent = state.aiOpen ? '▶' : '◄';
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
      const data = await api('run_terminal', {command});
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

    document.querySelectorAll('[data-open-file]').forEach(btn => {
      btn.addEventListener('click', () => guardUnsaved(() => openFile(btn.getAttribute('data-open-file'))));
    });

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
      const data = await api('create_file', {path, content: ''});
      if (data.ok) {
        await loadFiles();
        await openFile(path);
      } else window.adminAlert('Create failed', data.message || 'Unable to create file.', 'error');
    });

    document.getElementById('btnNewFolder').addEventListener('click', async () => {
      const path = prompt('New folder path:');
      if (!path) return;
      const data = await api('create_folder', {path});
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
      const data = await api('git_pull', {branch: 'main'});
      log(data.output || data.message || 'Pull completed');
      await loadRecentChanges();
    });

    document.getElementById('btnToggleTerminal').addEventListener('click', toggleTerminal);
    document.getElementById('btnRunTerminal').addEventListener('click', runTerminalCommand);

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
      const ok = confirm('Apply AI patch to file? This will overwrite it.');
      if (!ok) return;
      const data = await api('apply_ai_patch', {
        path: state.currentPath,
        content: state.suggestedContent
      });
      if (!data.ok) return window.adminAlert('Apply failed', data.message || 'Unable to apply patch.', 'error');
      state.currentContent = state.suggestedContent;
      DOM.editor.value = state.currentContent;
      setDirty(false);
      updateLineNumbers();
      renderDiff(state.currentContent, state.currentContent);
      log(data.message || 'AI patch applied');
      window.adminAlert('Applied', 'AI patch applied to file.', 'success');
      await loadRecentChanges();
    });

    document.getElementById('btnBackDashboard').addEventListener('click', goBackToDashboard);

    DOM.aiProvider.addEventListener('change', () => {
      if (DOM.aiProvider.value === 'gemini' && !DOM.aiModel.value.trim()) DOM.aiModel.value = 'gemini-2.0-flash';
      if (DOM.aiProvider.value === 'openrouter' && !DOM.aiModel.value.trim()) DOM.aiModel.value = 'openrouter/free';
    });

    window.addEventListener('beforeunload', event => {
      if (!state.dirty) return;
      event.preventDefault();
      event.returnValue = '';
    });

    // Initialize
    loadFiles();
    loadRecentChanges();
    updateAiStatus();
    updateLineNumbers();
  })();
</script>
