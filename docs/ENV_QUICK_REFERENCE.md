# 🚀 Quick Reference: Environment Deployment

## One-Line Deploy

```powershell
# Deploy code + sync .env
.\tools\deploy-azure.ps1 -SyncEnv

# Or use the batch file
.\deploy.bat
```

## Common Tasks

### 📝 Sync Only (No Code Deploy)
```powershell
.\tools\sync-env-to-azure.ps1
```

### 🔐 Sync Excluding Passwords (Safer)
```powershell
.\tools\sync-env-to-azure.ps1 -SkipSensitive $true
```

### 🔄 Full Deploy: Code + .env + Restart
```powershell
.\tools\deploy-azure.ps1 -SyncEnv -Restart
```

### 💾 View Backups
```powershell
ls ./tools/backups/
```

### ✅ Verify Variables in Azure
```powershell
az webapp config appsettings list `
  --resource-group attendance-app-rgv2 `
  --name attendancev2app123 `
  -o table
```

### 🔄 Restart App
```powershell
az webapp restart `
  --resource-group attendance-app-rgv2 `
  --name attendancev2app123
```

---

## Workflow

### 1️⃣ Update Local .env
```bash
notepad .env
```

### 2️⃣ Deploy (Choose One)
```bash
# Option A: Just deploy code
.\tools\deploy-azure.ps1

# Option B: Code + .env (Recommended)
.\tools\deploy-azure.ps1 -SyncEnv

# Option C: One-click (Windows)
.\deploy.bat
```

### 3️⃣ Monitor
```powershell
# Check app is running
az webapp show -g attendance-app-rgv2 -n attendancev2app123 --query "{state:state,host:defaultHostName}" -o table

# View logs (last 30 lines)
az webapp log tail -g attendance-app-rgv2 -n attendancev2app123 --lines 30
```

---

## Environment Variables

### 🔒 Sensitive (Use Key Vault or -SkipSensitive)
- SMTP_PASS
- SUPABASE_SERVICE_ROLE_KEY
- POLYGON_PRIVATE_KEY
- Any passwords/keys/tokens

### ✅ Safe to Deploy
- APP_ENV
- APP_DEBUG
- APP_URL
- SUPABASE_URL
- Most configuration values

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| ".env not found" | Run from project root: `cd project-dir` |
| "Unauthorized" | `az login` then retry |
| "Settings not updating" | `az webapp restart -g attendance-app-rgv2 -n attendancev2app123` |
| "Variable not visible" | Check spelling matches exactly (case-sensitive) |
| "Want to revert changes" | Restore from backup in `./tools/backups/` |

---

## Files

- 📄 `.env` - Your local config (git-ignored)
- 📄 `.env.example` - Template (git-tracked)
- 📄 `.env.local` - Local overrides (git-ignored)
- 🔧 `tools/deploy-azure.ps1` - Main deploy script
- 🔧 `tools/sync-env-to-azure.ps1` - .env sync tool
- 🔧 `deploy.bat` - One-click Windows launcher
- 📖 `docs/ENVIRONMENT_DEPLOYMENT.md` - Full guide

---

## Security Checklist

- ✅ `.env` is git-ignored
- ✅ `.env.local` is git-ignored
- ✅ Never commit credentials to git
- ✅ Use Key Vault for production secrets
- ✅ Backup created before sync
- ✅ Variables verified after deployment

---

**Pro Tip**: Use `-SyncEnv` flag on all deployments to keep Azure settings in sync with local .env!
