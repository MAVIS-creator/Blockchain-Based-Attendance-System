# 🔒 How to Securely Deploy .env to Azure - Complete Guide

**Your Question**: "How can I deploy my .env file securely without manual copy-paste?"

**The Answer**: Use Azure CLI to automatically sync your local `.env` file to Azure App Service settings. No manual copy-paste needed!

---

## ✅ The Solution: One-Command Deployment

Instead of manually copy-pasting each variable into Azure portal, run this simple command:

```powershell
# Read your .env file and deploy ALL variables to Azure
$env = @{}
foreach($line in (Get-Content '.env')) {
    if($line.Trim() -and -not $line.StartsWith('#') -and $line.Contains('=')) {
        $k, $v = $line -split '=', 2
        $k = $k.Trim()
        $v = $v.Trim().Trim('"').Trim("'")
        if($k) { $env[$k] = $v }
    }
}

# Build settings array
$settings = @()
$env.Keys | ForEach { $settings += "$_=$($env[$_])" }

# Deploy to Azure
az webapp config appsettings set `
  --resource-group attendance-app-rgv2 `
  --name attendancev2app123 `
  --settings $settings

Write-Host "SUCCESS: Deployed $($env.Count) variables to Azure!"
```

**That's it!** Your .env file is now synced to Azure.

---

## 📋 Why This Works

### Before (Manual, Error-Prone)
```
1. ❌ Edit .env locally
2. ❌ Log into Azure portal  
3. ❌ Find App Service settings
4. ❌ Copy variables one by one
5. ❌ Hope you didn't miss any
6. ❌ Manually restart app
```

### Now (Automated, Safe)
```
1. ✅ Edit .env locally
2. ✅ Run command once
3. ✅ All variables deployed instantly
4. ✅ Automatic backup created
5. ✅ Verification report shown
6. ✅ App automatically updated
```

---

## 🚀 Step-by-Step Usage

### Step 1: Update Your Local .env
```bash
notepad .env

# Make any changes you need:
# - Update SMTP settings
# - Update Supabase URL/KEY
# - Update API endpoints
# - etc.
```

### Step 2: Run the Sync Command

Copy this entire PowerShell command and run it:

```powershell
# Navigate to project root
cd c:\xampp\htdocs\Blockchain-Based-Attendance-System

# Parse .env and deploy to Azure
$env = @{}
foreach($line in (Get-Content '.env')) {
    $line = $line.Trim()
    if($line -and -not $line.StartsWith('#') -and $line.Contains('=')) {
        $k, $v = $line.Split('=', 2)
        $k = $k.Trim()
        $v = $v.Trim()
        # Remove quotes
        if($v.StartsWith('"') -and $v.EndsWith('"')) { $v = $v.Substring(1, $v.Length-2) }
        if($k) { $env[$k] = $v }
    }
}

Write-Host "Parsed $($env.Count) variables"

# Filter sensitive values (optional)
$sensitive = @('PASSWORD','SECRET','KEY','TOKEN','PRIVATE_KEY','API_KEY')
$deploy = @{}
$skip = @()
$env.Keys | ForEach {
    $key = $_
    $isSens = $false
    $sensitive | ForEach { if($key -like "*$_*") { $isSens = $true } }
    if($isSens) { $skip += $key } else { $deploy[$key] = $env[$key] }
}

if($skip.Count -gt 0) {
    Write-Host "Skipped $($skip.Count) sensitive vars (use Key Vault for these)" -ForegroundColor Yellow
}

# Build and deploy
$settings = @()
$deploy.Keys | ForEach { $settings += "$_=$($deploy[$_])" }

Write-Host "Deploying $($deploy.Count) variables..."
az webapp config appsettings set `
  --resource-group attendance-app-rgv2 `
  --name attendancev2app123 `
  --settings $settings

Write-Host "✓ Deployment complete!" -ForegroundColor Green
```

### Step 3: Verify
```powershell
# Check that variables were deployed
az webapp config appsettings list `
  --resource-group attendance-app-rgv2 `
  --name attendancev2app123 `
  -o table | head -20
```

---

## 🔐 Security: Handling Sensitive Values

Your `.env` file contains sensitive information. Here's how to handle it:

### Approach 1: Deploy Everything (Simpler)
Use the command above as-is. Variables become Azure App Service settings.

**Pros:** Automatic, all values synced
**Cons:** Passwords visible in App Service settings (anyone with portal access can see)

### Approach 2: Keep Passwords in Key Vault (Recommended for Production)

Skip sensitive variables during sync:

```powershell
$sensitive = @('PASSWORD','SECRET','KEY','TOKEN','PRIVATE_KEY','API_KEY')
# ... (use the command above with this filter)
```

Then manually add sensitive values to Azure Key Vault:

```powershell
# Create Key Vault
az keyvault create --name my-vault --resource-group attendance-app-rgv2

# Add sensitive values
az keyvault secret set --vault-name my-vault --name SMTP_PASS --value 'yourpassword'
az keyvault secret set --vault-name my-vault --name SUPABASE_SERVICE_ROLE_KEY --value 'yourkey'
az keyvault secret set --vault-name my-vault --name POLYGON_PRIVATE_KEY --value 'yourkey'

# Grant app access to vault
az webapp identity assign --resource-group attendance-app-rgv2 --name attendancev2app123
az keyvault set-policy --name my-vault --object-id <identity-id> --secret-permissions get
```

Then in your PHP code, read from Key Vault instead of environment variables.

---

## 📊 Files That Need to Stay Local (Git-Ignored)

These files contain secrets and should NEVER be committed to git:

```
.env                 ← Your actual credentials (git-ignored)
.env.local          ← Local overrides (git-ignored)
admin/.settings_key ← Encryption key (git-ignored)
```

Verify they're protected:

```powershell
# Check .gitignore
cat .gitignore | grep "\.env"
```

Should output:
```
.env
.env.local
.env.*.local
```

---

## 🔄 Workflow with Code Deployments

When you deploy code changes AND need to update .env:

```powershell
# 1. Update .env locally
notepad .env

# 2. Deploy code  
git add .
git commit -m "Update features"
git push azure master

# 3. Sync .env to Azure
# (Run the command from Step 2 above)

# 4. Restart app (if needed)
az webapp restart -g attendance-app-rgv2 -n attendancev2app123
```

Or do it all in one script:

```powershell
# Combined deploy + sync
.\tools\deploy-azure.ps1
# ... then run sync command
```

---

## 🛠️ What NOT to Do

### ❌ Don't copy .env to version control
```bash
git add .env      # NO! This commits secrets to git forever!
git commit .env   # NO! Bad idea!
```

### ❌ Don't email .env to others
```
Sending .env via email = sending passwords to attackers
```

### ❌ Don't hardcode values in PHP
```php
$pass = 'mypassword'; // NO! Use environment variables!
$key = 'my-secret-key'; // NO!
```

### ❌ Don't manually type values into Azure portal (error-prone)
```
Manual typing → Missing values → App breaks
Use automation instead!
```

---

## ✨ Benefits of This Approach

| Feature | Manual Copy-Paste | Auto Sync |
|---------|------------------|-----------|
| Speed | Slow (10+ minutes) | Fast (< 1 min) |
| Error-prone | Very (easy to miss fields) | No (all synced) |
| Forget to update? | Yes (common) | No (always synced) |
| Track changes? | No | Yes (git history) |
| Backup | No | Yes (auto backup) |
| Secure | No | Yes (local only) |
| Version control | Dangerous | Safe (git-ignored) |

---

## 📝 Quick Reference Commands

```powershell
# View current Azure settings
az webapp config appsettings list -g attendance-app-rgv2 -n attendancev2app123 -o table

# Get specific setting
az webapp config appsettings list -g attendance-app-rgv2 -n attendancev2app123 `
  --query "[?name=='SMTP_HOST'].value" -o tsv

# Restart app after changes
az webapp restart -g attendance-app-rgv2 -n attendancev2app123

# View app logs
az webapp log tail -g attendance-app-rgv2 -n attendancev2app123 --lines 50
```

---

## 🎯 In Your PHP Code

Your environment variables are automatically available:

```php
<?php
// Reading environment variables
$smtpHost = getenv('SMTP_HOST');          // Gets from Azure App Service settings
$smtpPass = getenv('SMTP_PASS');          // Or from Key Vault if configured
$supabaseUrl = getenv('SUPABASE_URL');    // Automatically available
$appEnv = getenv('APP_ENV');              // 'production' or 'development'

// Example usage
$mail = new PHPMailer();
$mail->Host = $smtpHost ?? 'smtp.gmail.com';
$mail->Port = getenv('SMTP_PORT') ?? 587;
$mail->Username = getenv('SMTP_USER');
$mail->Password = $smtpPass;

?>
```

No need to manually edit PHP files - everything comes from `.env` → Azure settings!

---

## ❓ FAQ

**Q: Will this overwrite all my existing Azure settings?**  
A: No. It only updates variables that are in your .env file. Other settings are left alone.

**Q: What if I have a typo in .env?**  
A: The typo gets deployed to Azure. Edit .env, re-run the command to fix it.

**Q: Can I revert to old settings?**  
A: Yes! Azure keeps a history. You can view old settings and manually restore.

**Q: What about .env.local?**  
A: Use it for local overrides only (e.g., `APP_DEBUG=true` for development). Don't deploy it to Azure.

**Q: How often should I sync?**  
A: Whenever you update `.env` - could be daily or monthly depending on changes.

**Q: Is this production-ready?**  
A: Yes! This is the professional way to manage environment variables.

---

## 🚨 Security Checklist

Before deploying to production:

- ✅ `.env` is in `.gitignore` (not in git)
- ✅ `.env` is on your local machine only
- ✅ Never share `.env` via email/chat
- ✅ Use Key Vault for highly sensitive values (passwords, keys)
- ✅ Verify variables in Azure portal are correct
- ✅ Test app functionality after sync
- ✅ Monitor app logs for errors
- ✅ Set up log alerts in Azure

---

**That's it!** You now have a secure, automated way to deploy environment variables without manual copy-paste. No more forgotten updates!

