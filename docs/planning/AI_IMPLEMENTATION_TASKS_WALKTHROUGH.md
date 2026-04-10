# AI Automation Implementation Tasks & Walkthrough

## What was implemented

This implementation adds a file-first AI automation layer that extends the current architecture (no redesign):

- `src/AiServiceIdentity.php`
  - Non-login AI service identity model (`system_ai_operator`)
  - Seed + load behavior for `storage/admin/ai_accounts.json`

- `src/AiCapabilityChecker.php`
  - Capability checks (`ai_can(...)`)
  - Seed + load behavior for `storage/admin/ai_permissions.json`

- `src/AiTicketDiagnoser.php`
  - Message intent classification
  - Fingerprint/IP matching against daily logs
  - Revoked device checks
  - Duplicate/fraud sequence detection
  - Deterministic classification + confidence scoring

- `src/AiAnnouncementService.php`
  - Device-targeted announcements using `storage/admin/announcement_targets.json`

- `src/AiAdminChatAssistant.php`
  - Optional admin-chat insights for high-confidence, high-risk diagnostics only

- `src/AiTicketAutomationEngine.php`
  - End-to-end ticket processing pipeline:
    1. Diagnose ticket
    2. Enforce rules
    3. Optionally auto-fix attendance
    4. Send targeted announcement
    5. Resolve ticket
    6. Save diagnostics
    7. Trigger log auto-send once per completed attendance cycle

- `admin/includes/ticket_helpers.php`
  - Shared atomic helpers for reading/resolving tickets + appending attendance log entries

- `admin/ai_ticket_processor.php`
  - Trigger endpoint/script (CLI or authenticated admin request) to process unresolved tickets

- `ticket_status_api.php`
  - Device-isolated status endpoint
  - Returns only ticket + diagnostics + targeted message for same fingerprint (+ IP if provided)

- `get_announcement.php` updated
  - Supports fingerprint-targeted announcement retrieval while preserving existing broadcast fallback

- Public pages updated
  - `index.php`, `support.php` now pass fingerprint when polling announcements

- Admin visibility updated
  - `admin/view_tickets.php` now includes a “Recent AI Diagnostics” panel

- `admin/state_helpers.php` updated
  - Added helper paths for AI files

## New storage files

- `storage/admin/ai_accounts.json`
- `storage/admin/ai_permissions.json`
- `storage/admin/announcement_targets.json`
- `storage/admin/ai_ticket_diagnostics.json`
- `storage/admin/ai_auto_send_tracker.json`

## Rule mapping (implemented)

1. Revoked device/IP/MAC → deny + targeted warning + resolve ticket.
2. Attendance already recorded or duplicate sequence → deny + targeted explanation + resolve ticket.
3. Fingerprint+IP match and no attendance yet → auto-fix by appending attendance log + targeted success + resolve.
4. Fingerprint match / IP mismatch → guidance + admin-review suggestion + resolve.
5. No FP/IP match → verification guidance + admin-review suggestion + resolve.
6. High-confidence high-risk diagnostics optionally posted to admin chat as concise insights.

## How to run

### CLI mode

- Run processor for up to default limit (200 unresolved tickets):
  - `php admin/ai_ticket_processor.php`

- Run with explicit limit:
  - `php admin/ai_ticket_processor.php 100`

### Web mode (admin-authenticated)

- Request:
  - `/admin/ai_ticket_processor.php?limit=100`

Returns JSON summary with per-ticket outcomes.

## Verification checklist

- [ ] AI identity exists and is non-login (`can_login=false`)
- [ ] Capabilities load correctly
- [ ] Unresolved ticket is diagnosed and resolved
- [ ] Targeted message appears only on matching fingerprint
- [ ] Diagnostics record written to `ai_ticket_diagnostics.json`
- [ ] Admin support page shows recent AI diagnostics panel
- [ ] Auto-send tracker writes one marker per `date|matric` completed cycle

## Notes

- Core validation flow in `submit.php` is untouched.
- Existing `announcement.json` broadcast behavior remains intact.
- All writes follow existing lock-safe atomic patterns.
- Optional future enhancement: add manual targeted-announcement controls in `admin/announcement.php` UI.
