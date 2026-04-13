# Azure Deployment Guide - Blockchain Attendance System

## Quick Setup (3 Steps)

### Step 1: Set Azure App Service Startup Command

The startup script must be configured in Azure to run automatically.

**Via Azure Portal:**

1. Go to [portal.azure.com](https://portal.azure.com)
2. Find your App Service: `attendancev2app123`
3. Navigate to **Settings** → **Configuration**
4. Under **General Settings** tab:
   - Find **Startup Command** field
   - Enter: `bash /home/site/wwwroot/startup.sh`
5. Click **Save** and wait for the app to restart

**Via Azure CLI:**

```bash
az webapp config set \
  --name attendancev2app123 \
  --resource-group attendance-app-rgv2 \
  --startup-file "bash /home/site/wwwroot/startup.sh"
```

### Step 2: Commit & Push to Azure

```bash
cd c:\xampp\htdocs\Blockchain-Based-Attendance-System
git add -A
git commit -m "Setup nginx error pages for Azure Linux"
git push azure main
```

### Step 3: Verify Deployment

After push, the startup script runs automatically:

- Nginx is configured
- PHP-FPM starts
- Error pages are mapped (400, 401, 403, 404, 405, 408, 429, 500, 502, 503, 504)

Test error pages:

```
https://smart-attendance-samex.me/nonexistent → Should show 404.php
https://smart-attendance-samex.me/forbidden → Should show 403.php
```

---

## What Gets Installed

- ✅ **Nginx** (web server with custom error page routing)
- ✅ **PHP-FPM** (PHP FastCGI Process Manager)
- ✅ **Error Pages** (individual .php files automatically mapped)
- ✅ **Security Headers** (XSS, clickjacking, MIME type protection)
- ✅ **Protected Paths** (/storage, /vendor, .env, sensitive files)
- ✅ **Static Asset Caching** (images, CSS, JS for 30 days)

---

## Files Involved

| File                                       | Purpose                                        |
| ------------------------------------------ | ---------------------------------------------- |
| `startup.sh`                               | Main initialization script (runs at app start) |
| `deploy.sh`                                | Post-deployment hook                           |
| `nginx-azure.conf`                         | Nginx server configuration (Azure-optimized)   |
| `docs/references/nginx-server-config.conf` | Full reference config                          |
| `*.php` (400, 401, 403, etc.)              | Custom error page templates                    |

---

## Troubleshooting

**Error pages not showing?**

- Check that startup command is set correctly in App Service settings
- View logs: `az webapp log tail --name attendancev2app123 --resource-group attendance-app-rgv2`
- SSH into app: `az webapp create-remote-connection --subscription {subId} --resource-group attendance-app-rgv2 --name attendancev2app123`

**Nginx not starting?**

- Run manually: SSH and execute `bash /home/site/wwwroot/startup.sh`
- Check syntax: `nginx -t`

**PHP-FPM not running?**

- Verify socket is listening: `netstat -an | grep 9000`
- Check PHP-FPM status: `service php-fpm status`

---

## Next Steps After Deployment

1. **Test the application** at: https://smart-attendance-samex.me
2. **Verify error pages work**
3. **Check logs** for any issues
4. **Monitor performance** using Azure Monitor

---

## Security Notes

⚠️ **IMPORTANT:** Before deploying, ensure:

- `.env` file is NOT in git (check `.gitignore`)
- All API keys are rotated (OpenRouter, Gemini, Groq)
- Only source code is pushed, not credentials

See: [Security Deployment Checklist](SECURITY.md)
