import os
import logging
import uuid
import random
import re
import csv
import json
import atexit
import statistics
import threading
import time
from pathlib import Path
from locust import HttpUser, task, between
from locust.exception import StopUser
from dotenv import load_dotenv

# Load environment variables from .env file if present
load_dotenv()


REPORT_DIR = Path(os.getenv("REPORT_DIR", "load-test-reports"))
REPORT_DIR.mkdir(parents=True, exist_ok=True)
REPORT_STAMP = os.getenv("WAVE_ID") or time.strftime("%Y%m%d-%H%M%S")
REPORT_CSV = REPORT_DIR / f"attendance_timing_report_{REPORT_STAMP}.csv"
REPORT_JSON = REPORT_DIR / f"attendance_timing_summary_{REPORT_STAMP}.json"

_report_lock = threading.Lock()
_timing_rows = []


MOBILE_USER_AGENTS = [
    "Mozilla/5.0 (iPhone; CPU iPhone OS 17_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3 Mobile/15E148 Safari/604.1",
    "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Mobile Safari/537.36",
    "Mozilla/5.0 (Linux; Android 13; SM-A546B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Mobile Safari/537.36",
]


def _record_timing(step_name, duration_ms, status, detail=""):
    row = {
        "timestamp": time.strftime("%Y-%m-%d %H:%M:%S"),
        "step": step_name,
        "duration_ms": round(duration_ms, 2),
        "status": status,
        "detail": detail,
    }
    with _report_lock:
        _timing_rows.append(row)


def _write_report():
    with _report_lock:
        rows = list(_timing_rows)

    if not rows:
        return

    with REPORT_CSV.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=["timestamp", "step", "duration_ms", "status", "detail"])
        writer.writeheader()
        writer.writerows(rows)

    by_step = {}
    for row in rows:
        by_step.setdefault(row["step"], []).append(float(row["duration_ms"]))

    summary = {
        "report_csv": str(REPORT_CSV),
        "total_events": len(rows),
        "steps": {},
    }
    for step_name, values in by_step.items():
        summary["steps"][step_name] = {
            "count": len(values),
            "avg_ms": round(statistics.mean(values), 2),
            "min_ms": round(min(values), 2),
            "max_ms": round(max(values), 2),
            "median_ms": round(statistics.median(values), 2),
            "p90_ms": round(statistics.quantiles(values, n=10)[8], 2) if len(values) > 1 else round(values[0], 2),
        }

    with REPORT_JSON.open("w", encoding="utf-8") as handle:
        json.dump(summary, handle, indent=2)

    print(f"[Locust Report] CSV written to: {REPORT_CSV}")
    print(f"[Locust Report] Summary written to: {REPORT_JSON}")


atexit.register(_write_report)


class ApiUser(HttpUser):
    wait_time = between(1, 2)
    _configured_host = os.getenv("HOST", "attendancev2app123.azurewebsites.net").strip()
    host = _configured_host if _configured_host.startswith(("http://", "https://")) else f"https://{_configured_host}"
    timeout_duration = 90  # seconds

    def on_start(self):
        self.enable_logging = os.getenv("ENABLE_LOGGING", "True") == "True"
        if self.enable_logging:
            logging.basicConfig(level=logging.DEBUG)
        else:
            logging.basicConfig(level=logging.WARNING)
        self.user_agent = random.choice(MOBILE_USER_AGENTS)
        self.form_action = "checkin"
        self.form_course = "General"
        self.attendance_enabled = False
        self.session_id = uuid.uuid4().hex[:10]
        self.client.headers.update({
            "User-Agent": self.user_agent,
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
            "Accept-Language": "en-US,en;q=0.9",
            "Referer": f"https://{self.host}/",
        })
        self._prime_phone_session()
        self._load_active_mode()

    @task
    def run_scenario(self):
        self.submit_attendance()
        raise StopUser()

    def _prime_phone_session(self):
        start = time.perf_counter()
        with self.client.get(
            url="/",
            name="GET / (phone landing)",
            catch_response=True,
            timeout=self.timeout_duration,
        ) as response:
            if response.status_code != 200:
                response.failure(f"Landing page failed with status {response.status_code}")
                return

            body = response.text or ""
            action_match = re.search(r'name="action"\s+value="([^"]*)"', body)
            course_match = re.search(r'name="course"\s+value="([^"]*)"', body)

            if action_match and action_match.group(1):
                self.form_action = action_match.group(1).strip().lower()
            if course_match and course_match.group(1):
                self.form_course = course_match.group(1).strip() or "General"

            duration_ms = (time.perf_counter() - start) * 1000.0
            _record_timing("landing_page", duration_ms, "ok", f"action={self.form_action}; course={self.form_course}")
            response.success()

    def _load_active_mode(self):
        start = time.perf_counter()
        with self.client.get(
            url="/status_api.php",
            name="GET /status_api.php (active mode)",
            catch_response=True,
            timeout=self.timeout_duration,
        ) as response:
            if response.status_code != 200:
                _record_timing("status_api", (time.perf_counter() - start) * 1000.0, "error", f"status code {response.status_code}")
                response.failure(f"status_api.php failed with status {response.status_code}")
                return

            try:
                payload = response.json()
            except Exception as exc:
                _record_timing("status_api", (time.perf_counter() - start) * 1000.0, "error", f"invalid json: {exc}")
                response.failure(f"status_api.php returned invalid JSON: {exc}")
                return

            if payload.get("checkin"):
                self.form_action = "checkin"
                self.attendance_enabled = True
            elif payload.get("checkout"):
                self.form_action = "checkout"
                self.attendance_enabled = True
            else:
                self.attendance_enabled = False

            duration_ms = (time.perf_counter() - start) * 1000.0
            _record_timing("status_api", duration_ms, "ok", f"action={self.form_action}; enabled={self.attendance_enabled}")
            response.success()

    def submit_attendance(self):
        """
        POST /submit.php
        Simulates a mobile browser session loading the form and submitting one attendance record.
        """
        wave_label = "wave-1"
        name = f"Load Test User {self.session_id}"
        matric = str(random.randint(100000, 9999999999))
        fingerprint = f"{self.session_id}_{uuid.uuid4().hex[:12]}"
        scenario_start = time.perf_counter()

        if not self.attendance_enabled:
            _record_timing(wave_label, 0.0, "skipped", "attendance is not currently active")
            return

        payload = {
            "name": name,
            "matric": matric,
            "fingerprint": fingerprint,
            "action": self.form_action or "checkin",
            "course": self.form_course or "General",
        }
        headers = {
            "Accept": "application/json, text/plain, */*",
            "Origin": f"https://{self.host}",
            "Referer": f"https://{self.host}/",
            "X-Requested-With": "XMLHttpRequest",
        }

        if self.enable_logging:
            print(f"[Locust] Sending POST /submit.php for {name}")

        request_start = time.perf_counter()

        with self.client.post(
            url="/submit.php",
            data=payload,
            headers=headers,
            name=f"POST /submit.php ({wave_label})",
            catch_response=True,
            timeout=self.timeout_duration
        ) as response:
            if response.status_code != 200:
                msg = f"submit.php failed with status {response.status_code}, response: {response.text}"
                _record_timing(wave_label, (time.perf_counter() - request_start) * 1000.0, "error", msg)
                response.failure(msg)
                if self.enable_logging:
                    logging.error(msg)
                return

            try:
                body = response.json()
            except Exception as exc:
                _record_timing(wave_label, (time.perf_counter() - request_start) * 1000.0, "error", f"invalid json: {exc}")
                response.failure(f"submit.php returned invalid JSON: {exc}")
                return

            if body.get("ok"):
                _record_timing(wave_label, (time.perf_counter() - request_start) * 1000.0, "ok", body.get("message", "ok"))
                _record_timing("end_to_end", (time.perf_counter() - scenario_start) * 1000.0, "ok", f"{name};{wave_label}")
                response.success()
            else:
                msg = body.get("message") or "Attendance submission was rejected"
                _record_timing(wave_label, (time.perf_counter() - request_start) * 1000.0, "error", msg)
                response.failure(msg)
                if self.enable_logging:
                    logging.error(msg)

    def on_stop(self):
        pass

# To run:
# locust -f locustfile.py -u 10 -r 2 --run-time 1m