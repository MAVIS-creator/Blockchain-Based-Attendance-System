<?php
// Make this page friendly whether embedded or standalone.
if (session_status() === PHP_SESSION_NONE) session_start();

$fingerprintFile = __DIR__ . '/fingerprints.json';
$fingerprintsData = file_exists($fingerprintFile) ? json_decode(file_get_contents($fingerprintFile), true) : [];
if (!is_array($fingerprintsData)) $fingerprintsData = [];

function save_fingerprints_atomic($fingerprintFile, $fingerprintsData) {
    $fp = fopen($fingerprintFile, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    $payload = json_encode($fingerprintsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, $payload);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return true;
}

// Pagination settings
$entriesPerPage = 10;
$totalEntries = count($fingerprintsData);
$totalPages = ceil($totalEntries / $entriesPerPage);
$currentPage = isset($_GET['fp_page']) ? max(1, intval($_GET['fp_page'])) : 1;
$offset = ($currentPage - 1) * $entriesPerPage;

$paginatedData = array_slice($fingerprintsData, $offset, $entriesPerPage, true);

$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $matric = trim($_POST['matric'] ?? '');

    if ($matric && isset($fingerprintsData[$matric])) {
        unset($fingerprintsData[$matric]);
        save_fingerprints_atomic($fingerprintFile, $fingerprintsData);

        // Audit log
        $auditFile = __DIR__ . '/fingerprint_audit.log';
        $logEntry = "[" . date('Y-m-d H:i:s') . "] Matric $matric fingerprint unlinked by admin IP: " . ($_SERVER['REMOTE_ADDR'] ?? '') . "\n";
        file_put_contents($auditFile, $logEntry, FILE_APPEND | LOCK_EX);

        $message = "Fingerprint for Matric Number $matric has been unlinked successfully.";

        // Preserve existing query params
        $queryParams = $_GET;
        $queryParams['fp_page'] = $currentPage;
        $queryString = http_build_query($queryParams);

        header("Location: " . $_SERVER['PHP_SELF'] . "?$queryString");
        exit;
    } else {
        $message = "No fingerprint found for Matric Number $matric.";
    }
}

$embedded = (basename($_SERVER['SCRIPT_NAME']) === 'index.php' || isset($page));

function render_unlink_page($paginatedData, $totalPages, $currentPage, $embedded, $message){
    ob_start();
    if (!$embedded) {
        ?>
        <!doctype html>
        <html>
        <head>
            <title>Unlink Fingerprint - Admin</title>
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
            <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
            <link rel="stylesheet" href="style.css">
        </head>
        <body>
        <div style="max-width:960px;margin:32px auto;padding:0 16px;">
        <?php
    }
    ?>

    <div style="max-width:900px;margin:0 auto;">
      <div style="margin-bottom:24px;">
        <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;">
          <span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;">link_off</span>Unlink Fingerprint
        </h2>
        <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">Remove linked fingerprints from student matric numbers.</p>
      </div>

      <?php if ($message): ?>
        <div class="alert <?= strpos($message, 'successfully') !== false ? 'alert-success' : 'alert-danger' ?>"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>

      <!-- Unlink Form -->
      <div class="st-card" style="margin-bottom:20px;">
        <form id="unlinkForm" method="POST" onsubmit="return confirmUnlink(event);" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
          <div style="flex:1;min-width:200px;">
            <label style="display:block;font-weight:600;margin-bottom:6px;color:var(--on-surface-variant);font-size:0.85rem;">Matric Number</label>
            <input type="text" name="matric" id="matricInput" placeholder="Enter Matric Number" required>
          </div>
          <button type="submit" class="st-btn st-btn-danger">
            <span class="material-symbols-outlined" style="font-size:1rem;">link_off</span> Unlink
          </button>
        </form>
      </div>

      <!-- Linked List -->
      <div class="st-card" style="padding:0;">
        <div style="padding:20px 24px;border-bottom:1px solid rgba(194,199,209,0.1);">
          <p style="font-weight:700;color:var(--on-surface);margin:0;display:flex;align-items:center;gap:8px;">
            <span class="material-symbols-outlined" style="font-size:1.1rem;">fingerprint</span>
            Linked Fingerprints
            <span class="st-chip st-chip-info" style="margin-left:auto;"><?= count($GLOBALS['fingerprintsData'] ?? []) ?: count($paginatedData) ?> linked</span>
          </p>
        </div>
        <?php if (count($paginatedData) > 0): ?>
        <div style="overflow-x:auto;">
          <table class="st-table" style="width:100%;">
            <thead>
              <tr><th>Matric Number</th><th>Fingerprint Hash (truncated)</th></tr>
            </thead>
            <tbody>
              <?php foreach ($paginatedData as $matric => $hash): ?>
                <tr>
                  <td style="font-weight:600;"><?= htmlspecialchars($matric) ?></td>
                  <td style="font-family:monospace;font-size:0.85rem;color:var(--on-surface-variant);"><?= htmlspecialchars(substr($hash, 0, 20)) ?>...</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($totalPages > 1): ?>
          <div style="padding:12px 24px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap;border-top:1px solid rgba(194,199,209,0.1);">
            <?php
            $queryParams = $_GET;
            foreach (range(1, $totalPages) as $i) {
                $queryParams['fp_page'] = $i;
                $queryString = http_build_query($queryParams);
                $active = $i === $currentPage;
                echo '<a href="?' . $queryString . '" style="display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:36px;border-radius:8px;font-weight:600;font-size:0.88rem;text-decoration:none;' . ($active ? 'background:var(--primary);color:#fff;' : 'background:var(--surface-container-high);color:var(--on-surface);') . '">' . $i . '</a>';
            }
            ?>
          </div>
        <?php endif; ?>
        <?php else: ?>
          <div style="text-align:center;padding:32px;color:var(--on-surface-variant);">
            <span class="material-symbols-outlined" style="font-size:2.5rem;color:var(--outline-variant);display:block;margin-bottom:8px;">fingerprint</span>
            No fingerprints are currently linked.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <script>
        function confirmUnlink(e) {
            e.preventDefault();
            Swal.fire({
                title: 'Are you sure?',
                text: "This fingerprint will be permanently unlinked!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: 'var(--primary)',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, unlink it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    e.target.submit();
                }
            });
            return false;
        }
    </script>
    <?php
    if (!$embedded) {
        echo '</div></body></html>';
    }
    return ob_get_clean();
}

echo render_unlink_page($paginatedData, $totalPages, $currentPage, $embedded, $message);
