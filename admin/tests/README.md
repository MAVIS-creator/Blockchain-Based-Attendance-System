# CSRF test harness

Use this only on a local or dedicated test deployment. It makes write requests.

```powershell
$env:BBAS_TEST_ADMIN_USER = 'test-admin'
$env:BBAS_TEST_ADMIN_PASSWORD = 'replace-with-test-only-password'
php admin/tests/csrf_test.php http://localhost/Blockchain-Based-Attendance-System/admin
```

No default admin credentials are provided. Use a disposable test account.
Do not run the harness against the live website.
