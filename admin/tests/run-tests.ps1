# PowerShell wrapper to run the PHP CSRF test harness
# Usage: .\run-tests.ps1 [baseUrl]
# Example: .\run-tests.ps1 http://localhost/Blockchain-Based-Attendance-System/admin

param(
    [string]$Base = "http://localhost/Blockchain-Based-Attendance-System/admin"
)

$php = "php"
$script = Join-Path -Path $PSScriptRoot -ChildPath "csrf_test.php"
if (-not (Test-Path $script)) { Write-Error "Test script not found: $script"; exit 2 }

Write-Host "Running CSRF tests against: $Base"
& $php $script $Base
$rc = $LASTEXITCODE
if ($rc -eq 0) { Write-Host "CSRF tests PASSED." -ForegroundColor Green } else { Write-Host "CSRF tests FAILED (exit code $rc)." -ForegroundColor Red }
exit $rc
