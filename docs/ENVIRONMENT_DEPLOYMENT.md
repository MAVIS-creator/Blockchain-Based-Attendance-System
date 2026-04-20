# 🔒 Environment Variables Deployment Guide

## Overview

This guide explains how to securely deploy environment variables from your local `.env` file to Azure App Service **without manual copy-paste**.

## The Problem (Before)

❌ **Manual Process:**
```
1. Edit local .env file
2. Manually upload to Azure portal
3. Remember to update all fields
4. Risk of forgetting to update after deployment
5. Secrets visible in portal
```

## The Solution (Now)

✅ **Automated Process:**
```
1. Edit local .env file
2. Run sync script
3. Variables automatically deployed to Azure
4. Backup of previous settings saved
5. Verification report provided
```

---

## How to Use

### Option 1: Full Deployment with .env Sync (Recommended)

```powershell
# Deploy code AND sync .env variables
.\tools\deploy-azure.ps1 -SyncEnv

# Deploy code AND sync .env (skip sensitive values like passwords/API keys)
.\tools\deploy-azure.ps1 -SyncEnv -SkipSensitive
```

### Option 2: Sync Only (No Code Deployment)

```powershell
# Just sync .env without deploying code
.\tools\sync-env-to-azure.ps1

# Sync but skip sensitive values
.\tools\sync-env-to-azure.ps1 -SkipSensitive $true
```

### Option 3: Manual Sync with Custom Settings

```powershell
# Sync specific .env file
.\tools\sync-env-to-azure.ps1 -EnvFile '.\.env.staging'

# Sync without creating backup
.\tools\sync-env-to-azure.ps1 -Backup $false

# Sync to different Azure resource
.\tools\sync-env-to-azure.ps1 -ResourceGroup 'my-rg' -WebAppName 'my-app'
```

---

## How It Works

### 1. **Local .env File** (Git-ignored, stays on your machine)
```bash
SMTP_HOST=smtp.gmail.com
SMTP_USER=your-email@gmail.com
SMTP_PASS=your-app-password        # ← Sensitive, local only
SUPABASE_URL=...
SUPABASE_SERVICE_ROLE_KEY=...      # ← Sensitive, local only
APP_ENV=production
APP_DEBUG=false
```

### 2. **Sync Script**
- Reads your local `.env` file
- Parses key-value pairs
- Optionally filters sensitive values
- Uploads to Azure App Service Settings
- Backs up previous settings

### 3. **Azure App Service Settings**
- Variables become available to your PHP app
- Accessible via `getenv('KEY')` in code
- Can be viewed/edited in Azure portal
- Survives code deployments

### 4. **Fallback: .env.local**
```bash
# .env.local overrides .env values
# Useful for environment-specific settings
# Also git-ignored and local-only
APP_DEBUG=true   # Override production setting for local testing
```

---

## Security Best Practices

### ✅ DO

1. **Keep .env git-ignored** (already configured in `.gitignore`)
   ```bash
   # These are safe from the script
   .env
   .env.local
   .env.*.local
   ```

2. **Use Azure Key Vault for Highly Sensitive Values**
   ```powershell
   # For passwords, API keys, private keys:
   .\tools\sync-env-to-azure.ps1 -SkipSensitive $true
   
   # Then manually add to Key Vault:
   az keyvault secret set --vault-name 'my-vault' --name 'SMTP_PASS' --value 'xxx'
   ```

3. **Backup Settings Before Sync**
   ```powershell
   # Automatic backup enabled by default
   # Stored in: ./tools/backups/azure_appsettings_*.json
   ```

4. **Review Changes**
   ```powershell
   # The script shows what will be deployed:
   # ✅ Parsed 25 variables from .env
   # 📤 Will deploy 25 variables to Azure
   # ⚠️  Skipped 3 sensitive variables
   ```

### ❌ DON'T

1. ❌ Commit `.env` to git
2. ❌ Share `.env` via email or chat
3. ❌ Upload `.env` to cloud storage
4. ❌ Leave plaintext passwords in code
5. ❌ Mix secrets with application code

---

## Sensitive Variables Auto-Detection

The script automatically identifies sensitive variables:

```
PASSWORD  → SMTP_PASS, DATABASE_PASSWORD
SECRET    → API_SECRET, SUPABASE_SERVICE_ROLE_KEY
KEY       → POLYGON_PRIVATE_KEY, ENCRYPTION_KEY
TOKEN     → AUTH_TOKEN, REFRESH_TOKEN
PRIVATE_KEY → Any cryptographic private key
API_KEY   → Third-party API keys
ROLE_KEY  → Service role keys
```

**When using `-SkipSensitive`**, these are NOT deployed to App Service Settings (deploy them to Key Vault instead).

---

## Step-by-Step Example

### 1. Update Local .env
```powershell
# Edit your .env file with new values
notepad .env

# Make changes:
# - Update SMTP credentials
# - Update Supabase keys
# - Update API endpoints
# - etc.
```

### 2. Run Sync
```powershell
# Navigate to project root
cd c:\xampp\htdocs\Blockchain-Based-Attendance-System

# Option A: Include all values (including passwords)
.\tools\sync-env-to-azure.ps1

# Option B: Keep passwords in Key Vault
.\tools\sync-env-to-azure.ps1 -SkipSensitive $true

# Option C: Full deploy with code AND .env
.\tools\deploy-azure.ps1 -SyncEnv
```

### 3. Verify Deployment
The script automatically verifies:
```
✔️  Verifying deployment...
✅ Verified 25/25 settings deployed
```

### 4. Monitor Application
```powershell
# Check if app is working correctly
curl https://attendancev2app123.azurewebsites.net/submit.php

# View logs
az webapp log tail --resource-group attendance-app-rgv2 --name attendancev2app123
```

---

## Backup & Restore

### View Backup Files
```powershell
# List all backups
ls ./tools/backups/

# Backup files are timestamped:
# azure_appsettings_20260420_143025.json
# azure_appsettings_20260420_141530.json
```

### Restore Previous Settings
```powershell
# If something goes wrong, restore from backup
$backupFile = './tools/backups/azure_appsettings_20260420_141530.json'
$settings = Get-Content $backupFile | ConvertFrom-Json
$settingsArray = $settings | ForEach-Object { "$($_.name)=$($_.value)" }

az webapp config appsettings set `
  --resource-group attendance-app-rgv2 `
  --name attendancev2app123 `
  --settings $settingsArray
```

---

## Troubleshooting

### "Cannot find .env file"
```powershell
# Make sure you're in the project root directory
cd c:\xampp\htdocs\Blockchain-Based-Attendance-System
ls .env  # Should show the file
```

### "Unauthorized: Invalid credentials"
```powershell
# Log in to Azure again
az login

# Or login with specific subscription
az login --subscription 'your-subscription-id'
```

### "Resource group or app not found"
```powershell
# Verify resource group and app name
az webapp show --resource-group attendance-app-rgv2 --name attendancev2app123

# Update script parameters if different
.\tools\sync-env-to-azure.ps1 -ResourceGroup 'correct-rg' -WebAppName 'correct-app'
```

### Variables not taking effect immediately
```powershell
# Restart the app service
az webapp restart --resource-group attendance-app-rgv2 --name attendancev2app123

# Or use full deploy with restart
.\tools\deploy-azure.ps1 -SyncEnv -Restart
```

---

## Viewing Deployed Variables

### Via Azure CLI
```powershell
# List all settings
az webapp config appsettings list `
  --resource-group attendance-app-rgv2 `
  --name attendancev2app123 `
  -o table

# Show specific setting
az webapp config appsettings list `
  --resource-group attendance-app-rgv2 `
  --name attendancev2app123 `
  --query "[?name=='SMTP_HOST'].value" -o tsv
```

### Via Azure Portal
1. Open Azure Portal
2. Navigate to App Service > `attendancev2app123`
3. Click "Configuration" → "Application settings"
4. View all synced variables

---

## Integration with Deployment Pipeline

### Full Deployment Workflow
```powershell
# One command that does everything:
# 1. ✅ Push code to Azure
# 2. ✅ Build with Oryx
# 3. ✅ Sync .env variables
# 4. ✅ Restart app
# 5. ✅ Verify deployment

.\tools\deploy-azure.ps1 -SyncEnv -Restart
```

### CI/CD Integration (GitHub Actions)
```yaml
- name: Deploy Code and Sync .env
  run: |
    az login --service-principal -u ${{ secrets.AZURE_CLIENT_ID }} `
      -p ${{ secrets.AZURE_CLIENT_SECRET }} `
      --tenant ${{ secrets.AZURE_TENANT_ID }}
    
    powershell -Command ".\tools\deploy-azure.ps1 -SyncEnv"
```

---

## Migration from Manual Copy-Paste

If you've been manually updating settings:

### Before (Manual)
1. ❌ Log into Azure portal
2. ❌ Find App Service Settings
3. ❌ Copy values from .env one by one
4. ❌ Paste into portal
5. ❌ Risk of forgetting fields
6. ❌ Restart app manually

### After (Automated)
1. ✅ Edit `.env` locally
2. ✅ Run `.\tools\sync-env-to-azure.ps1`
3. ✅ All variables deployed instantly
4. ✅ Backup created automatically
5. ✅ Verification report shown
6. ✅ Optional auto-restart

---

## Next Steps

1. **Update .env locally** with your values
2. **Run first sync**:
   ```powershell
   .\tools\sync-env-to-azure.ps1
   ```
3. **Verify in Azure portal** that variables are there
4. **Test your application** works with new values
5. **Consider Key Vault** for highly sensitive values

---

## Additional Resources

- 📖 [Azure App Service Configuration](https://learn.microsoft.com/en-us/azure/app-service/configure-common)
- 🔐 [Azure Key Vault for Secrets Management](https://learn.microsoft.com/en-us/azure/key-vault/)
- 🔧 [Azure CLI Reference](https://learn.microsoft.com/en-us/cli/azure/)
- 📝 [12-Factor App Environment Variables](https://12factor.net/config)

---

**Created**: April 20, 2026  
**Last Updated**: April 20, 2026
