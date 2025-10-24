<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
  header('Location: login.php');
  exit;
}

$chainFile = __DIR__ . '/../secure_logs/attendance_chain.json';
if (!file_exists($chainFile)) {
  $status = ['ok'=>false,'message'=>'Chain file not found.'];
} else {
  $chain = json_decode(file_get_contents($chainFile), true);
  if (!is_array($chain) || count($chain) === 0) {
    $status = ['ok'=>false,'message'=>'Chain is empty or invalid.'];
  } else {
    $valid = true;
    $prevHash = null;
    $errors = [];
    foreach ($chain as $i => $block) {
      $blockDataForHash = $block;
      unset($blockDataForHash['hash']);
      ksort($blockDataForHash);
      $expectedHash = hash('sha256', json_encode($blockDataForHash, JSON_UNESCAPED_SLASHES) . $prevHash);
      if (($block['hash'] ?? null) !== $expectedHash) {
        $errors[] = "Tampering detected at block #$i (hash mismatch)";
        $valid = false;
        break;
      }
      if ($i > 0 && (($block['prevHash'] ?? null) !== $prevHash)) {
        $errors[] = "Tampering detected at block #$i (prevHash mismatch)";
        $valid = false;
        break;
      }
      $prevHash = $block['hash'] ?? null;
    }
    $status = ['ok'=>$valid,'errors'=>$errors,'blocks'=>count($chain)];
  }
}

?>

<div style="padding:20px;">
  <h2>Attendance Chain</h2>
  <?php if (!$status['ok']): ?>
    <div style="background:#ffe6e6;padding:12px;border-radius:8px;color:#8a1f1f;">
      <strong>Problem:</strong> <?=htmlspecialchars($status['message'] ?? implode('; ',$status['errors'] ?? []))?>
    </div>
  <?php else: ?>
    <div style="background:#dff0d8;padding:12px;border-radius:8px;color:#23512a;margin-bottom:12px;">
      Chain is valid. <?=intval($status['blocks'])?> blocks checked.
    </div>
    <div style="max-height:420px;overflow:auto;border:1px solid #e6e6e6;padding:12px;border-radius:8px;background:#fff;">
      <?php foreach ($chain as $i=>$block): ?>
        <div style="padding:8px;border-bottom:1px solid #f1f1f1;margin-bottom:8px;">
          <div style="font-weight:700;">Block #<?= $i ?></div>
          <div style="font-size:0.9rem;color:#444;margin-top:6px;">Timestamp: <?=htmlspecialchars($block['timestamp'] ?? '')?></div>
          <div style="font-size:0.9rem;color:#444;">Name: <?=htmlspecialchars($block['name'] ?? '')?> — Matric: <?=htmlspecialchars($block['matric'] ?? '')?> — Action: <?=htmlspecialchars($block['action'] ?? '')?></div>
          <div style="font-size:0.8rem;color:#666;margin-top:6px;word-break:break-all;">Hash: <?=htmlspecialchars($block['hash'] ?? '')?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
