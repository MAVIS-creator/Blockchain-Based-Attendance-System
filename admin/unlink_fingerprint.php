<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$fingerprintFile = __DIR__ . '/fingerprints.json';
$fingerprintsData = file_exists($fingerprintFile) ? json_decode(file_get_contents($fingerprintFile), true) : [];
if (!is_array($fingerprintsData)) $fingerprintsData = [];

// ✅ Pagination settings
$entriesPerPage = 10;
$totalEntries = count($fingerprintsData);
$totalPages = ceil($totalEntries / $entriesPerPage);
$currentPage = isset($_GET['fp_page']) ? max(1, intval($_GET['fp_page'])) : 1;
$offset = ($currentPage - 1) * $entriesPerPage;

// ✅ Slice data for current page
$paginatedData = array_slice($fingerprintsData, $offset, $entriesPerPage, true);

$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $matric = trim($_POST['matric']);

    if (isset($fingerprintsData[$matric])) {
        unset($fingerprintsData[$matric]);
        file_put_contents($fingerprintFile, json_encode($fingerprintsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Audit log
        $auditFile = __DIR__ . '/fingerprint_audit.log';
        $logEntry = "[" . date('Y-m-d H:i:s') . "] Matric $matric fingerprint unlinked by admin IP: " . $_SERVER['REMOTE_ADDR'] . "\n";
        file_put_contents($auditFile, $logEntry, FILE_APPEND | LOCK_EX);

        $message = "✅ Fingerprint for Matric Number <strong>$matric</strong> has been unlinked successfully!";

        // Preserve existing query params
        $queryParams = $_GET;
        $queryParams['fp_page'] = $currentPage;
        $queryString = http_build_query($queryParams);

        header("Location: ".$_SERVER['PHP_SELF']."?$queryString");
        exit;
    } else {
        $message = "❌ No fingerprint found for Matric Number <strong>$matric</strong>.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Unlink Fingerprint - Admin</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #00eaff, #00c5cc);
            --container-bg: rgba(255,255,255,0.05);
            --text-color: #fff;
            --input-bg: rgba(255,255,255,0.1);
            --shadow-color: rgba(0, 234, 255, 0.2);
        }
        body.light-mode {
            --bg-gradient: linear-gradient(135deg, #ff7e5f, #feb47b);
            --container-bg: #fff;
            --text-color: #333;
            --input-bg: #f3f4f6;
            --shadow-color: rgba(255, 126, 95, 0.3);
        }
        body {
            margin: 0;
            font-family: "Segoe UI", sans-serif;
            background: #001a33;
            color: var(--text-color);
            transition: all 0.4s ease;
        }
        .container {
            max-width: 800px;
            margin: 40px auto;
            background: var(--container-bg);
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 8px 30px var(--shadow-color);
            backdrop-filter: blur(10px);
        }
        h2 {
            text-align: center;
            margin-bottom: 25px;
            color: var(--text-color);
        }
        .theme-toggle {
            text-align: center;
            margin-bottom: 20px;
        }
        .theme-toggle button {
            background: var(--bg-gradient);
            color: #000;
            border: none;
            padding: 10px 20px;
            font-weight: bold;
            border-radius: 8px;
            cursor: pointer;
            transition: 0.3s;
        }
        .theme-toggle button:hover {
            filter: brightness(1.1);
        }
        form {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
        }
        input[type="text"] {
            flex: 1;
            padding: 12px;
            border-radius: 8px;
            border: none;
            background: var(--input-bg);
            color: var(--text-color);
            font-size: 1rem;
        }
        button[type="submit"] {
            background: var(--bg-gradient);
            color: #000;
            font-weight: bold;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            padding: 12px 20px;
            transition: 0.3s;
        }
        button[type="submit"]:hover {
            transform: translateY(-2px);
        }
        .linked-list {
            margin-top: 30px;
        }
        .linked-list h3 {
            margin-bottom: 10px;
        }
        .linked-table {
            width: 100%;
            border-collapse: collapse;
        }
        .linked-table th, .linked-table td {
            padding: 10px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            text-align: left;
        }
        .linked-table th {
            background: var(--input-bg);
        }
        .linked-table tr:nth-child(even) {
            background: rgba(255,255,255,0.05);
        }
        .pagination {
            text-align: center;
            margin-top: 20px;
        }
        .pagination a {
            display: inline-block;
            padding: 6px 12px;
            margin: 0 4px;
            background: var(--input-bg);
            color: var(--text-color);
            text-decoration: none;
            border-radius: 6px;
            transition: 0.2s;
        }
        .pagination a:hover {
            background: var(--bg-gradient);
            color: #000;
        }
        .pagination .active-page {
            background: var(--bg-gradient);
            color: #000;
        }
        .no-links {
            text-align: center;
            padding: 15px;
            color: #bbb;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2><i class='bx bx-unlink'></i> Unlink Fingerprint</h2>
        <div class="theme-toggle">
            <button onclick="toggleTheme()"><i class='bx bx-palette'></i> Toggle Theme</button>
        </div>
        <form id="unlinkForm" method="POST" onsubmit="return confirmUnlink(event);">
            <input type="text" name="matric" id="matricInput" placeholder="Enter Matric Number" required>
            <button type="submit"><i class='bx bx-unlink'></i> Unlink</button>
        </form>

        <div class="linked-list">
            <h3>Currently Linked Matric Numbers</h3>
            <?php if (count($fingerprintsData) > 0): ?>
                <table class="linked-table">
                    <thead>
                        <tr>
                            <th>Matric Number</th>
                            <th>Fingerprint Hash (truncated)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paginatedData as $matric => $hash): ?>
                            <tr>
                                <td><?= htmlspecialchars($matric) ?></td>
                                <td><?= htmlspecialchars(substr($hash, 0, 20)) ?>...</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="pagination">
                    <?php
                    // Prepare current query params
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
                <div class="no-links">
                    <i class='bx bx-block'></i> No fingerprints are currently linked.
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
                confirmButtonColor: '#00eaff',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, unlink it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    e.target.submit();
                }
            });
            return false;
        }

        function toggleTheme() {
            document.body.classList.toggle('light-mode');
        }
    </script>
</body>
</html>
