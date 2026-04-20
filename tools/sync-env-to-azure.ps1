param(
    [string]$ResourceGroup = 'attendance-app-rgv2',
    [string]$WebAppName = 'attendancev2app123',
    [string]$EnvFile = '.env',
    [bool]$SkipSensitive = $false,
    [bool]$Backup = $true
)

$ErrorActionPreference = 'Stop'

Write-Host "=======================================" 
Write-Host "Azure App Service .env Sync Tool"
Write-Host "=======================================" 
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
    
    $timestamp = (Get-Date).ToString('yyyyMMdd_HHmmss')
    $backupFile = "$backupDir/azure_appsettings_$timestamp.json"
    
    $currentSettings = az webapp config appsettings list `
        --resource-group $ResourceGroup `
        --name $WebAppName | ConvertFrom-Json
    
    $currentSettings | ConvertTo-Json | Set-Content $backupFile
    Write-Host "Backed up to: $backupFile"
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
        
        Write-Host " OK"
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

Write-Host "Verified: $verified/$($deployVars.Count) settings"
Write-Host ""

# Summary
Write-Host "=======================================" 
Write-Host "SYNC COMPLETE"
Write-Host "=======================================" 
Write-Host ""
Write-Host "Summary:"
Write-Host "  File: $EnvFile"
Write-Host "  App: $WebAppName"
Write-Host "  Deployed: $verified/$($deployVars.Count)"
Write-Host ""

az webapp show --resource-group $ResourceGroup --name $WebAppName --query "{state:state,host:defaultHostName}" -o table
param(
    [string]$ResourceGroup = 'attendance-app-rgv2',
    [string]$WebAppName = 'attendancev2app123',
    [string]$EnvFile = '.env',
    [bool]$SkipSensitive = $false,
    [bool]$Backup = $true
)

$ErrorActionPreference = 'Stop'

Write-Host "=======================================" 
Write-Host "Azure App Service .env Sync Tool"
Write-Host "=======================================" 
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
    
    $timestamp = (Get-Date).ToString('yyyyMMdd_HHmmss')
    $backupFile = "$backupDir/azure_appsettings_$timestamp.json"
    
    $currentSettings = az webapp config appsettings list `
        --resource-group $ResourceGroup `
        --name $WebAppName | ConvertFrom-Json
    
    $currentSettings | ConvertTo-Json | Set-Content $backupFile
    Write-Host "Backed up to: $backupFile"
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
        
        Write-Host " OK"
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

Write-Host "Verified: $verified/$($deployVars.Count) settings"
Write-Host ""

# Summary
Write-Host "=======================================" 
Write-Host "SYNC COMPLETE"
Write-Host "=======================================" 
Write-Host ""
Write-Host "Summary:"
Write-Host "  File: $EnvFile"
Write-Host "  App: $WebAppName"
Write-Host "  Deployed: $verified/$($deployVars.Count)"
Write-Host ""

az webapp show --resource-group $ResourceGroup --name $WebAppName --query "{state:state,host:defaultHostName}" -o table
<#
.SYNOPSIS
    Syncs local .env file to Azure App Service Application Settings securely

.DESCRIPTION
    - Reads .env and .env.local files from project root
    - Parses key-value pairs
    - Updates Azure App Service application settings
    - Skips sensitive values if desired (can use Key Vault instead)
    - Validates deployment
    - Supports backup/restore of previous settings

.PARAMETER ResourceGroup
    Azure resource group name (default: attendance-app-rgv2)

.PARAMETER WebAppName
    Azure App Service name (default: attendancev2app123)

.PARAMETER EnvFile
    Path to .env file (default: .env from current directory)

.PARAMETER SkipSensitive
    If true, skips deploying highly sensitive values (passwords, private keys)
    These should use Key Vault instead

.PARAMETER Backup
    If true, backs up current Azure settings before sync

.EXAMPLE
    .\sync-env-to-azure.ps1
    .\sync-env-to-azure.ps1 -SkipSensitive $true
    .\sync-env-to-azure.ps1 -Backup $true
#>

param(
    [string]$ResourceGroup = 'attendance-app-rgv2',
    [string]$WebAppName = 'attendancev2app123',
    [string]$EnvFile = '.env',
    [bool]$SkipSensitive = $false,
    [bool]$Backup = $true
)

$ErrorActionPreference = 'Stop'

# =====================================================
# Configuration
# =====================================================
$SensitivePatterns = @(
    'PASSWORD',
    'SECRET',
    'KEY',
    'TOKEN',
    'PRIVATE_KEY',
    'API_KEY',
    'ROLE_KEY'
)

Write-Host "===============================================" -ForegroundColor Cyan
Write-Host "🔒 Azure App Service .env Sync Tool" -ForegroundColor Cyan
Write-Host "===============================================" -ForegroundColor Cyan
Write-Host ""

# =====================================================
# Validate Input Files
# =====================================================
Write-Host "📋 Validating environment files..."

if (-not (Test-Path $EnvFile)) {
    Write-Host "❌ ERROR: .env file not found at: $EnvFile" -ForegroundColor Red
    exit 1
}

Write-Host "✅ Found: $EnvFile"

$EnvLocalFile = '.env.local'
$useEnvLocal = Test-Path $EnvLocalFile
if ($useEnvLocal) {
    Write-Host "✅ Found: $EnvLocalFile (will override .env values)"
}

Write-Host ""

# =====================================================
# Parse Environment Files
# =====================================================
Write-Host "📖 Parsing environment variables..."

$envVars = @{}

# First read .env
$lines = @(Get-Content $EnvFile)
foreach ($line in $lines) {
    $line = $line.Trim()
    
    # Skip empty lines and comments
    if ([string]::IsNullOrWhiteSpace($line) -or $line.StartsWith('#')) {
        continue
    }
    
    # Parse KEY=VALUE
    if ($line.Contains('=')) {
        $key, $value = $line -split '=', 2
        $key = $key.Trim()
        $value = $value.Trim()
        
        # Remove surrounding quotes if present
        if (($value.StartsWith('"') -and $value.EndsWith('"')) -or 
            ($value.StartsWith("'") -and $value.EndsWith("'"))) {
            $value = $value.Substring(1, $value.Length - 2)
        }
        
        if (-not [string]::IsNullOrWhiteSpace($key)) {
            $envVars[$key] = $value
        }
    }
}

Write-Host "✅ Parsed $($envVars.Count) variables from .env"

# Override with .env.local if it exists
if ($useEnvLocal) {
    $localLines = @(Get-Content $EnvLocalFile)
    $localCount = 0
    foreach ($line in $localLines) {
        $line = $line.Trim()
        if ([string]::IsNullOrWhiteSpace($line) -or $line.StartsWith('#')) {
            continue
        }
        if ($line.Contains('=')) {
            $key, $value = $line -split '=', 2
            $key = $key.Trim()
            $value = $value.Trim()
            if (($value.StartsWith('"') -and $value.EndsWith('"')) -or 
                ($value.StartsWith("'") -and $value.EndsWith("'"))) {
                $value = $value.Substring(1, $value.Length - 2)
            }
            if (-not [string]::IsNullOrWhiteSpace($key)) {
                $envVars[$key] = $value
                $localCount++
            }
        }
    }
    Write-Host "✅ Overridden $localCount variables from .env.local"
}

Write-Host ""

# =====================================================
# Filter Sensitive Values
# =====================================================
$deployVars = @{}
$skippedVars = @()

foreach ($key in $envVars.Keys) {
    $isSensitive = $false
    foreach ($pattern in $SensitivePatterns) {
        if ($key -like "*$pattern*") {
            $isSensitive = $true
            break
        }
    }
    
    if ($isSensitive -and $SkipSensitive) {
        $skippedVars += $key
    } else {
        $deployVars[$key] = $envVars[$key]
    }
}

if ($skippedVars.Count -gt 0) {
    Write-Host "⚠️  Skipped $($skippedVars.Count) sensitive variables (use Key Vault):" -ForegroundColor Yellow
    foreach ($key in $skippedVars) {
        Write-Host "   • $key" -ForegroundColor Yellow
    }
    Write-Host ""
}

Write-Host "📤 Will deploy $($deployVars.Count) variables to Azure" -ForegroundColor Cyan
Write-Host ""

# =====================================================
# Backup Current Settings (Optional)
# =====================================================
if ($Backup) {
    Write-Host "💾 Backing up current Azure settings..."
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
    Write-Host "✅ Backed up to: $backupFile" -ForegroundColor Green
    Write-Host ""
}

# =====================================================
# Sync to Azure App Service
# =====================================================
Write-Host "🔄 Syncing to Azure App Service..." -ForegroundColor Cyan

$settingsArray = @()
foreach ($key in $deployVars.Keys) {
    $settingsArray += "$key=$($deployVars[$key])"
}

if ($settingsArray.Count -eq 0) {
    Write-Host "⚠️  No variables to deploy!" -ForegroundColor Yellow
    exit 0
}

try {
    Write-Host "   Uploading $($settingsArray.Count) settings..."
    
    # Deploy in batches to avoid rate limiting
    $batchSize = 50
    for ($i = 0; $i -lt $settingsArray.Count; $i += $batchSize) {
        $batch = $settingsArray[$i..([Math]::Min($i + $batchSize - 1, $settingsArray.Count - 1))]
        $batchNum = [Math]::Floor($i / $batchSize) + 1
        $totalBatches = [Math]::Ceiling($settingsArray.Count / $batchSize)
        
        Write-Host "   Batch $batchNum/$totalBatches..." -NoNewline
        
        az webapp config appsettings set `
            --resource-group $ResourceGroup `
            --name $WebAppName `
            --settings $batch | Out-Null
        
        Write-Host " ✅" -ForegroundColor Green
    }
    
    Write-Host "✅ Successfully synced all settings!" -ForegroundColor Green
}
catch {
    Write-Host "❌ Error syncing settings: $_" -ForegroundColor Red
    Write-Host ""
    Write-Host "Troubleshooting:" -ForegroundColor Yellow
    Write-Host "1. Ensure you are logged in to Azure: az login" -ForegroundColor Yellow
    Write-Host "2. Verify resource group and app name are correct" -ForegroundColor Yellow
    Write-Host "3. Check Azure CLI is installed: az --version" -ForegroundColor Yellow
    exit 1
}

Write-Host ""

# =====================================================
# Verification
# =====================================================
Write-Host "✔️  Verifying deployment..." -ForegroundColor Cyan

$uploadedSettings = az webapp config appsettings list `
    --resource-group $ResourceGroup `
    --name $WebAppName | ConvertFrom-Json

$verified = 0
foreach ($key in $deployVars.Keys) {
    $found = $uploadedSettings | Where-Object { $_.name -eq $key }
    if ($found) {
        $verified++
    }
}

Write-Host "✅ Verified $verified/$($deployVars.Count) settings deployed"
Write-Host ""

# =====================================================
# Summary
# =====================================================
Write-Host "===============================================" -ForegroundColor Cyan
Write-Host "✅ DEPLOYMENT COMPLETE" -ForegroundColor Green
Write-Host "===============================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Summary:" -ForegroundColor Green
Write-Host "  📁 .env File: $EnvFile"
Write-Host "  🌐 Azure App: $WebAppName"
Write-Host "  📊 Total Variables: $($envVars.Count)"
Write-Host "  📤 Deployed: $($deployVars.Count)"
if ($skippedVars.Count -gt 0) {
    Write-Host "  ⏭️  Skipped (Sensitive): $($skippedVars.Count)"
}
Write-Host "  🔐 Sensitive Data: Use Azure Key Vault for passwords/keys"
Write-Host ""
Write-Host "Next Steps:" -ForegroundColor Green
Write-Host "  1. Run tests to verify application behavior"
Write-Host "  2. Monitor: az webapp log tail --resource-group $ResourceGroup --name $WebAppName"
Write-Host "  3. For sensitive values, migrate to Key Vault: https://aka.ms/keyvault"
Write-Host ""
Write-Host "⚠️  IMPORTANT:" -ForegroundColor Yellow
Write-Host "  • .env file contains SECRETS - keep it git-ignored!" -ForegroundColor Yellow
Write-Host "  • Never commit .env to version control" -ForegroundColor Yellow
Write-Host "  • Consider using Azure Key Vault for passwords/API keys" -ForegroundColor Yellow
Write-Host ""

az webapp show --resource-group $ResourceGroup --name $WebAppName --query "{state:state,host:defaultHostName}" -o table
