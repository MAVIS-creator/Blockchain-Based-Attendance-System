param(
  [string]$ResourceGroup = 'attendance-app-rgv2',

  [string]$WebAppName = 'attendancev2app123',

  [string]$GitRemote = 'azure',
  [string]$DeployBranch = 'master',
  [switch]$PushMaster,
  [switch]$Restart,
  [switch]$ConfigureBuildFlags,
  [switch]$SyncEnv,
  [switch]$SkipSensitive
)

$ErrorActionPreference = 'Stop'

Write-Host "Checking git working tree..."
$inside = git rev-parse --is-inside-work-tree 2>$null
if ($inside -ne 'true') {
  throw 'Current directory is not a git repository.'
}

Write-Host "Deploying HEAD to $GitRemote/$DeployBranch ..."
git push $GitRemote HEAD:$DeployBranch

if ($PushMaster) {
  Write-Host "Deploying HEAD to $GitRemote/main ..."
  git push $GitRemote HEAD:main
}

if ($ConfigureBuildFlags) {
  Write-Host "Applying App Service build flags (Oryx + deploy build) ..."
  az webapp config appsettings set --resource-group $ResourceGroup --name $WebAppName --settings SCM_DO_BUILD_DURING_DEPLOYMENT=true ENABLE_ORYX_BUILD=true WEBSITE_RUN_FROM_PACKAGE=0 | Out-Null
}

# 🔒 Sync .env to Azure App Service (new feature)
if ($SyncEnv) {
  Write-Host ""
  Write-Host "═══════════════════════════════════════════════════════════" -ForegroundColor Cyan
  Write-Host "🔒 Syncing environment variables from .env to Azure" -ForegroundColor Cyan
  Write-Host "═══════════════════════════════════════════════════════════" -ForegroundColor Cyan
  Write-Host ""
  
  $syncArgs = @(
    '-ResourceGroup', $ResourceGroup,
    '-WebAppName', $WebAppName,
    '-EnvFile', '.\.env'
  )
  
  if ($SkipSensitive) {
    $syncArgs += @('-SkipSensitive', $true)
  }
  
  & ".\tools\sync-env-to-azure.ps1" @syncArgs
}

if ($Restart) {
  Write-Host "Restarting web app..."
  az webapp restart --resource-group $ResourceGroup --name $WebAppName | Out-Null
}

Write-Host ""
Write-Host "Deployment complete." -ForegroundColor Green
az webapp show --resource-group $ResourceGroup --name $WebAppName --query "{state:state,host:defaultHostName}" -o table
