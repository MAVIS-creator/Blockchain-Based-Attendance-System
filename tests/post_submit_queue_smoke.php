<?php
$dir = sys_get_temp_dir() . '/bbas-post-submit-test-' . bin2hex(random_bytes(6));
mkdir($dir,0700,true);
putenv('STORAGE_PATH=' . $dir);
require_once __DIR__ . '/../src/PostSubmitQueue.php';
try {
   if (!bbas_post_submit_enqueue(['day'=>'2026-11-18','chain_hash'=>'abc'])) exit(1);
   $f=bbas_post_submit_queue_path();
   $rows=file($f,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
   $job=json_decode($rows[0]??'',true);
   if (!isset($job['id']) || ($job['chain_hash']??null)!=='abc') exit(2);
   echo "Post-submit durable enqueue passed\n";
} finally {
   foreach (glob($dir.'/logs/*')?:[] as $f) @unlink($f);
   @rmdir($dir.'/logs'); @rmdir($dir);
}
