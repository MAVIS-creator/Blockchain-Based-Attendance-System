"""HTTP regression checks against a fresh, disposable PHP server."""
import hashlib
import json
import os
import re
from pathlib import Path
import secrets
import socket
import shutil
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from http.cookiejar import CookieJar
from urllib.request import build_opener, HTTPCookieProcessor, Request, urlopen


def request(base, route, fields=None, method=None, origin=None):
    body = None if fields is None else urlencode(fields).encode()
    try:
        headers = {} if origin is None else {"Origin": origin}
        response = urlopen(Request(base + route, body, headers, method=method), timeout=3)
    except HTTPError as error:
        response = error
    with response:
        body = response.read()
        return response.status, response.headers, json.loads(body) if body else None


directory = Path(tempfile.gettempdir()) / ("bbas-http-" + secrets.token_hex(8))
directory.mkdir()
try:
    storage = Path(directory)
    capability = secrets.token_hex(32)
    tickets = [
        {
            "resolved": True,
            "name": "Synthetic Student",
            "status_capability_hash": hashlib.sha256(capability.encode()).hexdigest(),
            "ai_suggested_admin_action": "private test content",
        },
        {"resolved": False, "fingerprint": "legacy-synthetic", "ip": "127.0.0.1"},
    ]
    (storage / "support_tickets.json").write_text(json.dumps(tickets))
    origin = "https://smart-attendance-samex.me"
    env = os.environ | {"STORAGE_PATH": str(directory), "APP_ENV": "testing", "APP_DEBUG": "false",
                        "BBAS_STATIC_FRONTEND_ORIGIN": origin}
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", 0))
        port = probe.getsockname()[1]
    server = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "-t", "."],
        cwd=Path(__file__).resolve().parents[1], env=env,
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    try:
        base = f"http://127.0.0.1:{port}/"
        route = "ticket_status_api.php"
        for _ in range(20):
            try:
                code, _, _ = request(base, route)
                break
            except URLError:
                time.sleep(0.1)
        else:
            raise AssertionError("Local PHP server did not start")
        assert code == 405
        code, headers, _ = request(base, route, method="OPTIONS", origin=origin)
        assert code == 204 and headers["Access-Control-Allow-Origin"] == origin
        code, headers, _ = request(base, route, method="OPTIONS", origin="https://unapproved.example")
        assert code == 403 and headers["Access-Control-Allow-Origin"] is None
        code, headers, result = request(base, route, {"capability": capability}, origin=origin)
        assert headers["Access-Control-Allow-Origin"] == origin
        assert code == 200 and "no-store" in headers["Cache-Control"]
        assert result == {
            "ticket_found": True, "status": "resolved",
            "message": "Support has reviewed this ticket.",
        }
        for fields in (
            {"capability": secrets.token_hex(32)},
            {"capability[]": capability},
            {"fingerprint": "legacy-synthetic", "ip": "127.0.0.1"},
        ):
            code, headers, result = request(base, route, fields)
            assert code == 404 and not result["ticket_found"]
            assert "no-store" in headers["Cache-Control"]
        browser = build_opener(HTTPCookieProcessor(CookieJar()))
        with browser.open(base + "support.php", timeout=3) as response:
            html = response.read().decode()
        csrf = re.search(r'name="support_csrf_token" value="([^"]+)"', html).group(1)
        fields = {
            "support_csrf_token": csrf, "name": "Synthetic Student",
            "matric": "1234567890", "message": "My attendance submission did not complete.",
            "course": "General", "requested_action": "checkin",
        }
        with browser.open(base + "support.php", urlencode(fields).encode(), timeout=3) as response:
            html = response.read().decode()
        issued = re.search(r'<code class="break-all">([0-9a-f]{64})</code>', html).group(1)
        saved = next(row for path in storage.rglob("support_tickets.json") for row in json.loads(path.read_text()) if row.get("matric") == "1234567890")
        assert saved["status_capability_hash"] == hashlib.sha256(issued.encode()).hexdigest()
        assert issued not in json.dumps(saved)
        code, _, result = request(base, route, {"capability": issued})
        assert code == 200 and result["status"] == "pending_ai_review"
        code, _, context = request(base, "public_context_api.php")
        assert code == 200 and context["announcement"] == {"enabled": False, "message": ""}
    finally:
        server.terminate()
        server.wait(timeout=5)

finally:
    shutil.rmtree(directory)

print("Ticket capability and public context HTTP checks passed")
