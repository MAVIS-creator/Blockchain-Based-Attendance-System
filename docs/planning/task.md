# Patcher Copilot Assistant — Phase Tasks (Todo)

Source: `docs/planning/PATCHER_COPILOT_ASSISTANT_IMPLEMENTATION_PLAN.md`

## Phase 1 — Issue-first Copilot Assistant (current iteration)

### Backend
- [x] Add `auto` provider support in resolver functions.
- [x] Add strategy-based provider/model picker helper.
- [x] Add fallback execution wrapper around AI request.
- [x] Add `ai_analyze_issue` API action with issue + scan mode input.
- [x] Return timeline event payload from backend.
- [x] Return provider/model/fallback metadata in analysis response.

### Frontend (Patcher UI)
- [x] Add issue-first analysis UX (Analyze Issue flow).
- [x] Add provider option `Auto (best available)`.
- [x] Add scan mode selector (`quick|standard|deep`).
- [x] Add processing timeline rendering panel.
- [x] Render structured result summary/risk/checks.
- [x] Keep manual apply approval workflow.

### Validation
- [x] Syntax lint for `admin/patcher.php`.
- [x] Manual QA scenario #1: UI/mobile issue analysis.
- [x] Manual QA scenario #2: hybrid/env config issue analysis.
- [x] Manual QA scenario #3: logs/debug issue analysis.

---

## Phase 1.1 — Hardening pass (recommended next)

### Safety/Quality
- [x] Add explicit secret redaction pass before prompt assembly.
- [x] Tighten strict JSON schema validation and fallback handling.
- [x] Add explicit `fallback_reason` field in UI output card.
- [x] Add confidence/quality indicator for AI output.

### UX polish
- [x] Show provider+model badge next to analysis result title.
- [x] Add "why these files were scanned" expandable section.
- [x] Add one-click open for suggested target file if different from current file.

---

## Phase 2 — Extended assistant capabilities
- [x] Add asynchronous job mode (polling) for long analyses.
- [x] Add richer diagnosis tabs (root cause, fix options, tradeoffs).
- [x] Add staged apply preview with backup snapshot metadata.

---

## Phase 3 — Release workflow (future)
- [x] Controlled upload/review pipeline (not direct production overwrite).
- [x] Staging slot approval integration.
- [x] Optional CI checks before patch apply.
