# Supabase Hybrid Setup

This project uses **file-first + Supabase best-effort dual-write**.

## 1) Create tables in Supabase

1. Open Supabase Dashboard → SQL Editor.
2. Run `supabase/schema.sql`.

## 2) Configure environment

In local `.env` (never commit this file):

- `HYBRID_MODE=dual_write`
- `HYBRID_ADMIN_READ=true`
- `SUPABASE_URL=https://<your-project-ref>.supabase.co`
- `SUPABASE_SERVICE_ROLE_KEY=<service-role-key>`
- `STORAGE_PATH=` (optional, defaults to `./storage`)

## 3) Verify dual-write

1. Submit attendance from `index.php`.
2. Submit a support ticket from `support.php`.
3. Confirm rows appear in:
   - `public.attendance_logs`
   - `public.support_tickets`

## 4) Failure behavior (important)

- If Supabase is down, app **still succeeds** using local files.
- Failed DB writes are queued to `STORAGE_PATH/logs/hybrid_outbox.jsonl`.

## 5) Replay failed writes

Run replay manually (CLI):

- `php replay_outbox.php`
- `php replay_outbox.php 500` (replay up to 500 queued records)

For scheduled retry, run this periodically via Task Scheduler or cron.

## 6) Recommended production hardening

- Rotate service-role keys if they were ever exposed.
- Keep `.env` and runtime JSON/log files untracked in git.
- Restrict outbound network to trusted endpoints where possible.
