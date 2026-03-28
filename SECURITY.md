# Security Policy

## Supported Versions

We release patches for security vulnerabilities in the following versions:

| Version | Supported          |
| ------- | ------------------ |
| 2.0.x   | :white_check_mark: |
| 1.0.x   | :x:                |

## Reporting a Vulnerability

We take the security of the Blockchain-Based Attendance System seriously. If you believe you have found a security vulnerability, please report it to us as described below.

### Please DO NOT:
- Open a public GitHub issue
- Disclose the vulnerability publicly before it has been addressed

### Please DO:
1. **Email us directly** at: mavisenquires@gmail.com
2. **Include the following information:**
   - Type of vulnerability
   - Full paths of source file(s) related to the vulnerability
   - Location of the affected source code (tag/branch/commit or direct URL)
   - Step-by-step instructions to reproduce the issue
   - Proof-of-concept or exploit code (if possible)
   - Impact of the issue, including how an attacker might exploit it

### What to expect:
- We will acknowledge your email within 48 hours
- We will send a more detailed response within 7 days indicating the next steps
- We will keep you informed about the progress towards a fix
- We may ask for additional information or guidance

## Security Best Practices for Users

When deploying this system, please follow these security guidelines:

### 1. Environment Configuration
```bash
# Never commit .env to version control
# Use strong, unique passwords for SMTP
# Enable HTTPS in production
```

### 2. File Permissions
```bash
# Set restrictive permissions on sensitive files
chmod 600 .env
chmod 600 admin/.settings_key
chmod 700 admin/logs
chmod 700 secure_logs
```

### 3. SMTP Security
- Use app-specific passwords (not your main password)
- Enable 2FA on email accounts
- Use TLS/SSL for SMTP connections

### 4. Regular Updates
```bash
# Keep dependencies updated
composer update

# Monitor security advisories
composer audit
```

### 5. Session Security
- Set appropriate session timeout (default: 30 minutes for admin)
- Use HTTPS to prevent session hijacking
- Enable `session.cookie_secure` in php.ini for production

### 6. Backup Security
- Encrypt backups before storing
- Store backups in secure, off-site location
- Regularly test backup restoration

### 7. Access Control
- Use strong admin passwords (minimum 12 characters)
- Limit admin accounts (configure `MAX_ADMINS` in .env)
- Review audit logs regularly

### 8. Web Server Configuration

#### Apache (.htaccess)
```apache
# Disable directory listing
Options -Indexes

# Protect sensitive files
<FilesMatch "\.(env|json|log|key)$">
    Require all denied
</FilesMatch>

# Force HTTPS (production)
# RewriteEngine On
# RewriteCond %{HTTPS} off
# RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

#### Nginx
```nginx
# Deny access to sensitive files
location ~ /\.(env|git|htaccess) {
    deny all;
}

location ~ \.(json|log|key)$ {
    deny all;
}

# Force HTTPS (production)
# server {
#     listen 80;
#     return 301 https://$host$request_uri;
# }
```

### 9. PHP Configuration (php.ini)
```ini
# Production settings
expose_php = Off
display_errors = Off
log_errors = On
error_log = /path/to/logs/php_errors.log

# Session security
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = Strict
session.use_strict_mode = 1
```

### 10. Database-less Security
This system uses log files instead of SQL, but:
- Encrypt sensitive log files
- Set proper file permissions (600 for logs)
- Regularly archive and purge old logs
- Validate all log entries before processing

## Known Security Features

### Built-in Protection
- ✅ CSRF protection on all forms
- ✅ Input sanitization and validation
- ✅ Session timeout
- ✅ Device fingerprinting
- ✅ IP logging and tracking
- ✅ Blockchain verification (tamper detection)
- ✅ Secure password hashing (bcrypt)
- ✅ Audit logging

### Security Headers (Recommended)
Add these to your web server configuration:

```
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000; includeSubDomains
Content-Security-Policy: default-src 'self'
Referrer-Policy: strict-origin-when-cross-origin
```

## Vulnerability Disclosure Timeline

1. **Day 0**: Vulnerability reported
2. **Day 1-2**: Acknowledgment sent to reporter
3. **Day 7**: Detailed response with timeline
4. **Day 30**: Patch developed and tested
5. **Day 45**: Security update released
6. **Day 60**: Public disclosure (if critical)

## Security Updates

Subscribe to security updates:
- Watch this repository on GitHub
- Check [CHANGELOG.md](CHANGELOG.md) for security fixes
- Monitor your email if you reported a vulnerability

## Credits

We appreciate responsible disclosure and will credit reporters in:
- Security advisories
- CHANGELOG.md
- CONTRIBUTORS.md (if they wish)

## Contact

Security Team: mavisenquires@gmail.com

**PGP Key:** (Coming soon)

---

**Thank you for helping keep the Blockchain-Based Attendance System and its users safe!**
