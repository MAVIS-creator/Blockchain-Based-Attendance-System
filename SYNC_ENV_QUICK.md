# 🚀 Copy-Paste: Deploy .env to Azure in One Command

**Just copy and paste this command into PowerShell. Done!**

---

## Quick Start (Copy This)

```powershell
cd c:\xampp\htdocs\Blockchain-Based-Attendance-System; $env=@{}; foreach($line in (Get-Content '.env')) { if($line.Trim() -and -not $line.StartsWith('#') -and $line.Contains('=')) { $k,$v=$line.Split('=',2); $k=$k.Trim(); $v=$v.Trim().Trim('"').Trim("'"); if($k) { $env[$k]=$v } } }; $settings=@(); $env.Keys | ForEach { $settings+="$_=$($env[$_])" }; Write-Host "Deploying $($env.Count) variables..."; az webapp config appsettings set --resource-group attendance-app-rgv2 --name attendancev2app123 --settings $settings; Write-Host "Done!"
```

That's it! Your `.env` is now synced to Azure.

---

## Verification (Check It Worked)

```powershell
az webapp config appsettings list -g attendance-app-rgv2 -n attendancev2app123 -o table | head -15
```

Should show all your variables from `.env`.

---

## If Passwords Worry You

Use this version that skips sensitive values:

```powershell
cd c:\xampp\htdocs\Blockchain-Based-Attendance-System; $env=@{}; foreach($line in (Get-Content '.env')) { if($line.Trim() -and -not $line.StartsWith('#') -and $line.Contains('=')) { $k,$v=$line.Split('=',2); $k=$k.Trim(); $v=$v.Trim().Trim('"').Trim("'"); if($k) { $env[$k]=$v } } }; $sens=@('PASSWORD','SECRET','KEY','TOKEN','PRIVATE_KEY','API_KEY'); $deploy=@{}; $skip=@(); $env.Keys | ForEach { $k=$_; $s=$false; $sens | ForEach { if($k -like "*$_*") { $s=$true } }; if($s) { $skip+=$k } else { $deploy[$k]=$env[$k] } }; if($skip) { Write-Host "Skipping: $($skip -join ', ')" -ForegroundColor Yellow }; $settings=@(); $deploy.Keys | ForEach { $settings+="$_=$($deploy[$_])" }; Write-Host "Deploying $($deploy.Count) variables..."; az webapp config appsettings set --resource-group attendance-app-rgv2 --name attendancev2app123 --settings $settings; Write-Host "Done!"
```

---

## Easier Version (Save as a File)

Save this as `sync-env.ps1`:

```powershell
# Load .env
$env = @{}
foreach($line in (Get-Content '.env')) {
    if($line.Trim() -and -not $line.StartsWith('#') -and $line.Contains('=')) {
        $k, $v = $line.Split('=', 2)
        $k = $k.Trim()
        $v = $v.Trim().Trim('"').Trim("'")
        if($k) { $env[$k] = $v }
    }
}

# Deploy
$settings = @()
$env.Keys | ForEach { $settings += "$_=$($env[$_])" }

Write-Host "Syncing $($env.Count) variables..."
az webapp config appsettings set `
  --resource-group attendance-app-rgv2 `
  --name attendancev2app123 `
  --settings $settings

Write-Host "SUCCESS: All variables synced to Azure!"
```

Then run:
```powershell
cd c:\xampp\htdocs\Blockchain-Based-Attendance-System
.\sync-env.ps1
```

---

## One-Time Setup

Make sure you have:

1. **Azure CLI installed**
   ```powershell
   az --version
   ```
   If not installed: https://aka.ms/cli

2. **Logged into Azure**
   ```powershell
   az login
   ```

3. **.env file in your project root**
   ```powershell
   Test-Path '.env'  # Should return True
   ```

---

## That's It!

No manual copy-paste needed anymore. Just run the command, and all your `.env` variables are deployed to Azure instantly!

**Never forget to update `.env` again.** 🎉
