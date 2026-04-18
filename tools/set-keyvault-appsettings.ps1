param(
  [Parameter(Mandatory = $true)]
  [string]$ResourceGroup,

  [Parameter(Mandatory = $true)]
  [string]$WebAppName,

  [Parameter(Mandatory = $true)]
  [string]$KeyVaultName,

  [Parameter(Mandatory = $true)]
  [string]$SqlServerFqdn,

  [Parameter(Mandatory = $true)]
  [string]$SqlDatabaseName,

  [string]$SqlUsernameSecretId = 'admin-sql-username',
  [string]$SqlCredentialSecretId = 'admin-sql-password',
  [string]$BootstrapSecretId = 'admin-bootstrap-password'
)

$ErrorActionPreference = 'Stop'

function KvRef([string]$vault, [string]$secretName) {
  return "@Microsoft.KeyVault(SecretUri=https://$vault.vault.azure.net/secrets/$secretName/)"
}

Write-Host "[1/4] Ensuring system-assigned managed identity is enabled on $WebAppName ..."
az webapp identity assign --resource-group $ResourceGroup --name $WebAppName | Out-Null

$principalId = az webapp identity show --resource-group $ResourceGroup --name $WebAppName --query principalId -o tsv
if ([string]::IsNullOrWhiteSpace($principalId)) {
  throw 'Unable to resolve web app managed identity principalId.'
}

Write-Host "[2/4] Granting Key Vault secret read access to web app identity (RBAC) ..."
$vaultId = az keyvault show --name $KeyVaultName --query id -o tsv
if ([string]::IsNullOrWhiteSpace($vaultId)) {
  throw 'Unable to resolve Key Vault resource id.'
}

try {
  az role assignment create --assignee-object-id $principalId --assignee-principal-type ServicePrincipal --role "Key Vault Secrets User" --scope $vaultId | Out-Null
} catch {
  Write-Host "Role assignment may already exist or require propagation time; continuing..." -ForegroundColor Yellow
}

Write-Host "[3/4] Writing App Settings with Key Vault references ..."
$settings = @{
  APP_ENV = 'production'
  APP_DEBUG = 'false'
  ADMIN_ACCOUNTS_BACKEND = 'sql'
  ADMIN_SQL_SERVER = $SqlServerFqdn
  ADMIN_SQL_DATABASE = $SqlDatabaseName
  ADMIN_SQL_USERNAME = (KvRef $KeyVaultName $SqlUsernameSecretId)
  ADMIN_SQL_PASSWORD = (KvRef $KeyVaultName $SqlCredentialSecretId)
  ADMIN_BOOTSTRAP_USERNAME = 'Maximus'
  ADMIN_BOOTSTRAP_EMAIL = 'isholasamuel062@gmail.com'
  ADMIN_BOOTSTRAP_PASSWORD = (KvRef $KeyVaultName $BootstrapSecretId)
  ADMIN_DEFAULT_PASSWORD_HASH = ''
  ADMIN_DEFAULT_PASSWORD = ''
}

$tempJson = Join-Path $env:TEMP "appsettings-$WebAppName.json"
$settings | ConvertTo-Json -Compress | Set-Content -Path $tempJson -Encoding utf8
az webapp config appsettings set --resource-group $ResourceGroup --name $WebAppName --settings "@$tempJson" | Out-Null
Remove-Item $tempJson -Force -ErrorAction SilentlyContinue

Write-Host "[4/4] Restarting web app ..."
az webapp restart --resource-group $ResourceGroup --name $WebAppName | Out-Null

Write-Host "Done. Key Vault references are now in App Settings for $WebAppName." -ForegroundColor Green
