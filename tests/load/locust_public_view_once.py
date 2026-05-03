import os
import random
import time
import uuid
from locust import HttpUser, task, constant
from locust.exception import StopUser


MOBILE_USER_AGENTS = [
    "Mozilla/5.0 (iPhone; CPU iPhone OS 17_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3 Mobile/15E148 Safari/604.1",
    "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Mobile Safari/537.36",
    "Mozilla/5.0 (Linux; Android 13; SM-A546B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Mobile Safari/537.36",
]


class PublicViewBurstUser(HttpUser):
    wait_time = constant(0)
    host = os.getenv("LOCUST_HOST", "https://attendancev2app7t5g81ps.azurewebsites.net").strip()
    timeout_duration = 60

    def on_start(self):
        self.client.headers.update({
            "User-Agent": random.choice(MOBILE_USER_AGENTS),
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
            "Accept-Language": "en-US,en;q=0.9",
        })

    @task
    def open_site_once(self):
        fingerprint = f"burst_{uuid.uuid4().hex[:12]}_{int(time.time() * 1000)}"

        self.client.get(
            "/index.php",
            name="GET /index.php",
            timeout=self.timeout_duration,
        )
        self.client.get(
            "/status_api.php",
            name="GET /status_api.php",
            headers={"Accept": "application/json"},
            timeout=self.timeout_duration,
        )
        self.client.get(
            f"/get_announcement.php?fingerprint={fingerprint}",
            name="GET /get_announcement.php",
            headers={"Accept": "application/json"},
            timeout=self.timeout_duration,
        )
        raise StopUser()
