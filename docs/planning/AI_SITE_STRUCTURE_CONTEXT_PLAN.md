# AI Site Structure Insight Plan

## Goal

Give the AI assistant full, reliable awareness of the platform structure (public pages, admin routes, sidebar/navigation groups, feature ownership) without model retraining.

## Why training data is not required

- This is a rapidly changing app; retraining/fine-tuning becomes stale quickly.
- We already have high-quality local sources (`PROJECT_STRUCTURE.md`, `README.md`, `admin/includes/sidebar.php`, router/page maps).
- Injecting fresh context at inference-time is faster, cheaper, and easier to maintain.

## Proposed approach (context injection / lightweight RAG)

1. Build a canonical runtime site context payload:
   - Public routes/pages and purpose
   - Admin routes/pages and purpose
   - Sidebar groups and labels (actual UI navigation)
   - AI-related endpoints and where they are surfaced in UI

- Source-backed response directives (answer only from indexed sources)

2. Add a small context builder class (cached) that composes this payload from:
   - `PROJECT_STRUCTURE.md`
   - `README.md`
   - `admin/includes/sidebar.php`
   - Optional static map for critical routes not explicit in docs
3. Add full-repository scan + index generation:

- Scan all project pages/endpoints (`*.php`) and key docs (`*.md`, selected `*.json` metadata)
- Build a normalized index: route/file, page label, section/group, capability summary, and source file reference
- Persist index snapshot (cache file) for fast prompt assembly
- Exclude noisy/generated/vendor paths (`vendor/`, backups, runtime logs/data)

4. Add answer-directness policy to prompt context:

- Prefer direct, action-oriented answers with explicit navigation paths (e.g., `index.php?page=ai_suggestions`)
- Cite source file hints in reasoning context (internal, not necessarily shown verbatim to end user)
- If route/page is not in index, respond with uncertainty instead of guessing
- Keep responses concise and deterministic for repeated similar queries

5. Inject this payload into AI prompts in `AiProviderClient`:
   - Ticket resolution prompt
   - Fingerprint/student-facing response prompt
   - Admin chat reply prompt
6. Add strict limits/guardrails:
   - Max context chars/tokens per request
   - Priority sections (nav + route map first)
   - Graceful fallback if docs missing
7. Add visibility for operators:
   - Diagnostics fields for `site_context_version`, `site_context_source_count`

- Diagnostics fields for `site_context_indexed_pages`, `site_context_last_scan_at`
- Optional admin preview panel later (phase 2)

## Implementation steps

### Phase 1 (now)

- [ ] Create `src/AiSiteStructureContext.php`
  - Build context string from known files + route map
  - Implement repository scanner for pages/docs and produce normalized in-memory index
  - Add include/exclude path rules to avoid noisy runtime/vendor data
  - Cache assembled context for performance
- [ ] Update `src/AiProviderClient.php`
  - Append context block to `buildPrompt(...)`
  - Add response policy hints for direct answers + no guessing
  - Ensure prompt remains bounded by max char budget
- [ ] Add environment settings
  - `AI_SITE_CONTEXT_ENABLED=true`
  - `AI_SITE_CONTEXT_MAX_CHARS=3000`
  - `AI_SITE_CONTEXT_SCAN_ENABLED=true`
  - `AI_SITE_CONTEXT_SCAN_INCLUDE=*.php,*.md`
  - `AI_SITE_CONTEXT_SCAN_EXCLUDE=vendor/,storage/logs/,storage/backups/,admin/backups/`
- [ ] Add diagnostics metadata in AI engine output where useful
  - Include scan stats: pages indexed, sources used, last scan timestamp

### Phase 2 (optional)

- [ ] Add “AI Context Preview” in admin UI (read-only)
- [ ] Add auto-refresh/rebuild button and timestamp
- [ ] Add page-level confidence report (which response came from which source path)

## Validation plan

- Run lint checks on modified PHP files.
- Execute AI processor once and verify diagnostics include context metadata.
- Test admin chat query: ask where specific page lives (e.g., "Where is AI Suggestions page?") and confirm accurate navigation answer.
- Test student-facing guidance consistency with current public pages.
- Run repository-scan validation:
  - Confirm expected pages/routes are indexed (public + admin + APIs)
  - Confirm excluded paths are not indexed
  - Confirm route answers remain correct after sidebar/page updates
- Run directness validation:
  - Ask location/action questions and verify responses are specific (path + page) and non-generic
  - Ask about a non-existent page and verify AI states uncertainty (no hallucinated path)

## Risks and mitigations

- **Risk:** Prompt bloat / latency
  - **Mitigation:** strict char cap, priority ordering, cached context
- **Risk:** Docs drift from code
  - **Mitigation:** include full page scan + sidebar parse + explicit route map from live code
- **Risk:** Over-confident wrong navigation replies
  - **Mitigation:** enforce "no source => no assertion" response policy and uncertainty fallback
- **Risk:** Scan cost on large projects
  - **Mitigation:** cache index with mtime checks and incremental rebuilds

## Outcome

AI gains practical “full insight” into site structure/nav behavior using live project context, without expensive or brittle training workflows.
