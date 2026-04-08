from __future__ import annotations

import concurrent.futures
import hashlib
import html
import json
import random
import re
import statistics
import sys
import time
import urllib.parse
import urllib.request


BASE_URL = "https://attendancev2app123.azurewebsites.net"
INDEX_URL = BASE_URL.rstrip("/") + "/index.php"
SUBMIT_URL = BASE_URL.rstrip("/") + "/submit.php"
USERS = 200
# Higher timeout for realistic burst testing under server stress.
TIMEOUT = 75
FORCE_ACTION = ""
FORCE_COURSE = "General"


def fetch_index():
    req = urllib.request.Request(
        INDEX_URL,
        headers={
            "User-Agent": "Mozilla/5.0 load-test",
            "Accept": "text/html,application/xhtml+xml",
        },
    )
    with urllib.request.urlopen(req, timeout=TIMEOUT) as resp:
        body = resp.read().decode("utf-8", errors="replace")
    action = extract_hidden(body, "action")
    course = extract_hidden(body, "course") or "General"
    if not action:
        raise RuntimeError("Could not extract hidden action from index.php")
    return action, course


def extract_hidden(body: str, name: str) -> str:
    m = re.search(rf'name="{re.escape(name)}"\s+value="([^"]*)"', body)
    return html.unescape(m.group(1)).strip() if m else ""


def build_identities(total_users: int):
    run_id = f"{int(time.time() * 1000)}_{random.randint(10000, 99999)}"
    run_prefix = run_id.split("_")[0][-4:]
    identities = []
    seen_fingerprints = set()
    seen_matrics = set()

    for user_no in range(1, total_users + 1):
        # Keep matric <= 10 chars while ensuring uniqueness across repeated same-day runs.
        matric = f"L{run_prefix}{user_no:05d}"[:10]

        # Fingerprint format is visitorId_token to align with submit.php token extraction logic.
        visitor_id = f"ltv_{user_no:03d}"
        token_seed = f"{run_id}:{user_no}:{random.randint(100000,999999)}"
        token_hash = hashlib.sha256(token_seed.encode("utf-8")).hexdigest()[:20]
        fingerprint = f"{visitor_id}_{token_hash}"

        if fingerprint in seen_fingerprints:
            raise RuntimeError(f"Fingerprint collision detected for user {user_no}: {fingerprint}")
        if matric in seen_matrics:
            raise RuntimeError(f"Matric collision detected for user {user_no}: {matric}")

        seen_fingerprints.add(fingerprint)
        seen_matrics.add(matric)

        identities.append(
            {
                "user_no": user_no,
                "name": f"Load Test {user_no}",
                "matric": matric,
                "fingerprint": fingerprint,
            }
        )

    return run_id, identities


def submit_one(identity: dict, action: str, course: str):
    payload = {
        "name": identity["name"],
        "matric": identity["matric"],
        "fingerprint": identity["fingerprint"],
        "action": action,
        "course": course,
    }
    data = urllib.parse.urlencode(payload).encode("utf-8")
    req = urllib.request.Request(
        SUBMIT_URL,
        data=data,
        headers={
            "Content-Type": "application/x-www-form-urlencoded",
            "Accept": "application/json",
            "User-Agent": "Mozilla/5.0 load-test",
        },
        method="POST",
    )
    started = time.perf_counter()
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
            status = resp.getcode()
        elapsed_ms = round((time.perf_counter() - started) * 1000, 2)
        return {
            "ok": True,
            "status": status,
            "elapsed_ms": elapsed_ms,
            "body": raw[:500],
            "user_no": identity["user_no"],
            "matric": identity["matric"],
            "fingerprint": identity["fingerprint"],
        }
    except Exception as exc:
        elapsed_ms = round((time.perf_counter() - started) * 1000, 2)
        return {
            "ok": False,
            "status": None,
            "elapsed_ms": elapsed_ms,
            "body": str(exc),
            "user_no": identity["user_no"],
            "matric": identity["matric"],
            "fingerprint": identity["fingerprint"],
        }


def pct(values, p):
    if not values:
        return 0.0
    ordered = sorted(values)
    idx = min(len(ordered) - 1, max(0, round((p / 100.0) * (len(ordered) - 1))))
    return ordered[idx]


def main():
    users = USERS
    wave_size = 0
    if len(sys.argv) > 1:
        try:
            users = int(sys.argv[1])
        except ValueError:
            users = USERS
    if len(sys.argv) > 2:
        try:
            wave_size = int(sys.argv[2])
        except ValueError:
            wave_size = 0
    if users < 1:
        users = USERS
    if wave_size < 0:
        wave_size = 0

    print(f"Fetching {INDEX_URL} ...")
    try:
        action, course = fetch_index()
        print(f"Discovered action={action}, course={course}")
    except Exception as exc:
        action = FORCE_ACTION or "checkin"
        course = FORCE_COURSE
        print(f"Index discovery failed: {exc}")
        print(f"Falling back to action={action}, course={course}")
    run_id, identities = build_identities(users)
    if wave_size > 0:
        print(f"Running {users} submits in wave mode (wave_size={wave_size}) against {SUBMIT_URL}")
    else:
        print(f"Running {users} concurrent submits against {SUBMIT_URL}")
    print(f"Run ID: {run_id}")
    print(f"Unique identities generated: {len(identities)}")

    started = time.perf_counter()
    results = []
    if wave_size > 0:
        for offset in range(0, len(identities), wave_size):
            chunk = identities[offset : offset + wave_size]
            with concurrent.futures.ThreadPoolExecutor(max_workers=len(chunk)) as ex:
                futures = [ex.submit(submit_one, identity, action, course) for identity in chunk]
                for fut in concurrent.futures.as_completed(futures):
                    results.append(fut.result())
    else:
        with concurrent.futures.ThreadPoolExecutor(max_workers=users) as ex:
            futures = [ex.submit(submit_one, identity, action, course) for identity in identities]
            for fut in concurrent.futures.as_completed(futures):
                results.append(fut.result())
    total_ms = round((time.perf_counter() - started) * 1000, 2)

    elapsed = [r["elapsed_ms"] for r in results]
    success = [r for r in results if r["ok"]]
    failed = [r for r in results if not r["ok"]]
    status_buckets = {}
    app_ok = 0
    app_rejected = 0
    app_parse_failed = 0
    rejection_reasons = {}
    for r in success:
        status_buckets[r["status"]] = status_buckets.get(r["status"], 0) + 1
        try:
            payload = json.loads(r["body"])
            if isinstance(payload, dict) and payload.get("ok") is True:
                app_ok += 1
            else:
                app_rejected += 1
                message = ""
                if isinstance(payload, dict):
                    message = str(payload.get("message") or payload.get("code") or "unknown_rejection")
                else:
                    message = "unknown_rejection"
                rejection_reasons[message] = rejection_reasons.get(message, 0) + 1
        except Exception:
            app_parse_failed += 1

    print("\nLoad test summary")
    print(f"Total requests: {len(results)}")
    print(f"Successful transport responses: {len(success)}")
    print(f"Transport failures: {len(failed)}")
    print(f"App-level accepted (ok=true): {app_ok}")
    print(f"App-level rejected (ok=false/non-true): {app_rejected}")
    print(f"App-level parse failures: {app_parse_failed}")
    print(f"Total wall time: {total_ms} ms ({round(total_ms/1000,2)} s)")
    print(f"Average response time: {round(statistics.mean(elapsed), 2) if elapsed else 0} ms")
    print(f"Median response time: {round(statistics.median(elapsed), 2) if elapsed else 0} ms")
    print(f"P95 response time: {round(pct(elapsed, 95), 2)} ms")
    print(f"P99 response time: {round(pct(elapsed, 99), 2)} ms")
    print(f"Max response time: {round(max(elapsed), 2) if elapsed else 0} ms")
    print(f"Status counts: {status_buckets}")
    if rejection_reasons:
        ordered_rejections = sorted(rejection_reasons.items(), key=lambda kv: kv[1], reverse=True)
        print("Top rejection reasons:")
        for reason, count in ordered_rejections[:10]:
            print(f"- {count}x :: {reason}")

    if failed:
        print("\nSample failures:")
        for r in failed[:10]:
            print(f"- {r['elapsed_ms']} ms :: {r['body']}")

    if success:
        print("\nSample responses:")
        for r in success[:5]:
            print(
                f"- user#{r['user_no']} {r['matric']} :: "
                f"{r['status']} in {r['elapsed_ms']} ms :: {r['body'][:180]}"
            )


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"Fatal error: {exc}", file=sys.stderr)
        sys.exit(1)
