# AI Rules Training + Auto Attendance Decision Plan

**Date:** 2026-04-12
**Scope:** Support-ticket automation, anti-rigging rules, and admin rule-teaching UX

## Goals from user request

1. Allow automatic attendance write (checkin/checkout) when strict policy conditions pass.
2. Always write with the ticket fingerprint + IP and link to the same matric in logs.
3. Detect and block rig attempts when fingerprint is linked to another matric for same-day/course policy scope.
4. Add an admin “teach rules” interface (chat-like) where rules can be added/rephrased and applied immediately.
5. Provide visibility into what rules the system currently understands.

---

## Existing baseline (already present)

- Ticket diagnosis and automation pipeline exists:
  - `src/AiTicketDiagnoser.php`
  - `src/AiTicketAutomationEngine.php`
- Current course guard added recently:
  - `invalid_course_reference`
  - `inactive_course_reference`
- Existing AI context page:
  - `admin/ai_context_preview.php` (preview + rebuild only)
- Rule-based fallback provider:
  - `src/AiProviderClient.php` (`rules-v1`, `rules-fingerprint-v1`)

---

## Proposed implementation

## Phase A — Strict auto-write policy upgrade (core behavior)

### A1) Add explicit policy object in diagnosis output

Extend `AiTicketDiagnoser::diagnose()` output with:

- `auto_write_allowed` (bool)
- `auto_write_action` (`checkin|checkout|none`)
- `auto_write_block_reason` (string)
- `identity_link_strength` (`strong|medium|weak`)

### A2) Enforce anti-rig guard before any auto-write

Before `legitimate_session_issue` can auto-write, require:

- Fingerprint not actively shared across other matrics in same-day/course context.
- If shared fingerprint exists with other matric => classify as `policy_device_sharing_risk` (or stronger `fingerprint_conflict_rig_attempt`).
- Auto-write hard blocked.

### A3) Deterministic write action mapping

When allowed:

- If requested action is valid and not duplicate, write that action.
- If requested action empty:
  - if no checkin exists => write `checkin`
  - if checkin exists and checkout missing => write `checkout`
  - else block as duplicate cycle.

### A4) Fingerprint/IP log guarantees

On ticket-driven auto-write, always use:

- fingerprint: ticket fingerprint (fallback blocked if missing)
- ip: ticket ip (fallback blocked if missing)
- course: ticket course (must be valid and active)
- reason: standard marker `AI auto-fix: policy-approved attendance write`

If fingerprint or IP missing, classify `manual_review_required` with reason `missing_identity_keys_for_auto_write`.

---

## Phase B — Rulebook runtime (teach + rephrase + immediate apply)

### B1) Add runtime rulebook storage

Create new file:

- `storage/admin/ai_rulebook.json`

Schema:

- `version`
- `updated_at`
- `rules[]` with fields:
  - `id`
  - `priority`
  - `enabled`
  - `intent` (plain-language description)
  - `conditions` (normalized condition map)
  - `outcome` (classification/action/announcement template)
  - `examples[]`
  - `created_by`, `updated_by`

### B2) Add rulebook engine adapter

Create:

- `src/AiRulebook.php`

Responsibilities:

- load/save rulebook
- evaluate ordered rules before static fallback rules
- apply first matching rule with highest priority
- expose `listRules()` and `explainDecision()`

### B3) Integrate into diagnoser + provider

- `AiTicketDiagnoser` checks `AiRulebook` first for classification overrides.
- `AiProviderClient` fingerprint/admin rule responses can use rulebook phrasing templates when present.

---

## Phase C — Admin “Teach Rules” UI (chat-like)

### C1) Add page

Create `admin/ai_rulebook.php` with:

- current active rules list
- “teach rule” chat box
- “rephrase existing rule” action
- enable/disable toggle
- priority editor
- dry-run simulator against sample ticket payload

### C2) Add endpoint

Create `admin/ai_rulebook_api.php` (CSRF + role protected):

Actions:

- `list_rules`
- `teach_rule`
- `rephrase_rule`
- `toggle_rule`
- `simulate_rule`

### C3) Teach/rephrase parser (instant apply)

Plain-language admin text is parsed into normalized condition/outcome blocks.
If parser confidence is low, return structured clarification prompt.
If accepted, write to `ai_rulebook.json` immediately (no restart).

---

## Phase D — Safety + observability

- Add audit log entries for every rule create/update/rephrase/toggle.
- Add diagnostics fields:
  - `matched_rule_id`
  - `rulebook_version`
  - `rulebook_applied`
- Add “Known Rules” card to `admin/ai_context_preview.php` with latest top rules and version.

---

## Acceptance criteria

1. Auto-write occurs only when policy gates pass.
2. Auto-write always logs ticket fingerprint/IP and matric linkage.
3. Fingerprint-multi-matric conflict blocks auto-write and classifies rig risk.
4. Admin can teach a new rule from plain text; it applies immediately to new tickets.
5. Admin can rephrase a rule and see updated behavior without deployment.
6. AI context preview shows rulebook visibility (what system currently understands).

---

## Important implementation note

“Train immediately” here will be implemented as **runtime rulebook updates** (deterministic policy learning), not ML weight training. This gives immediate, auditable behavior changes inside your current PHP app architecture.

---

## Execution order after approval

1. Phase A (core policy/auto-write hardening)
2. Phase B (rulebook backend)
3. Phase C (chat-like teaching UI)
4. Phase D (audit/preview visibility)
