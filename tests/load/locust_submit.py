import logging
import os
import random
import re
import time
import uuid
from locust import HttpUser, task, between
from locust.exception import StopUser


MOBILE_USER_AGENTS = [
    "Mozilla/5.0 (iPhone; CPU iPhone OS 17_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3 Mobile/15E148 Safari/604.1",
    "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Mobile Safari/537.36",
    "Mozilla/5.0 (Linux; Android 13; SM-A546B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Mobile Safari/537.36",
]


class ApiUser(HttpUser):
    wait_time = between(1, 2)
    _configured_host = os.getenv("HOST", "attendancev2app7t5g81ps.azurewebsites.net").strip()
    host = _configured_host if _configured_host.startswith(("http://", "https://")) else f"https://{_configured_host}"
    timeout_duration = 90

    def on_start(self):
        self.enable_logging = os.getenv("ENABLE_LOGGING", "False") == "True"
        self.force_inactive_mode = os.getenv("FORCE_INACTIVE_MODE", "False") == "True"
        logging.basicConfig(level=logging.DEBUG if self.enable_logging else logging.WARNING)
        self.user_agent = random.choice(MOBILE_USER_AGENTS)
        self.form_action = "checkin"
        self.form_course = "General"
        self.attendance_enabled = False
        self.session_id = uuid.uuid4().hex[:10]
        self.client.headers.update({
            "User-Agent": self.user_agent,
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
            "Accept-Language": "en-US,en;q=0.9",
            "Referer": f"{self.host}/",
        })
        self._prime_phone_session()
        self._load_active_mode()

    @task
    def run_scenario(self):
        self.submit_attendance()
        raise StopUser()

    def _prime_phone_session(self):
        with self.client.get("/", name="GET / (phone landing)", catch_response=True, timeout=self.timeout_duration) as response:
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
            response.success()

    def _load_active_mode(self):
        with self.client.get("/status_api.php", name="GET /status_api.php (active mode)", catch_response=True, timeout=self.timeout_duration) as response:
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

            if self.force_inactive_mode:
                self.attendance_enabled = False
            response.success()

    def submit_attendance(self):
        if not self.attendance_enabled:
            return

        name = f"Load Test User {self.session_id}"
        matric = str(random.randint(100000, 9999999999))
        fingerprint = f"{self.session_id}_{uuid.uuid4().hex[:12]}"
        payload = {
            "name": name,
            "matric": matric,
            "fingerprint": fingerprint,
            "action": self.form_action or "checkin",
            "course": self.form_course or "General",
        }
        headers = {
            "Accept": "application/json, text/plain, */*",
            "Origin": self.host,
            "Referer": f"{self.host}/",
            "X-Requested-With": "XMLHttpRequest",
        }

        with self.client.post("/submit.php", data=payload, headers=headers, name="POST /submit.php", catch_response=True, timeout=self.timeout_duration) as response:
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
