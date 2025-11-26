# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2025-11-26

### Added
- **Composer Package Support**: Transformed project into installable Composer package
- **Environment Configuration**: Complete `.env` support for all settings
- **SMTP Configuration via .env**: SMTP settings now loaded from environment variables
- **Advanced Log Sending**: Multi-file and date+course group selection for log exports
- **Enhanced Email System**: 
  - Select multiple log files or grouped logs
  - Filter by date range, time range, and course
  - Choose specific fields to include in exports
  - Support for CSV and PDF formats
  - Per-file "Send" action buttons
  - Group-based sending with aggregated data
- **PSR-4 Autoloading**: Proper namespace structure with `MavisCreator\AttendanceSystem`
- **Bootstrap File**: Application initialization with environment loading
- **Config Class**: Centralized configuration management
- **Composer Scripts**: Setup, test, and post-install automation
- **Documentation**: 
  - INSTALL.md with Composer installation guide
  - CONTRIBUTING.md for contributors
  - PROJECT_STRUCTURE.md with complete file documentation
  - CHANGELOG.md (this file)
- **Security Enhancements**:
  - SMTP credentials no longer stored in settings.json
  - All sensitive data moved to .env
  - Improved CSRF protection

### Changed
- **Settings Interface**: SMTP fields now read-only (configured via .env)
- **Log Sending Interface**: Complete redesign with file/group selection
- **Email Configuration**: Separated editable fields (From Name, Recipient) from read-only SMTP credentials
- **composer.json**: Enhanced with complete package metadata, keywords, and autoloading
- **Clear Logs**: Fixed scope handling for multi-select deletion (CSV-separated scopes)
- **UI Improvements**: Cleaner feedback messages instead of raw JSON output

### Fixed
- Clear logs functionality not deleting files when multiple scopes selected
- JSON output displaying in UI after log clearing
- Vendor autoload path in email sending (fixed to `../vendor/autoload.php`)

### Security
- SMTP credentials now environment-only (not stored in JSON files)
- Enhanced input validation on all forms
- CSRF tokens enforced on all POST requests

## [1.0.0] - 2025-06-01

### Added
- Initial release
- Student check-in/check-out system
- Device fingerprinting
- IP tracking and logging
- Blockchain verification
- Admin dashboard
- Course management
- Support ticket system
- Announcement system
- Manual attendance entry
- Log export (basic CSV/PDF)
- Backup/Restore functionality
- Token revocation system
- Fingerprint unlinking
- CSRF protection
- Session management
- Real-time log viewing
- Failed attempts tracking

---

## Version History Legend

- **Added**: New features
- **Changed**: Changes in existing functionality
- **Deprecated**: Soon-to-be removed features
- **Removed**: Removed features
- **Fixed**: Bug fixes
- **Security**: Security improvements

---

[2.0.0]: https://github.com/MAVIS-creator/Blockchain-Based-Attendance-System/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/MAVIS-creator/Blockchain-Based-Attendance-System/releases/tag/v1.0.0
