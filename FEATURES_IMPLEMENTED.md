# Implemented Features Inventory

Last reviewed: 2026-02-26

This document lists features that are **implemented in code** (verified from repository files), not just planned/documented features.

## 1) Student Attendance Flow

- Student landing page for attendance submission (`index.php`)
- Dynamic mode support (check-in/check-out) based on global status (`index.php`, `status.json`)
- Active course display and hidden course binding during submission (`index.php`, `admin/courses/active_course.json`)
- Announcement display to students (`index.php`, `admin/announcement.json`)
- Attendance submission endpoint with JSON responses (`submit.php`)
- Closed-state pages when attendance is unavailable (`attendance_closed.php`, `closed.php`)

## 2) Attendance Validation & Enforcement

- Input sanitization for submitted attendance fields (`submit.php`)
- Mode gating (rejects submissions when mode disabled) (`submit.php`)
- Duplicate prevention by matric + action for same day (`submit.php`)
- Duplicate prevention by device (IP/MAC preference) (`submit.php`, `admin/settings.php`)
- Checkout blocked without prior check-in (`submit.php`)
- Failed-attempt logging with reason codes (`submit.php`, `admin/logs/*_failed_attempts.log`)
- Fingerprint-to-matric linkage and mismatch blocking (`submit.php`, `admin/fingerprints.json`)

## 3) Security Controls (Runtime)

- IP whitelist enforcement (including CIDR support) (`submit.php`, `admin/settings.php`)
- Geo-fence enforcement with Haversine distance checks (`submit.php`, `admin/settings.php`)
- Device cooldown enforcement (`submit.php`, `admin/settings.php`)
- User-agent lock enforcement (`submit.php`, `admin/settings.php`)
- One-device-per-fingerprint-per-day enforcement (`submit.php`, `admin/settings.php`)
- Optional encrypted settings/log stores via AES-256-CBC (`admin/settings.php`, `submit.php`)
- CSRF token generation/validation helpers and integration in admin actions (`admin/includes/csrf.php`, multiple admin endpoints)

## 4) Blockchain-Style Integrity Features

- Log-to-chain block creation on each valid attendance (`submit.php`, `secure_logs/attendance_chain.json`)
- SHA-256 block hashing with previous-hash linkage (`submit.php`)
- Chain validation endpoint (public CLI-style output) (`verify_chain.php`)
- Admin chain validation view with tamper detection and pagination (`admin/validate_chain.php`, `admin/chain.php`)
- Chain repair utility to recompute `prevHash` and `hash` (`fix_chain.php`)

## 5) Optional Polygon/Web3 Integration

- Optional hash anchoring to Polygon using Web3 PHP libraries (`polygon_hash.php`)
- Polygon transaction send attempt integrated into submission path (`submit.php`)
- Environment-driven Polygon credentials/RPC settings (`polygon_hash.php`, `.env` conventions)

## 6) Admin Authentication & Account Management

- Admin login with session establishment (`admin/login.php`)
- Role-aware sessions (`admin/login.php`, `admin/accounts.json`)
- Admin route-based dashboard shell (`admin/index.php`)
- Admin account creation/deletion (superadmin-gated) (`admin/accounts.php`)
- Password change flows (self-service + superadmin reset) (`admin/accounts.php`, `admin/profile_settings.php`)
- Logout/session termination (`admin/logout.php`)

## 7) System Status & Access Windows

- Enable/disable check-in or check-out modes (`admin/status.php`)
- Mode duration + end-time countdown persistence (`admin/status.php`, `status.json`)
- Optional enforcement of configured check-in time window before enabling check-in (`admin/status.php`, `admin/settings.php`)

## 8) Course Management

- Add/remove courses (`admin/courses/add.php`, `admin/courses/course.json`)
- Set/disable active course (`admin/courses/set_active.php`, `admin/courses/active_course.json`)
- Course-linked attendance metadata in logs/chain (`submit.php`, `admin/logs/*.log`)

## 9) Logs, Monitoring & Audit

- Daily attendance logs in structured pipe-delimited format (`submit.php`, `admin/logs/*.log`)
- Failed attempts log handling (`submit.php`, `admin/logs/*_failed_attempts.log`)
- Valid attendance log viewer with filters/pagination (`admin/logs/logs.php`)
- Failed attempts + check-in-only anomaly viewer (`admin/logs/failed_attempts.php`)
- Audit log recording for sensitive admin actions (revocation/device clear) (`admin/revoke_entry.php`, `admin/clear_device.php`, `admin/audit.php`)
- Audit viewer and retention-based purge tool (`admin/audit.php`)
- File change polling endpoint for admin dashboard refresh logic (`admin/_last_updates.php`)
- Inactivity logging and blocked-token rotation (`log_inactivity.php`)

## 10) Export, Email & Automation

- Export attendance groups by date+course (`admin/send_logs_email.php`)
- Column selection for exports (`admin/send_logs_email.php`)
- CSV export generation (`admin/send_logs_email.php`)
- PDF export generation via Dompdf (`admin/send_logs_email.php`)
- SMTP email send via PHPMailer and `.env` config (`admin/send_logs_email.php`)
- Default recipient and format from settings (`admin/send_logs_email.php`, `admin/settings.php`)
- Scheduled auto-send wrapper (`admin/auto_send_logs.php`)
- Legacy/basic export endpoint still present (`admin/export.php`)

## 11) Backup/Restore & Data Hygiene

- ZIP backup creation for logs, fingerprints, chain (`admin/backup_logs.php`)
- Backup restore from uploaded ZIP (`admin/restore_logs.php`)
- Clear operations UI and backend for logs/backups/chain/etc. (`admin/clear_logs_ui.php`, `admin/clear_logs.php`)
- Backup directory handling (`admin/backups/`)

## 12) Revocation & Access Blocking

- Revoke token/IP/MAC with expiry metadata (`admin/revoke_entry.php`, `admin/revoked.json`)
- Public revoked-list endpoint with expiry cleanup (`admin/revoked_tokens.php`)
- SSE endpoint for real-time revocation updates (`admin/revoke_sse.php`)
- Token clearing UI/backend (`admin/clear_tokens_ui.php`)

## 13) Fingerprint & Device Administration

- Fingerprint unlinking from matric records (`admin/unlink_fingerprint.php`)
- Device state clearing for enforcement stores (`admin/clear_device.php`)
- Fingerprint audit artifacts/logging present (`admin/fingerprint_audit.log`, audit entries)

## 14) Support & Communication

- Student support ticket submission form with fingerprint/IP capture (`support.php`)
- Ticket storage and admin viewing/resolution (`admin/view_tickets.php`, `admin/support_tickets.json`)
- Manual attendance action from ticket context (`admin/view_tickets.php`)
- Announcement creation/edit/enable/disable (`admin/announcement.php`, `admin/announcement.json`)
- Public announcement JSON API (`get_announcement.php`)
- Admin-to-admin lightweight chat (post/fetch/delete) with role controls (`admin/chat_post.php`, `admin/chat_fetch.php`, `admin/chat_delete.php`)

## 15) Manual Attendance Tools

- Manual attendance entry page (`admin/manual_attendance.php`)
- Reason keyword checks using allowed reason list (`admin/manual_attendance.php`, `admin/allowed_reasons.json`)
- Reuse of key enforcement controls in manual flow (IP whitelist, cooldown, UA lock, one-device) (`admin/manual_attendance.php`)

## 16) Configuration & Packaging

- Composer package setup with dependencies for Web3, PHPMailer, Dompdf (`composer.json`)
- PSR-4 configuration class and `.env` loader (`src/Config.php`, `bootstrap.php`)
- Environment-aware app behavior (timezone, debug/production modes) (`bootstrap.php`)

## 17) Tests & Developer Utilities

- CSRF automated integration test script (`admin/tests/csrf_test.php`, `admin/tests/run-tests.ps1`)
- Auto-save helper scripts for local workflow (`autosave.bat`, `autosave-watcher.ps1`, `open_with_autosave.bat`)

---

## Notes from Verification

- The project is implemented as a **JSON/log-file based system** (no SQL schema usage in core flows).
- Some modules include both newer and legacy implementations (e.g., export endpoints and chain verification variants).
- This inventory reflects features observed in source files, and does not score feature quality or production readiness.
