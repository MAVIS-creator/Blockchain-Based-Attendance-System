# Patcher Copilot Assistant — Implementation Plan, Tasks, and Walkthrough

## 1) Goal
Upgrade `admin/patcher.php` into a Copilot-like assistant where an admin can describe an issue and receive:
- live processing/progress steps,
- root-cause analysis,
- suggested safe fixes,
- patch preview and approval-based apply.

Target platform: Azure App Service (no VPS required).

---

## 2) Scope (Phase 1)
Phase 1 focuses on issue-driven analysis and safe patch suggestions.

### In scope
- Add provider option: `auto | openrouter | gemini`.
- Add issue-analysis flow (not file-pick-first flow).
- Add processing timeline feed ("Scanning files", "Reading logs", etc.).
- Add structured result blocks:
  - summary,
  - likely causes,
  - impacted files,
  - patch preview,
  - risk,
  - test checklist.
- Keep apply flow manual (explicit approval before write).

### Out of scope (later phases)
- direct upload-to-production pipeline,
- autonomous no-approval code writes,
- full CI/CD integration from patcher UI.

---

## 3) Architecture

### Backend updates (`admin/patcher.php`)
1. **Provider resolution**
   - Support `auto` in provider resolver.
   - If `auto`, choose provider/model by strategy and fallback handling.

2. **New API actions**
   - `ai_analyze_issue`:
     - input: `issue`, `provider`, `model`, `scan_mode`.
     - returns: structured analysis payload + timeline events.
   - `ai_result` (if asynchronous mode is used later).

3. **Auto strategy logic**
   - Strategy presets: `balanced` (default), `quality`, `speed`, `cost`.
   - Fallback flow:
     - try primary provider/model,
     - on timeout/429/5xx/missing key, fallback to secondary.
   - Return metadata: provider_used, model_used, fallback_used, fallback_reason.

4. **Context collection for analysis**
   - Build bounded context from:
     - relevant files by keyword mapping,
     - optional recent git changes,
     - optional relevant logs,
     - non-secret env/runtime state.

5. **Safe output schema enforcement**
   - Require JSON response shape with required fields.
   - Reject malformed response and return safe error.

6. **Apply safety**
   - Keep allowlist extension checks.
   - Keep path traversal guard.
   - Keep size bounds.
   - Keep explicit user approval before applying patch.

### Frontend updates (`admin/patcher.php` UI/JS)
1. **Issue-first panel**
   - textarea for issue description,
   - scan mode selector,
   - provider selector with `Auto`.

2. **Live timeline panel**
   - render step cards with statuses:
     - pending/running/success/error.
   - examples:
     - "Indexed candidate files",
     - "Checked env consistency",
     - "Generated patch preview".

3. **Result tabs/panels**
   - Diagnosis,
   - Suggested patch,
   - Risk & checks,
   - Apply changes.

4. **Provider/model transparency**
   - show exact provider+model used and fallback info.

---

## 4) Configuration

Add/confirm env keys:
- `PATCHER_AI_PROVIDER=auto`
- `PATCHER_AI_AUTO_STRATEGY=balanced`
- `PATCHER_AI_AUTO_PRIMARY=gemini`
- `PATCHER_AI_AUTO_FALLBACK=openrouter`
- existing:
  - `PATCHER_OPENROUTER_API_KEY`, `PATCHER_OPENROUTER_MODEL`
  - `PATCHER_GEMINI_API_KEY`, `PATCHER_GEMINI_MODEL`

Notes:
- On blank model input, fallback behavior remains deterministic.
- Do not expose secret values in UI output.

---

## 5) Security & Reliability Requirements
- Superadmin-only access.
- CSRF validation on mutating endpoints.
- Prompt redaction for obvious secret patterns.
- No arbitrary shell command expansion beyond existing safe allowlist.
- Manual approval before write operations.
- Friendly failures with fallback diagnostics.

---

## 6) Implementation Tasks (Todo Source)

### Task Group A — Backend logic
1. Add `auto` provider support in resolver functions.
2. Add strategy-based provider/model picker helper.
3. Add fallback execution wrapper around AI request.
4. Add `ai_analyze_issue` API action with strict JSON schema validation.
5. Add timeline event generation payload for frontend rendering.

### Task Group B — Frontend UX
6. Add issue-first assistant panel UI.
7. Add provider dropdown option `Auto`.
8. Add timeline/progress feed component in JS.
9. Add structured result renderer (diagnosis/patch/risk/checklist).
10. Show provider/model/fallback badge in output.

### Task Group C — Safety & validation
11. Add secret redaction for logs/env snippets before prompt assembly.
12. Validate all paths/extensions in apply action remain enforced.
13. Add clear user-facing error states for provider failures.

### Task Group D — Verification
14. Lint/check modified PHP file(s).
15. [x] Manual QA: run 3 issue scenarios (UI, env/hybrid, logs).
16. Confirm apply flow only writes after explicit approval.

---

## 7) Walkthrough (Post-Implementation Acceptance)

### Admin user flow
1. Open `Tools -> Patcher`.
2. Enter issue text (e.g., "Hybrid admin read not saving").
3. Choose Provider = `Auto`.
4. Click **Analyze Issue**.
5. Observe timeline cards updating in sequence.
6. Review result sections:
   - summary + likely root cause,
   - files analyzed,
   - suggested patch,
   - risk and tests.
7. Click **Apply** only if approved.
8. Run listed validation checks.

### Expected behavior
- If preferred provider fails, system falls back and reports it.
- Results are deterministic JSON-shaped output.
- No secret leakage in the rendered analysis.

### Done criteria
- Timeline feed works.
- Auto provider routing works.
- Fallback works.
- Patch suggestions render correctly.
- Approval gate before write works.

---

## 8) Rollout Note
Implement Phase 1 behind a simple feature flag if needed, then iterate with user feedback before Phase 2.
