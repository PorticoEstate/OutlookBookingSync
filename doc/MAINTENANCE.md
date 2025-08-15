# Maintenance Guide (Runbook)

Operational guidance for running, monitoring, and troubleshooting the Calendar Bridge.

## Daily Operations

- Health checks:
  - `/health` – quick probe
  - `/health/system` – detailed status
  - `/bridges/health` – per-bridge health
  - `/health/sync-status` – detailed sync status

- Alerts:
  - Trigger: `POST /alerts/check`
  - List: `GET /alerts`
  - Stats: `GET /alerts/stats`
  - Acknowledge: `POST /alerts/{id}/acknowledge`
  - Cleanup: `DELETE /alerts/old`

## Sync and Queue Management

- Process pending syncs: `POST /bridges/process-pending-syncs[/{bridge}]`
- Re-enable failed events: `POST /bridges/re-enable-failed[/{bridge}]`
- Deletions flow:
  - Detect: `POST /bridges/sync-deletions`
  - Process queue: `POST /bridges/process-deletion-queue`

## Resource Mapping

- List: `GET /mappings/resources`
- Create: `POST /mappings/resources`
- Update: `PUT /mappings/resources/{id}`
- Delete by key: `DELETE /mappings/resources/by-key/{bridge_from}/{source_calendar_id}/{target_calendar_id}`
- Lookup by resource: `GET /mappings/resources/by-resource/{source_calendar_id}`

## Webhooks

- Bridge handler: `POST /bridges/webhook/{bridge}`
- Subscriptions: `POST /bridges/{bridge}/subscriptions`
- Legacy Outlook webhook mirror: gated by `ENABLE_LEGACY_WEBHOOKS` (default false)

## Environment and Secrets

- API key: header `api_key: <API_KEY>` required by all API endpoints
- Rotate secrets by updating `.env` and restarting the service
- Add new env vars to `.env.example` and docs (never commit real secrets)

## Database and Migrations

- Baseline: `database/bridge_schema.sql`
- Migrations: `database/migrations/*.sql` (apply in order)
- Backup before applying migrations
- Document apply steps in PRs; keep `doc/MAINTENANCE.md` updated for critical ops

## Backups and Recovery

- Regular DB backups (schema + data)
- To recover failed syncs:
  - Re-enable failed: `POST /bridges/re-enable-failed[/{bridge}]`
  - Process pending: `POST /bridges/process-pending-syncs[/{bridge}]`
  - Inspect alerts, then re-run checks

## Monitoring Dashboard

- `/health/dashboard` JSON for frontend dashboard (static `public/` assets served by web server)
- Static dashboard HTML: `/dashboard` (served by web server via .htaccess)

## Cron Suggestions

- Alerts check: every 5–10 minutes
- Process pending syncs: every 5 minutes
- Deletion queue: every 2–5 minutes
- Clear old alerts: daily
- Cleanup sync logs: daily (POST /maintenance/cleanup-logs?days=30)
  - Configure retention via env var CLEANUP_DAYS (default 30)
  - Cron is pre-wired in docker entrypoint at 03:00

## Cron jobs (container)

The Docker entrypoint installs cron jobs for the www-data user. Key jobs and environment:

Environment exported to cron

- API_KEY – used for all cron HTTP calls (send as header `api_key`)
- BRIDGE_URL – base URL for internal HTTP calls (default: `http://localhost`)
- DEFAULT_TENANT_ID – tenant used when no `X-Tenant-Id` is provided (single-tenant mode)
- CLEANUP_DAYS – sync log retention (default: 30)
- RENEW_MINUTES – webhook renew window in minutes (default: 1440)
- TENANT_MODE – `single` (default) or `multi` (multi-tenant processing)
- ENABLE_MULTI_TENANT_SYNC – `true`/`false` to enable periodic per-tenant sync runner (default: false)
- SYNC_WINDOW_DAYS – lookback window days for the optional per-tenant sync runner (default: 2)

Jobs installed

- Bidirectional sync windows
  - booking_system → outlook every 5 minutes
  - outlook → booking_system every 10 minutes (handle_deletions=1)
- Deletions/cancellations processor (enhanced script) every 5 minutes
  - Honors TENANT_MODE: single runs once (scoped by DEFAULT_TENANT_ID); multi discovers all tenants and sends `X-Tenant-Id` for each
- Optional per-tenant periodic sync runner every 10 minutes
  - Enabled only if TENANT_MODE=multi and ENABLE_MULTI_TENANT_SYNC=true
  - Skips tenants without active mappings
- Health checks every 10–15 minutes
- Maintenance (cleanup logs daily; renew Outlook subscriptions hourly)

Multi-tenant behavior

- TENANT_MODE=single (default)
  - Cron calls do not send `X-Tenant-Id`, so the app uses `DEFAULT_TENANT_ID` from `.env`
- TENANT_MODE=multi
  - The deletions processor calls `/admin/tenants` with the admin `API_KEY` and iterates over all active tenants
  - Each per-tenant call adds `X-Tenant-Id: <tenant>` header
  - Optional sync runner (`scripts/multi_tenant_sync.sh`) also iterates tenants, triggers both directions, and skips tenants with zero active mappings

Toggles and examples

- Run multi-tenant deletions only:
  - Set `TENANT_MODE=multi` (container env)
- Add periodic per-tenant syncs as well:
  - Set `TENANT_MODE=multi` and `ENABLE_MULTI_TENANT_SYNC=true`
- Non-default service URL (behind proxy/compose):
  - Set `BRIDGE_URL=http://portico_outlook` (or the internal hostname)

Logs

- Cron output: `/var/log/bridge-cron.log`
- Deletion sync details: `/var/log/bridge-deletion-sync.log`
- Multi-tenant sync runner: `/var/log/bridge-sync.log`

Quick tests (optional)

You can sanity-check endpoints manually. Replace placeholders with your values.

Single-tenant mode (uses DEFAULT_TENANT_ID)

```bash
# Trigger deletion processor (idempotent)
curl -sS -X POST "$BRIDGE_URL/maintenance/process-deletions" \
  -H "api_key: <ADMIN_OR_TENANT_KEY>" | jq .

# Trigger booking_system → outlook sync
curl -sS -X POST "$BRIDGE_URL/sync/booking-to-outlook?days=2" \
  -H "api_key: <ADMIN_OR_TENANT_KEY>" | jq .
```

Multi-tenant mode (explicit tenant header)

```bash
# List tenants (requires admin API key)
curl -sS -X GET "$BRIDGE_URL/admin/tenants" \
  -H "api_key: <ADMIN_API_KEY>" | jq .

# Trigger outlook → booking_system for a specific tenant
curl -sS -X POST "$BRIDGE_URL/sync/outlook-to-booking?days=2&handle_deletions=1" \
  -H "api_key: <ADMIN_OR_TENANT_KEY>" \
  -H "X-Tenant-Id: <tenant_id>" | jq .
```


## Troubleshooting

- Missing `.env`: API replies with configuration JSON error; follow steps provided
- DB down: health endpoints remain readable; most API operations will degrade
- Webhooks failing: check `ENABLE_LEGACY_WEBHOOKS`, subscriptions, and logs
- 404s: consult the 404 `available_endpoints` list and `README_BRIDGE.md` routes table
