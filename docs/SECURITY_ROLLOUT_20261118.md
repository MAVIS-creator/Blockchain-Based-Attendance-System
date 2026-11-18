# BBAS security rollout — 18 November 2026

This change deliberately removes mutable data from the **Git HEAD**. Removing
tracked data in Git is NOT sufficient to remove it from repository history
or to revoke stolen credentials.

## 1. Before merging or deploying

1. Put the attendance application into maintenance mode and arrange a short
   maintenance window. Confirm who owns the live Azure deployment.
2. Create an encrypted, access-restricted backup of the actual `STORAGE_PATH`,
   the legacy `admin/*.json` configuration, and any SQL admin account backend.
   Store it **outside** `/home/site/wwwroot` and outside Git. Test restore.
3. Confirm active account data and attendance logs reside in persistent storage
   **outside the served document root**. If necessary, set `STORAGE_PATH`
   to the existing persistent storage directory and migrate the live data.
   The code currently has a project-local fallback; do not rely on that fallback
   for public Nginx hosting.
4. Confirm how the Azure App Service actually loads Nginx configuration.
   `nginx-azure.conf` is a template and is NOT installed automatically by
   `startup.sh`. Apply equivalent deny rules in the active server/edge config
   and check that `/storage/`, `/home/data/`, `/tools/`, `/tests/`,
   JSON/JSONL/SQL/CSV and other internal paths return **403 or 404**.
5. Configure `TRUSTED_PROXY_CIDRS` to include only verified immediate
   proxy CIDRs, with sanitized or correctly appended X-Forwarded-For headers.
   Otherwise the system uses REMOTE_ADDR rather than trusting forged headers.
6. Run the PHP Composer workflow, `php tests/security_smoke.php`, a full
   check-in/out smoke test, chain validation, admin login/roles, support ticket
   processing and export. Verify email and Supabase outbox replay.

## 2. Credential exposure containment

- Rotate every administrator account password that may have been seeded or
  recorded in the repository; disable unknown/inactive accounts.
- Invalidate old app sessions and tracker cookies on the **live server** after
  a coordinated administrator re-login plan.
- Review and rotate potentially exposed SMTP, Supabase, Azure SQL and Key Vault,
  cloud automation, AI provider and Polygon credentials. Never publish them.
- Review the repository's commit history, branches, forks and previously shared
  clones. The sensitive data existed in earlier commits and remains retrievable
  there. A coordinated history rewrite (e.g. with git-filter-repo) and any
  GitHub support escalation must be planned separately; it disrupts clones and
  DOES NOT replace credential rotation.
- Do not paste credentials into GitHub issues, logs or chat for troubleshooting.

## 3. Operational controls

- `fix_chain.php` is CLI-only. It requires `--apply` and preserves the old
  chain file before rewriting. Treat chain repairs as exceptional, audited work.
- `replay_outbox.php`, `admin/ai_ticket_worker.php`,
  `tools/seed_sql_admin_accounts.php` and `diagnostic.php` are CLI-only.
  Schedule their execution using PHP CLI, not public HTTP endpoints.
- SQL seeding only creates an optional named admin when specifically requested
  with `--maximus` and environment-supplied `BBAS_SEED_ADMIN_EMAIL` and
  `BBAS_SEED_ADMIN_PASSWORD`.
- The old CSRF harness no longer contains default production credentials. Use
  a disposable test account and do not run it against the public website.

## 4. Branch protection

The connected GitHub App cannot change repository administration settings.
In GitHub → Settings → Rules → Rulesets, require passing `PHP Composer`
checks, at least one PR review, prevent force-pushes and limit direct
pushes to `main`. Only enable the rules after confirming collaborator access.

## 5. Recovery

If deployment changes cause login failures or missing attendance records,
STOP writes. Restore the private runtime backup to the original
`STORAGE_PATH` and redeploy the prior known-good code. Do not restore
historical credentials to a public Git working tree. Log the rollback and
recheck integrity.
