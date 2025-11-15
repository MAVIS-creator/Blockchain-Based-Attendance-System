# 📁 Blockchain-Based Attendance System - Complete Project Structure

> **Detailed documentation of all working files and their purposes**  
> Last Updated: November 15, 2025

---

## 🗂️ Root Directory

```
Blockchain-Based-Attendance-System/
│
├── 📄 index.php                    # Main student landing page - check-in/check-out interface
├── 📄 submit.php                   # Handles attendance submission (check-in/check-out) with blockchain integration
├── 📄 closed.php                   # Display page when attendance system is closed
├── 📄 attendance_closed.php        # Alternative closed state handler
├── 📄 support.php                  # Student support ticket submission page
├── 📄 get_announcement.php         # API endpoint to fetch active announcements
├── 📄 verify_chain.php             # Blockchain verification endpoint - checks chain integrity
├── 📄 polygon_hash.php             # Polygon blockchain integration for decentralized hash storage
├── 📄 fix_chain.php                # Utility to repair broken blockchain chains
├── 📄 log_inactivity.php           # Logs user inactivity for security monitoring
│
├── 📄 .env                         # Environment configuration (SMTP, database, API keys)
├── 📄 composer.json                # PHP dependencies (PHPMailer, Web3.php, Dompdf)
├── 📄 composer.lock                # Locked dependency versions
├── 📄 README.md                    # Project overview and setup instructions
├── 📄 CONTRIBUTORS.md              # List of project contributors
├── 📄 LICENSE                      # MIT License
├── 📄 .gitignore                   # Git ignore rules
│
├── 📄 autosave.bat                 # Windows batch script for auto-saving changes
├── 📄 autosave-watcher.ps1         # PowerShell script for file watching
├── 📄 open_with_autosave.bat       # Launch project with auto-save enabled
│
├── 📄 status.json                  # Current system status (check-in/out enabled, countdown)
├── 📄 settings.json                # System-wide settings and configurations
├── 📄 active_course.json           # Currently active course for attendance
├── 📄 active_courses.json          # List of all active courses
├── 📄 announcement.json            # Active announcements for students
├── 📄 invalid_attempts.log         # Root-level invalid attempt logs
│
├── 📁 admin/                       # Admin panel and management system
├── 📁 asset/                       # Static assets (images, icons, manifests)
├── 📁 js/                          # JavaScript libraries
├── 📁 secure_logs/                 # Blockchain-secured attendance chain
├── 📁 vendor/                      # Composer dependencies (auto-generated)
└── 📁 .vscode/                     # VS Code workspace settings
```

---

## 🔐 Admin Directory (`admin/`)

### **Entry Points & Authentication**

```
admin/
├── 📄 index.php                    # Admin dashboard router - main entry point
├── 📄 login.php                    # Admin login with CSRF protection
├── 📄 logout.php                   # Session termination
├── 📄 admin.php                    # Legacy admin interface
├── 📄 admin1.php                   # Alternative admin dashboard
├── 📄 dashboard.php                # Modern admin dashboard with statistics
```

**Purpose:**
- `index.php` - Routes to different admin sections based on query parameters
- `login.php` - Handles authentication, session management, and brute-force protection
- `dashboard.php` - Shows attendance statistics, recent logs, and system health

---

### **User & Account Management**

```
admin/
├── 📄 accounts.php                 # Manage admin accounts (create, edit, delete, role management)
├── 📄 profile_settings.php         # Current admin's profile and password change
├── 📄 accounts.json                # Admin account storage (hashed passwords, roles)
```

**Purpose:**
- Create super-admin and regular admin accounts
- Role-based access control (superadmin can manage all settings)
- Password changes with re-authentication

---

### **Attendance & Log Management**

```
admin/logs/
├── 📄 logs.php                     # View valid attendance logs with filtering
├── 📄 failed_attempts.php          # View failed check-in/out attempts
├── 📄 export.php                   # Export logs to CSV/PDF with custom filters
├── 📄 export_simple.php            # Simple CSV export
├── 📄 export_failed.php            # Export failed attempts
├── 📄 export_simple_failed_attempts.php  # Simple failed export
├── 📄 log.css                      # Styling for log pages
│
├── 📄 YYYY-MM-DD.log              # Daily attendance logs (pipe-delimited)
├── 📄 YYYY-MM-DD_failed_attempts.log  # Daily failed attempts
└── 📄 inactivity_log.txt          # User inactivity tracking
```

**Log File Format (pipe-delimited):**
```
Name | Matric | Action | Token | IP | Status | Datetime | User-Agent | Course | Reason
```

**Purpose:**
- Real-time log viewing with pagination and search
- Export attendance to CSV/PDF with field selection
- Track failed login/attendance attempts for security

---

### **Email & Log Sending**

```
admin/
├── 📄 send_logs_email.php          # Manual log sending with file/group selection
├── 📄 auto_send_logs.php           # Automated scheduled log sending script
```

**Features:**
- **Multi-file selection:** Choose specific log files or date+course groups
- **Field customization:** Select which columns to include (name, matric, datetime, etc.)
- **Format options:** CSV or PDF export
- **Filtering:** Date/time range, course filter, success/failed only
- **SMTP from .env:** Uses environment variables for email configuration
- **Group overview:** Shows attendance grouped by date and course

**Usage:**
```bash
# Schedule auto_send_logs.php with Task Scheduler (Windows) or cron (Linux)
# Example: Run daily at 11:59 PM
php admin/auto_send_logs.php
```

---

### **Settings & Configuration**

```
admin/
├── 📄 settings.php                 # System settings (SMTP, enforcement rules, security)
├── 📄 settings.json                # Stored settings (can be encrypted)
├── 📄 settings_templates.json      # Saved settings templates
├── 📄 .settings_key                # Encryption key for settings (auto-generated)
```

**Settings Categories:**
1. **Device Matching:** Prefer MAC or IP for student identification
2. **Max Admins:** Limit admin account creation
3. **Attendance Enforcement:**
   - Require fingerprint match
   - Require reason keywords
   - Check-in time window (HH:MM to HH:MM)
   - One device per student per day
4. **Network & Security:**
   - IP whitelist
   - Encrypted settings storage
   - Device cooldown (prevent spam)
   - Geo-fencing (latitude, longitude, radius)
   - User-agent locking
5. **SMTP (from .env):**
   - Host, Port, User, Password, Security (TLS/SSL)
   - From Email (read-only from .env)
   - From Name (editable)
6. **Auto-send Logs:**
   - Recipient email
   - Default format (CSV/PDF)

**Environment Variables (.env):**
```bash
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your-email@gmail.com
SMTP_PASS=your-app-password
SMTP_SECURE=tls
FROM_EMAIL=no-reply@example.com
FROM_NAME=Attendance System
AUTO_SEND_RECIPIENT=dean@university.edu
```

---

### **Course Management**

```
admin/courses/
├── 📄 add.php                      # Create new courses
├── 📄 set_active.php               # Set active course for attendance
├── 📄 course.json                  # All courses database
└── 📄 active_course.json           # Currently active course
```

**Purpose:**
- Create courses with code, title, and description
- Set which course is currently accepting attendance
- Edit/delete existing courses

---

### **Status Control**

```
admin/
├── 📄 status.php                   # Enable/disable check-in and check-out
├── 📄 status.json                  # Current system status
└── 📄 timeout.php                  # Session timeout handler
```

**Status JSON Format:**
```json
{
  "checkin": true,
  "checkout": false,
  "end_time": "2025-11-15 23:59:59"
}
```

---

### **Blockchain & Chain Management**

```
admin/
├── 📄 chain.php                    # View blockchain chain details
├── 📄 validate_chain.php           # Validate chain integrity
└── 📄 fix_chain.php                # Repair broken chains
```

**Blockchain Structure:**
Each attendance entry is hashed with the previous hash, creating an immutable chain.

```json
{
  "index": 123,
  "timestamp": "2025-11-15T14:30:00Z",
  "data": {
    "name": "John Doe",
    "matric": "CS/2021/001",
    "action": "check-in"
  },
  "previous_hash": "abc123...",
  "hash": "def456..."
}
```

---

### **Backup & Restore**

```
admin/
├── 📄 backup_logs.php              # Create ZIP backup of logs, fingerprints, chain
├── 📄 restore_logs.php             # Restore from backup ZIP
├── 📄 clear_logs.php               # Delete logs/backups/chain (DANGEROUS)
├── 📄 clear_logs_ui.php            # UI for backup/restore/clear operations
└── 📁 backups/                     # Backup storage directory
```

**Backup Contents:**
- `admin/logs/` (all .log files)
- `admin/backups/` (previous backups)
- `admin/fingerprints.json` (device fingerprints)
- `secure_logs/attendance_chain.json` (blockchain data)

---

### **Fingerprint & Device Management**

```
admin/
├── 📄 unlink_fingerprint.php       # Remove device fingerprint from student
├── 📄 clear_device.php             # Clear device tracking
└── 📄 fingerprint_audit.log        # Fingerprint modification audit trail
```

**Purpose:**
- Students can only check-in from one registered device
- Admin can unlink fingerprints if student changes device
- Audit trail tracks all fingerprint changes

---

### **Manual Attendance**

```
admin/
├── 📄 manual_attendance.php        # Manually add attendance entry (bypass automation)
```

**Use Cases:**
- Student forgot to check-in
- System was down during class
- Retroactive attendance correction

---

### **Revoked Tokens & Security**

```
admin/
├── 📄 revoked_tokens.php           # View revoked student tokens
├── 📄 revoke_entry.php             # Revoke attendance entry
├── 📄 revoke_sse.php               # Server-Sent Events for real-time revocation
├── 📄 revoked.json                 # Revoked tokens storage
└── 📄 clear_tokens_ui.php          # UI for clearing/revoking tokens
```

**Purpose:**
- Block specific student devices from checking in
- Real-time token revocation with SSE
- Prevent abuse or unauthorized access

---

### **Announcements**

```
admin/
├── 📄 announcement.php             # Create/edit/delete announcements
└── 📄 announcement.json            # Announcement storage
```

**Announcement Format:**
```json
{
  "title": "Class Cancelled",
  "message": "Due to public holiday...",
  "date": "2025-11-15",
  "active": true
}
```

---

### **Support Tickets**

```
admin/
├── 📄 view_tickets.php             # View and respond to student support tickets
└── 📄 support_tickets.json         # Ticket storage
```

**Ticket Workflow:**
1. Student submits ticket via `support.php`
2. Admin views in `view_tickets.php`
3. Admin can mark as resolved or respond

---

### **Chat System (Admin-to-Admin)**

```
admin/
├── 📄 chat_post.php                # Post chat message
├── 📄 chat_fetch.php               # Fetch chat messages
├── 📄 chat_delete.php              # Delete chat message
└── 📄 chat.json                    # Chat message storage
```

**Purpose:**
- Internal communication between admins
- Coordinate attendance management

---

### **Audit & Monitoring**

```
admin/
├── 📄 audit.php                    # System audit logs viewer
├── 📄 _last_updates.php            # Last update timestamps
└── 📄 fingerprint_audit.log        # Fingerprint changes audit
```

---

### **Export Utilities**

```
admin/
└── 📄 export.php                   # Advanced export with filters and formats
```

**Export Features:**
- Date range filtering
- Course filtering
- Success/failed filtering
- Column selection
- CSV or PDF output
- Email delivery

---

### **Includes & Shared Components**

```
admin/includes/
├── 📄 header.php                   # Common header with navigation
├── 📄 footer.php                   # Common footer
├── 📄 sidebar.php                  # Admin sidebar navigation
├── 📄 csrf.php                     # CSRF token generation and validation
└── 📄 get_mac.php                  # MAC address extraction utility
```

**CSRF Protection:**
All POST requests require a valid CSRF token to prevent cross-site request forgery attacks.

---

### **Styling & Assets**

```
admin/
├── 📄 style.css                    # Main admin styles
├── 📄 admin-theme.css              # Color theme and layout
├── 📄 swal-theme.css               # SweetAlert2 customization
├── 📄 boxicons.min.css             # Icon font
└── 📄 local-icons.css              # Custom icon styles
```

---

### **Testing**

```
admin/tests/
├── 📄 csrf_test.php                # CSRF protection test suite
├── 📄 run-tests.ps1                # PowerShell test runner
└── 📄 README.md                    # Testing documentation
```

---

## 🔒 Secure Logs Directory (`secure_logs/`)

```
secure_logs/
└── 📄 attendance_chain.json        # Blockchain-secured attendance history
```

**Purpose:**
- Immutable attendance record
- Each entry is cryptographically linked to previous entry
- Tampering detection through hash validation

---

## 🎨 Assets Directory (`asset/`)

```
asset/
├── 📄 banner.png                   # Project banner image
├── 📄 favicon.ico                  # Browser favicon
├── 📄 logo.png                     # System logo
└── 📄 site.webmanifest             # PWA manifest
```

---

## 📜 JavaScript Directory (`js/`)

```
js/
└── 📄 fp.min.js                    # FingerprintJS library for device fingerprinting
```

**Purpose:**
- Generate unique device fingerprints
- Prevent multi-device check-ins
- Track device changes

---

## 📦 Vendor Directory (`vendor/`)

**Auto-generated by Composer - DO NOT edit manually**

Key dependencies:
- `phpmailer/phpmailer` - Email sending
- `dompdf/dompdf` - PDF generation
- `web3p/web3.php` - Polygon blockchain integration

---

## 🔑 Key Configuration Files

### **1. .env (Environment Variables)**
```bash
# SMTP Configuration
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your-email@gmail.com
SMTP_PASS=your-app-password
SMTP_SECURE=tls
FROM_EMAIL=no-reply@example.com
FROM_NAME=Attendance System

# Auto-send recipient
AUTO_SEND_RECIPIENT=admin@university.edu

# Polygon Blockchain (optional)
POLYGON_RPC_URL=https://polygon-rpc.com
POLYGON_CONTRACT_ADDRESS=0x...
POLYGON_PRIVATE_KEY=your-private-key
```

### **2. status.json (System Status)**
```json
{
  "checkin": true,
  "checkout": false,
  "end_time": "2025-11-15 23:59:59"
}
```

### **3. settings.json (System Settings)**
```json
{
  "prefer_mac": true,
  "max_admins": 5,
  "require_fingerprint_match": false,
  "checkin_time_start": "08:00",
  "checkin_time_end": "10:00",
  "smtp": {
    "host": "smtp.gmail.com",
    "port": 587,
    "user": "email@example.com",
    "from_name": "Attendance System"
  },
  "auto_send": {
    "enabled": false,
    "recipient": "admin@university.edu",
    "format": "csv"
  }
}
```

---

## 🔄 Data Flow

### **Student Check-in Flow:**
```
1. Student visits index.php
2. Fills name, matric, reason (if required)
3. Submits to submit.php
4. System validates:
   - Status enabled?
   - Within time window?
   - Device fingerprint matches?
   - Not duplicate?
5. Creates log entry in admin/logs/YYYY-MM-DD.log
6. Adds to blockchain chain in secure_logs/attendance_chain.json
7. (Optional) Sends hash to Polygon blockchain
8. Returns success/error JSON
```

### **Admin Log Export Flow:**
```
1. Admin visits send_logs_email.php
2. Selects files or date+course groups
3. Sets filters (date range, course, success/failed)
4. Chooses columns (name, matric, datetime, etc.)
5. Selects format (CSV/PDF)
6. Enters recipient email
7. System generates export
8. Sends via PHPMailer using .env SMTP
9. Returns success with download link
```

---

## 🛡️ Security Features

### **1. CSRF Protection**
All POST requests require valid CSRF tokens generated via `admin/includes/csrf.php`

### **2. Session Management**
- Secure session cookies (HttpOnly, SameSite)
- Session timeout after inactivity
- Re-authentication for critical actions

### **3. Input Validation**
- All user inputs sanitized with `filter_var()` and `trim()`
- Email validation
- Matric number format checking
- IP address validation

### **4. Blockchain Integrity**
- Each attendance entry hashed with SHA-256
- Previous hash included in current hash
- Chain validation detects tampering

### **5. Device Fingerprinting**
- Prevents multi-device check-ins
- Tracks device changes
- Audit trail for fingerprint modifications

### **6. IP Logging**
- All actions logged with IP address
- IP whitelisting support
- Duplicate IP detection per day

---

## 📊 Database Schema (JSON-based)

### **accounts.json**
```json
{
  "admin1": {
    "password": "$2y$10$hashed...",
    "role": "superadmin",
    "email": "admin@example.com",
    "created": "2025-11-15"
  }
}
```

### **course.json**
```json
[
  {
    "id": 1,
    "code": "CS101",
    "title": "Introduction to Computing",
    "description": "Basics of computer science"
  }
]
```

### **fingerprints.json**
```json
{
  "CS/2021/001": {
    "fingerprint": "abc123def456...",
    "linked_at": "2025-11-15T10:00:00Z",
    "ip": "192.168.1.100"
  }
}
```

---

## 🚀 Quick Start Guide

### **For Students:**
1. Visit `http://localhost/Blockchain-Based-Attendance-System/`
2. Enter name, matric number, reason (if required)
3. Click Check-in or Check-out
4. Receive confirmation

### **For Admins:**
1. Visit `http://localhost/Blockchain-Based-Attendance-System/admin/`
2. Login with credentials
3. Dashboard shows recent activity
4. Manage settings, courses, logs from sidebar

### **Setup Email Sending:**
1. Create `.env` file in root directory
2. Add SMTP credentials (see .env section above)
3. Go to Admin > Settings > Email & Auto-send
4. Edit "From name" and "Recipient email"
5. Visit `admin/send_logs_email.php` to send logs manually

### **Schedule Auto-send (Windows Task Scheduler):**
```batch
Program: C:\xampp\php\php.exe
Arguments: C:\xampp\htdocs\Blockchain-Based-Attendance-System\admin\auto_send_logs.php
Trigger: Daily at 11:59 PM
```

---

## 🔧 Maintenance Tasks

### **Daily:**
- Check `admin/logs/` for attendance records
- Monitor failed attempts in `failed_attempts.php`
- Review support tickets in `view_tickets.php`

### **Weekly:**
- Backup logs using `backup_logs.php`
- Validate blockchain chain integrity
- Review audit logs

### **Monthly:**
- Clean up old backups
- Archive old log files
- Update SMTP credentials if needed

---

## 📞 Support & Troubleshooting

### **Common Issues:**

**1. Email not sending:**
- Check `.env` SMTP credentials
- Verify SMTP port not blocked by firewall
- Enable "Less secure app access" or use App Password (Gmail)

**2. Blockchain validation fails:**
- Run `verify_chain.php` to check integrity
- Use `fix_chain.php` to repair (if possible)
- Check `secure_logs/attendance_chain.json` permissions

**3. Students can't check-in:**
- Verify `status.json` has `"checkin": true`
- Check time window in settings
- Confirm active course is set

**4. Device fingerprint errors:**
- Clear fingerprint via `unlink_fingerprint.php`
- Check browser allows JavaScript
- Verify `js/fp.min.js` is loading

---

## 👨‍💻 Developer Notes

### **Adding New Features:**
1. Follow existing code structure
2. Add CSRF protection to all POST endpoints
3. Log all admin actions in audit trail
4. Update this documentation

### **Code Style:**
- Use camelCase for JavaScript variables
- Use snake_case for PHP variables
- Comment complex logic
- Validate all inputs

### **Testing:**
- Test CSRF protection on new forms
- Verify session timeout works
- Check mobile responsiveness
- Test with different browsers

---

## 📜 License

MIT License - See `LICENSE` file for details

---

## 👤 Authors

- **MAVIS** - Creator & Lead Developer
  - Email: mavisenquires@gmail.com
  - GitHub: [@MAVIS-creator](https://github.com/MAVIS-creator)

- **SamexHighshow** - Co-Author
  - GitHub: [@SamexHighshow](https://github.com/SamexHighshow)

---

**Last Updated:** November 15, 2025  
**Version:** 2.0  
**Documentation Status:** ✅ Complete
