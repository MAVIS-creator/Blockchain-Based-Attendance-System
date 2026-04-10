# Course-Aware Fencing + Groq AI Implementation Plan

## Scope

Implement Option 1 (course-aware attendance fencing) and extend AI automation with:
- Admin page for AI suggestions/review signals
- Groq provider integration with fast-response preference and rules fallback

## Planned changes

1. **Course-aware attendance fencing (`submit.php`)**
   - Change duplicate checks from day-level to `(date, matric, course, action)`
   - Change device duplicate checks from `(date, device, action)` to `(date, device, course, action)`
   - Make checkout prerequisite require prior checkin for same `(date, matric, course)`

2. **Ticket context enrichment (`support.php`)**
   - Persist `course` and `requested_action` in support tickets
   - Keep backward compatibility if older tickets lack these fields

3. **AI diagnosis course-awareness (`src/AiTicketDiagnoser.php`)**
   - Add optional course/action context into daily log stats
   - Compute course-scoped counts and duplicate signals

4. **AI automation action safety (`src/AiTicketAutomationEngine.php`)**
   - Use ticket course for auto attendance append
   - Select checkin/checkout under guarded conditions using requested action + course-scoped state

5. **Groq provider layer (`src/AiProviderClient.php`)**
   - Provider mode: `rules|groq|auto`
   - Auto mode prefers Groq and falls back to rules
   - Return metadata (`provider`, `model`, `latency_ms`) for diagnostics

6. **Admin AI suggestions view**
   - Add `admin/ai_suggestions.php` to review recent diagnostics and recommendations
   - Add route + sidebar entry in `admin/state_helpers.php` and `admin/includes/sidebar.php`

7. **Env wiring**
   - Add AI automation provider keys/settings to `.env.example` and `.env.local.example`
   - Add local runtime settings to `.env.local` for Groq usage

8. **Validation**
   - Run syntax/error checks on modified/new PHP files
   - Smoke run AI processor and confirm no parse/runtime errors

## Guardrails

- Keep existing manual admin workflows unchanged
- Preserve backward compatibility for existing tickets/log formats
- Keep AI write actions capability-gated and auditable
