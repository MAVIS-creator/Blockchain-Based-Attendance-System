param(
    [string]$ResourceGroup = 'attendance-app-rgv2',
    [string]$WebAppName = 'attendancev2app7t5g81ps',
    [string]$EnvFile = '.env',
    [bool]$SkipSensitive = $false,
    [bool]$Backup = $true
)

$ErrorActionPreference = 'Stop'

Write-Host "=======================================" -ForegroundColor Cyan
Write-Host "Azure App Service .env Sync Tool"
Write-Host "=======================================" -ForegroundColor Cyan
Write-Host ""

# Validate .env file exists
if (-not (Test-Path $EnvFile)) {
    Write-Host "ERROR: $EnvFile not found!" -ForegroundColor Red
    exit 1
}

Write-Host "Found: $EnvFile"
Write-Host ""

# Read .env file
Write-Host "Parsing $EnvFile..."
$envVars = @{}

$content = Get-Content $EnvFile -Raw
$lines = $content -split "`n"

foreach ($line in $lines) {
    $line = $line.Trim()
    
    # Skip empty lines and comments
    if ([string]::IsNullOrEmpty($line) -or $line.StartsWith("#")) {
        continue
    }
    
    # Parse KEY=VALUE
    if ($line -match '(.+?)=(.*)') {
        $key = $matches[1].Trim()
        $value = $matches[2].Trim()
        
        # Remove quotes
        if ($value.StartsWith('"') -and $value.EndsWith('"')) {
            $value = $value.Substring(1, $value.Length - 2)
        }
        elseif ($value.StartsWith("'") -and $value.EndsWith("'")) {
            $value = $value.Substring(1, $value.Length - 2)
        }
        
        if ($key) {
            $envVars[$key] = $value
        }
    }
}

Write-Host "Parsed $($envVars.Count) variables"
Write-Host ""

# Filter sensitive values
$sensitiveKeywords = @('PASSWORD', 'SECRET', 'KEY', 'TOKEN', 'PRIVATE_KEY', 'API_KEY')
$deployVars = @{}
$skippedVars = @()

foreach ($key in $envVars.Keys) {
    $isSensitive = $false
    foreach ($keyword in $sensitiveKeywords) {
        if ($key -like "*$keyword*") {
            $isSensitive = $true
            break
        }
    }
    
    if ($isSensitive -and $SkipSensitive) {
        $skippedVars += $key
    }
    else {
        $deployVars[$key] = $envVars[$key]
    }
}

if ($skippedVars.Count -gt 0) {
    Write-Host "Skipped $($skippedVars.Count) sensitive variables:" -ForegroundColor Yellow
    foreach ($var in $skippedVars) {
        Write-Host "  - $var" -ForegroundColor Yellow
    }
    Write-Host ""
}

# Backup current settings
if ($Backup) {
    Write-Host "Backing up current Azure settings..."
    $backupDir = './tools/backups'
    if (-not (Test-Path $backupDir)) {
        New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
    }
    
    $timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
    $backupFile = "$backupDir/azure_appsettings_$timestamp.json"
    
    $currentSettings = az webapp config appsettings list `
        --resource-group $ResourceGroup `
        --name $WebAppName | ConvertFrom-Json
    
    $currentSettings | ConvertTo-Json | Set-Content $backupFile
    Write-Host "Backed up to: $backupFile" -ForegroundColor Green
    Write-Host ""
}

# Sync to Azure
Write-Host "Syncing $($deployVars.Count) variables to Azure..."
Write-Host ""

if ($deployVars.Count -eq 0) {
    Write-Host "No variables to deploy!"
    exit 0
}

$settingsArray = @()
foreach ($key in $deployVars.Keys) {
    $settingsArray += "$key=$($deployVars[$key])"
}

try {
    # Deploy in batches
    $batchSize = 50
    for ($i = 0; $i -lt $settingsArray.Count; $i += $batchSize) {
        $end = [Math]::Min($i + $batchSize - 1, $settingsArray.Count - 1)
        $batch = $settingsArray[$i..$end]
        
        $batchNum = [Math]::Floor($i / $batchSize) + 1
        $totalBatches = [Math]::Ceiling($settingsArray.Count / $batchSize)
        
        Write-Host "Batch $batchNum/$totalBatches..." -NoNewline
        
        az webapp config appsettings set `
            --resource-group $ResourceGroup `
            --name $WebAppName `
            --settings $batch | Out-Null
        
        Write-Host " OK" -ForegroundColor Green
    }
}
catch {
    Write-Host "ERROR: $_" -ForegroundColor Red
    exit 1
}

Write-Host ""

# Verify
Write-Host "Verifying deployment..."
$uploadedSettings = az webapp config appsettings list `
    --resource-group $ResourceGroup `
    --name $WebAppName | ConvertFrom-Json

$verified = 0
foreach ($key in $deployVars.Keys) {
    if ($uploadedSettings | Where-Object { $_.name -eq $key }) {
        $verified++
    }
}

Write-Host "Verified: $verified/$($deployVars.Count) settings" -ForegroundColor Green
Write-Host ""

# Summary
Write-Host "=======================================" -ForegroundColor Cyan
Write-Host "SYNC COMPLETE"
Write-Host "=======================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Summary:"
Write-Host "  File: $EnvFile"
Write-Host "  App: $WebAppName"
Write-Host "  Deployed: $verified/$($deployVars.Count)"
Write-Host ""

az webapp show --resource-group $ResourceGroup --name $WebAppName --query "{state:state,host:defaultHostName}" -o table

Write-Host ""
Write-Host "Next: Restart the app with:"
Write-Host "  az webapp restart --resource-group $ResourceGroup --name $WebAppName"
Write-Host ""
