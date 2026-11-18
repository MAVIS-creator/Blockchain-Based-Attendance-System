#!/usr/bin/env bash
set -euo pipefail

# Launch a disposable local PHP server; never target a live deployment.
tmp="$(mktemp -d)"
pid=""
cleanup() {
  if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
  rm -rf "$tmp"
}
trap cleanup EXIT

export STORAGE_PATH="$tmp/storage"
export APP_ENV=testing
export APP_DEBUG=false
php -S 127.0.0.1:18765 -t . >"$tmp/server.log" 2>&1 &
pid=$!
base="http://127.0.0.1:18765"
ready=0
for _ in {1..30}; do
  code="$(curl --max-time 2 -s -o /dev/null -w '%{http_code}' "$base/" || true)"
  if [[ "$code" != "000" ]]; then ready=1; break; fi
  sleep 0.25
done
if [[ "$ready" != "1" ]]; then
  echo 'Local PHP server did not start'; tail -n 30 "$tmp/server.log"; exit 1
fi

# Reject an incomplete attendance request before attempting a storage write.
response="$(curl --fail --silent --show-error --max-time 8 -X POST "$base/submit.php" -d '')"
php -r '$j=json_decode(stream_get_contents(STDIN),true); if (!is_array($j) || ($j["ok"]??null)!==false || strpos((string)($j["message"]??""),"Missing required")===false) exit(1);' <<< "$response"

response="$(curl --fail --silent --show-error --max-time 8 -X POST "$base/submit.php" --data-urlencode 'name=Test' --data-urlencode 'matric=123' --data-urlencode 'fingerprint=smoke_fingerprint' --data-urlencode 'action=checkin')"
php -r '$j=json_decode(stream_get_contents(STDIN),true); if (!is_array($j) || ($j["ok"]??null)!==false || strpos((string)($j["message"]??""),"valid matric")===false) exit(1);' <<< "$response"

# Maintenance entrypoints must not be callable from the public web.
for endpoint in fix_chain.php replay_outbox.php diagnostic.php admin/ai_ticket_worker.php admin/post_submit_worker.php tools/seed_sql_admin_accounts.php; do
  code="$(curl --max-time 8 -s -o /dev/null -w '%{http_code}' "$base/$endpoint")"
  if [[ "$code" != "404" ]]; then echo "$endpoint returned $code instead of 404"; exit 1; fi
done

# Direct route URLs must enforce the same auth as admin/index.php.
for endpoint in admin/courses/set_active.php admin/courses/add.php admin/dashboard.php admin/ai_suggestions.php admin/ai_rulebook.php; do
  code="$(curl -s -o /dev/null -w '%{http_code}' "$base/$endpoint")"
  [[ "$code" == "403" ]] || { echo "Unprotected admin GET $endpoint returned $code"; exit 1; }
done
code="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$base/admin/courses/set_active.php" -d 'disable=1')"
[[ "$code" == "403" ]] || { echo "Unprotected admin course POST returned $code"; exit 1; }

# Security: no global revocation list or long-running public revocation SSE.
code="$(curl -s -o /dev/null -w '%{http_code}' "$base/admin/revoked_tokens.php")"
[[ "$code" == "405" ]] || { echo "Global revocation endpoint GET returned $code"; exit 1; }
code="$(curl -s -o /dev/null -w '%{http_code}' "$base/admin/revoke_sse.php")"
[[ "$code" == "410" ]] || { echo "Legacy SSE returned $code"; exit 1; }
code="$(curl -s -o /dev/null -w '%{http_code}' "$base/index-backup.php")"
[[ "$code" == "404" ]] || { echo "Public backup entrypoint returned $code"; exit 1; }
revoke="$(curl -fsS --max-time 8 -X POST "$base/admin/revoked_tokens.php" --data-urlencode 'token=00000000-0000-4000-a000-000000000000')"
php -r '$j=json_decode(stream_get_contents(STDIN),true); if (!is_array($j) || !array_key_exists("revoked",$j) || is_array($j["revoked"]) || array_key_exists("tokens",$j)) exit(1);' <<< "$revoke"

# A static frontend needs a small, unprivileged context response.
context="$(curl --silent --show-error --max-time 8 "$base/public_context_api.php")"
php -r '$j=json_decode(stream_get_contents(STDIN),true); if(!is_array($j)||!array_key_exists("active",$j)||!array_key_exists("course",$j))exit(1);' <<< "$context"

echo "HTTP endpoint security and attendance validation smoke checks passed."
