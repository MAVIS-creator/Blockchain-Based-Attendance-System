# Quick Start Guide - Blockchain-Based Attendance System

Get up and running in 5 minutes! 🚀

## Installation Options

### Option 1: Composer (Recommended)

```bash
composer create-project mavis-creator/blockchain-attendance-system my-attendance
cd my-attendance
```

### Option 2: Manual Download

```bash
git clone https://github.com/MAVIS-creator/Blockchain-Based-Attendance-System.git
cd Blockchain-Based-Attendance-System
composer install
```

## Initial Setup (3 Steps)

### Step 1: Configure Environment

```bash
# Copy the example environment file
cp .env.example .env

# Edit .env with your settings
nano .env  # or use any text editor
```

**Minimum required settings:**
```env
# SMTP (required for email features)
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your-email@gmail.com
SMTP_PASS=your-app-password
SMTP_SECURE=tls
FROM_EMAIL=no-reply@example.com
FROM_NAME=Attendance System
```

### Step 2: Start the Server

**Option A - PHP Built-in Server:**
```bash
php -S localhost:8000
```

**Option B - XAMPP/WAMP:**
- Move folder to `htdocs` (XAMPP) or `www` (WAMP)
- Start Apache
- Visit `http://localhost/blockchain-attendance-system/`

### Step 3: Create First Admin

1. Visit: `http://localhost:8000/admin/`
2. First-time setup will prompt you to create admin account
3. Login with your credentials

## Quick Configuration

### Enable Email Sending

1. Go to **Admin → Settings → Email & Auto-send**
2. SMTP details are auto-loaded from `.env` (read-only)
3. Edit **From Name** and **Auto-send Recipient**
4. Click **Save Email Settings**

### Create Your First Course

1. Go to **Admin → Courses → Add Course**
2. Enter:
   - Course Code: `CS101`
   - Course Title: `Introduction to Computing`
   - Description: `Basic computer science course`
3. Click **Create Course**
4. Click **Set Active** to enable attendance for this course

### Enable Check-in

1. Go to **Admin → Status Control**
2. Click **Enable Check-in**
3. Set countdown timer (e.g., 2 hours)
4. Students can now check in!

## Student Usage

Students visit: `http://localhost:8000/`

1. Enter **Name**
2. Enter **Matric Number**
3. Enter **Reason** (if required)
4. Click **Check-in** or **Check-out**

## Admin Features Overview

### Dashboard
- Recent attendance statistics
- Quick actions
- System health monitoring

### View Logs
- **Admin → Logs → View Logs** - See all attendance records
- **Admin → Logs → Failed Attempts** - See blocked attempts
- Filter by date, course, or search

### Export Logs

**Manual Export:**
1. Go to **Admin → Send Logs via Email**
2. Select log files or date+course groups
3. Choose fields to include
4. Select format (CSV/PDF)
5. Enter recipient email
6. Click **Create & Send Selected**

**Scheduled Export:**
```bash
# Windows Task Scheduler
Program: C:\xampp\php\php.exe
Arguments: C:\path\to\admin\auto_send_logs.php
Schedule: Daily at 11:59 PM

# Linux Cron
59 23 * * * /usr/bin/php /path/to/admin/auto_send_logs.php
```

### Manage Students

**Unlink Fingerprint:**
- **Admin → Unlink Fingerprint**
- Enter student's matric number
- Click **Unlink** (allows re-registration)

**Revoke Access:**
- **Admin → Revoked Tokens**
- Enter student's token/fingerprint
- Click **Revoke** (blocks student completely)

### Backup & Restore

**Create Backup:**
1. **Admin → Logs → Backup/Restore**
2. Click **Create Backup**
3. Download ZIP file

**Restore:**
1. **Admin → Logs → Backup/Restore**
2. Choose backup ZIP file
3. Click **Upload & Restore**

### Blockchain Verification

1. **Admin → Blockchain → Validate Chain**
2. System checks all attendance entries
3. Reports any tampering detected
4. Use **Fix Chain** if issues found

## Common Tasks

### Change Admin Password
1. **Admin → Profile Settings**
2. Enter current password
3. Enter new password
4. Click **Update Password**

### Post Announcement
1. **Admin → Announcements**
2. Enter title and message
3. Click **Post Announcement**
4. Students see it on landing page

### View Support Tickets
1. **Admin → Support Tickets**
2. View student messages
3. Mark as resolved when done

### Manual Attendance Entry
1. **Admin → Manual Attendance**
2. Enter student details
3. Select action (check-in/check-out)
4. Click **Submit**

## Troubleshooting

### Email Not Sending?

**Check:**
```bash
# In .env file
SMTP_HOST=smtp.gmail.com  # Correct host?
SMTP_PORT=587             # Correct port?
SMTP_USER=your@email.com  # Valid email?
SMTP_PASS=apppassword     # App password (not regular password)?
```

**Gmail Users:**
1. Enable 2-Factor Authentication
2. Generate App Password: https://myaccount.google.com/apppasswords
3. Use app password in `.env`

### Students Can't Check-in?

**Verify:**
1. Check-in is enabled (Admin → Status)
2. Active course is set (Admin → Courses)
3. Time window is correct (Admin → Settings)
4. Student hasn't already checked in today

### Fingerprint Issues?

**Solution:**
1. **Admin → Unlink Fingerprint**
2. Enter student's matric
3. Student can register new device

### Blockchain Validation Failed?

**Fix:**
1. **Admin → Blockchain → Validate Chain**
2. Note the broken block index
3. Click **Fix Chain** to repair
4. Create backup before fixing!

## Security Checklist

- [ ] `.env` file is NOT in version control (check `.gitignore`)
- [ ] Strong admin passwords (12+ characters)
- [ ] HTTPS enabled (production only)
- [ ] Regular backups scheduled
- [ ] Audit logs reviewed weekly
- [ ] File permissions set correctly:
  ```bash
  chmod 600 .env
  chmod 600 admin/.settings_key
  chmod 700 admin/logs
  chmod 700 secure_logs
  ```

## Production Deployment

### Before Going Live:

1. **Update .env:**
```env
APP_ENV=production
APP_DEBUG=false
```

2. **Enable HTTPS:**
- Get SSL certificate (Let's Encrypt, Cloudflare, etc.)
- Update `.htaccess` or Nginx config to force HTTPS

3. **Secure Files:**
```bash
# Set restrictive permissions
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;
chmod 600 .env
chmod 600 admin/.settings_key
```

4. **Disable Unnecessary Features:**
- Remove test files
- Disable debug mode
- Remove development dependencies:
  ```bash
  composer install --no-dev --optimize-autoloader
  ```

5. **Setup Backups:**
- Schedule daily automated backups
- Test restoration process
- Store backups off-site

## Getting Help

- 📖 **Full Documentation:** [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md)
- 🐛 **Report Issues:** [GitHub Issues](https://github.com/MAVIS-creator/Blockchain-Based-Attendance-System/issues)
- 📧 **Email Support:** mavisenquires@gmail.com
- 💬 **Discussions:** [GitHub Discussions](https://github.com/MAVIS-creator/Blockchain-Based-Attendance-System/discussions)

## Next Steps

1. ✅ Configure `.env` file
2. ✅ Create admin account
3. ✅ Create courses
4. ✅ Enable check-in
5. ✅ Test with a student check-in
6. ✅ Export logs via email
7. ✅ Create your first backup

## Quick Reference Commands

```bash
# Install/Update dependencies
composer install
composer update

# Validate configuration
composer validate

# Run tests
composer test

# Create backup (manual)
# Visit: Admin → Logs → Backup/Restore → Create Backup

# Send logs (manual)
# Visit: Admin → Send Logs via Email

# Check composer.json is valid
composer validate --no-check-all --strict
```

## Default Ports & URLs

- **Student Portal:** `http://localhost:8000/`
- **Admin Panel:** `http://localhost:8000/admin/`
- **API Endpoints:**
  - `/submit.php` - Attendance submission
  - `/get_announcement.php` - Fetch announcements
  - `/verify_chain.php` - Verify blockchain
  - `/support.php` - Submit support ticket

---

**🎉 You're all set! Happy attendance tracking!**

For detailed documentation, see [INSTALL.md](INSTALL.md) and [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md)
