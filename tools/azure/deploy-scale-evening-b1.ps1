$ErrorActionPreference = 'Stop'

$subscriptionId = '94c4ded6-910a-418f-b124-91a3a3d6bb2d'
$resourceGroup = 'attendance-app-rgv2'
$template = Join-Path $PSScriptRoot 'scale-evening-b1-workflow.template.json'
$deploymentName = 'update-scale-evening-b1-' + (Get-Date -Format 'yyyyMMddHHmmss')

az account set --subscription $subscriptionId | Out-Null

az deployment group create --resource-group $resourceGroup --name $deploymentName --template-file $template --output json

$wfUrl = "https://management.azure.com/subscriptions/$subscriptionId/resourceGroups/$resourceGroup/providers/Microsoft.Logic/workflows/scale-evening-f1?api-version=2019-05-01"
az rest --method GET --url $wfUrl --output json | ConvertFrom-Json | Select-Object id, name, location, @{n='state';e={$_.properties.state}}
