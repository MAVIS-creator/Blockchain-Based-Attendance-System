@echo off
REM Simple .env to Azure App Service sync script
REM Usage: sync-env.bat

setlocal enabledelayedexpansion

if not exist ".env" (
    echo ERROR: .env file not found!
    exit /b 1
)

echo Syncing .env to Azure App Service...
echo.

powershell -NoProfile -Command "
`$env = @{}
foreach(`$line in @(Get-Content '.env')) {
    `$line = `$line.Trim()
    if([string]::IsNullOrEmpty(`$line) -or `$line.StartsWith('#')) { continue }
    if(`$line -match '(.+?)=(.*)') {
        `$k = `$matches[1].Trim()
        `$v = `$matches[2].Trim()
        if(`$v.StartsWith('\"')) { `$v = `$v.Substring(1, `$v.Length-2) }
        if(`$k) { `$env[`$k] = `$v }
    }
}
Write-Host ('Parsed ' + `$env.Count + ' variables')

`$skipSens = '%1' -eq '--skip-sensitive'
`$deploy = @{}
`$skip = @()
`$sens = @('PASSWORD','SECRET','KEY','TOKEN','PRIVATE_KEY','API_KEY')
foreach(`$k in `$env.Keys) {
    `$isSens = (`$sens | Where { `$k -like \"*`$_*\" }).Count -gt 0
    if(`$isSens -and `$skipSens) { `$skip += `$k } else { `$deploy[`$k] = `$env[`$k] }
}

if(`$skip.Count -gt 0) { Write-Host ('Skipped ' + `$skip.Count + ' sensitive vars') }
Write-Host ('Deploying ' + `$deploy.Count + ' vars...')

`$settings = @()
foreach(`$k in `$deploy.Keys) { `$settings += `$k + '=' + `$deploy[`$k] }

az webapp config appsettings set -g attendance-app-rgv2 -n attendancev2app123 --settings `$settings
Write-Host 'Sync complete!'
"

pause
