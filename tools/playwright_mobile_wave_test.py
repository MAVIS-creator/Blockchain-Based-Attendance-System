import argparse
import asyncio
import csv
import json
import random
import statistics
import time
import uuid
from pathlib import Path

from playwright.async_api import async_playwright, TimeoutError as PlaywrightTimeoutError


def now_stamp() -> str:
    return time.strftime("%Y%m%d-%H%M%S")


def summarize(values):
    if not values:
        return {
            "count": 0,
            "avg_ms": None,
            "min_ms": None,
            "max_ms": None,
            "median_ms": None,
            "p90_ms": None,
        }
    return {
        "count": len(values),
        "avg_ms": round(statistics.mean(values), 2),
        "min_ms": round(min(values), 2),
        "max_ms": round(max(values), 2),
        "median_ms": round(statistics.median(values), 2),
        "p90_ms": round(statistics.quantiles(values, n=10)[8], 2) if len(values) > 1 else round(values[0], 2),
    }


async def run_session(browser, base_url: str, wave: int, session_idx: int, device_profile: dict):
    session_id = f"w{wave}-s{session_idx}-{uuid.uuid4().hex[:8]}"
    context = await browser.new_context(**device_profile)
    page = await context.new_page()

    row = {
        "timestamp": time.strftime("%Y-%m-%d %H:%M:%S"),
        "wave": wave,
        "session": session_id,
        "landing_ms": None,
        "status_api_ms": None,
        "submit_ms": None,
        "end_to_end_ms": None,
        "submit_ok": False,
        "message": "",
    }

    start_total = time.perf_counter()

    try:
        t0 = time.perf_counter()
        await page.goto(base_url + "/", wait_until="domcontentloaded", timeout=45000)
        row["landing_ms"] = round((time.perf_counter() - t0) * 1000.0, 2)

        action_input = await page.query_selector("input[name='action']")
        action_value = await action_input.get_attribute("value") if action_input else None
        is_active = bool(action_value and action_value.strip() in {"checkin", "checkout"})
        if not is_active:
            row["message"] = "attendance inactive"
            row["end_to_end_ms"] = round((time.perf_counter() - start_total) * 1000.0, 2)
            return row

        await page.fill("#name", f"Mobile User {session_id}")
        await page.fill("#matric", str(random.randint(100000, 9999999999)))

        # Prefer real JS-generated fingerprint; if not ready quickly, inject a unique fallback.
        try:
            await page.wait_for_function(
                "(() => { const el = document.querySelector('#fingerprint'); return !!el && !!el.value && el.value.length > 8; })()",
                timeout=6000,
            )
        except PlaywrightTimeoutError:
            await page.fill("#fingerprint", f"{uuid.uuid4().hex}_{uuid.uuid4().hex}")

        t2 = time.perf_counter()
        async with page.expect_response(
            lambda r: r.url.endswith("/submit.php") and r.request.method == "POST",
            timeout=45000,
        ) as submit_waiter:
            await page.click("#submitBtn")

        submit_resp = await submit_waiter.value
        row["submit_ms"] = round((time.perf_counter() - t2) * 1000.0, 2)

        try:
            payload = await submit_resp.json()
        except Exception:
            payload = {"ok": False, "message": await submit_resp.text()}

        row["submit_ok"] = bool(payload.get("ok"))
        row["message"] = str(payload.get("message", ""))
        row["end_to_end_ms"] = round((time.perf_counter() - start_total) * 1000.0, 2)
        return row

    except Exception as exc:
        row["message"] = f"error: {exc}"
        row["end_to_end_ms"] = round((time.perf_counter() - start_total) * 1000.0, 2)
        return row
    finally:
        await context.close()


async def run_wave(browser, base_url: str, wave: int, sessions: int, device_profile: dict):
    tasks = [run_session(browser, base_url, wave, i + 1, device_profile) for i in range(sessions)]
    return await asyncio.gather(*tasks)


async def main():
    parser = argparse.ArgumentParser(description="Real mobile-browser attendance wave load test")
    parser.add_argument("--base-url", default="https://attendancev2app123.azurewebsites.net")
    parser.add_argument("--waves", type=int, default=3)
    parser.add_argument("--sessions-per-wave", type=int, default=100)
    parser.add_argument("--headless", action="store_true", default=True)
    parser.add_argument("--report-prefix", default="playwright_mobile_wave")
    args = parser.parse_args()

    base_url = args.base_url.rstrip("/")
    report_dir = Path("load-test-reports")
    report_dir.mkdir(parents=True, exist_ok=True)
    stamp = now_stamp()
    csv_path = report_dir / f"{args.report_prefix}_{stamp}.csv"
    json_path = report_dir / f"{args.report_prefix}_{stamp}.json"

    all_rows = []

    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=args.headless)
        device = p.devices["iPhone 13"]

        for wave in range(1, args.waves + 1):
            wave_rows = await run_wave(browser, base_url, wave, args.sessions_per_wave, device)
            all_rows.extend(wave_rows)

        await browser.close()

    fields = [
        "timestamp",
        "wave",
        "session",
        "landing_ms",
        "status_api_ms",
        "submit_ms",
        "end_to_end_ms",
        "submit_ok",
        "message",
    ]
    with csv_path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows(all_rows)

    landing_vals = [r["landing_ms"] for r in all_rows if isinstance(r.get("landing_ms"), (int, float))]
    status_vals = [r["status_api_ms"] for r in all_rows if isinstance(r.get("status_api_ms"), (int, float))]
    submit_vals = [r["submit_ms"] for r in all_rows if isinstance(r.get("submit_ms"), (int, float))]
    e2e_vals = [r["end_to_end_ms"] for r in all_rows if isinstance(r.get("end_to_end_ms"), (int, float))]

    wave_summary = {}
    for wave in range(1, args.waves + 1):
        rows = [r for r in all_rows if r.get("wave") == wave]
        wave_summary[f"wave_{wave}"] = {
            "sessions": len(rows),
            "submit_ok": sum(1 for r in rows if r.get("submit_ok")),
            "submit_fail": sum(1 for r in rows if not r.get("submit_ok")),
            "landing": summarize([r["landing_ms"] for r in rows if isinstance(r.get("landing_ms"), (int, float))]),
            "status_api": summarize([r["status_api_ms"] for r in rows if isinstance(r.get("status_api_ms"), (int, float))]),
            "submit": summarize([r["submit_ms"] for r in rows if isinstance(r.get("submit_ms"), (int, float))]),
            "end_to_end": summarize([r["end_to_end_ms"] for r in rows if isinstance(r.get("end_to_end_ms"), (int, float))]),
        }

    summary = {
        "base_url": base_url,
        "waves": args.waves,
        "sessions_per_wave": args.sessions_per_wave,
        "total_sessions": len(all_rows),
        "submit_ok": sum(1 for r in all_rows if r.get("submit_ok")),
        "submit_fail": sum(1 for r in all_rows if not r.get("submit_ok")),
        "landing": summarize(landing_vals),
        "status_api": summarize(status_vals),
        "submit": summarize(submit_vals),
        "end_to_end": summarize(e2e_vals),
        "per_wave": wave_summary,
        "csv_report": str(csv_path),
    }

    with json_path.open("w", encoding="utf-8") as handle:
        json.dump(summary, handle, indent=2)

    print(f"[Playwright Mobile Report] CSV: {csv_path}")
    print(f"[Playwright Mobile Report] JSON: {json_path}")
    print(json.dumps(summary, indent=2))


if __name__ == "__main__":
    asyncio.run(main())
