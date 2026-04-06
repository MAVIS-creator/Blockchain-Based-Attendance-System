$ErrorActionPreference = 'Stop'

$subscriptionId = '94c4ded6-910a-418f-b124-91a3a3d6bb2d'
$resourceGroup = 'attendance-app-rgv2'
$workflowName = 'scale-evening-f1'
$apiVersion = '2019-05-01'
$bodyPath = Join-Path $PSScriptRoot 'scale-evening-b1-patch-body.json'

az account set --subscription $subscriptionId | Out-Null


$url = "https://management.azure.com/subscriptions/$subscriptionId/resourceGroups/$resourceGroup/providers/Microsoft.Logic/workflows/$workflowName?api-version=$apiVersion"

az rest --method PATCH --url $url --headers "Content-Type=application/json" --body "@$bodyPath" --output json

az rest --method GET --url $url --output json | ConvertFrom-Json | Select-Object id, name, location, @{n = 'state'; e = { $_.properties.state } }
