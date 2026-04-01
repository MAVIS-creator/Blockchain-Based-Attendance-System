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
$csrf = csrf_token();

$projectRoot = realpath(__DIR__ . '/..');

function patcher_allowed_extensions()
{
  return ['php', 'md', 'json', 'txt', 'js', 'css', 'html', 'env', 'sql', 'yml', 'yaml'];
}

function patcher_safe_rel_path($input)
{
  $p = trim((string)$input);
  $p = str_replace('\\', '/', $p);
  $p = ltrim($p, '/');
  if ($p === '') return '';
  if (strpos($p, '..') !== false) return '';
  return $p;
}

function patcher_extension_allowed($relPath)
{
  if (strtolower((string)$relPath) === '.env') return true;
  $ext = strtolower(pathinfo((string)$relPath, PATHINFO_EXTENSION));
  return in_array($ext, patcher_allowed_extensions(), true);
}

function patcher_absolute_path($projectRoot, $relPath)
{
  $rel = patcher_safe_rel_path($relPath);
  if ($rel === '') return '';
  return $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

function patcher_run_cmd($command, $cwd = null)
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
      if (preg_match('#(^|/)' . preg_quote($sd, '#') . '(/|$)#', $rel)) {
        continue 2;
      }
    }

    if ($fileInfo->isDir()) {
      continue;
    }

    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
      continue;
    }

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

if (isset($_GET['api'])) {
  header('Content-Type: application/json');
  $action = (string)$_GET['api'];

  if ($action !== 'list_files' && !csrf_check_request()) {
    echo json_encode(['ok' => false, 'message' => 'Invalid CSRF token.']);
    exit;
  }

  if ($action === 'list_files') {
    echo json_encode(['ok' => true, 'files' => patcher_list_files($projectRoot)]);
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
    echo json_encode([
      'ok' => $res['ok'],
      'message' => $res['ok'] ? 'Git status completed.' : ('git status failed (exit ' . $res['exit'] . ').'),
      'output' => trim($res['out'] . "\n" . $res['err'])
    ]);
    exit;
  }

  if ($action === 'git_pull') {
    $branch = trim($_POST['branch'] ?? 'main');
    if ($branch === '') $branch = 'main';
    $cmd = 'git pull --ff-only origin ' . escapeshellarg($branch);
    $res = patcher_run_cmd($cmd, $projectRoot);
    echo json_encode([
      'ok' => $res['ok'],
      'message' => $res['ok'] ? ('Git pull successful on ' . $branch . '.') : ('git pull failed (exit ' . $res['exit'] . '). Configure credentials in terminal/credential manager.'),
      'output' => trim($res['out'] . "\n" . $res['err'])
    ]);
    exit;
  }

  echo json_encode(['ok' => false, 'message' => 'Unknown API action.']);
  exit;
}
?>

<style>
  html,
  body.admin-page-patcher {
    height: 100% !important;
  }

  body.admin-page-patcher {
    margin: 0 !important;
    overflow: auto !important;
    background: #020617 !important;
  }

  body.admin-page-patcher .layout {
    display: block !important;
    height: 100vh !important;
    min-height: 100vh !important;
    overflow: auto !important;
  }

  body.admin-page-patcher .navbar,
  body.admin-page-patcher .sidebar,
  body.admin-page-patcher .main-content>.header,
  body.admin-page-patcher .main-content>footer,
  body.admin-page-patcher .main-content>.footer {
    display: none !important;
  }

  body.admin-page-patcher .main-content,
  body.admin-page-patcher .content-wrapper {
    margin: 0 !important;
    padding: 0 !important;
    max-width: none !important;
    width: 100% !important;
    min-height: 100vh !important;
    height: auto !important;
    overflow-x: auto !important;
    overflow-y: auto !important;
  }

  #patcherRoot {
    position: relative;
    border-radius: 0 !important;
    border: 0 !important;
    padding: 10px !important;
    background: #020617;
    min-height: 100vh !important;
    height: auto !important;
    width: 100% !important;
    max-width: 100% !important;
    display: flex;
    flex-direction: column;
    box-sizing: border-box;
    overflow-x: auto;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
  }

  #patcherRoot,
  #patcherRoot * {
    box-sizing: border-box;
  }

  #cmdOutputWrap.collapsed {
    display: none;
  }

  #cmdOutputWrap {
    height: 140px;
    min-height: 90px;
    max-height: 50vh;
    resize: vertical;
    overflow: auto;
    border-top: 1px solid #1f2937;
    -webkit-overflow-scrolling: touch;
  }

  #fileList,
  #cmdOutput {
    overflow: auto;
    -webkit-overflow-scrolling: touch;
    touch-action: pan-y pan-x;
  }

  #editor {
    min-width: 0;
    touch-action: pan-y pan-x;
  }

  @media (max-width: 1100px) {
    #patcherWorkspace {
      grid-template-columns: minmax(220px, 34%) minmax(0, 1fr) !important;
    }
  }

  @media (max-width: 860px) {
    #patcherWorkspace {
      grid-template-columns: 1fr !important;
      grid-template-rows: minmax(220px, 32vh) minmax(420px, 1fr);
    }
  }
</style>

<div id="patcherRoot" class="bg-surface-container-lowest rounded-xl border border-outline-variant/20 p-4" style="min-height:100vh;">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
    <div>
      <h2 style="margin:0;font-size:1.15rem;font-weight:800;">Patcher Studio</h2>
      <p style="margin:2px 0 0;color:var(--on-surface-variant);font-size:0.8rem;">VSCode-style editor for quick in-app changes. Git credentials should be configured in terminal/credential manager.</p>
      <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;">
        <button type="button" class="st-btn st-btn-sm" data-hybrid-open=".env" style="background:#1d4ed8;color:#fff;">Open .env</button>
        <button type="button" class="st-btn st-btn-sm" data-hybrid-open="hybrid_dual_write.php" style="background:#1d4ed8;color:#fff;">Open hybrid_dual_write.php</button>
        <button type="button" class="st-btn st-btn-sm" data-hybrid-open="replay_outbox.php" style="background:#1d4ed8;color:#fff;">Open replay_outbox.php</button>
        <button type="button" class="st-btn st-btn-sm" data-hybrid-open="supabase/schema.sql" style="background:#1d4ed8;color:#fff;">Open supabase/schema.sql</button>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <button type="button" class="st-btn st-btn-sm" id="btnBackDashboard" style="background:#475569;color:#fff;">Back to Dashboard</button>
      <button type="button" class="st-btn st-btn-sm st-btn-secondary" id="btnRefreshFiles">Refresh Files</button>
      <button type="button" class="st-btn st-btn-sm st-btn-secondary" id="btnNewFolder">New Folder</button>
      <button type="button" class="st-btn st-btn-sm st-btn-primary" id="btnNewFile">New File</button>
      <button type="button" class="st-btn st-btn-sm st-btn-success" id="btnSaveFile">Save</button>
      <button type="button" class="st-btn st-btn-sm" id="btnGitStatus" style="background:#1f2937;color:#fff;">Git Status</button>
      <button type="button" class="st-btn st-btn-sm" id="btnGitPull" style="background:#0f766e;color:#fff;">Git Pull</button>
    </div>
  </div>

  <div id="patcherWorkspace" style="display:grid;grid-template-columns:minmax(240px,300px) minmax(0,1fr);gap:12px;flex:1;min-height:70vh;">
    <aside style="border:1px solid var(--outline-variant);border-radius:10px;overflow:hidden;background:#0f172a;color:#e2e8f0;display:flex;flex-direction:column;min-height:0;">
      <div style="padding:10px 12px;border-bottom:1px solid #334155;font-weight:700;font-size:0.8rem;letter-spacing:0.04em;">EXPLORER</div>
      <div style="padding:8px;border-bottom:1px solid #334155;">
        <input id="fileSearch" type="text" placeholder="Filter files..." style="width:100%;border:1px solid #334155;background:#1e293b;color:#fff;border-radius:6px;padding:7px 9px;font-size:0.82rem;">
      </div>
      <div id="fileList" style="overflow:auto;flex:1;padding:6px;"></div>
    </aside>

    <main style="display:flex;flex-direction:column;min-height:0;border:1px solid var(--outline-variant);border-radius:10px;overflow:hidden;">
      <div style="display:flex;align-items:center;justify-content:space-between;background:#111827;color:#e5e7eb;padding:8px 12px;border-bottom:1px solid #374151;">
        <div id="currentFileLabel" style="font-size:0.85rem;font-weight:600;">No file selected</div>
        <div style="font-size:0.75rem;color:#93c5fd;">Allowed: .<?= htmlspecialchars(implode(', .', patcher_allowed_extensions())) ?></div>
      </div>
      <div id="editor" style="flex:1;min-height:0;"></div>
      <div id="editorStatusBar" style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:6px 10px;background:#0f172a;color:#cbd5e1;border-top:1px solid #1f2937;font-size:0.73rem;">
        <div style="display:flex;gap:12px;align-items:center;min-width:0;">
          <span id="sbFile" style="color:#93c5fd;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:48ch;">No file</span>
          <span id="sbDirty" style="color:#86efac;">Saved</span>
          <span id="sbLang">Plain Text</span>
        </div>
        <div style="display:flex;gap:12px;align-items:center;">
          <span id="sbCursor">Ln 1, Col 1</span>
          <span id="sbEncoding">UTF-8</span>
        </div>
      </div>
      <div style="border-top:1px solid var(--outline-variant);background:#0b1220;color:#d1d5db;padding:8px 10px;display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <span id="statusText" style="font-size:0.78rem;">Ready.</span>
        <div style="display:flex;gap:8px;align-items:center;">
          <button type="button" id="btnToggleOutput" style="background:#334155;color:#fff;border:1px solid #475569;border-radius:6px;padding:4px 8px;font-size:0.75rem;cursor:pointer;">Terminal ▾</button>
          <button type="button" id="btnClearOutput" style="background:#1f2937;color:#fff;border:1px solid #374151;border-radius:6px;padding:4px 8px;font-size:0.75rem;cursor:pointer;">Clear Output</button>
        </div>
      </div>
      <div id="cmdOutputWrap">
        <pre id="cmdOutput" style="margin:0;height:100%;max-height:none;min-height:80px;overflow:auto;background:#020617;color:#e2e8f0;padding:10px;font-size:0.75rem;">No command run yet.</pre>
      </div>
    </main>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.52.2/min/vs/loader.min.js"></script>
<script>
  (function() {
    var csrfToken = <?= json_encode($csrf) ?>;
    var editor = null;
    var currentFile = '';
    var allFiles = [];
    var expandedDirs = {};
    var lastSavedContent = '';
    var isDirty = false;
    var outputCollapsed = false;
    var apiBaseUrl = null;

    function getApiBaseUrl() {
      if (apiBaseUrl) return apiBaseUrl;
      var path = window.location.pathname || '';
      var dir = path.replace(/\/[^\/]*$/, '/');
      apiBaseUrl = dir + 'patcher.php';
      return apiBaseUrl;
    }

    function labelForLang(path) {
      var p = (path || '').toLowerCase();
      if (p === '.env' || p.endsWith('.env')) return 'ENV';
      if (p.endsWith('.sql')) return 'SQL';
      if (p.endsWith('.php')) return 'PHP';
      if (p.endsWith('.json')) return 'JSON';
      if (p.endsWith('.js')) return 'JavaScript';
      if (p.endsWith('.css')) return 'CSS';
      if (p.endsWith('.html')) return 'HTML';
      if (p.endsWith('.md')) return 'Markdown';
      if (p.endsWith('.txt')) return 'Plain Text';
      return 'Plain Text';
    }

    function updateStatusBar() {
      var sbFile = document.getElementById('sbFile');
      var sbDirty = document.getElementById('sbDirty');
      var sbLang = document.getElementById('sbLang');
      if (sbFile) sbFile.textContent = currentFile || 'No file';
      if (sbDirty) {
        sbDirty.textContent = isDirty ? 'Unsaved changes' : 'Saved';
        sbDirty.style.color = isDirty ? '#fca5a5' : '#86efac';
      }
      if (sbLang) sbLang.textContent = labelForLang(currentFile);
    }

    function updateCursorStatus() {
      var sbCursor = document.getElementById('sbCursor');
      if (!sbCursor || !editor) return;
      var pos = editor.getPosition();
      var line = (pos && pos.lineNumber) ? pos.lineNumber : 1;
      var col = (pos && pos.column) ? pos.column : 1;
      sbCursor.textContent = 'Ln ' + line + ', Col ' + col;
    }

    function setStatus(msg, isErr) {
      var el = document.getElementById('statusText');
      if (!el) return;
      el.textContent = msg || '';
      el.style.color = isErr ? '#fca5a5' : '#93c5fd';
    }

    function setOutput(text) {
      var out = document.getElementById('cmdOutput');
      if (!out) return;
      out.textContent = text || '';
    }

    function setCurrentFile(path) {
      currentFile = path || '';
      var lbl = document.getElementById('currentFileLabel');
      if (lbl) lbl.textContent = (currentFile || 'No file selected') + (isDirty ? ' • unsaved' : '');
      updateStatusBar();
    }

    function updateDirtyState(nextDirty) {
      isDirty = !!nextDirty;
      setCurrentFile(currentFile);
      updateStatusBar();
    }

    function ensureCanLeave(message) {
      if (!isDirty) return true;
      return window.confirm(message || 'You have unsaved changes. Save before leaving this page?');
    }

    function updateTerminalCollapsedState() {
      var wrap = document.getElementById('cmdOutputWrap');
      var btn = document.getElementById('btnToggleOutput');
      if (!wrap || !btn) return;
      wrap.classList.toggle('collapsed', outputCollapsed);
      btn.textContent = outputCollapsed ? 'Terminal ▸' : 'Terminal ▾';
    }

    function encodeForm(data) {
      var p = new URLSearchParams();
      Object.keys(data || {}).forEach(function(k) {
        p.append(k, data[k] == null ? '' : String(data[k]));
      });
      return p.toString();
    }

    function api(action, method, data) {
      method = method || 'GET';
      var opts = {
        method: method,
        credentials: 'same-origin',
        headers: {
          'Cache-Control': 'no-store'
        }
      };
      if (method !== 'GET') {
        opts.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
        opts.headers['X-CSRF-Token'] = csrfToken;
        opts.body = encodeForm(data || {});
      }
      var url = getApiBaseUrl() + '?api=' + encodeURIComponent(action) + '&_ts=' + Date.now();
      return fetch(url, opts).then(async function(r) {
        var text = await r.text();
        try {
          return JSON.parse(text);
        } catch (e) {
          var preview = String(text || '').replace(/\s+/g, ' ').slice(0, 220);
          throw new Error('Server returned non-JSON response. Preview: ' + preview);
        }
      });
    }

    function guessLang(path) {
      var p = (path || '').toLowerCase();
      if (p.endsWith('.php')) return 'php';
      if (p.endsWith('.json')) return 'json';
      if (p.endsWith('.js')) return 'javascript';
      if (p.endsWith('.css')) return 'css';
      if (p.endsWith('.html')) return 'html';
      if (p.endsWith('.md')) return 'markdown';
      return 'plaintext';
    }

    function createTree(files) {
      var root = {
        type: 'dir',
        name: '',
        path: '',
        dirs: {},
        files: []
      };
      (files || []).forEach(function(item) {
        var fullPath = String(item.path || '');
        if (!fullPath) return;
        var parts = fullPath.split('/');
        var node = root;
        var walk = '';
        for (var i = 0; i < parts.length; i++) {
          var name = parts[i];
          if (!name) continue;
          walk = walk ? (walk + '/' + name) : name;
          var isFile = i === parts.length - 1;
          if (isFile) {
            node.files.push({
              name: name,
              path: fullPath
            });
          } else {
            if (!node.dirs[name]) {
              node.dirs[name] = {
                type: 'dir',
                name: name,
                path: walk,
                dirs: {},
                files: []
              };
              if (walk.indexOf('/') === -1 && expandedDirs[walk] === undefined) {
                expandedDirs[walk] = true;
              }
            }
            node = node.dirs[name];
          }
        }
      });
      return root;
    }

    function dirMatches(node, query) {
      if (!query) return true;
      var dirNames = Object.keys(node.dirs || {});
      for (var i = 0; i < dirNames.length; i++) {
        var d = node.dirs[dirNames[i]];
        if (d.path.toLowerCase().indexOf(query) !== -1) return true;
        if (dirMatches(d, query)) return true;
      }
      for (var j = 0; j < (node.files || []).length; j++) {
        if ((node.files[j].path || '').toLowerCase().indexOf(query) !== -1) return true;
      }
      return false;
    }

    function renderDir(node, depth, query) {
      var html = '';
      var dirNames = Object.keys(node.dirs || {}).sort();

      for (var i = 0; i < dirNames.length; i++) {
        var d = node.dirs[dirNames[i]];
        if (!dirMatches(d, query)) continue;

        var isExpanded = query ? true : (expandedDirs[d.path] !== false);
        var chevron = isExpanded ? '▾' : '▸';
        var pad = 8 + depth * 14;

        html += '<div class="tree-row dir" data-dir="' + escapeHtml(d.path) + '" style="display:flex;align-items:center;gap:6px;padding:4px 8px 4px ' + pad + 'px;color:#cbd5e1;font-size:0.8rem;cursor:pointer;border-radius:6px;">';
        html += '<span data-toggle-dir="' + escapeHtml(d.path) + '" style="width:14px;display:inline-block;color:#94a3b8;">' + chevron + '</span>';
        html += '<span style="color:#fbbf24;">📁</span>';
        html += '<span>' + escapeHtml(d.name) + '</span>';
        html += '</div>';

        if (isExpanded) {
          html += renderDir(d, depth + 1, query);
        }
      }

      var files = (node.files || []).slice().sort(function(a, b) {
        return a.name.localeCompare(b.name);
      });
      for (var k = 0; k < files.length; k++) {
        var f = files[k];
        if (query && (f.path || '').toLowerCase().indexOf(query) === -1) continue;
        var fPad = 8 + depth * 14 + 18;
        var isActive = currentFile === f.path;
        html += '<div class="tree-row file" data-open-file="' + escapeHtml(f.path) + '" style="display:flex;align-items:center;gap:6px;padding:4px 8px 4px ' + fPad + 'px;color:' + (isActive ? '#bfdbfe' : '#e2e8f0') + ';font-size:0.8rem;cursor:pointer;border-radius:6px;' + (isActive ? 'background:#1e3a8a55;' : '') + '">';
        html += '<span style="width:14px;display:inline-block;"></span>';
        html += '<span style="color:#60a5fa;">📄</span>';
        html += '<span>' + escapeHtml(f.name) + '</span>';
        html += '</div>';
      }

      return html;
    }

    function wireTreeEvents() {
      var wrap = document.getElementById('fileList');
      if (!wrap) return;

      Array.from(wrap.querySelectorAll('[data-toggle-dir]')).forEach(function(el) {
        el.addEventListener('click', function(e) {
          e.stopPropagation();
          var dir = this.getAttribute('data-toggle-dir') || '';
          expandedDirs[dir] = !(expandedDirs[dir] !== false);
          renderFiles(allFiles);
        });
      });

      Array.from(wrap.querySelectorAll('.tree-row.dir')).forEach(function(el) {
        el.addEventListener('click', function() {
          var dir = this.getAttribute('data-dir') || '';
          expandedDirs[dir] = !(expandedDirs[dir] !== false);
          renderFiles(allFiles);
        });
        el.addEventListener('mouseenter', function() {
          this.style.background = '#1e293b';
        });
        el.addEventListener('mouseleave', function() {
          this.style.background = 'transparent';
        });
      });

      Array.from(wrap.querySelectorAll('[data-open-file]')).forEach(function(el) {
        el.addEventListener('click', function() {
          openFile(this.getAttribute('data-open-file') || '');
        });
        el.addEventListener('mouseenter', function() {
          this.style.background = '#1e293b';
        });
        el.addEventListener('mouseleave', function() {
          var fp = this.getAttribute('data-open-file') || '';
          this.style.background = (fp === currentFile) ? '#1e3a8a55' : 'transparent';
        });
      });
    }

    function renderFiles(list) {
      allFiles = list || [];
      var wrap = document.getElementById('fileList');
      if (!wrap) return;

      var query = (document.getElementById('fileSearch').value || '').toLowerCase().trim();
      var tree = createTree(allFiles);
      var html = renderDir(tree, 0, query);
      wrap.innerHTML = html || '<div style="padding:8px;color:#94a3b8;font-size:0.8rem;">No files found.</div>';
      wireTreeEvents();
    }

    function refreshFiles() {
      setStatus('Refreshing files...');
      api('list_files', 'GET').then(function(res) {
        if (!res || !res.ok) throw new Error((res && res.message) || 'Failed to list files.');
        renderFiles(res.files || []);
        setStatus('Files refreshed.');
      }).catch(function(err) {
        setStatus(err.message || 'Failed to list files.', true);
      });
    }

    function quickOpenFile() {
      if (!allFiles || !allFiles.length) {
        setStatus('No files available yet.', true);
        return;
      }
      var guess = currentFile || (allFiles[0] && allFiles[0].path) || '';
      var path = window.prompt('Quick Open (relative file path):', guess);
      if (!path) return;
      var normalized = String(path).trim();
      var exists = allFiles.some(function(f) {
        return (f.path || '') === normalized;
      });
      if (!exists) {
        setStatus('File not found in explorer list: ' + normalized, true);
        return;
      }
      openFile(normalized);
    }

    function openFile(path) {
      if (!path) return;
      if (path !== currentFile && !ensureCanLeave('You have unsaved changes. Continue and discard them?')) {
        return;
      }
      setStatus('Opening ' + path + '...');
      api('read_file', 'POST', {
        path: path
      }).then(function(res) {
        if (!res || !res.ok) throw new Error((res && res.message) || 'Failed to read file.');
        if (!editor) return;
        var oldModel = editor.getModel();
        var model = monaco.editor.createModel(res.content || '', guessLang(path));
        editor.setModel(model);
        if (oldModel) oldModel.dispose();
        setCurrentFile(path);
        lastSavedContent = res.content || '';
        updateDirtyState(false);
        setStatus('Opened: ' + path);
      }).catch(function(err) {
        setStatus(err.message || 'Read failed.', true);
      });
    }

    function saveCurrent() {
      if (!currentFile) {
        setStatus('Select a file first.', true);
        return;
      }
      setStatus('Saving ' + currentFile + '...');
      api('save_file', 'POST', {
        path: currentFile,
        content: editor ? editor.getValue() : ''
      }).then(function(res) {
        if (!res || !res.ok) throw new Error((res && res.message) || 'Save failed.');
        lastSavedContent = editor ? editor.getValue() : '';
        updateDirtyState(false);
        setStatus(res.message || 'Saved.');
      }).catch(function(err) {
        setStatus(err.message || 'Save failed.', true);
      });
    }

    function createFile() {
      var path = window.prompt('New file path (relative):', 'docs/new-note.md');
      if (!path) return;
      var content = window.prompt('Initial content (optional):', '') || '';
      setStatus('Creating file...');
      api('create_file', 'POST', {
        path: path,
        content: content
      }).then(function(res) {
        if (!res || !res.ok) throw new Error((res && res.message) || 'Create file failed.');
        setStatus(res.message || 'File created.');
        refreshFiles();
        openFile(path);
      }).catch(function(err) {
        setStatus(err.message || 'Create file failed.', true);
      });
    }

    function createFolder() {
      var path = window.prompt('New folder path (relative):', 'modules/new-feature');
      if (!path) return;
      setStatus('Creating folder...');
      api('create_folder', 'POST', {
        path: path
      }).then(function(res) {
        if (!res || !res.ok) throw new Error((res && res.message) || 'Create folder failed.');
        setStatus(res.message || 'Folder created.');
        refreshFiles();
      }).catch(function(err) {
        setStatus(err.message || 'Create folder failed.', true);
      });
    }

    function runGitStatus() {
      setStatus('Running git status...');
      api('git_status', 'POST', {}).then(function(res) {
        if (!res || !res.ok) throw new Error((res && res.message) || 'git status failed.');
        setOutput(res.output || '(no output)');
        setStatus(res.message || 'Git status done.');
      }).catch(function(err) {
        setStatus(err.message || 'git status failed.', true);
      });
    }

    function runGitPull() {
      var branch = window.prompt('Branch to pull:', 'main') || 'main';
      setStatus('Running git pull...');
      api('git_pull', 'POST', {
        branch: branch
      }).then(function(res) {
        if (!res || !res.ok) {
          setOutput((res && res.output) || '');
          throw new Error((res && res.message) || 'git pull failed.');
        }
        setOutput(res.output || '(no output)');
        setStatus(res.message || 'Git pull done.');
        refreshFiles();
      }).catch(function(err) {
        setStatus(err.message || 'git pull failed.', true);
      });
    }

    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, function(c) {
        return ({
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '"': '&quot;',
          "'": '&#39;'
        })[c];
      });
    }

    require.config({
      paths: {
        'vs': 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.52.2/min/vs'
      }
    });
    require(['vs/editor/editor.main'], function() {
      editor = monaco.editor.create(document.getElementById('editor'), {
        value: '// Select a file from Explorer to start editing\n',
        language: 'plaintext',
        theme: 'vs-dark',
        automaticLayout: true,
        fontSize: 14,
        minimap: {
          enabled: true
        },
        mouseWheelScrollSensitivity: 1,
        fastScrollSensitivity: 5,
        scrollbar: {
          alwaysConsumeMouseWheel: false,
        },
        scrollBeyondLastLine: false,
      });

      editor.onDidChangeModelContent(function() {
        if (!editor) return;
        updateDirtyState(editor.getValue() !== lastSavedContent);
      });
      editor.onDidChangeCursorPosition(function() {
        updateCursorStatus();
      });

      refreshFiles();
      updateTerminalCollapsedState();
      updateStatusBar();
      updateCursorStatus();

      document.getElementById('btnRefreshFiles').addEventListener('click', refreshFiles);
      document.getElementById('btnNewFile').addEventListener('click', createFile);
      document.getElementById('btnNewFolder').addEventListener('click', createFolder);
      document.getElementById('btnSaveFile').addEventListener('click', saveCurrent);
      document.getElementById('btnGitStatus').addEventListener('click', runGitStatus);
      document.getElementById('btnGitPull').addEventListener('click', runGitPull);
      document.getElementById('btnBackDashboard').addEventListener('click', function() {
        if (!ensureCanLeave('You have unsaved changes. Leave patcher and discard them?')) return;
        window.location.href = 'index.php?page=dashboard';
      });
      document.getElementById('btnClearOutput').addEventListener('click', function() {
        setOutput('');
      });
      document.getElementById('btnToggleOutput').addEventListener('click', function() {
        outputCollapsed = !outputCollapsed;
        updateTerminalCollapsedState();
      });

      document.getElementById('fileSearch').addEventListener('input', function() {
        renderFiles(allFiles);
      });

      Array.from(document.querySelectorAll('[data-hybrid-open]')).forEach(function(btn) {
        btn.addEventListener('click', function() {
          var fp = this.getAttribute('data-hybrid-open') || '';
          if (fp) openFile(fp);
        });
      });

      window.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
          e.preventDefault();
          saveCurrent();
          return;
        }
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') {
          e.preventDefault();
          quickOpenFile();
        }
      });

      window.addEventListener('beforeunload', function(e) {
        if (!isDirty) return;
        e.preventDefault();
        e.returnValue = '';
      });
    });
  })();
</script>
