# BBAS GCP / SQL staged rollout — 300–500 students

## Critical gate
Never merge or deploy this PR without backing up and verifying the live attendance
logs, JSON chain, fingerprints, admin accounts, sessions and private settings.
Security PR #4 removes previously tracked runtime data. Rotate exposed credentials.
The SQL attendance table is **staging only**: full attendance read/write
migration and legacy admin exports have **not yet** been switched to it.

## Single-VM configuration
- Ubuntu 24.04; Nginx + PHP-FPM 8.2+ with OPcache; MariaDB 10.11+ InnoDB.
- Start 4 vCPU/16 GiB for the 500-student rehearsal, and resize only after measurements.
- Serve static frontend from Vercel, or retain the original PHP frontend while
  parity checks are ongoing. Cloudflare free can protect DNS/assets.
- Set STORAGE_PATH=/srv/bbas/data; keep it outside /srv/bbas/app and web root.
- Keep MariaDB bound to 127.0.0.1; use a least-privileged SQL account;
  do not expose port 3306. Store credentials outside Git.
- Apply db/schema.mysql.sql, then set:
  BBAS_SQL_DSN=mysql:host=127.0.0.1;port=3306;dbname=bbas;charset=utf8mb4
  BBAS_SQL_USER=<secret-user>
  BBAS_SQL_PASSWORD=<secret-password>
  BBAS_AI_QUEUE_DRIVER=mysql
  AI_AUTOMATION_PROVIDER=auto
  AI_GEMINI_MODEL=gemini-2.5-flash-lite
  GROQ_MODEL=openai/gpt-oss-20b
  AI_AUTOMATION_TIMEOUT_SECONDS=8
- Install deploy/gcp/bbas-ai-worker.service; edit locations/user to match your VM.
  Run systemctl daemon-reload; systemctl enable --now bbas-ai-worker.
- Schedule the VM to start 30–60 minutes before class and stop only after all
  attendance writes, support jobs, exports, backups and checks are finished.
  Persistent disk charges continue while the VM is stopped.
- Require verified trusted proxy CIDRs if Cloudflare or Vercel proxies requests.
  Never trust arbitrary X-Forwarded-For. Allow only trusted origin domains.
- Keep health checks and alerts; a billing budget alert alone does not cap usage.

## Queue semantics
- SQL job IDs are unique by ticket timestamp; claims are transactional leases.
- Five failed attempts produce a dead-letter state; inspect and retry manually.
- Single supervised worker initially protects attendance capacity from AI spikes.
- The default file queue also retries safely with an exclusive worker mutex.
- A ticket may be retried after a worker crash. All automatic attendance actions
  must remain idempotent and verification-gated. Never grant attendance from LLM
  output alone. Review pending/dead SQL jobs on a schedule.

## Performance and data checks
1. Run isolated tests, then simulate 300, 500, and 800 students with a campus
   NAT/shared IP and bursty submissions, including duplicate retry scenarios.
2. Confirm 0 missing acknowledged records, 0 invalid duplicate passes and
   correct hash-chain continuity under concurrency and process termination.
3. Check exact status/rate-limit counters, PHP-FPM worker saturation, disk I/O
   queue depth, CPU/RAM, MySQL slow queries, AI worker queue age, and provider
   quota errors. Measure p95/p99 student request latency.
4. Rehearse an actual mobile-network test with consent and class operators.
5. Import historic attendance separately, validate totals and export parity,
   then enable SQL primary only in a future verified migration change.

## Remaining release blockers
- Public PHP page to static Vercel frontend feature parity and cross-origin
  auth/CSRF/CORS, especially fingerprint, geofence, tab-away policy and support.
- SQL-first attendance ingestion/exports and migration from all legacy JSON/log
  readers. Existing critical attendance records are still file-backed.
- Prove full offline fallback, restore, and failover during live traffic.
- Confirm actual GCP runtime, DNS, HTTPS, network latency and Cloudflare settings.

## Static student interface (staged preview)
- Vercel project root directory: `web`.
- Before deployment, set `web/config.js` to the HTTPS GCP API origin,
  or replace it in Vercel's build pipeline. No secrets belong in it.
- Set `BBAS_STATIC_FRONTEND_ORIGIN=https://<exact-vercel-domain>`
  on GCP. Frontend-to-backend calls use CORS without cookies.
- The static interface preserves the legacy device hash, browser local token,
  6-second tab-away grace, three strikes, geolocation submission fields and
  server-controlled action/course. It never decides attendance locally.
- Support and rules currently navigate to their unchanged GCP PHP pages.
- Do not switch DNS until an operator checks every anti-fraud policy and
  mobile browser in the full regression suite. The legacy PHP page stays
  available as a rollback.

## SQL attendance data migration staging
- SQL table exists, but the PHP attendance writer and admin readers remain on
  legacy files until an end-to-end checked transaction/read migration is built.
- `php tools/import_attendance_mysql.php --dry-run` enumerates valid legacy
  records and refuses duplicates/anomalies. `--apply` uses one DB transaction
  with rollback on error. Run only after a verified backup and schema install.
- Important: avoid automatic seven-day deletion. BBAS no longer sweeps old
  attendance logs during each student request. Archive only through an explicit
  independently backed-up retention process.
- The ticket AI worker now requests admin+student explanations together in one
  provider call, with rule-based fallback when the provider is unavailable.

## Optional post-submit background queue
- After verifying the worker, install
  `deploy/gcp/bbas-post-submit-worker.service`; start with
  `systemctl enable --now bbas-post-submit-worker`.
- Only then set `BBAS_ASYNC_POST_SUBMIT=1`. When enabled, each successful
  chain commit durably enqueues Supabase dual-write, log anchoring and legacy
  email auto-send work before returning success, freeing PHP-FPM promptly.
- The queue is at-least-once: review `post_submit_deadletter.jsonl` and ensure
  external writes are idempotent. If enqueue fails, the original synchronous
  post-response processing remains the fallback.
- Keep `BBAS_ASYNC_POST_SUBMIT` disabled until a live worker, backups and
  recovery checks are verified. An unstarted worker would leave queued tasks
  pending indefinitely.
