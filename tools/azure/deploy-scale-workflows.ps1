$ErrorActionPreference = 'Stop'

$subscriptionId = '94c4ded6-910a-418f-b124-91a3a3d6bb2d'
$resourceGroup = 'attendance-app-rgv2'

az account set --subscription $subscriptionId | Out-Null

$morningDefinition = Join-Path $PSScriptRoot 'scale-morning-p2v3-definition.json'
$alertDefinition = Join-Path $PSScriptRoot 'scale-alert-handler-definition.json'

az logic workflow update --resource-group $resourceGroup --name scale-morning-b1 --definition $morningDefinition --output json
az logic workflow update --resource-group $resourceGroup --name scale-alert-handler --definition $alertDefinition --output json

Write-Host 'Updated scale-morning-b1 to S3-at-7AM and scale-alert-handler to avoid downgrading standard/premium plans.'
Write-Host 'scale-evening-f1 remains the 4PM B1 downscale workflow.'