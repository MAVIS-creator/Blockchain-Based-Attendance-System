param(
  [string]$ResourceGroup = 'attendance-app-rgv2',

  [string]$WebAppName = 'attendancev2app123',

  [string]$GitRemote = 'azure',
  [string]$DeployBranch = 'master',
  [switch]$PushMaster,
  [switch]$Restart,
  [switch]$ConfigureBuildFlags
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

if ($Restart) {
  Write-Host "Restarting web app..."
  az webapp restart --resource-group $ResourceGroup --name $WebAppName | Out-Null
}

Write-Host "Deployment complete." -ForegroundColor Green
az webapp show --resource-group $ResourceGroup --name $WebAppName --query "{state:state,host:defaultHostName}" -o table
