$ErrorActionPreference = 'Stop'

$envPath = Join-Path $PSScriptRoot '..\.env'
$envLines = Get-Content -Path $envPath

$user = (($envLines | Where-Object { $_ -match '^ADMIN_DEFAULT_USER=' } | Select-Object -First 1) -replace '^ADMIN_DEFAULT_USER=', '').Trim()
$pass = (($envLines | Where-Object { $_ -match '^ADMIN_DEFAULT_PASSWORD=' } | Select-Object -First 1) -replace '^ADMIN_DEFAULT_PASSWORD=', '').Trim()

if ([string]::IsNullOrWhiteSpace($user) -or [string]::IsNullOrWhiteSpace($pass)) {
  Write-Host 'MISSING_DEFAULT_ADMIN_IN_ENV'
  exit 1
}

$base = 'https://smart-attendance-samex.me/admin'
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

Invoke-WebRequest -Uri "$base/login.php" -Method Get -WebSession $session | Out-Null

try {
  Invoke-WebRequest -Uri "$base/login.php" -Method Post -Body @{ username = $user; password = $pass } -WebSession $session -MaximumRedirection 0 | Out-Null
} catch {
  # Redirect responses can throw in PowerShell; continue and validate below.
}

$index = Invoke-WebRequest -Uri "$base/index.php" -Method Get -WebSession $session -MaximumRedirection 0
if ($index.StatusCode -in 301,302,303,307,308) {
  $loc = [string]$index.Headers['Location']
  if ($loc -match 'login\.php') {
    Write-Host 'LOGIN_FAILED_OR_REDIRECTED_TO_LOGIN'
    exit 1
  }
}

$ok = 0
$fail = 0
$samples = 30
for ($i = 1; $i -le $samples; $i++) {
  try {
    $resp = Invoke-WebRequest -Uri "$base/_last_updates.php" -Method Get -WebSession $session -MaximumRedirection 0
    $text = [string]$resp.Content
    if ($text -match 'unauthenticated') {
      $fail++
    } else {
      $ok++
    }
  } catch {
    $fail++
  }
}

Write-Host ("probe_samples={0} auth_ok={1} auth_fail={2}" -f $samples, $ok, $fail)
if ($fail -gt 0) {
  exit 2
}
