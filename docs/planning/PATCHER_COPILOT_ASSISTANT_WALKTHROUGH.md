# Patcher Copilot Assistant — Walkthrough (Current Build)

## Implemented in this iteration
- `admin/patcher.php` updated with:
  - provider mode: `auto | openrouter | gemini`
  - strategy-driven auto provider selection
  - fallback provider execution
  - issue-based analysis API: `api=ai_analyze_issue`
  - scan mode support: `quick | standard | deep`
  - timeline payload and timeline UI rendering
  - structured result rendering + manual apply workflow
   - prompt secret redaction for context snippets and file prompts
   - strict result normalization (`schema_valid` flag)
   - fallback reason reporting from auto-provider routing
   - provider/model badge in UI metrics
   - confidence indicator (`score` + `level`) in metrics
   - expandable "why files were scanned" rationale section
   - asynchronous issue analysis job flow (`ai_analyze_issue_start` + `ai_analyze_issue_poll`)
   - staged timeline updates during polling (discovery → context → reasoning)
   - diagnosis tabs: Root Cause, Fix Options, Tradeoffs
   - extended normalized schema fields: `root_cause`, `fix_options[]`, `tradeoffs[]`
   - staged apply preview with backup snapshot metadata before overwrite
   - release request queue with approve/reject review actions

## How to use
1. Open **Tools → Patcher**.
2. In AI panel, choose:
   - **Provider**: `Auto (best available)`
   - **Scan Mode**: `Standard` (or `Deep` for complex cases)
3. Enter issue text (example: `Hybrid admin read not saving on App Service`).
4. Click **Analyze Issue**.
5. Watch **Processing Timeline** cards update in stages while the job is polled.
6. Review result:
   - explanation
   - root cause
   - fix options
   - tradeoffs
   - risk
   - confidence
   - checks
   - file-selection rationale
   - patch preview
   - diff preview
7. If approved:
   - stage the apply to inspect snapshot metadata
   - submit the release request for review
   - approve the request from the release queue to create the backup snapshot and write the file.
   - PHP targets are linted again before approval, so syntax failures block the final apply.

## Verification completed
- PHP lint passed:
  - `php -l admin/patcher.php`
- Editor diagnostics for modified file: no syntax errors.
- Release approval now performs a pre-apply validation gate, and PHP files must pass lint before they can be approved.

## Notes
- Auto mode attempts provider/model selection and uses fallback on provider failure.
- Manual approval gate remains in place before writing file changes.
- If AI returns malformed JSON, response is normalized safely and flagged.
- Async analysis jobs are persisted under `storage/admin/patcher_jobs/` for polling.
- Staged apply records backup snapshots under `storage/admin/patcher_backups/`.
- Pending release requests are stored under `storage/admin/patcher_releases/` until approved.
- Release approval includes a lightweight pre-apply validation check (PHP lint for `.php` targets).
- Additional hardening tasks are listed in `docs/planning/task.md` (Phase 1.1).
