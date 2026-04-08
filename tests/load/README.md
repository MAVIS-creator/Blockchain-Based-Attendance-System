Run with `k6`:

```powershell
k6 run tests/load/azure_submit_200.js
```

Optional geofence coordinates:

```powershell
$env:GEO_LAT="6.5244"
$env:GEO_LNG="3.3792"
k6 run tests/load/azure_submit_200.js
```

Optional alternate target:

```powershell
$env:BASE_URL="https://attendancev2app123.azurewebsites.net"
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
