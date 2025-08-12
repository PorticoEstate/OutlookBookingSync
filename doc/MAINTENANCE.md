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

## Troubleshooting

- Missing `.env`: API replies with configuration JSON error; follow steps provided
- DB down: health endpoints remain readable; most API operations will degrade
- Webhooks failing: check `ENABLE_LEGACY_WEBHOOKS`, subscriptions, and logs
- 404s: consult the 404 `available_endpoints` list and `README_BRIDGE.md` routes table
