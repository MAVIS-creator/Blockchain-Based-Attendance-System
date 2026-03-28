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
            <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
            <style>body{font-family:'Segoe UI',sans-serif;background:#f4f7fb;margin:0;padding:24px} .container{max-width:960px;margin:0 auto;background:#fff;padding:24px;border-radius:12px;box-shadow:0 12px 30px rgba(2,6,23,0.06)}</style>
        </head>
        <body>
        <div class="container">
        <?php
    } else {
        echo '<div class="card">';
        echo '<div class="card-header"><h3>Unlink Fingerprint</h3></div><div class="card-body">';
    }

    if ($message) echo '<div class="msg" style="margin-bottom:12px;">'.htmlspecialchars($message).'</div>';
    ?>
    <form id="unlinkForm" method="POST" onsubmit="return confirmUnlink(event);" style="display:flex;gap:10px;margin-bottom:16px;">
        <input type="text" name="matric" id="matricInput" placeholder="Enter Matric Number" required style="flex:1;padding:10px;border-radius:8px;border:1px solid #e6eef9;">
        <button type="submit" class="btn btn-primary" style="padding:10px 14px;border-radius:8px;"><i class='bx bx-unlink'></i> Unlink</button>
    </form>

    <div class="linked-list">
        <h4>Currently Linked Matric Numbers</h4>
        <?php if (count($paginatedData) > 0): ?>
            <table class="table">
                <thead>
                    <tr><th>Matric Number</th><th>Fingerprint Hash (truncated)</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($paginatedData as $matric => $hash): ?>
                        <tr><td><?= htmlspecialchars($matric) ?></td><td><?= htmlspecialchars(substr($hash, 0, 20)) ?>...</td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="pagination" style="margin-top:12px;">
                <?php
                $queryParams = $_GET;
                foreach (range(1, $totalPages) as $i) {
                    $queryParams['fp_page'] = $i;
                    $queryString = http_build_query($queryParams);
                    $activeClass = $i === $currentPage ? 'active-page' : '';
                    echo "<a href=\"?{$queryString}\" class=\"{$activeClass}\">{$i}</a>";
                }
                ?>
            </div>
        <?php else: ?>
            <div class="no-links">No fingerprints are currently linked.</div>
        <?php endif; ?>
    </div>

    <script>
        function confirmUnlink(e) {
            e.preventDefault();
            Swal.fire({
                title: 'Are you sure?',
                text: "This fingerprint will be permanently unlinked!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#6366f1',
                cancelButtonColor: '#d33',
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
    } else {
        echo '</div></div>'; // close card-body and card
    }
    return ob_get_clean();
}

echo render_unlink_page($paginatedData, $totalPages, $currentPage, $embedded, $message);

