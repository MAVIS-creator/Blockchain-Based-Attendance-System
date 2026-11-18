<?php
declare(strict_types=1);

require_once __DIR__ . '/../storage_helpers.php';

function bbas_post_submit_queue_path(): string
{
    return app_storage_file('logs/post_submit_jobs.jsonl');
}
function bbas_post_submit_enqueue(array $job): bool
{
    $job['id'] = bin2hex(random_bytes(16));
    $job['created_at'] = date('c');
    $job['attempts'] = 0;
    $line = json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $file = bbas_post_submit_queue_path();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        return false;
    }
    $result = file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    @chmod($file, 0600);
    return $result === strlen($line);
}
