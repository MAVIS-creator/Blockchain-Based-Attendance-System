import logging
import os
import random
import re
import time
import uuid

from locust import HttpUser, task, between


MOBILE_USER_AGENTS = [
    "Mozilla/5.0 (iPhone; CPU iPhone OS 17_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3 Mobile/15E148 Safari/604.1",
    "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Mobile Safari/537.36",
    "Mozilla/5.0 (Linux; Android 13; SM-A546B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Mobile Safari/537.36",
]


class SustainedSubmitUser(HttpUser):
    wait_time = between(2, 5)
    _configured_host = os.getenv("HOST", "attendancev2app7t5g81ps.azurewebsites.net").strip()
    host = _configured_host if _configured_host.startswith(("http://", "https://")) else f"https://{_configured_host}"
    timeout_duration = 90

    def on_start(self):
        self.enable_logging = os.getenv("ENABLE_LOGGING", "False") == "True"
        logging.basicConfig(level=logging.DEBUG if self.enable_logging else logging.WARNING)
        self.user_agent = random.choice(MOBILE_USER_AGENTS)
        self.form_action = "checkin"
        self.form_course = "General"
        self.attendance_enabled = False
        self.last_status_refresh = 0.0
        self.client.headers.update({
            "User-Agent": self.user_agent,
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
            "Accept-Language": "en-US,en;q=0.9",
            "Referer": f"{self.host}/",
        })
        self.refresh_active_mode(force=True)

    def refresh_active_mode(self, force=False):
        now = time.time()
        if not force and (now - self.last_status_refresh) < 20:
            return

        with self.client.get("/status_api.php", name="GET /status_api.php (submit sustained)", catch_response=True, timeout=self.timeout_duration) as response:
            if response.status_code != 200:
                response.failure(f"status_api.php failed with status {response.status_code}")
                return
            try:
                payload = response.json()
            except Exception as exc:
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

            self.last_status_refresh = now
            response.success()

    def prime_course_and_action(self):
        with self.client.get("/index.php", name="GET /index.php (submit sustained)", catch_response=True, timeout=self.timeout_duration) as response:
            if response.status_code not in (200, 302):
                response.failure(f"index.php failed with status {response.status_code}")
                return False

            body = response.text or ""
            action_match = re.search(r'name="action"\s+value="([^"]*)"', body)
            course_match = re.search(r'name="course"\s+value="([^"]*)"', body)
            if action_match and action_match.group(1):
                self.form_action = action_match.group(1).strip().lower()
            if course_match and course_match.group(1):
                self.form_course = course_match.group(1).strip() or "General"
            response.success()
            return True

    @task
    def submit_attendance_journey(self):
        self.refresh_active_mode()

        if not self.attendance_enabled:
            with self.client.get("/attendance_closed.php", name="GET /attendance_closed.php (submit sustained fallback)", timeout=self.timeout_duration):
                pass
            return

        if not self.prime_course_and_action():
            return

        session_id = uuid.uuid4().hex[:10]
        payload = {
            "name": f"Sustained Load User {session_id}",
            "matric": str(random.randint(100000, 9999999999)),
            "fingerprint": f"{session_id}_{uuid.uuid4().hex[:12]}",
            "action": self.form_action or "checkin",
            "course": self.form_course or "General",
        }
        headers = {
            "Accept": "application/json, text/plain, */*",
            "Origin": self.host,
            "Referer": f"{self.host}/index.php",
            "X-Requested-With": "XMLHttpRequest",
        }

        with self.client.post("/submit.php", data=payload, headers=headers, name="POST /submit.php (sustained)", catch_response=True, timeout=self.timeout_duration) as response:
            if response.status_code != 200:
                response.failure(f"submit.php failed with status {response.status_code}")
                return
            try:
                body = response.json()
            except Exception as exc:
                response.failure(f"submit.php returned invalid JSON: {exc}")
                return
            if body.get("ok"):
                response.success()
            else:
                response.failure(body.get("message") or "Attendance submission was rejected")
