#!/usr/bin/env bash
set -euo pipefail
dir="$(mktemp -d)"
trap 'rm -rf "$dir"' EXIT
export BBAS_TEST_CHAIN_FILE="$dir/chain.json"
for n in $(seq 1 12); do php tests/chain_append_worker.php & done
wait
php -r '
$f=getenv("BBAS_TEST_CHAIN_FILE");
$x=json_decode(file_get_contents($f), true, 512, JSON_THROW_ON_ERROR);
if(count($x)!==360){fwrite(STDERR,"Chain count mismatch\n");exit(1);}
$prior=null; $seen=[];
foreach($x as $b) {
 if(($b["prevHash"]??null)!==$prior){fwrite(STDERR,"Broken chain link\n");exit(1);}
 $hash=$b["hash"];unset($b["hash"]);ksort($b);
 $expected=hash("sha256",json_encode($b,JSON_UNESCAPED_SLASHES).$prior);
 if(!hash_equals($expected,$hash)){fwrite(STDERR,"Invalid chain hash\n");exit(1);}
 $prior=$hash;
 $key=$b["matric"];if(isset($seen[$key])){fwrite(STDERR,"Duplicate chain entry\n");exit(1);} $seen[$key]=true;
}
echo "360 chained writes verified\n";
'
