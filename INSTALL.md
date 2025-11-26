# Blockchain-Based Attendance System

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-blue.svg)](https://php.net)
[![Packagist](https://img.shields.io/badge/packagist-mavis--creator%2Fblockchain--attendance--system-orange.svg)](https://packagist.org/packages/mavis-creator/blockchain-attendance-system)

A secure, blockchain-verified attendance management system with device fingerprinting, IP tracking, and log-based storage (no SQL database required).

## 📦 Installation

### Via Composer (Recommended)

```bash
composer create-project mavis-creator/blockchain-attendance-system attendance-system
cd attendance-system
```

### Manual Installation

1. **Clone the repository:**
```bash
git clone https://github.com/MAVIS-creator/Blockchain-Based-Attendance-System.git
cd Blockchain-Based-Attendance-System
```

2. **Install dependencies:**
```bash
composer install
```

3. **Configure environment:**
```bash
cp .env.example .env
# Edit .env with your settings
```

## 🚀 Quick Start

### 1. Configure SMTP (Required for email features)

Edit `.env` file:
```env
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your-email@gmail.com
SMTP_PASS=your-app-password
SMTP_SECURE=tls
FROM_EMAIL=no-reply@example.com
FROM_NAME=Attendance System
```

### 2. Start the Application

#### Using XAMPP/WAMP:
- Place the project in `htdocs` (XAMPP) or `www` (WAMP)
- Visit: `http://localhost/Blockchain-Based-Attendance-System/`

#### Using PHP Built-in Server:
```bash
php -S localhost:8000
```
Visit: `http://localhost:8000`

### 3. Access Admin Panel

- URL: `http://localhost:8000/admin/`
- Default credentials will be created on first admin setup

## 📋 Features

### Student Features
- ✅ Check-in/Check-out attendance
- ✅ Device fingerprinting for security
- ✅ Submit support tickets
- ✅ View announcements

### Admin Features
- ✅ Dashboard with attendance statistics
- ✅ Real-time log monitoring
- ✅ Course management
- ✅ Email log exports (CSV/PDF)
- ✅ Multi-file log selection
- ✅ Date/Course group filtering
- ✅ Blockchain verification
- ✅ Backup/Restore system
- ✅ Device fingerprint management
- ✅ Role-based access control

### Security Features
- 🔒 CSRF protection on all forms
- 🔒 Device fingerprinting
- 🔒 IP address logging
- 🔒 Blockchain hash verification
- 🔒 Session timeout
- 🔒 Input sanitization
- 🔒 Optional Polygon blockchain integration

## 📖 Documentation

For detailed documentation, see [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md)

### Configuration

All configuration is done via `.env` file. Key settings:

```env
# SMTP for email sending
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your-email
SMTP_PASS=your-password

# Application settings
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Africa/Lagos

# Security
FINGERPRINT_ENABLED=true
IP_WHITELIST_ENABLED=false

# Attendance rules
REQUIRE_REASON=false
CHECKIN_TIME_START=08:00
CHECKIN_TIME_END=18:00
```

### Email Log Sending

The system supports advanced log exporting:

1. **Manual Export:**
   - Go to `admin/send_logs_email.php`
   - Select specific log files or date+course groups
   - Choose fields to include
   - Select CSV or PDF format
   - Send via email

2. **Automated Export (Scheduled):**
   - Configure auto-send in admin settings
   - Schedule `admin/auto_send_logs.php` with cron/Task Scheduler

**Windows Task Scheduler:**
```batch
Program: C:\xampp\php\php.exe
Arguments: C:\path\to\admin\auto_send_logs.php
Schedule: Daily at 11:59 PM
```

**Linux Cron:**
```bash
59 23 * * * /usr/bin/php /path/to/admin/auto_send_logs.php
```

## 🔧 Advanced Usage

### Composer Scripts

```bash
# Run tests
composer test

# Setup project (install + configure)
composer setup

# Update dependencies
composer update
```

### API Endpoints

- `GET /get_announcement.php` - Fetch active announcements
- `POST /submit.php` - Submit attendance (check-in/out)
- `POST /support.php` - Submit support ticket
- `GET /verify_chain.php` - Verify blockchain integrity
- `POST /polygon_hash.php` - Store hash on Polygon blockchain

## 🗂️ Directory Structure

```
blockchain-attendance-system/
├── admin/                      # Admin panel
│   ├── includes/              # Shared components
│   ├── logs/                  # Daily attendance logs
│   ├── courses/               # Course management
│   └── backups/               # Backup storage
├── asset/                     # Static assets
├── js/                        # JavaScript libraries
├── secure_logs/               # Blockchain chain data
├── src/                       # PSR-4 source code (future)
├── vendor/                    # Composer dependencies
├── .env.example               # Environment template
├── composer.json              # Package configuration
└── index.php                  # Student landing page
```

## 🔐 Security Best Practices

1. **Never commit `.env` to version control**
2. **Use strong SMTP passwords** (app passwords for Gmail)
3. **Enable HTTPS in production**
4. **Regular backups** via admin panel
5. **Keep dependencies updated** with `composer update`
6. **Review audit logs** regularly in admin panel

## 🧪 Testing

```bash
# Run all tests
composer test

# Run specific test
vendor/bin/phpunit tests/CsrfTest.php
```

## 📝 Requirements

- PHP >= 7.4
- PHP Extensions:
  - `json`
  - `openssl`
  - `mbstring`
  - `curl` (for Polygon integration)
- Composer
- Web server (Apache/Nginx) or PHP built-in server

## 🤝 Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

See [CONTRIBUTORS.md](CONTRIBUTORS.md) for the list of contributors.

## 📜 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 👨‍💻 Authors

- **MAVIS** - *Creator & Lead Developer*
  - Email: mavisenquires@gmail.com
  - GitHub: [@MAVIS-creator](https://github.com/MAVIS-creator)

- **SamexHighshow** - *Co-Author*
  - GitHub: [@SamexHighshow](https://github.com/SamexHighshow)

## 🆘 Support

- **Email:** mavisenquires@gmail.com
- **Issues:** [GitHub Issues](https://github.com/MAVIS-creator/Blockchain-Based-Attendance-System/issues)
- **Documentation:** [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md)

## 🙏 Acknowledgments

- [Web3.php](https://github.com/web3p/web3.php) - Ethereum/Polygon integration
- [PHPMailer](https://github.com/PHPMailer/PHPMailer) - Email functionality
- [Dompdf](https://github.com/dompdf/dompdf) - PDF generation
- [FingerprintJS](https://github.com/fingerprintjs/fingerprintjs) - Device fingerprinting

## 📊 Package Statistics

[![Total Downloads](https://img.shields.io/packagist/dt/mavis-creator/blockchain-attendance-system.svg)](https://packagist.org/packages/mavis-creator/blockchain-attendance-system)
[![Latest Stable Version](https://img.shields.io/packagist/v/mavis-creator/blockchain-attendance-system.svg)](https://packagist.org/packages/mavis-creator/blockchain-attendance-system)

---

**Made with ❤️ by MAVIS and the community**
