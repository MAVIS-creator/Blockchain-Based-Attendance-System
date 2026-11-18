<?php
require_once __DIR__ . '/../src/RevocationRecoveryPolicy.php';
$now = 1773830000;
$token = 'abcdef01-2345-4567-89ab-abcdef012345';
$fp = 'f00dsha_' . $token;
$temp = ['source'=>'temporary_tab_lock','issued_by'=>'bbas_policy_engine','at'=>$now-30,'expiry'=>$now+870];
if (!bbas_auto_recovery_eligible($fp, $fp, $token, $temp, $now)) exit(1);
$admin = $temp; $admin['by'] = 'superadmin';
if (bbas_auto_recovery_eligible($fp, $fp, $token, $admin, $now)) exit(2);
$admin = $temp; $admin['source'] = 'admin_revocation';
if (bbas_auto_recovery_eligible($fp, $fp, $token, $admin, $now)) exit(3);
if (bbas_auto_recovery_eligible($fp, 'other_' . $token, $token, $temp, $now)) exit(4);
if (bbas_auto_recovery_eligible($fp, $fp, 'another-token', $temp, $now)) exit(5);
$expired = $temp; $expired['expiry'] = $now - 1;
if (bbas_auto_recovery_eligible($fp, $fp, $token, $expired, $now)) exit(6);
echo "Recovery policy: exact device+token, short provenance; admin/IP-only unlock denied\n";
