param(
  [Parameter(Mandatory = $true)]
  [string]$ResourceGroup,

  [Parameter(Mandatory = $true)]
  [string]$Location,

  [Parameter(Mandatory = $true)]
  [string]$WebAppName,

  [string]$SqlServerName,
  [string]$SqlDatabaseName = 'attendance_admin',
  [string]$KeyVaultName,

  [string]$SqlAdminUsername = 'sqladminops',
  [PSCredential]$BootstrapCredential,
  [string]$MaximusEmail = 'isholasamuel062@gmail.com'
)

$ErrorActionPreference = 'Stop'

function New-RandomSecret([int]$length = 24) {
  $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%^&*()_+-=[]{}'.ToCharArray()
  $bytes = New-Object byte[] ($length)
  [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
  $secretChars = for ($i = 0; $i -lt $length; $i++) { $chars[$bytes[$i] % $chars.Length] }
  return (-join $secretChars)
}

if ([string]::IsNullOrWhiteSpace($SqlServerName)) {
  $suffix = (Get-Random -Minimum 10000 -Maximum 99999)
  $SqlServerName = "attendancev2sql$suffix"
}

if ([string]::IsNullOrWhiteSpace($KeyVaultName)) {
  $suffix = (Get-Random -Minimum 10000 -Maximum 99999)
  $KeyVaultName = "attendancev2kv$suffix"
}

$sqlAdminPassword = New-RandomSecret
if ($null -eq $BootstrapCredential) {
  $defaultSecure = ConvertTo-SecureString 'Callmelater' -AsPlainText -Force
  $BootstrapCredential = New-Object System.Management.Automation.PSCredential ('bootstrap', $defaultSecure)
}

$bootstrapPasswordPlain = [System.Net.NetworkCredential]::new('', $BootstrapCredential.Password).Password
$sqlServerFqdn = "$SqlServerName.database.windows.net"

Write-Host "[1/7] Creating Azure SQL server: $SqlServerName"
az sql server create --resource-group $ResourceGroup --name $SqlServerName --location $Location --admin-user $SqlAdminUsername --admin-password "$sqlAdminPassword" | Out-Null

Write-Host "[2/7] Creating Azure SQL database: $SqlDatabaseName"
az sql db create --resource-group $ResourceGroup --server $SqlServerName --name $SqlDatabaseName --service-objective Basic | Out-Null

Write-Host "[3/7] Enabling Azure services SQL firewall rule"
az sql server firewall-rule create --resource-group $ResourceGroup --server $SqlServerName --name AllowAzureServices --start-ip-address 0.0.0.0 --end-ip-address 0.0.0.0 | Out-Null

Write-Host "[4/7] Creating Key Vault: $KeyVaultName"
az keyvault create --name $KeyVaultName --resource-group $ResourceGroup --location $Location | Out-Null

Write-Host "[5/7] Storing secrets in Key Vault"
az keyvault secret set --vault-name $KeyVaultName --name admin-sql-username --value $SqlAdminUsername | Out-Null
az keyvault secret set --vault-name $KeyVaultName --name admin-sql-password --value $sqlAdminPassword | Out-Null
az keyvault secret set --vault-name $KeyVaultName --name admin-bootstrap-password --value $bootstrapPasswordPlain | Out-Null

Write-Host "[6/7] Setting app settings with Key Vault references"
$scriptPath = Join-Path $PSScriptRoot 'set-keyvault-appsettings.ps1'
& $scriptPath -ResourceGroup $ResourceGroup -WebAppName $WebAppName -KeyVaultName $KeyVaultName -SqlServerFqdn $sqlServerFqdn -SqlDatabaseName $SqlDatabaseName

Write-Host "[7/7] Completed provisioning"
Write-Host "SQL Server: $SqlServerName"
Write-Host "SQL DB: $SqlDatabaseName"
Write-Host "Key Vault: $KeyVaultName"
Write-Host "Maximus email configured: $MaximusEmail"
Write-Host "Next: deploy app and run SQL seeder script to migrate JSON accounts + ensure Maximus account in SQL." -ForegroundColor Yellow
