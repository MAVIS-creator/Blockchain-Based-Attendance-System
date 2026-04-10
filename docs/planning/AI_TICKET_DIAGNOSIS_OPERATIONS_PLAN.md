# AI Ticket Diagnosis + Auto-Operations Plan

## Objective

Build an **intelligent ticket analysis and auto-remediation system** that:
1. Diagnoses why a student can't mark attendance based on fingerprint/IP analysis
2. Auto-approves low-risk remediation ticket actions if conditions are met
3. Sends targeted device-specific announcements (not broadcast)
4. Tells students on public site: *"Having issues? Contact admin for support"*
5. Optionally replies to chat messages for common issues (in the admin page i mean)
6. All powered by a dedicated non-login **AI Service Account** with explicit, auditable permissions

### Runtime updates (implemented)

- Attendance fencing is course-aware: duplicate blocking now follows `(date, matric, course, action)` and `(date, device, course, action)`.
- Checkout prerequisites are now course-aware (same-course check-in required).
- AI recommendation channel supports provider mode `rules | groq | auto`.
- In `auto` mode, Groq is preferred for fast responses and rules fallback is used if model calls fail.
- Admin review page for AI recommendations is available at `index.php?page=ai_suggestions`.

---

## Current State Analysis (from view_tickets.php code)

### Existing Fingerprint/IP Matching Logic
In `admin/view_tickets.php` lines 317-328:
```php
$fpMatch = $fp ? checkLogMatch($logLines, $fp, 3) : false;  // field 3 in log
$ipMatch = $ip ? checkLogMatch($logLines, $ip, 4) : false;  // field 4 in log
```

**Standard log format:**
```
name | matric | action | FINGERPRINT | IP | MAC | timestamp | userAgent | course | reason
```

So:
- Field 0: name
- Field 1: matric
- Field 2: action (checkin/checkout)
- Field 3: **FINGERPRINT** (checked)
- Field 4: **IP** (checked)
- Field 5: MAC
- ...

### Current Ticket Card Display
Shows:
- Student name + matric
- Message (support issue description)
- **Fingerprint match status** (green ✓ if found in today's logs, red × if not)
- **IP match status** (green ✓ if found in today's logs, red × if not)
- Timestamp
- Manual check-in/out buttons or resolve button

---

## New Ticket Diagnosis Engine

### Phase 1A: Diagnosis Logic (derived from checkLogMatch pattern)

When a support ticket arrives, the AI diagnostic runner checks:

1. **Device Known?**
   - Query today's log file for `ticket.fingerprint` in field 3
   - Query today's log file for `ticket.ip` in field 4
   - Result: `fpMatch` (bool), `ipMatch` (bool)

2. **Device Revoked?**
   - Query `storage/admin/revoked_tokens.json`
   - Check if `ticket.fingerprint` is in the revoked list
   - Result: `isRevoked` (bool)

3. **Issue Classification** (based on above + message analysis):

   **A) Known Device (fpMatch=TRUE, ipMatch=TRUE)**
   - Likely: stale session, expired token, browser cache
   - AI Action: **CAN AUTO-APPROVE**
   - Remedy suggestion: "Click resolve and tell them that they have marked attendance today cause they have marked attendance that's why their logs are found in the db"
   - Optional: Auto-clear old revoked tokens for this device
   - Risk: LOW

   **B) Unknown Device (fpMatch=FALSE, ipMatch=FALSE)**
   - Likely: first time on new browser, new device, switched laptop
   - AI Action: **REQUIRES APPROVAL** (new device unvetted)
   - Remedy suggestion: "Seems you're on a new device. Verify your identity and try again"
   - Send targeted announcement to that device only with verification link
   - Risk: MEDIUM

   **C) Revoked Device (isRevoked=TRUE)**
   - Status: Admin blocked this device explicitly
   - AI Action: **MUST REJECT** (policy violation)
   - Response to student: "Your device was revoked by admin. Contact admin to re-enable."
   - Risk: HIGH (do not auto-approve ever)

   **D) IP Mismatch (fpMatch=TRUE, ipMatch=FALSE)**
   - Likely: VPN, cellular network change, ISP shift
   - AI Action: **MAYBE AUTO-APPROVE** (if same student, recent ticket)
   - Check: Is this the same matric as last know checkin? Within last hour?
   - If yes: auto-approve with confidence
   - If no or unknown: require approval
   - Risk: MEDIUM-HIGH

4. **Message Content Pattern Matching:**
   - Extract keywords from `ticket.message`
   - Patterns like: "can't mark", "attendance", "failed", "won't", "blocked", "can't submit"
   - Classify as **attendance access issue** vs **general question**
   - Only auto-approve if it's clearly an attendance access issue

---

## Auto-Approval Conditions (with Guardrails)

All of these must be TRUE to auto-approve a ticket remediation:

1. **Diagnosis result is LOW-risk** (known device + stale session pattern)
2. **Message matches attendance-failure pattern** (keywords present)
3. **Not previously flagged as abuse** (no recent rapid tickets from same device)
4. **AI has `ticket.resolve` capability** (explicitly granted in AI permissions)
5. **Action is reversible** (e.g., clearing tokens, not permanent ban)
6. **Audit will be created** (every action logged)
7. **Superadmin can override** (kill switch available)

**If ANY condition fails:** mark as `requires_approval` and queue for superadmin review.

---

## Device-Isolated Announcement Channel

### Public-Side Behavior (on attendance submission failure)
When student tries to submit attendance and system detects their device fingerprint is NOT in logs:
- Show error message: **"Having issues with attendance? [Possible causes: Browser cache, Device change, Session expired. Contact admin for support.]"**
- Show support ticket form to report it
- Do NOT broadcast to all students

### Ticket Status API (new endpoint)
**Path:** `ticket_status_api.php`

**Purpose:** Allow a device to query its own ticket status without exposing other devices' tickets

**Input:**
```json
{
  "fingerprint": "abc123...",
  "ip": "192.168.1.100",
  "action": "get_status"
}
```

**Verification:**
- Get ticket for this fingerprint
- Verify IP matches that ticket's IP
- Return ONLY that device's ticket + any targeted AI announcements for that device
- Do NOT return other tickets

**Output:**
```json
{
  "ticket_found": true,
  "status": "pending_ai_review",  // or "resolved", "auto_approved", "awaiting_approval"
  "message": "Your device was not recognized. We're checking if it's an access issue.",
  "guidance": "Please clear your browser cache and try again, or contact admin.",
  "estimated_time": "5-10 minutes"
}
```

---

## Targeted Announcements (not broadcast)

When AI diagnoses a device issue, it can send a **targeted announcement** bound to that device's fingerprint:

**Storage:** `admin/announcement.json`
- Add field: `target_fingerprint` (null = broadcast to all; or specific fingerprint = only that device)

**Example:**
```json
{
  "id": "auto_ai_12345",
  "timestamp": "2026-04-09 10:45:00",
  "title": "Account Access Guidance",
  "message": "Our system detected you're on a new device. Please verify your identity here: [link]",
  "target_fingerprint": "device_abc123xyz...",
  "auto_generated_by": "system_ai_operator",
  "created_for_ticket": "2026-04-09 10:30:00"
}
```

**On public site:**
- When user loads with fingerprint `device_abc123xyz`, fetch announcements
- Show only announcements where `target_fingerprint` is null OR matches their fingerprint
- Other announcements hidden from that device

---

## Chat Message Reply Capability (Phase 6 - Optional)

For common attendance issues, AI can optionally reply in `admin/chat.json`:
- Pattern match: "I can't mark attendance", "attendance fails", etc.
- Generate standard reply: "I understand. Have you tried clearing your browser cache? If that doesn't work, submit a support ticket and our team will help."
- Mark as `auto_replied_by: "system_ai_operator"`
- Student can then escalate to ticket if needed

---

## Implementation Structure

### Phase 1: Foundation (AI Service Identity + Diagnosis Engine)
**Files:**
- `src/AiTicketDiagnoser.php` — diagnosis logic (fpMatch, ipMatch, revoked check, classification)
- `src/AiServiceIdentity.php` — AI account model + non-login enforcement
- `src/AiCapabilityChecker.php` — permission checker
- `storage/admin/ai_accounts.json` — AI identity records
- `storage/admin/ai_permissions.json` — capability matrix

**Tasks:**
- [ ] Create AiServiceIdentity model (system_ai_operator, cannot login, explicit permissions)
- [ ] Create AiTicketDiagnoser class with diagnosis rules A/B/C/D
- [ ] Create AiCapabilityChecker with `ai_can($identity, $capability)` helper
- [ ] Add diagnostic fields to queue schema: `diagnosis`, `confidence_score`, `auto_approvable`
- [ ] Initialize JSON storage files with seed data

**Acceptance:**
- AI identity cannot authenticate via admin login
- Can query its own capabilities
- Diagnosis rules classify test tickets correctly

---

### Phase 2: Ticket Auto-Processing + Queue
**Files:**
- `src/AiActionRouter.php` — routes intent to action type
- `src/AiPolicyGuard.php` — checks auto-approval conditions
- `src/AiActionExecutor.php` — executes approved actions (resolve, add attendance, etc.)
- `storage/admin/ai_action_queue.jsonl` — action queue
- `storage/admin/ai_action_results.jsonl` — outcomes

**Tasks:**
- [ ] Create AiActionRouter (maps ticket → diagnosis → action intent)
- [ ] Create AiPolicyGuard with auto-approval gates
- [ ] Create AiActionExecutor that calls existing ticket helpers
- [ ] Implement queue append/read/update helpers
- [ ] Integrate with `admin/view_tickets.php` ticket resolution logic

**Acceptance:**
- A known device ticket is auto-resolved if conditions met
- A suspicious ticket is queued for approval, not auto-resolved
- Every action creates audit log entry
- Superadmin can view pending actions and override

---

### Phase 3: Public-Side Messaging + Ticket Status API
**Files:**
- `ticket_status_api.php` — new public endpoint for device status queries
- Enhanced `index.php` (attendance form) — show guidance message on failure
- Updated `admin/send_logs_email.php` — optional integration with auto-send

**Tasks:**
- [ ] Create ticket_status_api.php with fingerprint/IP verification
- [ ] Implement device-isolated query logic
- [ ] Add public-side "Contact admin for support" message to attendance form
- [ ] Create response guidance based on diagnosis result
- [ ] Test isolation: verify device A cannot see device B's announcements

**Acceptance:**
- Student on new device sees "Contact admin" message, not "Access Denied"
- ticket_status_api returns only that device's ticket
- Announcements targeting specific fingerprints do not cross-leak

---

### Phase 4: Device-Isolated Announcements
**Files:**
- Enhanced `admin/announcement.php` — add `target_fingerprint` field to UI
- Enhanced `admin/announcement.json` schema — support target field
- Public HTML/JS that fetches announcements — filter by fingerprint

**Tasks:**
- [ ] Add `target_fingerprint` column to announcement storage
- [ ] Update announcement create/edit UI to optionally target a device
- [ ] Update public announcement fetch to filter by matching fingerprints
- [ ] AI can auto-generate targeted announcements on diagnosis
- [ ] Test: verify only matching device sees the announcement

**Acceptance:**
- Superadmin can manually create device-targeted announcements
- AI can create them automatically on ticket diagnosis
- Public site only shows announcements meant for that device

---

### Phase 5: Chat Reply Capability (Optional)
**Files:**
- `src/AiChatResponder.php` — chat message analysis + reply generation
- Enhanced `admin/chat.json` — support `auto_replied_by` field

**Tasks:**
- [ ] Create AiChatResponder with pattern matching for common attendance issues
- [ ] Integrate into chat processing flow
- [ ] Mark auto-replies as generated by `system_ai_operator`
- [ ] Allow student to escalate to ticket if auto-reply doesn't solve it

**Acceptance:**
- Common pattern messages get AI replies
- AI replies are marked as such
- Audit logged

---

### Phase 6: Hardening + Security
**Files:**
- Enhanced logging and rate limiting throughout

**Tasks:**
- [ ] HMAC signing for internal AI action endpoints
- [ ] Replay nonce tracking in queue
- [ ] Rate limiting: max 10 auto-approvals per device per day
- [ ] Comprehensive tests for permission boundaries
- [ ] Finalize audit logging format

---

## Quick Reference: Diagnosis Decision Tree

```
Ticket Arrives
  ↓
Is fingerprint in today's log? (fpMatch)
  ├─ YES
  │   Is IP in today's log? (ipMatch)
  │   ├─ YES → Known Device (LOW RISK)
  │   │        Classification: STALE_SESSION
  │   │        Auto-Approve? IF message matches "can't attend" pattern
  │   │
  │   └─ NO  → IP Mismatch (MEDIUM RISK)
  │           Classification: IP_ROTATION
  │           Auto-Approve? IF same matric, recent
  │
  └─ NO
      Is fingerprint in revoked list? (isRevoked)
      ├─ YES → Revoked Device (HIGH RISK)
      │        Classification: BLOCKED
      │        Auto-Approve? NO (reject with explanation)
      │
      └─ NO  → Unknown Device (MEDIUM RISK)
               Classification: NEW_BROWSER
               Auto-Approve? NO (require approval, send verification)
```

---

## Task Checklist (Execution Order)

1. **Foundation Setup**
   - [ ] Create `src/AiServiceIdentity.php` with service account model
   - [ ] Create `src/AiTicketDiagnoser.php` with diagnosis rules
   - [ ] Initialize `storage/admin/ai_accounts.json`
   - [ ] Initialize `storage/admin/ai_permissions.json`

2. **Action Queue Infrastructure**
   - [ ] Create `src/AiActionRouter.php`
   - [ ] Create `src/AiPolicyGuard.php` with auto-approval gates
   - [ ] Create `src/AiActionExecutor.php`
   - [ ] Create queue helpers in `src/AiQueueHelper.php`

3. **Ticket Integration**
   - [ ] Extract ticket logic from `admin/view_tickets.php` into `admin/includes/ticket_helpers.php`
   - [ ] Create `admin/ai_ticket_processor.php` (cron-able or event-triggered)
   - [ ] Test: auto-approve known device tickets

4. **Public-Side Messaging**
   - [ ] Create `ticket_status_api.php`
   - [ ] Update `index.php` to show "Contact admin" message on failure
   - [ ] Test: device isolation

5. **Announcements**
   - [ ] Enhance `admin/announcement.php` UI for target_fingerprint field
   - [ ] Update public script to filter announcements by device
   - [ ] AI can call announcement creation helpers

6. **Chat (Optional)**
   - [ ] Create `src/AiChatResponder.php`
   - [ ] Integrate into chat processing

7. **Hardening**
   - [ ] Add HMAC, rate limiting, tests
   - [ ] Create comprehensive audit logs

---

## Acceptance Criteria (Final)

1. ✅ AI identity cannot sign in through admin login
2. ✅ AI diagnoses ticket issues correctly (known device / unknown device / revoked / IP rotation)
3. ✅ AI auto-approves only LOW-risk known device tickets if message pattern matches
4. ✅ HIGH-risk tickets queue for superadmin approval
5. ✅ Device-isolated ticket status endpoint returns only owner's data
6. ✅ Public site shows "Contact admin for support" on attendance failure, not expose device mismatch
7. ✅ Announcements can be targeted to specific devices
8. ✅ Every AI action is audited with full payload + outcome
9. ✅ Superadmin can view pending AI actions and approve/reject
10. ✅ Existing manual admin workflows (check-in/out/resolve) continue unchanged
11. ✅ All AI actions are reversible (can view, replay, or undo in audit trail)

---

## Files to Review Before Implementation

- `admin/view_tickets.php` → extract diagnosis logic + resolve logic
- `support.php` → see how public tickets are submitted
- `storage_helpers.php` → reuse format
- `admin/state_helpers.php` → reuse permission pattern
- `admin/announcement.php` → understand announcement schema
- `admin/chat.json` → understand chat schema (if Phase 5 enabled)
