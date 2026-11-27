<?php
declare(strict_types=1);
require_once __DIR__ . '/../storage_helpers.php';
$before=getenv('STORAGE_PATH');
putenv('STORAGE_PATH=/dev/null/bbas-records');
try {
    $threw=false;
    try { app_storage_path(); } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(),'STORAGE_PATH');
    }
    if(!$threw) throw new RuntimeException('Configured storage unexpectedly fell back');
    echo "Explicit persistent storage fails closed\n";
} finally {
    if($before===false) putenv('STORAGE_PATH'); else putenv('STORAGE_PATH='.$before);
}
