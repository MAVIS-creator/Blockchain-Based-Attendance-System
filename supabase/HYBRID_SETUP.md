# Supabase Hybrid Setup

This project uses **file-first + Supabase best-effort dual-write**.

## 1) Create tables in Supabase
1. Open Supabase Dashboard → SQL Editor.
2. Run `supabase/schema.sql`.

## 2) Configure environment
In local `.env` (never commit this file):

- `HYBRID_MODE=dual_write`
- `SUPABASE_URL=https://<your-project-ref>.supabase.co`
- `SUPABASE_SERVICE_ROLE_KEY=<service-role-key>`
- `STORAGE_PATH=` (optional, defaults to `admin/logs`)

## 3) Verify dual-write
1. Submit attendance from `index.php`.
2. Submit a support ticket from `support.php`.
3. Confirm rows appear in:
   - `public.attendance_logs`
   - `public.support_tickets`

## 4) Failure behavior (important)
- If Supabase is down, app **still succeeds** using local files.
- Failed DB writes are queued to `admin/logs/hybrid_outbox.jsonl`.

## 5) Recommended production hardening
- Rotate service-role keys if they were ever exposed.
- Keep `.env` and runtime JSON/log files untracked in git.
- Restrict outbound network to trusted endpoints where possible.
