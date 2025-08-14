<?php
$chainFile = dirname(__DIR__) . '/secure_logs/attendance_chain.json';
$chain = [];
$valid = true;
$errorMsg = '';
$checkedBlocks = 0;

$selectedDate = $_GET['date'] ?? null;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$blocksPerPage = 20;

if (!file_exists($chainFile)) {
    $valid = false;
    $errorMsg = 'Chain file not found.';
} else {
    $jsonData = file_get_contents($chainFile);
    $chain = json_decode($jsonData, true);

    if (!is_array($chain) || count($chain) === 0) {
        $valid = false;
        $errorMsg = 'Chain is empty or invalid.';
        $chain = []; // 🛡️ Make sure $chain is an array
    } else {
        foreach ($chain as $i => $block) {
            $blockDataForHash = $block;
            unset($blockDataForHash['hash']);

            $expectedHash = hash('sha256', json_encode($blockDataForHash) . ($block['prevHash'] ?? ''));

            if (($block['hash'] ?? null) !== $expectedHash) {
                $valid = false;
                $errorMsg = "Tampering detected at block #$i (hash mismatch)";
                break;
            }
            if ($i > 0 && ($block['prevHash'] ?? null) !== $chain[$i - 1]['hash']) {
                $valid = false;
                $errorMsg = "Tampering detected at block #$i (prevHash mismatch)";
                break;
            }

            $checkedBlocks++;
        }
    }
}

// 🟢 Filter chain blocks by date (for display)
if (!is_array($chain)) {
    $filteredBlocks = [];
} else {
    $filteredBlocks = $selectedDate
        ? array_filter($chain, fn($block) => strpos($block['timestamp'], $selectedDate) === 0)
        : $chain;
}

// Pagination logic
$totalBlocks = is_array($filteredBlocks) ? count($filteredBlocks) : 0;
$totalPages = ceil($totalBlocks / $blocksPerPage);
$startIndex = ($page - 1) * $blocksPerPage;
$blocksToShow = array_slice($filteredBlocks, $startIndex, $blocksPerPage, true);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Attendance Chain Validator</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; margin: 0; padding: 0; }
        .container { max-width: 1200px; margin: 40px auto; background: #fff; border-radius: 15px; box-shadow: 0 6px 30px rgba(0,0,0,0.08); padding: 40px; }
        h1 { text-align: center; color: #1f2937; margin-bottom: 20px; }
        .result { padding: 18px; border-radius: 10px; font-size: 1.15rem; margin-bottom: 30px; text-align: center; }
        .success { background: #d1fae5; color: #065f46; border: 2px solid #10b981; }
        .error { background: #fee2e2; color: #991b1b; border: 2px solid #ef4444; }
        .form-date { text-align: center; margin-bottom: 25px; }
        .form-date input, .form-date button { padding: 10px 16px; border-radius: 8px; border: 1px solid #ccc; font-size: 1rem; }
        .form-date button { background: #2563eb; color: #fff; cursor: pointer; border: none; }
        .form-date button:hover { background: #1e40af; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px 14px; border: 1px solid #e5e7eb; font-size: 0.95rem; }
        th { background: #1f2937; color: #f9fafb; }
        tr:nth-child(even) { background-color: #f9fafb; }
        .block-num { font-weight: bold; color: #3b82f6; }
        .pagination { text-align: center; margin-top: 20px; }
        .pagination a { display: inline-block; margin: 0 5px; padding: 8px 14px; border: 1px solid #ddd; border-radius: 5px; text-decoration: none; color: #2563eb; }
        .pagination a.active { background: #2563eb; color: #fff; border-color: #2563eb; }
        .pagination a:hover { background: #1e40af; color: #fff; }
    </style>
</head>
<body>
<div class="container">
    <h1>Attendance Chain Validator</h1>
    <?php if ($valid): ?>
        <div class="result success">
            ✅ Chain is valid. All blocks are intact (<?= $checkedBlocks ?> blocks checked).
        </div>
    <?php else: ?>
        <div class="result error">
            ❌ <?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <form method="get" class="form-date">
        <label for="date">Filter by Date:</label>
        <input type="date" id="date" name="date" value="<?= htmlspecialchars($selectedDate ?? '') ?>">
        <button type="submit">Filter</button>
    </form>

    <?php if ($valid && count($blocksToShow) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Timestamp</th>
                    <th>Name</th>
                    <th>Matric</th>
                    <th>Action</th>
                    <th>Fingerprint</th>
                    <th>IP</th>
                    <th>UserAgent</th>
                    <th>Course</th>
                    <th>Hash</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($blocksToShow as $i => $block): ?>
                <tr>
                    <td class="block-num"><?= $i + $startIndex ?></td>
                    <td><?= htmlspecialchars($block['timestamp'] ?? '') ?></td>
                    <td><?= htmlspecialchars($block['name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($block['matric'] ?? '') ?></td>
                    <td><?= htmlspecialchars($block['action'] ?? '') ?></td>
                    <td><?= htmlspecialchars($block['fingerprint'] ?? '') ?></td>
                    <td><?= htmlspecialchars($block['ip'] ?? '') ?></td>
                    <td><?= htmlspecialchars($block['userAgent'] ?? '') ?></td>
                    <td><?= htmlspecialchars($block['course'] ?? '') ?></td>
                    <td style="font-size:0.85em;word-break:break-all;"><?= htmlspecialchars($block['hash'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination links -->
        <div class="pagination">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a href="?date=<?= urlencode($selectedDate ?? '') ?>&page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php elseif ($valid): ?>
        <div class="result">No blocks found for this date.</div>
    <?php endif; ?>
</div>
</body>
</html>

