<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_logged_in'])) {
  header('Location: login.php');
  exit;
}

if (($_SESSION['admin_role'] ?? 'admin') !== 'superadmin') {
  echo '<div style="padding:20px;"><h2>Access denied</h2><p>Only superadmin can access Patcher.</p></div>';
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
  $p = trim((string)$input);
  $p = str_replace('\\', '/', $p);
  $p = ltrim($p, '/');
  if ($p === '' || strpos($p, '..') !== false) return '';
  return $p;
}

function patcher_extension_allowed($relPath)
{
  if (strtolower((string)$relPath) === '.env' || strtolower((string)$relPath) === '.env.local') return true;
  $ext = strtolower(pathinfo((string)$relPath, PATHINFO_EXTENSION));
  return in_array($ext, patcher_allowed_extensions(), true);
}

function patcher_absolute_path($projectRoot, $relPath)
{
  $rel = patcher_safe_rel_path($relPath);
  if ($rel === '') return '';
  return $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

function patcher_run_cmd($command, $cwd = null, $stdin = '')
{
  $descriptorspec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w']
  ];

  $process = @proc_open($command, $descriptorspec, $pipes, $cwd ?: null);
  if (!is_resource($process)) {
    return ['ok' => false, 'exit' => -1, 'out' => '', 'err' => 'Failed to start process.'];
  }

  fwrite($pipes[0], (string)$stdin);
  fclose($pipes[0]);
  $out = stream_get_contents($pipes[1]);
  $err = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exit = proc_close($process);

  return ['ok' => $exit === 0, 'exit' => $exit, 'out' => (string)$out, 'err' => (string)$err];
}

function patcher_list_files($projectRoot, $maxFiles = 1200)
{
  $result = [];
  $skipDirs = ['.git', 'vendor', 'node_modules'];
  $allowed = patcher_allowed_extensions();
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );

  foreach ($it as $fileInfo) {
    $full = $fileInfo->getPathname();
    $rel = str_replace('\\', '/', ltrim(substr($full, strlen($projectRoot)), '\\/'));

    foreach ($skipDirs as $sd) {
      if (preg_match('#(^|/)' . preg_quote($sd, '#') . '(/|$)#', $rel)) continue 2;
    }

    if ($fileInfo->isDir()) continue;
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true) && !in_array(strtolower($rel), ['.env', '.env.local'], true)) continue;

    $result[] = [
      'path' => $rel,
      'size' => $fileInfo->getSize(),
      'mtime' => $fileInfo->getMTime(),
    ];
    if (count($result) >= $maxFiles) break;
  }

  usort($result, function ($a, $b) {
    return strcmp($a['path'], $b['path']);
  });
  return $result;
}

function patcher_ai_enabled($env)
{
  $flag = strtolower((string)($env['PATCHER_AI_ENABLED'] ?? 'true'));
  return in_array($flag, ['1', 'true', 'yes', 'on'], true);
}

function patcher_ai_command($env, $promptFile)
{
  $template = trim((string)($env['PATCHER_QWEN_RUN'] ?? ''));
  if ($template !== '') {
    return str_replace('{{PROMPT_FILE}}', escapeshellarg($promptFile), $template);
  }

  $bin = trim((string)($env['PATCHER_QWEN_COMMAND'] ?? 'qwen'));
  $model = trim((string)($env['PATCHER_QWEN_MODEL'] ?? ''));
  $modelPart = $model !== '' ? (' --model ' . escapeshellarg($model)) : '';
  return $bin . $modelPart . ' -p ' . escapeshellarg((string)file_get_contents($promptFile));
}

function patcher_extract_json_block($text)
{
  $text = trim((string)$text);
  $decoded = json_decode($text, true);
  if (is_array($decoded)) return $decoded;

  if (preg_match('/```json\s*(\{.*\})\s*```/is', $text, $m)) {
    $decoded = json_decode($m[1], true);
    if (is_array($decoded)) return $decoded;
  }

  $start = strpos($text, '{');
  $end = strrpos($text, '}');
  if ($start !== false && $end !== false && $end > $start) {
    $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
    if (is_array($decoded)) return $decoded;
  }

  return null;
}

function patcher_ai_prompt($path, $issue, $content)
{
  $projectGuidance = "Project guidance:\n"
    . "- This is a PHP attendance system with file-first persistence and optional Supabase dual-write.\n"
    . "- Prefer minimal, production-safe edits.\n"
    . "- Preserve storage helper usage, runtime storage paths, CSRF protections, and SweetAlert-based UX where relevant.\n"
    . "- Avoid introducing destructive behavior or broad architectural rewrites.\n"
    . "- Keep code compatible with Azure Web App Linux and localhost XAMPP development.\n";

  return "You are Qwen Code acting as an in-app patch assistant for a PHP repository.\n"
    . "Return strict JSON only.\n"
    . "Schema:\n"
    . "{\n"
    . "  \"summary\": \"short title\",\n"
    . "  \"explanation\": \"why this matters and what to change\",\n"
    . "  \"risk\": \"low|medium|high\",\n"
    . "  \"checks\": [\"step 1\", \"step 2\"],\n"
    . "  \"patch_preview\": \"diff-style preview or code snippet\",\n"
    . "  \"improved_code\": \"full improved file content\"\n"
    . "}\n\n"
    . $projectGuidance . "\n"
    . "Target file: {$path}\n"
    . "Issue description: {$issue}\n\n"
    . "Current file content:\n"
    . "```text\n{$content}\n```";
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
    $enabled = patcher_ai_enabled($patcherEnv);
    if (!$enabled) {
      echo json_encode(['ok' => true, 'enabled' => false, 'online' => false, 'message' => 'AI assistant disabled by config.']);
      exit;
    }
    $bin = trim((string)($patcherEnv['PATCHER_QWEN_COMMAND'] ?? 'qwen'));
    $res = patcher_run_cmd($bin . ' --version', $projectRoot);
    echo json_encode([
      'ok' => true,
      'enabled' => true,
      'online' => $res['ok'],
      'message' => $res['ok'] ? trim($res['out']) : 'Qwen CLI not reachable. Set PATCHER_QWEN_RUN or PATCHER_QWEN_COMMAND in .env.local.',
    ]);
    exit;
  }

  if ($action === 'read_file') {
    $rel = patcher_safe_rel_path($_POST['path'] ?? '');
    if ($rel === '' || !patcher_extension_allowed($rel)) {
      echo json_encode(['ok' => false, 'message' => 'Invalid or disallowed file path.']);
      exit;
    }
    $target = patcher_absolute_path($projectRoot, $rel);
    if (!file_exists($target) || !is_file($target)) {
      echo json_encode(['ok' => false, 'message' => 'File not found.']);
      exit;
    }
    if (filesize($target) > 1024 * 1024) {
      echo json_encode(['ok' => false, 'message' => 'File too large to open here (max 1MB).']);
      exit;
    }
    echo json_encode(['ok' => true, 'path' => $rel, 'content' => (string)file_get_contents($target)]);
    exit;
  }

  if ($action === 'save_file') {
    $rel = patcher_safe_rel_path($_POST['path'] ?? '');
    $content = (string)($_POST['content'] ?? '');
    if ($rel === '' || !patcher_extension_allowed($rel)) {
      echo json_encode(['ok' => false, 'message' => 'Invalid or disallowed file path.']);
      exit;
    }
    $target = patcher_absolute_path($projectRoot, $rel);
    if (!file_exists($target) || !is_file($target)) {
      echo json_encode(['ok' => false, 'message' => 'File not found.']);
      exit;
    }
    if (@file_put_contents($target, $content, LOCK_EX) === false) {
      echo json_encode(['ok' => false, 'message' => 'Failed to save file.']);
      exit;
    }
    echo json_encode(['ok' => true, 'message' => 'Saved: ' . $rel]);
    exit;
  }

  if ($action === 'apply_ai_patch') {
    $rel = patcher_safe_rel_path($_POST['path'] ?? '');
    $content = (string)($_POST['content'] ?? '');
    if ($rel === '' || !patcher_extension_allowed($rel)) {
      echo json_encode(['ok' => false, 'message' => 'Invalid or disallowed file path.']);
      exit;
    }
    if ($content === '') {
      echo json_encode(['ok' => false, 'message' => 'No AI-generated content provided.']);
      exit;
    }
    $target = patcher_absolute_path($projectRoot, $rel);
    if (!file_exists($target) || !is_file($target)) {
      echo json_encode(['ok' => false, 'message' => 'File not found.']);
      exit;
    }
    if (@file_put_contents($target, $content, LOCK_EX) === false) {
      echo json_encode(['ok' => false, 'message' => 'Failed to apply AI patch.']);
      exit;
    }
    echo json_encode(['ok' => true, 'message' => 'AI patch applied to ' . $rel]);
    exit;
  }

  if ($action === 'create_file') {
    $rel = patcher_safe_rel_path($_POST['path'] ?? '');
    $content = (string)($_POST['content'] ?? '');
    if ($rel === '' || !patcher_extension_allowed($rel)) {
      echo json_encode(['ok' => false, 'message' => 'Invalid or disallowed file path.']);
      exit;
    }
    $target = patcher_absolute_path($projectRoot, $rel);
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
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
    if ($rel === '') {
      echo json_encode(['ok' => false, 'message' => 'Invalid folder path.']);
      exit;
    }
    $target = patcher_absolute_path($projectRoot, $rel);
    if (!is_dir($target) && !@mkdir($target, 0755, true)) {
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

  if ($action === 'git_pull') {
    $branch = trim($_POST['branch'] ?? 'main');
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
      if (!file_exists($target) || !is_file($target)) {
        echo json_encode(['ok' => false, 'message' => 'Target file not found.']);
        exit;
      }
      $content = (string)file_get_contents($target);
    }

    $prompt = patcher_ai_prompt($rel, $issue, $content);
    $promptFile = tempnam(sys_get_temp_dir(), 'patcher_qwen_');
    file_put_contents($promptFile, $prompt);
    $command = patcher_ai_command($patcherEnv, $promptFile);
    $res = patcher_run_cmd($command, $projectRoot);
    @unlink($promptFile);

    if (!$res['ok']) {
      echo json_encode([
        'ok' => false,
        'message' => 'Qwen command failed. Configure PATCHER_QWEN_RUN or PATCHER_QWEN_COMMAND in .env.local.',
        'output' => trim($res['out'] . "\n" . $res['err'])
      ]);
      exit;
    }

    $decoded = patcher_extract_json_block($res['out']);
    if (!is_array($decoded)) {
      $decoded = [
        'summary' => 'AI response generated',
        'explanation' => trim($res['out']),
        'risk' => 'medium',
        'checks' => [],
        'patch_preview' => trim($res['out']),
        'improved_code' => '',
      ];
    }

    echo json_encode(['ok' => true, 'result' => $decoded, 'raw' => trim($res['out'])]);
    exit;
  }

  echo json_encode(['ok' => false, 'message' => 'Unknown API action.']);
  exit;
}

$localMode = app_local_mode_enabled(__DIR__ . '/../.env');
$quickOpen = ['.env', '.env.local', 'hybrid_dual_write.php', 'replay_outbox.php', 'supabase/schema.sql'];
?>
<style>
  body.admin-page-patcher .layout,
  body.admin-page-patcher .main-content,
  body.admin-page-patcher .content-wrapper { margin:0!important; padding:0!important; max-width:none!important; width:100%!important; }
  body.admin-page-patcher .sidebar, body.admin-page-patcher .desktop-navbar, body.admin-page-patcher .page-header, body.admin-page-patcher footer { display:none!important; }
  #patcherStudio { min-height:100vh; background:
    radial-gradient(circle at top left, rgba(166,200,255,.10), transparent 26%),
    radial-gradient(circle at top right, rgba(78,222,163,.08), transparent 22%),
    linear-gradient(180deg, #0b1326 0%, #060e20 100%);
    color:#dae2fd; font-family:Inter,sans-serif; }
  .ps-shell { display:grid; grid-template-rows:56px 54px minmax(0,1fr); min-height:100vh; }
  .ps-top, .ps-toolbar { backdrop-filter: blur(18px); background:rgba(11,19,38,.82); border-bottom:1px solid rgba(67,70,84,.18); }
  .ps-top { display:flex; align-items:center; justify-content:space-between; padding:0 20px; }
  .ps-brand { display:flex; align-items:center; gap:14px; }
  .ps-brand h1 { margin:0; font:800 1.3rem Manrope,sans-serif; letter-spacing:-.03em; }
  .ps-brand p { margin:0; color:#c3c6d6; font-size:.82rem; }
  .ps-nav { display:flex; gap:18px; font-size:.85rem; }
  .ps-nav a { color:#8d90a0; text-decoration:none; padding:18px 0 14px; border-bottom:2px solid transparent; }
  .ps-nav a.active { color:#a6c8ff; border-color:#a6c8ff; font-weight:700; }
  .ps-badge { display:inline-flex; align-items:center; gap:8px; padding:6px 12px; border-radius:999px; background:rgba(78,222,163,.12); color:#4edea3; font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.12em; }
  .ps-badge-dot { width:8px; height:8px; border-radius:50%; background:#4edea3; box-shadow:0 0 14px rgba(78,222,163,.7); }
  .ps-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:0 18px; overflow:auto; }
  .ps-quick, .ps-actions { display:flex; gap:10px; align-items:center; }
  .ps-chip, .ps-btn { border:0; border-radius:12px; color:#dae2fd; font-weight:700; cursor:pointer; }
  .ps-chip { background:#171f33; padding:10px 14px; font-size:.76rem; white-space:nowrap; }
  .ps-btn { padding:10px 16px; font-size:.78rem; }
  .ps-btn.primary { background:linear-gradient(135deg,#a6c8ff,#6ea8ff); color:#00315f; }
  .ps-btn.success { background:linear-gradient(135deg,#4edea3,#2bbf80); color:#052e22; }
  .ps-btn.ghost { background:#171f33; }
  .ps-workspace { display:grid; grid-template-columns:56px 280px minmax(0,1fr) 360px; min-height:0; }
  .ps-rail { background:#131b2e; display:flex; flex-direction:column; align-items:center; gap:18px; padding:18px 0; }
  .ps-rail button { background:none; border:0; color:#8d90a0; cursor:pointer; }
  .ps-rail button.active { color:#a6c8ff; }
  .ps-panel { background:#131b2e; padding:16px; min-height:0; overflow:hidden; }
  .ps-panel h3, .ps-side h3 { margin:0 0 12px; font:800 .74rem Inter,sans-serif; color:#8d90a0; letter-spacing:.18em; text-transform:uppercase; }
  .ps-search, .ps-input, .ps-textarea { width:100%; background:#060e20; border:0; color:#dae2fd; border-radius:12px; padding:12px 14px; }
  .ps-filelist, .ps-console { overflow:auto; }
  .ps-filelist { margin-top:12px; max-height:calc(100vh - 240px); }
  .ps-file { padding:10px 12px; border-radius:12px; color:#c3c6d6; cursor:pointer; display:flex; justify-content:space-between; gap:8px; }
  .ps-file.active { background:#222a3d; color:#a6c8ff; }
  .ps-editor-wrap { display:grid; grid-template-rows:42px minmax(0,1fr) 170px; min-height:0; background:#060e20; }
  .ps-tabs { display:flex; align-items:center; justify-content:space-between; background:#131b2e; padding:0 14px; }
  .ps-tab { color:#a6c8ff; font-weight:700; font-size:.84rem; }
  .ps-editor { width:100%; height:100%; resize:none; border:0; padding:18px; background:#060e20; color:#dae2fd; font:500 .84rem/1.65 "JetBrains Mono", monospace; }
  .ps-console { border-top:1px solid rgba(67,70,84,.18); padding:12px 16px; background:#050a16; font:500 .76rem/1.6 "JetBrains Mono", monospace; color:#c3c6d6; }
  .ps-side { background:#131b2e; padding:16px; display:grid; grid-template-rows:auto auto auto 1fr auto; gap:14px; min-height:0; }
  .ps-card { background:#171f33; border-radius:16px; padding:14px; }
  .ps-label { color:#8d90a0; font-size:.72rem; font-weight:800; letter-spacing:.14em; text-transform:uppercase; margin-bottom:8px; display:block; }
  .ps-textarea { min-height:120px; resize:vertical; }
  .ps-output { background:#060e20; border-radius:14px; padding:14px; min-height:120px; white-space:pre-wrap; overflow:auto; font-size:.8rem; color:#c3c6d6; }
  .ps-metrics { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
  .ps-metric { background:#060e20; border-radius:12px; padding:12px; text-align:center; }
  .ps-local-tag { margin-left:12px; }
  @media (max-width: 1180px) { .ps-workspace { grid-template-columns:56px 240px minmax(0,1fr); } .ps-side { position:fixed; inset:110px 16px 16px auto; width:min(360px,calc(100vw - 32px)); box-shadow:0 12px 40px -12px rgba(6,14,32,.8); border-radius:18px; } }
  @media (max-width: 860px) { .ps-workspace { grid-template-columns:1fr; grid-template-rows:auto auto minmax(420px,1fr) auto; } .ps-rail { display:none; } .ps-panel, .ps-side { position:static; width:auto; inset:auto; border-radius:0; } }
</style>

<div id="patcherStudio">
  <div class="ps-shell">
    <header class="ps-top">
      <div class="ps-brand">
        <div>
          <h1>Patcher Studio</h1>
          <p>Manual + AI-assisted patch workflow</p>
        </div>
        <?php if ($localMode): ?>
          <span class="ps-badge ps-local-tag"><span class="ps-badge-dot"></span>Local Mode</span>
        <?php endif; ?>
      </div>
      <nav class="ps-nav">
        <a href="#" class="active">Files</a>
        <a href="#">Deploy</a>
        <a href="#">Logs</a>
        <a href="#">Terminal</a>
      </nav>
    </header>

    <div class="ps-toolbar">
      <div class="ps-quick">
        <?php foreach ($quickOpen as $file): ?>
          <button class="ps-chip" type="button" data-open-file="<?= htmlspecialchars($file) ?>">Open <?= htmlspecialchars(basename($file)) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="ps-actions">
        <button class="ps-btn ghost" type="button" id="btnRefreshFiles">Refresh</button>
        <button class="ps-btn ghost" type="button" id="btnNewFolder">New Folder</button>
        <button class="ps-btn ghost" type="button" id="btnNewFile">New File</button>
        <button class="ps-btn success" type="button" id="btnSaveFile">Save</button>
        <button class="ps-btn ghost" type="button" id="btnGitStatus">Git Status</button>
        <button class="ps-btn primary" type="button" id="btnGitPull">Git Pull</button>
      </div>
    </div>

    <div class="ps-workspace">
      <aside class="ps-rail">
        <button class="active" type="button"><span class="material-symbols-outlined">folder</span></button>
        <button type="button"><span class="material-symbols-outlined">search</span></button>
        <button type="button"><span class="material-symbols-outlined">account_tree</span></button>
        <button type="button"><span class="material-symbols-outlined">terminal</span></button>
      </aside>

      <section class="ps-panel">
        <h3>Explorer</h3>
        <input id="fileSearch" class="ps-search" type="text" placeholder="Filter files...">
        <div class="ps-filelist" id="fileList"></div>
      </section>

      <main class="ps-editor-wrap">
        <div class="ps-tabs">
          <div class="ps-tab" id="currentFileLabel">No file selected</div>
          <div style="font-size:.76rem;color:#8d90a0;">Qwen-assisted editor</div>
        </div>
        <textarea id="editor" class="ps-editor" spellcheck="false" placeholder="Open a file to start editing..."></textarea>
        <div class="ps-console" id="consoleOutput">Patcher Studio ready.</div>
      </main>

      <aside class="ps-side">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
          <h3 style="margin:0;color:#dae2fd;">AI Patch Assistant</h3>
          <span class="ps-badge" id="aiStatusBadge"><span class="ps-badge-dot"></span>Checking</span>
        </div>
        <div class="ps-card">
          <label class="ps-label">Target File</label>
          <input id="aiTargetFile" class="ps-input" type="text" readonly>
        </div>
        <div class="ps-card">
          <label class="ps-label">Issue Description</label>
          <textarea id="aiIssue" class="ps-textarea" placeholder="Describe the fix or improvement you want Qwen Code to propose..."></textarea>
          <div style="display:flex;gap:10px;margin-top:12px;">
            <button class="ps-btn primary" type="button" id="btnAiGenerate" style="flex:1;">Generate Patch Proposal</button>
            <button class="ps-btn ghost" type="button" id="btnAiApply">Apply to Editor</button>
            <button class="ps-btn success" type="button" id="btnAiApplyFile">Apply to File</button>
          </div>
        </div>
        <div class="ps-card">
          <label class="ps-label">Explanation</label>
          <div id="aiExplanation" class="ps-output">No AI analysis yet.</div>
        </div>
        <div class="ps-card" style="display:grid;grid-template-rows:auto auto 1fr auto;gap:12px;min-height:0;">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <label class="ps-label" style="margin:0;">Patch Preview</label>
            <span id="aiRisk" style="color:#4edea3;font-size:.72rem;font-weight:800;">Risk: n/a</span>
          </div>
          <div class="ps-metrics">
            <div class="ps-metric"><div style="font-size:.68rem;color:#8d90a0;">Checks</div><div id="aiChecksCount" style="font-weight:800;font-size:1rem;">0</div></div>
            <div class="ps-metric"><div style="font-size:.68rem;color:#8d90a0;">Patch Size</div><div id="aiPatchSize" style="font-weight:800;font-size:1rem;">0</div></div>
            <div class="ps-metric"><div style="font-size:.68rem;color:#8d90a0;">Target</div><div id="aiTargetShort" style="font-weight:800;font-size:.78rem;">n/a</div></div>
          </div>
          <div id="aiPatchPreview" class="ps-output">Patch preview will appear here.</div>
          <div>
            <label class="ps-label" style="margin-bottom:8px;">Diff View</label>
            <div id="aiDiffPreview" class="ps-output">Diff will appear here after AI generates a proposal.</div>
          </div>
          <div id="aiChecks" style="font-size:.78rem;color:#c3c6d6;"></div>
        </div>
      </aside>
    </div>
  </div>
</div>

<script>
(() => {
  const csrf = <?= json_encode($csrf) ?>;
  const state = { files: [], currentPath: '', currentContent: '', suggestedContent: '' };
  const fileList = document.getElementById('fileList');
  const editor = document.getElementById('editor');
  const fileSearch = document.getElementById('fileSearch');
  const currentFileLabel = document.getElementById('currentFileLabel');
  const consoleOutput = document.getElementById('consoleOutput');
  const aiTargetFile = document.getElementById('aiTargetFile');
  const aiIssue = document.getElementById('aiIssue');
  const aiExplanation = document.getElementById('aiExplanation');
  const aiPatchPreview = document.getElementById('aiPatchPreview');
  const aiChecks = document.getElementById('aiChecks');
  const aiChecksCount = document.getElementById('aiChecksCount');
  const aiPatchSize = document.getElementById('aiPatchSize');
  const aiTargetShort = document.getElementById('aiTargetShort');
  const aiRisk = document.getElementById('aiRisk');
  const aiStatusBadge = document.getElementById('aiStatusBadge');
  const aiDiffPreview = document.getElementById('aiDiffPreview');

  function log(msg) {
    const ts = new Date().toLocaleTimeString();
    consoleOutput.textContent = `[${ts}] ${msg}\n` + consoleOutput.textContent;
  }

  function escapeHtml(text) {
    return String(text).replace(/[&<>"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]));
  }

  function renderDiff(oldText, newText) {
    if (!newText) {
      aiDiffPreview.textContent = 'Diff will appear here after AI generates a proposal.';
      return;
    }
    const oldLines = String(oldText || '').split('\n');
    const newLines = String(newText || '').split('\n');
    const max = Math.max(oldLines.length, newLines.length);
    const rows = [];
    for (let i = 0; i < max; i++) {
      const a = oldLines[i];
      const b = newLines[i];
      if (a === b) continue;
      if (typeof a !== 'undefined') rows.push(`<div style="background:rgba(147,0,10,.18);padding:4px 8px;border-radius:8px;margin-bottom:4px;color:#ffb4ab;">- ${escapeHtml(a)}</div>`);
      if (typeof b !== 'undefined') rows.push(`<div style="background:rgba(0,104,70,.22);padding:4px 8px;border-radius:8px;margin-bottom:4px;color:#4edea3;">+ ${escapeHtml(b)}</div>`);
    }
    aiDiffPreview.innerHTML = rows.length ? rows.join('') : '<div>No diff detected.</div>';
  }

  async function api(action, payload = {}, method = 'POST') {
    const opts = { method, headers: {} };
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

  function renderFiles() {
    const q = fileSearch.value.trim().toLowerCase();
    fileList.innerHTML = '';
    state.files.filter(f => !q || f.path.toLowerCase().includes(q)).forEach(file => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ps-file' + (file.path === state.currentPath ? ' active' : '');
      btn.innerHTML = `<span>${file.path}</span><span style="color:#8d90a0;font-size:.72rem;">${Math.round((file.size || 0)/1024)} KB</span>`;
      btn.addEventListener('click', () => openFile(file.path));
      fileList.appendChild(btn);
    });
  }

  async function loadFiles() {
    const data = await api('list_files', {}, 'GET');
    if (!data.ok) return log(data.message || 'Failed to load files');
    state.files = data.files || [];
    renderFiles();
    log(`Loaded ${state.files.length} files`);
  }

  async function openFile(path) {
    const data = await api('read_file', { path });
    if (!data.ok) return window.adminAlert('Open failed', data.message || 'Unable to open file', 'error');
    state.currentPath = data.path;
    state.currentContent = data.content || '';
    state.suggestedContent = '';
    editor.value = state.currentContent;
    currentFileLabel.textContent = data.path;
    aiTargetFile.value = data.path;
    aiTargetShort.textContent = data.path.split('/').pop();
    aiDiffPreview.textContent = 'Diff will appear here after AI generates a proposal.';
    renderFiles();
    log(`Opened ${data.path}`);
  }

  async function saveCurrent() {
    if (!state.currentPath) return window.adminAlert('No file selected', 'Open a file first.', 'warning');
    const data = await api('save_file', { path: state.currentPath, content: editor.value });
    if (!data.ok) return window.adminAlert('Save failed', data.message || 'Unable to save file', 'error');
    state.currentContent = editor.value;
    log(data.message || 'Saved');
    window.adminAlert('Saved', data.message || 'File saved.', 'success');
  }

  async function aiStatus() {
    const data = await api('ai_status', {}, 'GET');
    const online = !!(data && data.online);
    aiStatusBadge.innerHTML = `<span class="ps-badge-dot"></span>${online ? 'Online' : 'Offline'}`;
    aiStatusBadge.style.background = online ? 'rgba(78,222,163,.12)' : 'rgba(255,180,171,.14)';
    aiStatusBadge.style.color = online ? '#4edea3' : '#ffb4ab';
    if (data && data.message) log(data.message);
  }

  async function generateAiProposal() {
    if (!state.currentPath) return window.adminAlert('No target file', 'Open a file before requesting AI help.', 'warning');
    if (!aiIssue.value.trim()) return window.adminAlert('Issue required', 'Describe the issue or requested improvement.', 'warning');
    aiExplanation.textContent = 'Generating proposal...';
    aiPatchPreview.textContent = 'Waiting for Qwen Code response...';
    const data = await api('ai_generate', { path: state.currentPath, issue: aiIssue.value.trim(), content: editor.value });
    if (!data.ok) {
      aiExplanation.textContent = data.message || 'AI request failed.';
      aiPatchPreview.textContent = data.output || '';
      return window.adminAlert('AI request failed', data.message || 'Qwen did not respond successfully.', 'error');
    }
    const result = data.result || {};
    state.suggestedContent = result.improved_code || '';
    aiExplanation.textContent = result.explanation || result.summary || 'No explanation returned.';
    aiPatchPreview.textContent = result.patch_preview || '(No patch preview returned)';
    renderDiff(state.currentContent, state.suggestedContent);
    aiRisk.textContent = 'Risk: ' + (result.risk || 'n/a');
    const checks = Array.isArray(result.checks) ? result.checks : [];
    aiChecksCount.textContent = String(checks.length);
    aiPatchSize.textContent = String((result.patch_preview || '').length);
    aiChecks.innerHTML = checks.map(item => `<div style="margin-top:6px;">- ${item}</div>`).join('') || '<div>No checks returned.</div>';
    log(`AI proposal generated for ${state.currentPath}`);
  }

  document.getElementById('btnAiApply').addEventListener('click', () => {
    if (!state.suggestedContent) return window.adminAlert('No AI code yet', 'Generate a patch proposal first.', 'warning');
    editor.value = state.suggestedContent;
    renderDiff(state.currentContent, state.suggestedContent);
    log('Applied AI proposal to editor buffer');
  });

  document.getElementById('btnAiApplyFile').addEventListener('click', async () => {
    if (!state.currentPath) return window.adminAlert('No target file', 'Open a file first.', 'warning');
    if (!state.suggestedContent) return window.adminAlert('No AI code yet', 'Generate a patch proposal first.', 'warning');
    const ok = await window.adminConfirm('Apply AI patch to file', 'This will overwrite the file on disk with the AI-generated content.');
    if (!ok) return;
    const data = await api('apply_ai_patch', { path: state.currentPath, content: state.suggestedContent });
    if (!data.ok) return window.adminAlert('Apply failed', data.message || 'Unable to apply AI patch.', 'error');
    state.currentContent = state.suggestedContent;
    editor.value = state.currentContent;
    renderDiff(state.currentContent, state.currentContent);
    log(data.message || 'AI patch applied');
    window.adminAlert('Applied', data.message || 'AI patch applied to file.', 'success');
  });

  document.getElementById('btnAiGenerate').addEventListener('click', generateAiProposal);
  document.getElementById('btnSaveFile').addEventListener('click', saveCurrent);
  document.getElementById('btnRefreshFiles').addEventListener('click', loadFiles);
  document.getElementById('btnGitStatus').addEventListener('click', async () => {
    const data = await api('git_status', {});
    log(data.output || data.message || 'No git output');
  });
  document.getElementById('btnGitPull').addEventListener('click', async () => {
    const data = await api('git_pull', { branch: 'main' });
    log(data.output || data.message || 'No git output');
  });
  document.getElementById('btnNewFile').addEventListener('click', async () => {
    const path = prompt('New file path');
    if (!path) return;
    const data = await api('create_file', { path, content: '' });
    if (data.ok) { await loadFiles(); await openFile(path); }
    else window.adminAlert('Create failed', data.message || 'Unable to create file.', 'error');
  });
  document.getElementById('btnNewFolder').addEventListener('click', async () => {
    const path = prompt('New folder path');
    if (!path) return;
    const data = await api('create_folder', { path });
    if (data.ok) { loadFiles(); log(data.message || 'Folder created'); }
    else window.adminAlert('Create failed', data.message || 'Unable to create folder.', 'error');
  });
  fileSearch.addEventListener('input', renderFiles);
  document.querySelectorAll('[data-open-file]').forEach(btn => btn.addEventListener('click', () => openFile(btn.getAttribute('data-open-file'))));

  loadFiles();
  aiStatus();
})();
</script>
