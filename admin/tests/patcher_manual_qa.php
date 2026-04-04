<?php
function print_header($title) {
    echo "\n======================================================\n";
    echo "QA SCENARIO: $title\n";
    echo "======================================================\n";
}

function do_post_local($data) {
    $tempFile = __DIR__ . '/temp_payload.json';
    file_put_contents($tempFile, json_encode($data));
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/qa_wrapper.php') . ' ' . escapeshellarg($tempFile);
    $output = shell_exec($cmd);
    @unlink($tempFile);
    $decoded = json_decode(trim($output), true);
    if (!is_array($decoded)) {
        return ['raw' => trim($output), '_json_error' => json_last_error_msg()];
    }
    return $decoded;
}

$scenarios = [
    [
        'title' => 'UI/mobile issue analysis',
        'issue' => 'The mobile sidebar toggle button is not visible on screens smaller than 768px in the student dashboard.',
        'scan_mode' => 'quick'
    ],
    [
        'title' => 'hybrid/env config issue analysis',
        'issue' => 'The system is throwing hybrid database connection errors stating that the ENVS lack Supabase API keys.',
        'scan_mode' => 'standard'
    ],
    [
        'title' => 'logs/debug issue analysis',
        'issue' => 'In the system logs, we are seeing repeated "undefined variable id" notices in the log_activity.php module when generating audits.',
        'scan_mode' => 'deep'
    ]
];

foreach ($scenarios as $idx => $scenario) {
    print_header($scenario['title']);
    
    // Step 1: Start Job
    $resStart = do_post_local([
        'action' => 'ai_analyze_issue_start',
        'issue' => $scenario['issue'],
        'scan_mode' => $scenario['scan_mode']
    ]);
    
    if (empty($resStart['ok'])) {
        echo "[FAILED] Start endpoint failed:\n";
        print_r($resStart);
        continue;
    }
    
    $jobId = $resStart['job_id'];
    echo "[OK] Job Started with ID: $jobId\n";
    
    // Step 2: Poll Until Complete
    $polls = 0;
    while ($polls < 6) { // Safety loop limit
        $polls++;
        $resPoll = do_post_local([
            'action' => 'ai_analyze_issue_poll',
            'job_id' => $jobId
        ]);
        
        if (empty($resPoll['ok'])) {
            echo "[FAILED] Poll endpoint failed:\n";
            print_r($resPoll);
            break 2; // Exit the loop entirely
        }
        
        if ($resPoll['status'] === 'done') {
            echo "[SUCCESS] Analysis completed successfully.\n";
            echo "- Generated Patch Lines: " . count(explode("\n", $resPoll['result']['patch_preview'] ?? '')) . "\n";
            echo "- Root Cause identified: " . (empty($resPoll['result']['root_cause']) ? 'No' : 'Yes') . "\n";
            break;
        } else if ($resPoll['status'] === 'error') {
            echo "[FAILED] Job returned error status: " . ($resPoll['message'] ?? 'Unknown error') . "\n";
            break;
        } else {
            echo "- Polling... (Stage: " . ($resPoll['stage'] ?? '0') . ")\n";
            sleep(1);
        }
    }
    echo "Done with scenario.\n";
}

echo "\nAll scenarios executed.\n";
