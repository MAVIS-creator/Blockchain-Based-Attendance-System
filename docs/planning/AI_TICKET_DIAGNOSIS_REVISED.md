# AI Ticket Diagnosis + Auto-Operations Plan (REVISED)

**Status:** Foundation Not Yet Started  
**Last Updated:** 2026-04-09  
**Current Impl Phase:** 0 (Planning Complete → Ready for Phase 1)

---

## Actual Current Infrastructure

### ✅ Already Exists
- **Public Ticket Submission:** `support.php` with fingerprint + IP capture
- **Admin Ticket Processing:** `admin/view_tickets.php` with bulk resolve/checkin/out actions
- **Ticket Storage:** `storage/admin/support_tickets.json` (array of ticket objects)
- **Log Format:** Standardized pipe-delimited in `storage/logs/YYYY-MM-DD.log`
  ```
  name | matric | action | fingerprint | ip | mac | timestamp | userAgent | course | reason
  ```
  - Field 0: name
  - Field 1: matric
  - Field 2: action (checkin/checkout)
  - Field 3: **fingerprint** (checked in diagnosis)
  - Field 4: **ip** (checked in diagnosis)
  - Field 5: mac
  - Field 6: timestamp
  - Field 7: userAgent
  - Field 8: course
  - Field 9: reason
- **Revoked Tokens:** `storage/admin/revoked.json` (tokens[], ips[], macs[])
- **Announcements:** `admin/announcement.php` + `storage/admin/announcement.json`
- **Chat:** `storage/admin/chat.json`
- **Admin Permissions:** `admin/state_helpers.php` with role catalog + permission loader
- **Audit Logging:** `admin_log_action()` function + `storage/admin/admin_audit.json`

### ❌ Does NOT Yet Exist
- `src/AiServiceIdentity.php`
- `src/AiTicketDiagnoser.php`
- `src/AiCapabilityChecker.php`
- `src/AiActionRouter.php`
- `src/AiPolicyGuard.php`
- `src/AiActionExecutor.php`
- `src/AiQueueHelper.php`
- `admin/includes/ticket_helpers.php` (extraction of logic from view_tickets.php)
- `storage/admin/ai_accounts.json`
- `storage/admin/ai_permissions.json`
- `storage/admin/ai_action_queue.jsonl`
- `storage/admin/ai_action_results.jsonl`
- `ticket_status_api.php`
- `admin/ai_actions.php` (approval UI)
- `admin/ai_ticket_processor.php` (diagnosis runner)
- `src/AiChatResponder.php` (optional Phase 5)

---

## Ticket Diagnosis Engine (Detailed Spec)

When a support ticket is submitted via `support.php`, it includes:
```json
{
  "name": "Student Name",
  "matric": "2023011471",
  "message": "I can't mark attendance",
  "fingerprint": "device_abc123xyz...",
  "ip": "192.168.1.100",
  "timestamp": "2026-04-09 14:30:00",
  "resolved": false
}
```

### Diagnosis Rules (Logic Derived from view_tickets.php)

**Step 1: Check Device Recognition**
```php
// From view_tickets.php lines 317-328:
$logLines = file($logFile, FILE_SKIP_EMPTY_LINES);
$fpMatch = checkLogMatch($logLines, $ticket['fingerprint'], 3);  // field 3 = fingerprint
$ipMatch = checkLogMatch($logLines, $ticket['ip'], 4);           // field 4 = ip
```

**Step 2: Check Revocation**
```php
$revokedFile = 'storage/admin/revoked.json';
$revoked = json_decode(file_get_contents($revokedFile), true);
$isRevoked = in_array($ticket['fingerprint'], $revoked['tokens'] ?? [])
          || in_array($ticket['ip'], $revoked['ips'] ?? []);
```

**Step 3: Message Pattern Analysis**
```php
$keywords = ['cant', 'can\'t', 'cannot', 'fail', 'blocked', 'issue', 'error', 'won\'t'];
$msgLower = strtolower($ticket['message']);
$hasAttendanceKeyword = preg_match('/(attend|mark|submit|check)/', $msgLower);
$hasFailureKeyword = preg_match('/(' . implode('|', $keywords) . ')/', $msgLower);
$isAttendanceFailure = $hasAttendanceKeyword && $hasFailureKeyword;
```

### Classification Matrix

| fpMatch | ipMatch | isRevoked | Message Type | **Classification** | **Risk** | **Auto-Approvable?** | **Action** |
|---------|---------|-----------|--------------|---|---|---|---|
| TRUE | TRUE | FALSE | attendance fail | STALE_SESSION | LOW | ✅ **YES** | Auto-resolve, notify student attendance found in logs |
| TRUE | FALSE | FALSE | attendance fail | IP_ROTATION | MEDIUM | ⚠ depends | If recent ticket from same matric, approve; else pending |
| FALSE | FALSE | FALSE | any | NEW_BROWSER | MEDIUM | ❌ NO | Pending approval, send verification |
| TRUE/FALSE | TRUE/FALSE | TRUE | any | BLOCKED | HIGH | ❌ **NO** | Reject, notify device revoked |

**Special Cases:**
- **No Message Pattern Match** (e.g., "Hi", "Hello") → UNCLEAR, require approval regardless of fpMatch
- **Duplicate Tickets** (same fingerprint within 30 min) → SPAM, rate-limit (max 3 auto-approvals per device per hour)
- **Message Abuse** (offensive text, SQL injection patterns) → BLOCKED, reject automatically

---

## Auto-Approval Guardrails

All conditions must be TRUE to auto-approve:

1. ✅ **Diagnosis confidence ≥ 0.85** (LOW or MEDIUM-LOW risk)
2. ✅ **Message is attendance-related** (has keywords)
3. ✅ **Not previously flagged** (no recent auto-rejects from this device)
4. ✅ **AI has `ticket.resolve` permission** (explicit in ai_permissions.json)
5. ✅ **Not abuse pattern** (not duplicate, not spam)
6. ✅ **Superadmin override available** (kill switch in admin UI)

**If ANY fails:** → Mark as `requires_approval`, queue for superadmin review

---

## Device-Isolated Messaging Flow

### Public-Side (index.php / submit.php)

When attendance submission fails due to unknown fingerprint:
```
1. Detect: device.fingerprint NOT in today's logs
2. Show error: "Having issues with attendance? Please contact support for help."
3. Offer ticket form link
4. Do NOT expose: "Device unrecognized" or fingerprint details
```

### Ticket Status API (ticket_status_api.php)

**Purpose:** Allow device to query its own status without exposing other devices

**Endpoint:** `GET /ticket_status_api.php`

**Params:**
```json
{
  "fingerprint": "device_abc123xyz...",
  "ip": "192.168.1.100"
}
```

**Verification:**
1. Load support_tickets.json
2. Find ticket(s) with matching fingerprint
3. Verify IP matches ticket's recorded IP
4. Return ONLY that device's data

**Response:**
```json
{
  "status": 200,
  "found": true,
  "ticket": {
    "message": "Your device was not recognized. We're checking if it's an access issue.",
    "status": "auto_approved",  // or "pending", "resolved", "rejected"
    "guidance": "Our system found you've already marked attendance today. Please refresh your browser.",
    "can_retry": true,
    "timestamp": "2026-04-09 14:30:00"
  },
  "announcement": null  // or targeted announcement obj
}
```

### Device-Targeted Announcements

Extend `announcement.json` schema:
```json
{
  "id": "auto_ai_20260409_001",
  "timestamp": "2026-04-09 14:35:00",
  "title": "Account Verification Required",
  "message": "Your device appears new to our system. Please verify your identity.",
  "enabled": true,
  "severity": "info",
  "target_fingerprint": "device_abc123xyz...",  // null = broadcast; set = device-only
  "auto_generated_by": "system_ai_operator",
  "created_for_ticket": "2026-04-09 14:30:00"
}
```

On public site announcement fetch: filter where `target_fingerprint` is null OR matches client fingerprint

---

## Implementation Phases (Detailed Breakdown)

### Phase 1: Foundation (AI Service Identity + Diagnosis)

**New Files to Create:**
- `src/AiServiceIdentity.php` — AI account model
- `src/AiTicketDiagnoser.php` — Diagnosis logic
- `src/AiCapabilityChecker.php` — Permission checker
- `storage/admin/ai_accounts.json` — AI identity seed data
- `storage/admin/ai_permissions.json` — Capability matrix

**Detailed Tasks:**

1. **Create `src/AiServiceIdentity.php`**
   - Class representing non-login service identity
   - Properties: id, name, created_at, capabilities[], can_login (always false)
   - Static method: `load($id)` → loads from ai_accounts.json or null
   - Enforcer: `canLogin()` always returns false
   - Example: `system_ai_operator` identity with capabilities: `['ticket.read', 'ticket.diagnose', 'ticket.resolve_stale_session']`

2. **Create `src/AiTicketDiagnoser.php`**
   - Core diagnosis engine
   - Methods:
     - `diagnose($ticket, $logFile)` → returns diagnosis object
     - `checkFpMatch($fp, $logLines)` → bool
     - `checkIpMatch($ip, $logLines)` → bool
     - `checkRevoked($fingerprint, $ip)` → bool
     - `classifyIssue($fpMatch, $ipMatch, $isRevoked, $msg)` → string (STALE_SESSION|IP_ROTATION|NEW_BROWSER|BLOCKED|UNCLEAR)
     - `confidenceScore($classification)` → float (0.0-1.0)
     - `analyzeMessage($msg)` → object {hasKeywords, keywords[], isAttendanceFailure}

3. **Create `src/AiCapabilityChecker.php`**
   - Load AI permissions from ai_permissions.json
   - Helper function: `ai_can($serviceIdentityId, $capability)` → bool
   - Example capabilities:
     - `ticket.read` (can fetch tickets)
     - `ticket.diagnose` (can run diagnosis)
     - `ticket.resolve_stale_session` (can auto-resolve known device tickets)
     - `ticket.add_attendance` (can add checkin/out records)
     - `announcement.write` (can send targeted announcements)
     - `logs.export` (for auto-send integration)

4. **Create `storage/admin/ai_accounts.json`**
   ```json
   {
     "system_ai_operator": {
       "id": "system_ai_operator",
       "name": "System AI Operator",
       "created_at": "2026-04-09",
       "can_login": false,
       "capabilities": [
         "ticket.read",
         "ticket.diagnose",
         "ticket.resolve_stale_session",
         "announcement.write",
         "logs.export"
       ]
     }
   }
   ```

5. **Create `storage/admin/ai_permissions.json`**
   ```json
   {
     "system_ai_operator": {
       "ticket.read": true,
       "ticket.diagnose": true,
       "ticket.resolve_stale_session": true,
       "ticket.resolve_new_browser": false,
       "ticket.add_attendance": true,
       "announcement.write": true,
       "logs.export": true
     }
   }
   ```

6. **Add to `admin/state_helpers.php`**
   - Extend with: `ai_service_account_file()` → returns ai_accounts.json path
   - Extend with: `ai_permissions_file()` → returns ai_permissions.json path

**Testing (Acceptance Criteria):**
- [ ] `AiServiceIdentity::load('system_ai_operator')` returns object with can_login=false
- [ ] `ai_can('system_ai_operator', 'ticket.resolve_stale_session')` returns true
- [ ] `ai_can('system_ai_operator', 'ticket.resolve_new_browser')` returns false
- [ ] `AiTicketDiagnoser::diagnose()` correctly classifies 5 test tickets
- [ ] Confidence scores correlate with risk levels

---

### Phase 2: Action Queue + Ticket Integration

**New Files:**
- `admin/includes/ticket_helpers.php` — Extracted logic from view_tickets.php
- `src/AiActionRouter.php` — Maps diagnosis to action
- `src/AiPolicyGuard.php` — Auto-approval gates
- `src/AiActionExecutor.php` — Executes actions
- `src/AiQueueHelper.php` — Queue management
- `storage/admin/ai_action_queue.jsonl` — Action queue (one JSON per line)
- `storage/admin/ai_action_results.jsonl` — Results (one JSON per line)

**Detailed Tasks:**

1. **Extract `admin/includes/ticket_helpers.php`**
   - From `admin/view_tickets.php`, extract:
     - `resolve_ticket_atomic($ticketsFile, $resolveTime)` →  refactor as `ticket_resolve($resolveTime)`
     - `bulk_update_tickets_atomic(...)` → refactor as `ticket_add_attendance($name, $matric, $action, $reason, $course)`
   - Helper: `get_support_tickets($resolved=null)` → load from file
   - Helper: `ticket_get($timestamp)` → load single ticket
   - Helper: `ticket_update($timestamp, $changes)` → atomic update

2. **Create `src/AiActionRouter.php`**
   - Routes diagnosis → action
   - Method: `route($diagnosis)` → returns action object
   - Logic:
     ```
     if (diagnosis.classification == 'STALE_SESSION' && confidence >= 0.85)
       action = { type: 'resolve', auto_approvable: true, reason: 'Known device, stale session' }
     elif (diagnosis.classification == 'IP_ROTATION' && isRecent)
       action = { type: 'resolve', auto_approvable: true, reason: 'Same device, IP rotated' }
     elif (diagnosis.classification == 'NEW_BROWSER')
       action = { type: 'send_verification', auto_approvable: false, reason: 'New device requires verification' }
     elif (diagnosis.classification == 'BLOCKED')
       action = { type: 'reject', auto_approvable: false, reason: 'Device revoked' }
     else
       action = { type: 'pending_review', auto_approvable: false, reason: 'Unclear issue' }
     ```

3. **Create `src/AiPolicyGuard.php`**
   - Guards auto-approval with condition checks
   - Method: `canAutoApprove($action, $ticket, $serviceIdentity)` → bool
   - Checks:
     - Has capability `ai_can($serviceIdentity, 'ticket.resolve_*')`
     - Action marked auto_approvable
     - No recent abuse (rate limit check)
     - No contradicting signals (e.g., multiple resolves for same device in 5min = suspicious)

4. **Create `src/AiActionExecutor.php`**
   - Executes approved actions
   - Method: `execute($action, $ticket, $serviceIdentity)` → result object
   - Calls `ticket_helpers.php` functions
   - Logs every action via `admin_log_action()` with category: 'AI_Operator'
   - Result includes: success bool, timestamp, details

5. **Create `src/AiQueueHelper.php`**
   - JSONL helpers (one JSON object per line)
   - Method: `queue_append($action_obj)` → appends to ai_action_queue.jsonl
   - Method: `queue_pending()` → returns array of actions with status='pending'
   - Method: `queue_mark($id, $status, $reason)` → updates status (pending/approved/executed/rejected/failed)
   - Method: `result_append($result_obj)` → appends to ai_action_results.jsonl

**Testing:**
- [ ] `ticket_resolve()` updates support_tickets.json atomically
- [ ] `ticket_add_attendance()` logs to storage/logs/YYYY-MM-DD.log
- [ ] Router classifies test tickets correctly
- [ ] PolicyGuard blocks unauthorized actions
- [ ] Executor calls correct helpers and logs all actions
- [ ] Queue operations preserve atomicity

---

### Phase 3: Ticket Processor + Public API

**New Files:**
- `admin/ai_ticket_processor.php` — Cron-able diagnosis runner
- `ticket_status_api.php` — Public device status query endpoint

**Detailed Tasks:**

1. **Create `admin/ai_ticket_processor.php`**
   - Entry point: CLI or cron or admin trigger
   - Logic:
     ```
     1. Load all unresolved tickets from support_tickets.json
     2. For each ticket:
        a. Get today's log file (storage/logs/YYYY-MM-DD.log)
        b. Run AiTicketDiagnoser::diagnose()
        c. Route via AiActionRouter
        d. Check PolicyGuard
        e. If auto_approvable && all guards pass:
           - Run Executor
           - Mark ticket resolved
           - Log action
        f. Else:
           - Queue for superadmin review
           - Create targeted announcement (optional)
     3. Report results
     ```
   - CLI args: `--dry-run`, `--force`, `--ticket-id=<timestamp>`
   - Output: summary of processed tickets, auto-approved count, pending count

2. **Create `ticket_status_api.php`**
   - No login required (public endpoint)
   - Params: `fingerprint`, `ip`
   - Logic:
     ```
     1. Verify fingerprint + ip match a ticket
     2. Get that ticket's diagnosis status (auto_approved/pending/rejected/resolved)
     3. Get any targeted announcements for that fingerprint
     4. Return device-isolated response
     ```
   - Use existing ticket_helpers.php functions

**Testing:**
- [ ] Processor auto-approves 3 known-device test tickets
- [ ] Processor queues 2 new-browser test tickets
- [ ] ticket_status_api returns only matching fingerprint's data
- [ ] ticket_status_api prevents fingerprint A from seeing fingerprint B's data

---

### Phase 4: Admin UI + Targeted Announcements

**New Files:**
- `admin/ai_actions.php` — Admin approval/review page
- Enhanced `admin/announcement.php` — Add target_fingerprint field

**Detailed Tasks:**

1. **Create `admin/ai_actions.php`**
   - Requires superadmin role
   - Layout: pending actions → details → approve/reject buttons
   - Features:
     - Filter: pending/approved/executed/rejected/failed
     - View payload + diagnosis + dry-run output
     - Approve with reason
     - Reject with reason + notify student
     - View audit trail (linked to admin_audit_log)
   - Style: Stitch UI consistent with existing admin pages

2. **Enhance `admin/announcement.php`**
   - Add checkbox: "Target specific device?"
   - Add text field: "Fingerprint (leave blank for broadcast)"
   - Auto-fill from AI processor when sending verification to new device
   - Schema update: add `target_fingerprint` field to announcement.json

**Testing:**
- [ ] Superadmin can view pending AI actions
- [ ] Can approve/reject with custom reason
- [ ] Approved action executes on confirm
- [ ] Rejection sends notification to student
- [ ] Targeted announcements only show in matching fingerprint

---

### Phase 5: Chat Reply Capability (Optional)

**New Files:**
- `src/AiChatResponder.php` — Chat analysis + reply generation

**Detailed Tasks:**

1. **Create `src/AiChatResponder.php`**
   - Pattern matching on common attendance issues
   - Generates helpful auto-replies
   - Marks replies with `auto_replied_by: 'system_ai_operator'`

**Testing:**
- [ ] Common issues get AI replies
- [ ] Non-matching messages not auto-replied

---

### Phase 6: Hardening + Security

**Enhanced:**
- All AI endpoints with HMAC signature validation
- Rate limiting (max 10 auto-approvals per device per day)
- Replay nonce tracking
- Comprehensive tests

---

## Task Checklist (Execution Order)

### Phase 1
- [ ] 1.1 Create `src/AiServiceIdentity.php`
- [ ] 1.2 Create `src/AiTicketDiagnoser.php`
- [ ] 1.3 Create `src/AiCapabilityChecker.php`
- [ ] 1.4 Create `storage/admin/ai_accounts.json`
- [ ] 1.5 Create `storage/admin/ai_permissions.json`
- [ ] 1.6 Update `admin/state_helpers.php` with ai_*_file() helpers
- [ ] 1.7 Test diagnosis on 5 sample tickets

### Phase 2
- [ ] 2.1 Create `admin/includes/ticket_helpers.php` (extract from view_tickets.php)
- [ ] 2.2 Create `src/AiActionRouter.php`
- [ ] 2.3 Create `src/AiPolicyGuard.php`
- [ ] 2.4 Create `src/AiActionExecutor.php`
- [ ] 2.5 Create `src/AiQueueHelper.php`
- [ ] 2.6 Create `storage/admin/ai_action_queue.jsonl` (seed: empty)
- [ ] 2.7 Create `storage/admin/ai_action_results.jsonl` (seed: empty)
- [ ] 2.8 Test action routing and execution

### Phase 3
- [ ] 3.1 Create `admin/ai_ticket_processor.php`
- [ ] 3.2 Create `ticket_status_api.php`
- [ ] 3.3 Test end-to-end: ticket → diagnosis → auto-approval → resolve

### Phase 4
- [ ] 4.1 Create `admin/ai_actions.php`
- [ ] 4.2 Enhance `admin/announcement.php` with target_fingerprint
- [ ] 4.3 Update `get_announcement.php` to filter by fingerprint
- [ ] 4.4 Test targeted announcements

### Phase 5 (Optional)
- [ ] 5.1 Create `src/AiChatResponder.php`
- [ ] 5.2 Integrate into chat flow

### Phase 6
- [ ] 6.1 Add HMAC request signing
- [ ] 6.2 Add rate limiting
- [ ] 6.3 Full test suite

---

## Acceptance Criteria

1. ✅ AI identity cannot sign in through `admin/login.php`
2. ✅ AI diagnoses ticket issues correctly (5/5 test cases)
3. ✅ AI auto-approves only LOW-risk STALE_SESSION tickets
4. ✅ MEDIUM/HIGH-risk tickets queue for superadmin approval
5. ✅ Device-isolated status API returns only owner's data
6. ✅ Public site shows "Contact admin for support" (not device internals)
7. ✅ Targeted announcements filter correctly by fingerprint
8. ✅ Every AI action is audited in admin_audit.json
9. ✅ Superadmin UI allows approve/reject of pending actions
10. ✅ Existing manual admin workflows continue unchanged

---

## Files to Extract/Reference During Implementation

- `admin/view_tickets.php` → resolve_ticket_atomic(), bulk_update_tickets_atomic(), checkLogMatch()
- `support.php` → ticket submission pattern
- `admin/state_helpers.php` → permission loading pattern, audit logging
- `storage_helpers.php` → file migration helper pattern
- `storage/admin/support_tickets.json` → ticket schema
- `storage/admin/revoked.json` → revoked fingerprints/IPs/MACs
- `admin/announcement.php` → announcement UI pattern
- `admin/cache_helpers.php` → caching utilities

---

## Key Differences from Original Plan

✅ **Now Reflects Reality:**
- Specific log field indices (fingerprint=3, ip=4)
- Actual existing permission system in state_helpers.php
- No ticket_helpers.php yet (must extract/create) 
- No separate TicketRouter.php (use AiActionRouter instead)
- Dual-write support already in place (hybrid_dual_write.php)
- Audit system uses admin_log_action() function
- Public API should NOT expose fingerprint mismatch (show generic "contact support")

✅ **Scope Clarifications:**
- AI diagnosis runs on **unresolved tickets** (either on-demand or cron)
- Auto-approval only for **STALE_SESSION** classification
- IP_ROTATION requires **recency check** (same matric, ticket within 1 hour)
- Chat replies are **optional** (Phase 5)
- HMAC + rate limiting are **hardening only** (Phase 6)

---

## Next Step: Phase 1 Implementation

Ready to proceed? Confirm:
1. Diagnosis rules (5 classifications) approved ✅
2. Auto-approval conditions acceptable ✅
3. Device-isolated messaging approach correct ✅

Then start Phase 1 with:
- AiServiceIdentity.php
- AiTicketDiagnoser.php with 5 classification logic
- AiCapabilityChecker.php
- Initial ai_accounts.json + ai_permissions.json
- Tests on sample tickets

