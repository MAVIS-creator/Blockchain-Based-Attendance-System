# Full Project Overview

## Project Identity

**Name:** Blockchain-Based Attendance System  
**Type:** PHP web application  
**Primary purpose:** Record student attendance with tamper-evident logs, admin controls, device-aware validation, and optional cloud dual-write.  
**Current stack:** PHP 7.4+, file-based persistence, JSON/log storage, optional Supabase, optional Polygon, Composer-managed dependencies.

This repository is not just a simple attendance form. It is a full attendance operations platform with:

- A public attendance portal
- An admin dashboard with role-aware routing
- File-first runtime storage
- Blockchain-style hash chaining for attendance integrity
- Optional hybrid file + Supabase persistence
- Email export/reporting workflows
- Support tickets and announcements
- Request timing telemetry
- A built-in admin-side AI patching tool

## High-Level Architecture

The application is built around a **file-first architecture**.

- The public site is served by root PHP entry points like `index.php`, `submit.php`, and `support.php`.
- The admin panel is served from `admin/` through `admin/index.php`, which acts as a page router.
- Runtime state is now centered on `storage/`, not just legacy files inside `admin/`.
- Compatibility helpers automatically migrate or mirror legacy files into the current storage layout.
- Attendance records are written to flat log files and also appended to a blockchain-style JSON chain.
- If hybrid mode is enabled, selected writes are also sent to Supabase on a best-effort basis.

## What This Project Does

### Public/User Side

- Shows an attendance portal only when check-in or check-out is currently enabled
- Accepts student submissions with:
  - name
  - matric number
  - browser fingerprint
  - action type (`checkin` or `checkout`)
  - course
  - optional geolocation
- Prevents invalid or duplicate submissions based on current rules
- Displays live announcements
- Supports support ticket submission
- Detects tab-away inactivity and can lock out abusive behavior
- Polls revocation state so blocked clients are invalidated quickly

### Admin Side

- Authentication and session management
- Dashboard and operational pages
- Status control for opening and closing attendance windows
- Role and permission management
- Account management
- Course creation and active-course assignment
- Log viewing and failed-attempt monitoring
- Backup and restore tooling
- Chain inspection and validation
- Announcement broadcasting
- Support ticket review
- Request timing inspection
- Email-based report/export workflows
- Patcher Studio for controlled file editing and AI-assisted patch review

## Entry Points

### Root Entry Points

- `index.php`: Public attendance page. Loads current status, active course, announcements, and fingerprint flow.
- `submit.php`: Main attendance submission endpoint and enforcement engine.
- `support.php`: Public support form with local file write and optional hybrid dual-write.
- `get_announcement.php`: Public announcement fetch endpoint.
- `status_api.php`: Public status polling endpoint used by the front end.
- `verify_chain.php`: Validates the blockchain-style attendance chain.
- `fix_chain.php`: Repair utility for chain issues.
- `polygon_hash.php`: Optional Polygon hash integration.
- `replay_outbox.php`: Replays queued hybrid writes to Supabase.
- `attendance_closed.php` and `closed.php`: Closed-state and lockout screens.
- `log_inactivity.php`: Records tab-away/inactivity events.

### Admin Entry Points

- `admin/login.php`: Admin authentication
- `admin/index.php`: Admin router and layout shell
- `admin/dashboard.php`: Default admin landing page
- `admin/status.php`: Opens/closes attendance mode
- `admin/accounts.php`: Account management
- `admin/roles.php`: Permission assignment and role privileges
- `admin/settings.php`: System settings management
- `admin/request_timings.php`: Request timing visibility
- `admin/patcher.php`: Browser-based repository editor and AI patch workflow

## Core Runtime Design

### 1. File-First Persistence

The system is designed so local file writes remain the source of truth.

- Attendance logs are appended to local files.
- Support tickets are stored locally.
- Admin state is stored in JSON files.
- Blockchain chain data is stored locally.
- Hybrid cloud syncing is additive, not authoritative.

This matters because the app can continue operating even when Supabase or external services are unavailable.

### 2. Storage Migration Layer

The repository shows an evolution from legacy paths to a cleaner runtime layout:

- Legacy admin state still exists under `admin/`
- Current runtime state lives under `storage/`
- `storage_helpers.php` resolves the effective storage root
- `admin/runtime_storage.php` resolves admin-specific storage paths
- migration helper functions copy from old locations into new runtime files when needed

Current active storage root defaults to:

```text
storage/
```

And can be overridden with:

```env
STORAGE_PATH=
```

### 3. Hybrid Dual-Write

`hybrid_dual_write.php` adds optional Supabase persistence.

- Disabled by default with `HYBRID_MODE=off`
- Enabled with `HYBRID_MODE=dual_write`
- Sends selected records to Supabase REST endpoints
- If the remote write fails, it queues the payload in:

```text
storage/logs/hybrid_outbox.jsonl
```

- `replay_outbox.php` can replay queued writes later

This keeps the local app resilient while allowing cloud reporting or cross-instance visibility.

## Attendance Submission Flow

The main business logic lives in `submit.php`.

### Request Processing Steps

1. Read and normalize posted fields.
2. Validate required inputs.
3. Determine client IP, user agent, and MAC-derived identity where available.
4. Load the current attendance status and normalize expired windows.
5. Load system settings from JSON or encrypted settings payload.
6. Enforce security and attendance rules.
7. Append the attendance record to the daily log.
8. Append a new block to the attendance chain.
9. Attempt hybrid dual-write if enabled.
10. Attempt Polygon hash submission if configured.
11. Return JSON success or failure.

### Validation and Enforcement in `submit.php`

The endpoint currently enforces multiple layers:

- Required field presence
- Valid action type
- Check-in/check-out mode currently enabled
- Revoked token/IP/MAC blocking
- Optional IP whitelist enforcement
- Optional geo-fence enforcement
- Optional device cooldown
- Optional user-agent locking
- Optional one-device-per-day enforcement
- Optional fingerprint matching by matric number
- Duplicate prevention for same matric/action/day
- Duplicate prevention for same device/action/day
- Prevent checkout without a prior check-in

### Attendance Log Format

Attendance is appended to daily log files under `storage/logs/` in a pipe-delimited format:

```text
name | matric | action | fingerprint | ip | mac | timestamp | userAgent | course | reason
```

Failed attendance validations are logged separately when applicable.

## Blockchain-Style Integrity Model

Attendance is also stored in:

```text
storage/secure_logs/attendance_chain.json
```

Each block contains:

- timestamp
- name
- matric
- action
- fingerprint
- ip
- userAgent
- course
- prevHash
- hash

The chain is not a decentralized blockchain by default. It is a **local tamper-evident hash chain**.

`verify_chain.php` walks the chain and confirms:

- each block hash matches its expected computed hash
- each block references the previous block correctly

Optional Polygon integration exists, but the local chain remains the primary integrity mechanism.

## Public Front-End Behavior

### Attendance Page

`index.php` is a styled public form with:

- dynamic attendance mode display
- active course display
- announcement banner + toast updates
- fingerprint generation via `js/fp.min.js`
- hidden token persistence in `localStorage`
- geolocation capture for geo-fence enforcement
- SweetAlert feedback
- automatic closed-state reload if status changes
- tab-away monitoring and lockout flow

### Support Page

`support.php` provides:

- public ticket submission
- optional fingerprint attachment
- announcement polling
- local ticket persistence
- optional hybrid Supabase write

## Admin Application Structure

### Router

`admin/index.php`:

- starts and validates admin session
- checks active session tracking
- applies role-based page access rules
- resolves the current page from `admin_route_catalog()`
- loads shared layout components

### Route Catalog

The admin route registry is defined in `admin/state_helpers.php`.

Important routes include:

- `dashboard`
- `roles`
- `audit`
- `status`
- `status_debug`
- `request_timings`
- `logs`
- `clear_logs_ui`
- `clear_tokens_ui`
- `failed_attempts`
- `accounts`
- `settings`
- `chain`
- `add_course`
- `set_active`
- `manual_attendance`
- `geofence`
- `support_tickets`
- `unlink_fingerprint`
- `announcement`
- `patcher`
- `send_logs_email`
- `profile_settings`

### Shared Admin Components

Inside `admin/includes/`:

- `csrf.php`: CSRF token generation/validation
- `header.php`, `footer.php`, `navbar.php`, `sidebar.php`: layout shell
- `get_mac.php`: MAC lookup helper
- `hybrid_admin_read.php`: hybrid read support for admin-facing data

## Admin Functional Areas

### Authentication and Sessions

- Admin login/logout pages
- Session timeout support
- Session registry stored in JSON
- Role-aware access checks
- Forced logout if a session disappears from tracked state

### Accounts and Roles

- `admin/accounts.php`
- `admin/roles.php`
- storage in `storage/admin/accounts.json`
- permissions in `storage/admin/permissions.json` when present

### Settings

Settings are stored in:

```text
storage/admin/settings.json
```

Possible capabilities reflected in the code and docs include:

- device identity mode
- fingerprint enforcement
- IP whitelist
- geo-fence
- cooldowns
- user-agent locking
- encryption flags
- load-test relaxation toggles
- email/export settings

If encrypted, settings are stored as `ENC:<blob>` and decrypted with `.settings_key`.

### Status Control

Status is stored in:

```text
storage/admin/status.json
```

Normalized status includes:

- `checkin`
- `checkout`
- `end_time`

Expired timers are actively normalized back to a closed state.

### Courses

Course data lives in:

```text
storage/admin/courses/course.json
storage/admin/courses/active_course.json
```

Used to determine which course is currently active on the public portal.

### Logs and Monitoring

Operational logging spans multiple files:

- daily attendance logs
- failed attempts logs
- blocked token logs
- inactivity log
- admin audit JSON/logs
- request timing JSONL

The app includes log viewing, export, and cleanup interfaces.

### Chain Management

Admin pages include chain display and validation tooling for the local attendance chain.

### Backup and Restore

The admin panel includes ZIP-oriented backup and restore flows for key runtime data.

Runtime backup directories include:

```text
storage/backups/
admin/backups/
```

### Support and Announcements

- Support tickets are stored and reviewed via admin pages.
- Announcements are stored in JSON and displayed live on public pages.

### Revocation and Device Controls

Revocation data is stored in:

```text
storage/admin/revoked.json
```

The code supports revoking:

- client tokens
- IP addresses
- MAC addresses

The public page also watches for revocation updates and invalidates local tokens.

### Manual Attendance

The admin can create attendance entries manually through a dedicated tool.

### Patcher Studio

`admin/patcher.php` is a large embedded admin tool for repository editing.

It includes:

- file explorer
- search
- editor
- git status/history visibility
- revert flow
- staged apply/release workflow
- terminal command execution
- AI-assisted issue analysis and patch proposals

It is controlled by `.env` flags such as:

- `PATCHER_AI_ENABLED`
- `PATCHER_AI_PROVIDER`
- `PATCHER_OPENROUTER_*`
- `PATCHER_GEMINI_*`

This is one of the most advanced modules in the repo and extends the project beyond attendance management into developer operations.

## Storage Layout

### Current Runtime Storage

```text
storage/
  admin/
    accounts.json
    admin_audit.json
    announcement.json
    announcement_history.json
    chat.json
    revoked.json
    sessions.json
    settings.json
    settings_audit.log
    settings_templates.json
    status.json
    support_tickets.json
    courses/
      active_course.json
      course.json
    patcher_jobs/
    patcher_releases/
  backups/
  logs/
    blocked_tokens.log
    hybrid_outbox.jsonl
    inactivity_log.txt
    request_timing.jsonl
    daily/
  secure_logs/
    attendance_chain.json
```

### Legacy and Compatibility Paths

The repo still contains legacy runtime-style files under `admin/`, `home/data/`, and some root-level references. The helper layer exists so the application can continue running while gradually standardizing on `storage/`.

## Configuration

Configuration comes from:

- `.env`
- optionally `.env.local`
- runtime JSON files under `storage/`

### Important Environment Areas

- SMTP settings
- app environment/debug
- timezone and session lifetime
- fingerprint and whitelist flags
- hybrid Supabase config
- storage path override
- localhost override mode
- Polygon config
- patcher AI config

`env_helpers.php` adds layered environment loading and localhost-aware overrides.

## Dependencies

From `composer.json`:

- `web3p/web3.php`: blockchain/Polygon integration
- `phpmailer/phpmailer`: email sending
- `dompdf/dompdf`: PDF generation
- `phpunit/phpunit`: tests

Autoloading:

- PSR-4 namespace: `MavisCreator\AttendanceSystem\`
- main class directory: `src/`

## Telemetry and Performance Instrumentation

`request_timing.php` implements lightweight request tracing.

Features include:

- request start/stop timing
- span recording for named phases
- sampling
- slow-request retention
- error retention
- peak memory capture
- JSONL logging
- optional Supabase mirroring when hybrid mode is enabled

Primary output file:

```text
storage/logs/request_timing.jsonl
```

This is used by pages like `index.php`, `submit.php`, and `support.php`.

## Testing and Operational Tooling

### Tests

- `admin/tests/`: admin-side QA and CSRF tests
- `tests/load/`: load testing scripts and README

The load-test scripts are designed to:

- fetch the public page first
- discover the active action and course
- generate unique attendance payloads
- test throughput while respecting business-rule constraints

### Azure/Deployment Utilities

Under `tools/azure/` there are deployment and scaling helpers, indicating cloud hosting or scale-out operational work has been considered.

### Supabase Assets

Under `supabase/`:

- `schema.sql`
- `HYBRID_SETUP.md`

These document the optional hybrid persistence model.

## Security Model

Security is distributed across multiple layers.

### Implemented Controls

- CSRF protection for admin write flows
- Session tracking and invalidation
- Role-based access control
- Fingerprint-based identity linkage
- Device/IP/MAC revocation
- Duplicate attendance prevention
- Optional user-agent lock
- Optional IP whitelist
- Optional geo-fence
- Optional encrypted settings/log stores
- File locking for critical writes
- Tamper-evident attendance chain
- Inactivity/tab-away enforcement on the public portal

### Practical Security Tradeoff

The app remains fundamentally file-based, so its operational security depends heavily on:

- filesystem permissions
- protecting `.env`
- protecting `storage/`
- controlling admin access
- securing backup files

## Project Evolution Notes

This repository is clearly in an active transition phase.

Notable signs of evolution:

- older docs still describe the legacy `admin/`-centric layout
- current runtime logic uses `storage/`
- hybrid Supabase support was added without removing file-first behavior
- request timing instrumentation was introduced
- admin patcher/AI workflows were added
- load-testing and Azure operational tooling were added

So the most accurate summary is:

> This is now a hybrid-capable, file-first attendance operations platform with admin tooling, observability, and experimental AI-assisted maintenance features.

## Suggested Mental Model for the Repo

If you are trying to understand the system quickly, think of it in six layers:

1. **Public UX layer**  
   `index.php`, `support.php`, front-end JS, announcement polling

2. **Business rules layer**  
   `submit.php`, admin settings, status/course state

3. **Persistence layer**  
   `storage_helpers.php`, `admin/runtime_storage.php`, JSON/log files

4. **Integrity layer**  
   attendance chain, verification, optional Polygon write

5. **Operations layer**  
   exports, backups, audits, request timings, load tests, Azure tools

6. **Admin/developer tooling layer**  
   admin router, roles, settings, patcher, AI review flow

## Most Important Files to Read First

If someone needs to understand the codebase efficiently, these are the best starting points:

- `index.php`
- `submit.php`
- `support.php`
- `storage_helpers.php`
- `admin/runtime_storage.php`
- `admin/index.php`
- `admin/state_helpers.php`
- `request_timing.php`
- `hybrid_dual_write.php`
- `src/Config.php`

## Summary

This project is a moderately large PHP application centered on attendance capture, but it has grown into much more than a form-and-log system.

Its current defining characteristics are:

- file-first persistence
- runtime state under `storage/`
- attendance integrity via chained hashes
- optional Supabase dual-write
- role-aware admin operations
- strong operational tooling
- optional Polygon integration
- live announcements and support handling
- performance/request timing instrumentation
- an embedded AI-enabled patching workspace for admins

That combination makes it part attendance app, part audit trail system, part admin operations console, and part evolving platform for future cloud-backed operation.
