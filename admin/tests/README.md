CSRF test harness

Files:
- csrf_test.php  - PHP CLI script that logs in, extracts CSRF token, and runs POSTs with/without token against endpoints.
- run-tests.ps1  - PowerShell wrapper to run the PHP script on Windows.

Usage:
- From the `admin/tests` folder run:
  php csrf_test.php http://localhost/Blockchain-Based-Attendance-System/admin

- Or on Windows PowerShell:
  .\run-tests.ps1 http://localhost/Blockchain-Based-Attendance-System/admin

Notes:
- The script logs in using the default superadmin credentials (username: Mavis, password: .*123$<>Callmelater.,12) as created by `admin/login.php` on first run.
- Ensure your web server is running and the base URL points to the `admin` folder of this project.
- The script uses a temporary cookie jar to preserve session state between requests.
