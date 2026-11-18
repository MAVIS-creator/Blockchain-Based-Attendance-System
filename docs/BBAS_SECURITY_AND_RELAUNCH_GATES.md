# BBAS: security + performance release gates (2026-11-18)

## Production status
This is a code audit and staged hardening, **not** a completed penetration test
or production deployment. Existing production records/configuration have not
been inspected. Draft PR #5 is not to be merged or deployed until the gates
below pass. Security hardening PR #4 carries removed historical runtime files;
first back up the live data and rotate exposed historical credentials.

## Attacker models for a university class
- Classmate with matric numbers, access to two browsers or multiple phones,
  ability to replay HTTP requests or edit browser JS, and shared campus NAT.
- Remote student or outsider with fake browser geolocation and stolen screenshots
  of class credentials or a QR. Neither OpenStreetMap nor Google Maps certifies
  physical presence.
- Network peer who can load expensive public routes, flood support tickets,
  and exploit a slow global JSON lock during class starts.
- Low-privilege administrator using a direct .php URL rather than the RBAC
  sidebar; stolen admin session; leaked secrets and developer patcher access.
- Non-malicious errors: flaky mobile data, mismatched clocks, missing GPS,
  wrong active course, duplicate retry after a timeout, and a stopped GCP VM.

## Already staged in draft PR #5
- Private token-only revocation POST instead of public token lists; retire
  leaked all-user SSE and public index-backup.php.
- Protect directly accessible admin route/command pages, CSRF guard course
  changes, deny non-admin requests to sensitive ticket/device actions.
- Reject mismatched client-supplied course against active server course.
- Do not treat normal Cloudflare/Vercel forwarding headers as evidence of VPN.
- Prevent AI IP-only administrative unrevocations; allow only explicit
  provenance-marked, exact-fingerprint temporary locks (separate recovery
  protocol and verification still required). Admin manual attendance uses
  duplicate and chain validation.
- Concurrent chain-lock regression; optional outbox workers to stop provider
  calls consuming the student PHP worker pool; SQL schema and shadow migration.
- Persistent data disk fails closed, worker templates and staged Vercel frontend.

## STILL OPEN — stop-ship risks
1. Student enrollment is not yet authenticated to an authoritative school
   roster. Matric regex, client-provided fingerprint and JS GPS are not proof
   the requester owns that matric or is at the faculty. Add verified enrollment
   and one-active-credential policy. Use possession-bound credentials and
   step-up verification to replace a registered phone. Cross-browser
   fingerprint matching is only a risk signal, not physical-phone attestation.
2. SQL is not yet the authoritative attendance transaction store. The current
   daily-index lock serializes attendance requests; the atomic JSON chain
   reads and rewrites its entire historical contents once per acceptance,
   making latency grow with records. Do not scale to multiple PHP instances
   while relying on local file locks and storage.
3. Avoid trusting JS tab-away strikes alone. Use a server-side eligible
   temporary-lock record, exact per-student identity association, and expiring
   one-time recovery challenges before enabling automatic early unlocks.
   Original client-side lock already expires after 15 minutes.
4. Targeted announcements and ticket status still rely on client-supplied
   fingerprint/IP fields rather than a verified ticket capability or session.
   Remove sensitive admin/private fields, use a random lookup capability and
   shorten/rotate access on resolution.
5. Admin session recovery and privileged developer editing need a separate
   authenticated review. Production patcher is disabled by default in this
   draft. Protect every remaining direct admin endpoint with RBAC+CSRF.
6. Add explicit authoritative database migration of manual attendance,
   support tickets, admin reports, idempotency receipts, geofence/identity
   decisions, revocations and blockchain projection.
7. Configure Cloudflare Free and origin firewall rules: API requests must
   reach only the intended origin. Force HTTPS, secure admin cookies, preserve
   trusted client IPs; never trust arbitrary forwarded headers.
8. Rehearse backup/restore, worker restarts, SQL migration conflicts, AI
   outage/quota exhaustion, GCP stop/start, and rollback before first class.

## Release topology, minimizing ongoing cost
- Vercel serves immutable/public HTML, JS and CSS (static, separately cached).
- Cloudflare Free is optional edge DNS/protection. Do not cache POST, personal
  attendance/ticket JSON, sessions, revocation state, or admin endpoints.
- Scheduled GCP Compute Engine e2-standard-4 (4 vCPU,16GB) as an initial
  rehearsal size, with Nginx/PHP-FPM/OPcache + MariaDB/InnoDB locally,
  persistent disk and remote encrypted backup; downsize after evidence.
- A separate supervised worker handles hosted LLM, email, Supabase retries,
  and blockchain anchoring. No LLM latency in student submissions.
- Plan eventual stateless Cloud Run autoscaling only after moving all
  authoritative mutable state into reliable SQL/shared storage.
- Start the VM 30–60 min before any class and confirm health/backup/SQL/worker
  checks. Never automatically stop with outstanding submissions/jobs.

## Rehearsals — do NOT run synthetic load on a public/live class
1. Prepare a private staging host with fake matric/student records and realistic
   session/device credentials. Match actual PHP-FPM, MariaDB and Cloudflare
   configurations; remove debugging & relax policies.
2. Test 50, 150, 300, 500 and 800 staggered students with *realistic burst
   arrivals*. Simulate mixed phones/browsers, campus shared IP (one NAT),
   and separate mobile-provider IP pools. The old tests alone were insufficient.
3. Run normal check-in then check-out; also wrong course, duplicate tap,
   same browser for two identities, second browser/device, GPS denied,
   expired mode, revoked token, lost response/retry, worker timeout,
   slow external provider and cold-start VM.
4. Compare immutable receipts, daily DB records, all PDF/CSV exports and
   chain projection. **Zero lost acknowledged records**, zero invalid
   duplicate pass, zero unauthorized manual/AI attendance writes.
5. Suggested prelaunch service objectives (validate with class reps):
   >=99.5% valid submission success, p95 <=2 seconds for check-in API,
   p99 <=5 seconds, no entire classroom falsely locked, zero unverified
   attendance pass. Measure errors by exact code, not one global failure rate.
6. Pilot with 20–30 real volunteers using actual campus Wi-Fi and Nigerian
   mobile data, then one smaller class before offering to all 500 students.
   Class rep must observe results and sign off; keep AttendanceBot as
   separate fallback until the rollout proves itself.

## Incident controls
- Health checks for VM, DNS/TLS, PHP-FPM busy workers, SQL locks/slow queries,
  disk space, rate-limit false positives, queue age, model errors and
  acknowledgement-to-DB reconciliation.
- During incidents disable optional AI or announcements first, not attendance
  transaction validation. Maintain a guarded admin-assisted fallback.
- Never declare a class successful from synthetic GET tests alone.
