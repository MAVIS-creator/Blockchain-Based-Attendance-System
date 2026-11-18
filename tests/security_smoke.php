<?php
// Dependency-free regression checks for trusted-proxy and maintenance controls.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../client_ip.php';

$cases = [
  ['untrusted peer ignores spoofed XFF', ['REMOTE_ADDR'=>'198.51.100.7','HTTP_X_FORWARDED_FOR'=>'1.2.3.4'], ['10.0.0.0/8'], '198.51.100.7'],
  ['no configured proxy trusts XFF', ['REMOTE_ADDR'=>'10.0.0.8','HTTP_X_FORWARDED_FOR'=>'1.2.3.4'], [], '10.0.0.8'],
  ['trusted proxy retrieves client', ['REMOTE_ADDR'=>'10.0.0.8','HTTP_X_FORWARDED_FOR'=>'198.51.100.77'], ['10.0.0.0/8'], '198.51.100.77'],
  ['spoofed prefix cannot override first untrusted hop', ['REMOTE_ADDR'=>'10.0.0.8','HTTP_X_FORWARDED_FOR'=>'1.2.3.4, 198.51.100.77'], ['10.0.0.0/8'], '198.51.100.77'],
  ['multiple trusted proxies', ['REMOTE_ADDR'=>'10.0.0.8','HTTP_X_FORWARDED_FOR'=>'198.51.100.77, 10.0.0.9'], ['10.0.0.0/8'], '198.51.100.77'],
  ['ipv6 trusted proxy', ['REMOTE_ADDR'=>'2001:db8::10','HTTP_X_FORWARDED_FOR'=>'2001:db8:2::10'], ['2001:db8::/48'], '2001:db8:2::10'],
  ['invalid XFF fails closed', ['REMOTE_ADDR'=>'10.0.0.8','HTTP_X_FORWARDED_FOR'=>'not-an-ip'], ['10.0.0.0/8'], '10.0.0.8'],
];
$failures = [];
foreach ($cases as [$title,$server,$trusted,$expected]) {
  $actual = attendance_client_ip_from($server,$trusted);
  if ($actual !== $expected) $failures[] = "$title: expected $expected; got $actual";
}
if (!attendance_ip_in_cidr('192.0.2.1','192.0.2.0/24')) $failures[]='IPv4 CIDR matching failed';
if (attendance_ip_in_cidr('192.0.3.1','192.0.2.0/24')) $failures[]='IPv4 CIDR rejected wrong network failed';
if (attendance_ip_in_cidr('192.0.2.1','192.0.2.0/33')) $failures[]='IPv4 mask validation failed';
foreach (['fix_chain.php','replay_outbox.php','diagnostic.php','admin/ai_ticket_worker.php','tools/seed_sql_admin_accounts.php'] as $path) {
  $source = file_get_contents(__DIR__ . '/../' . $path);
  if (!is_string($source) || strpos($source, "PHP_SAPI !== 'cli'") === false) $failures[]="$path lacks CLI-only guard";
}
if ($failures) {
  fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
  exit(1);
}
echo "Security regression checks passed." . PHP_EOL;
