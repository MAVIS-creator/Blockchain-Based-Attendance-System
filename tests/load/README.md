Run with `k6`:

```powershell
k6 run tests/load/azure_submit_200.js
```

This script is for the write path: opening the form, discovering the current hidden `action` and `course`, then posting to `submit.php`.

Run the public page-view burst test:

```powershell
k6 run tests/load/public_view_burst.js
```

This script is for the read path students hit most often: `index.php`, `status_api.php`, and `get_announcement.php`.

Run the same read-path test with `locust` instead of `k6`:

```powershell
python -m locust -f locust_public_view.py --headless -H https://smart-attendance-samex.me -u 150 -r 20 --run-time 2m
```

Run the write-path test with `locust`:

```powershell
$env:HOST="https://smart-attendance-samex.me"
python run-load-test.py
```

Optional geofence coordinates:

```powershell
$env:GEO_LAT="6.5244"
$env:GEO_LNG="3.3792"
k6 run tests/load/azure_submit_200.js
```

Optional alternate target:

```powershell
$env:BASE_URL="https://attendancev2app7t5g81ps.azurewebsites.net"
k6 run tests/load/azure_submit_200.js
```

Important:

- This script generates unique `name`, `matric`, and `fingerprint` values.
- It fetches `index.php` first to discover the current hidden `action` and `course`.
- If your app enforces IP/device/geofence rules, many requests may be rejected for business-rule reasons rather than server capacity.
- To test raw capacity fairly, temporarily disable or relax:
  - geo-fence
  - device/IP anti-duplicate limits
  - strict one-device-per-day rules

Recommended order:

1. Run `public_view_burst.js` first to measure how the site behaves when many students only open the page.
2. Run `azure_submit_200.js` second to measure check-in/check-out submission pressure.
3. If the read path is slow but submit is fine, focus on `index.php`, `status_api.php`, `get_announcement.php`, and polling intervals.

If `k6` is not installed:

- Use `locust_public_view.py` for page-view load.
- Use `run-load-test.py` or `locustfile.py` for attendance submissions.
