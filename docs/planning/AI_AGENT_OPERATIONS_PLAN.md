# AI Operations Agent Plan (Dedicated Non-Login System Account)

## Objective
Build a dedicated **AI system operator** inside the platform that can perform scoped admin actions (support tickets, announcements, auto-send logs, and selected future actions) with explicit, auditable permissions.

This agent is **not a human-login account**. It is a service identity used by controlled backend endpoints/workers.

---

## Reality Check Against Current Codebase

### Already present
- Ticket pipeline exists:
  - Public submission in `support.php`
  - Admin processing in `admin/view_tickets.php`
  - Ticket store in `storage/admin/support_tickets.json`
- Admin role + dynamic permissions infrastructure exists:
  - `admin/state_helpers.php` (`admin_route_catalog`, `admin_assignable_pages`, permissions loader)
  - `admin/roles.php`, `admin/accounts.php`
- Auto-send logs exists and works:
  - `admin/auto_send_logs.php`
  - `admin/send_logs_email.php`

### Not yet present (needs implementation)
- `ticket_status_api.php` endpoint (as named in proposed overview)
- `src/TicketRouter.php` AI router class
- Dedicated AI system identity + action permission matrix
- AI action queue/approval workflow with policy checks

---

## Target Design

### 1) Dedicated AI Identity (non-login)
Create a service identity record (e.g., `system_ai_operator`) that:
- cannot authenticate via `admin/login.php`
- has no password-based sign-in surface
- is used only by internal agent execution endpoints/jobs

Proposed storage:
- `storage/admin/ai_accounts.json` (service identities)
- `storage/admin/ai_permissions.json` (capability matrix)

---

### 2) Capability-Based Permissions (not page-based only)
Introduce explicit action permissions such as:
- `ticket.read`, `ticket.resolve`, `ticket.bulk_resolve`, `ticket.add_attendance`
- `announcement.read`, `announcement.write`
- `logs.export`, `logs.auto_send`
- `settings.read`, `settings.update_limited`

Each AI identity gets an allow-list of capabilities.

---

### 3) AI Action Router + Queue
Add a backend orchestration layer:
- `src/AiActionRouter.php` (intent -> action mapping)
- `src/AiPolicyGuard.php` (permission, risk, and argument checks)
- `src/AiActionExecutor.php` (calls existing safe functions/endpoints)

Action queue files:
- `storage/admin/ai_action_queue.jsonl` (requested actions)
- `storage/admin/ai_action_results.jsonl` (outcomes)

Execution modes:
- `auto` for low-risk actions
- `requires_approval` for high-risk actions

---

### 4) Approval Workflow (Superadmin)
Add admin UI page `admin/ai_actions.php` to:
- list pending AI actions
- approve/reject with reason
- inspect action payload + dry-run output

Guardrails:
- high-risk capabilities require superadmin approval
- every execution path writes detailed audit entries

---

### 5) Device-Isolated Ticket Response Channel
Implement endpoint:
- `ticket_status_api.php`

Behavior:
- takes ticket/device context from client
- verifies fingerprint/IP binding against saved ticket identity
- returns only that device’s relevant ticket state

This formalizes your privacy-preserving one-to-one support status flow.

---

### 6) Safe Integrations with Existing Features
The AI executor should call existing logic instead of duplicating business rules:
- ticket actions reuse logic from `admin/view_tickets.php` extracted into reusable helpers
- announcement actions reuse announcement storage helpers
- auto-send actions call `admin/auto_send_logs.php` internals through a shared function layer

---

### 7) Security Controls
- HMAC-signed internal action requests for agent execution endpoints
- strict schema validation for action payloads
- CSRF still required for all human-admin approval actions
- rate limiting and replay protection for action submission IDs
- full immutable audit trail in admin logs + dedicated AI audit file

---

## Proposed File Additions

- `src/AiActionRouter.php`
- `src/AiPolicyGuard.php`
- `src/AiActionExecutor.php`
- `src/TicketRouter.php`
- `ticket_status_api.php`
- `admin/ai_actions.php`
- `admin/ai_accounts.php` (optional, if identity management UI is needed)
- `storage/admin/ai_accounts.json` (runtime)
- `storage/admin/ai_permissions.json` (runtime)
- `storage/admin/ai_action_queue.jsonl` (runtime)
- `storage/admin/ai_action_results.jsonl` (runtime)

---

## Implementation Phases

### Phase 1 — Foundation
- Add AI service identity model + non-login enforcement
- Add capability matrix + checker helper (`ai_can($identity, $capability)`)
- Add queue format and append/read helpers

### Phase 2 — Ticket Routing & Actions
- Build `ticket_status_api.php` with fingerprint/IP ownership checks
- Extract ticket resolve/bulk/attendance logic from `admin/view_tickets.php` into reusable helpers
- Wire AI executor to ticket actions

### Phase 3 — Announcement + Auto-send
- Add announcement action handlers with policy limits
- Add auto-send invocation path and dry-run support
- Add output normalization for agent responses

### Phase 4 — Admin Approval UI
- Create `admin/ai_actions.php` for approve/reject workflows
- Add filters: pending/approved/rejected/executed/failed
- Add action detail panel and immutable audit log links

### Phase 5 — Hardening
- HMAC request signing for internal action endpoint
- replay nonce tracking
- rate limiting and abuse controls
- tests for permission boundaries and ticket isolation

---

## Acceptance Criteria

1. AI identity cannot sign in through admin login.
2. AI can execute only capabilities explicitly granted.
3. High-risk actions require superadmin approval.
4. Ticket status endpoint returns only owner-bound records by fingerprint/IP checks.
5. Every AI action is auditable (who/what/when/result).
6. Existing manual admin workflows continue to function unchanged.

---

## Notes
- Keep root minimal; place planning docs under `docs/planning` (done).
- Reuse existing storage/env helpers and admin audit style for consistency.
- Prefer incremental extraction from existing ticket/announcement code to avoid regressions.
