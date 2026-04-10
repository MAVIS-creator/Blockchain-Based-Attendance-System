# AI Automation Testing Prompt + Run Report

Date: 2026-04-10
Scope: Attendance AI automation (public-side guidance, ticket diagnosis, provider fallback, targeted announcements, admin chat, dashboard health badge, metrics)

## 1) Copy/Paste Testing Prompt

Use this exact prompt to test the AI automation end-to-end in this repository:

"""
You are validating the FULL AI automation pipeline for a PHP attendance system (public + admin paths).

Run all checks and report pass/fail for each section.

SECTION A — Public-side guidance / widget-like helper behavior

1. Open `index.php` and verify helper guidance text is present and visible to students:
   - should instruct students to contact support if attendance fails
   - should include a direct link/button to `support.php`
2. Verify public announcement polling sends fingerprint (`get_announcement.php?fingerprint=...`) and renders targeted responses.
3. Verify support page helper text explains:
   - provide course + failed action for faster AI review
   - targeted updates are tied to same fingerprint/browser session
4. Confirm this behaves like a widget-style assistant cue (contextual help shown without needing admin page).

SECTION B — Course-aware attendance enforcement

1. Validate same-day same-action duplicate blocking is course-scoped:
   - duplicate for same course/action/day should fail
   - different course same day should pass
2. Validate checkout guard is course-aware:
   - cannot checkout for course X without checkin for course X

SECTION C — Support ticket AI processing

1. Create or use unresolved support tickets with diverse contexts:
   - known session issue
   - new/suspicious device (fingerprint mismatch)
   - duplicate submission sequence
   - revoked/blocked device
2. Execute AI processor (`admin/ai_ticket_processor.php`) and confirm:
   - unresolved tickets are processed
   - classification is assigned
   - admin suggestion generated
   - ticket resolution state updates correctly

SECTION D — Fingerprint-targeted responses

1. Confirm `announcement_targets.json` contains targeted entries.
2. Confirm targeted message is specific to fingerprint context (not generic clone text).
3. Confirm `ticket_status_api.php` returns device-isolated data only.

SECTION E — AI provider/fallback behavior

1. Verify provider modes available: rules/groq/openrouter/gemini/auto.
2. In `auto`, verify fallback chain: Groq -> OpenRouter -> Gemini -> Rules.
3. Confirm diagnostics include provider/model/latency metadata.
4. Confirm metrics file tracks success/failure and avg latency per provider.

SECTION F — Admin chat AI assist

1. Post attendance-related/admin-help message in chat (`@ai` or support keywords).
2. Confirm AI auto-reply appears with useful operational guidance.
3. Confirm reply metadata includes provider/model/latency.

SECTION G — Admin configuration + dashboard health

1. In admin settings, verify selector exists for `AI_AUTOMATION_PROVIDER` (rules/groq/openrouter/gemini/auto).
2. Save each mode and confirm `.env` update.
3. On dashboard, verify AI card shows:
   - provider status (configured/last-run/current)
   - avg latency
   - pending review count

Output required:

- Summary table (Section A-G) with PASS/FAIL
- Successful attempts report (counts + examples)
- Error attempts report (counts + root causes + suggested fixes)
- Public-side UX findings (what student sees when issues happen)
- Final go/no-go recommendation.
  """

## 2) Actual Run Report (This Session)

### Run command

- `php admin/ai_ticket_processor.php 5`

### Raw result

- ok: true
- processed: 1
- classification: `new_or_suspicious_device`
- resolved: true
- announcement_sent: true
- attendance_added: false

## 3) Successful Attempts Report

### Processor execution

- Attempts: 1
- Success: 1
- Failed: 0
- Success rate: 100%

### Successful example

- Ticket timestamp: `2026-04-04 19:20:24`
- Matric: `2023001932`
- Fingerprint: `ccbf1cf9938d5e6be2c06a1dfa186491`
- Classification: `new_or_suspicious_device`
- AI provider: `groq`
- AI model: `llama-3.1-8b-instant`
- AI latency (admin suggestion): `4639 ms`
- AI latency (fingerprint response): `1100 ms`
- Ticket resolved by AI: yes
- Targeted announcement created: yes (`announcement_id=ai_20260410144151_b612a89b`)

### Metrics snapshot (`ai_provider_metrics.json`)

- Provider: groq
- Samples: 2
- Success: 2
- Failure: 0
- Avg latency: 2869.5 ms
- Last latency: 1100 ms

## 4) Error Attempts Report

### Processor-level errors

- Count: 0
- Last run status: no processor errors

### Provider/API errors

- Count: 0 recorded
- Notes: no Groq failure recorded in metrics for this run

## 5) Validation Notes

- `storage/admin/ai_ticket_diagnostics.json` now has 1 entry from this run.
- `storage/admin/support_tickets.json` ticket `2026-04-04 19:20:24` marked resolved with AI fields.
- This run validated suspicious-device branch (review/announce/resolve flow).

## 6) Recommended Next Test Cases (to exercise error paths)

1. Temporarily set invalid Groq key and keep `AI_AUTOMATION_PROVIDER=auto`.
   - Expected: fallback to OpenRouter/Gemini/rules.
2. Submit a revoked fingerprint ticket.
   - Expected: `blocked_revoked_device`, deny path, targeted warning.
3. Submit duplicate same-course action ticket.
   - Expected: duplicate classification and no attendance write.
4. Trigger admin chat with `@ai summarize pending reviews`.
   - Expected: AI chat response with provider metadata.
5. Disable/blank all provider keys except rules.
   - Expected: Dashboard provider shows configured fallback and processor still returns deterministic rule-based suggestions.
6. Verify public helper cue on `index.php` and support CTA link visibility on mobile viewport.
   - Expected: Student can immediately navigate to `support.php` after issue guidance.

## 7) Go/No-Go (based on current evidence)

- Go for current validated branch: YES (suspicious-device automation path passed).
- Full production go/no-go: PENDING additional branch coverage tests listed above.
