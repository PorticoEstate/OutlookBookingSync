# API Endpoints Reference

Canonical reference of public HTTP endpoints exposed by the Generic Calendar Bridge. All JSON responses use UTF-8 encoding and `Content-Type: application/json` unless otherwise stated.

## Authentication & Headers

Send the API key in header `X-API-Key: <value>`.

Multi‑tenant deployments MUST also send `X-Tenant-Id: <tenantId>` (unless relying on a default tenant via `DEFAULT_TENANT_ID`).

> Webhook POST/GET validation endpoints under `/bridges/webhook/{bridge}` are exempt from API key auth for inbound provider callbacks.

## Conventions

| Concept | Pattern |
|---------|---------|
| Path Parameters | `{bridgeName}`, `{resourceId}`, `{eventId}`, `{tenantId}` |
| Optional Segments | Expressed with Slim style `[/{bridgeName}]` |
| Pagination | `limit`, `offset` query params where supported |
| Date Range | `start_date` / `end_date` (body) or `startDate` / `endDate` (query) in `YYYY-MM-DD` |
| Time | Timestamps in ISO 8601 (`YYYY-MM-DDTHH:MM:SSZ`) |

## Bridge Operations

| Method | Path | Description | Auth | Notes |
|--------|------|-------------|------|-------|
| GET | /bridges | List registered bridges | API Key | Includes capabilities & health snapshot |
| GET | /bridges/{bridgeName}/calendars | List calendars/resources (bridge-provided) | API Key | Query: `limit`, `offset` |
| GET | /bridges/{bridgeName}/available-resources | Discover resources (rooms/equipment) | API Key | Query: `query`, `limit`, `offset` |
| GET | /bridges/{bridgeName}/available-groups | Discover groups/collections | API Key | Query filters |
| GET | /bridges/{bridgeName}/resources/{resourceId}/calendar-items | List events in resource | API Key | Query: `startDate`, `endDate`, `limit`, `offset` |
| POST | /bridges/sync/{sourceBridge}/{targetBridge} | Synchronize events between bridges | API Key | Body supports `dry_run`, `handle_deletions`, ownership rules respected |
| POST | /bridges/webhook/{bridgeName} | Inbound webhook handler | Unauth* | Provider-specific payload |
| GET | /bridges/webhook/{bridgeName} | Webhook validation (Graph) | Unauth* | Responds to `validationToken` |
| POST | /bridges/{bridgeName}/subscriptions | Create provider subscriptions | API Key | Outlook: creates/renews Graph subscriptions |
| POST | /bridges/{bridgeName}/resources/{resourceId}/events | Create event | API Key | Generic create; bridge maps fields |
| PUT | /bridges/{bridgeName}/events/{eventId} | Update event | API Key | Partial updates allowed based on bridge |
| DELETE | /bridges/{bridgeName}/events/{eventId} | Delete event | API Key | Soft vs hard delete depends on bridge |
| GET | /bridges/{bridgeName}/pending-events | Inspect pending sync events | API Key | Filtering via `limit`, `offset` |

*Webhook endpoints bypass API key to allow external providers. Harden at reverse proxy layer (IP allow list, secret validation) where possible.

## Queue Processing

| Method | Path | Description | Notes |
|--------|------|-------------|-------|
| POST | /bridges/process-queue | **Unified queue processor** (webhook, sync, deletion) | Recommended: Body: `{ "queue_types": ["webhook", "sync"], "batch_size": 50 }` |
| POST | /bridges/process-webhook-queue | Process webhook queue only | Legacy: Use `/bridges/process-queue` instead |
| POST | /bridges/process-deletion-queue | Process deletion verification queue | Legacy: Use `/bridges/process-queue` instead |
| GET | /bridges/queue/failed | Retrieve failed queue items | Query: `queue_type`, `limit`, `offset` |
| POST | /bridges/queue/{id}/retry | Manually retry failed queue item | Resets attempts and status to pending |
| DELETE | /bridges/queue/{id} | Permanently delete queue item | Use for irrecoverable failures |

## Deletion & Sync Operations

| Method | Path | Description | Notes |
|--------|------|-------------|-------|
| POST | /bridges/sync-deletions | Detect & reconcile deletions/cancellations | Booking inactive ↔ Outlook deletion |
| POST | /bridges/process-pending-syncs[/{bridgeName}] | Process events awaiting sync | Ownership enforced |
| POST | /bridges/re-enable-failed[/{bridgeName}] | Re-enable failed events | Resets status for retry |

## Sync Statistics & Monitoring

| Method | Path | Description |
|--------|------|-------------|
| GET | /bridges/health | Per-bridge health summary |
| GET | /health | Lightweight system health |
| GET | /health/system | Comprehensive system diagnostics |
| GET | /health/dashboard | Metrics for dashboard UI |
| GET | /health/sync-status | Detailed sync status (filter by status) |
| GET | /health/queue-stats | Queue metrics (webhook/deletion/pending) |
| GET | /bridges/sync-stats[/{bridgeName}] | Aggregated sync KPIs |
| GET | /bridges/cancelled-events[/{bridgeName}] | Recently cancelled/inactive reconciliations |

## Resource Mappings

| Method | Path | Description | Notes |
|--------|------|-------------|-------|
| GET | /mappings/resources | List mappings | Query: `limit`, `offset`, filters |
| POST | /mappings/resources | Create mapping | Body includes ownership `sync_direction` |
| PUT | /mappings/resources/{id} | Update mapping | Partial update |
| DELETE | /mappings/resources/by-key/{bridge_from}/{source_calendar_id}/{target_calendar_id} | Delete mapping by composite key | Idempotent |
| GET | /mappings/resources/by-resource/{source_calendar_id} | List mappings for source resource | Use before event create |
| POST | /mappings/resources/{id}/sync | Trigger mapping sync | Body date range overrides |

## Alerts

| Method | Path | Description |
|--------|------|-------------|
| POST | /alerts/check | Execute alert checks now |
| GET | /alerts | List recent alerts |
| GET | /alerts/stats | Alert statistics |
| POST | /alerts/{id}/acknowledge | Acknowledge alert |
| DELETE | /alerts/old | Remove aged alerts |

## Maintenance

| Method | Path | Description | Notes |
|--------|------|-------------|-------|
| POST | /maintenance/cleanup-logs | Prune old sync logs | Query: `days` (default: 90) |
| POST | /maintenance/cleanup-queue | Remove old queue items | Query: `days` (default: 30) |
| POST | /maintenance/renew-subscriptions | Renew expiring subscriptions | Checks subscription expiry window |

## Admin (Tenancy & Config)

| Method | Path | Description | Notes |
|--------|------|-------------|-------|
| GET | /admin/csrf | Obtain CSRF token | Session-based |
| GET | /admin/tenants | List tenants | Include inactive via `include_inactive=false` |
| POST | /admin/tenants | Create tenant | Body: `id`, `name`, `active` |
| GET | /admin/tenants/{tenantId} | Get tenant | 404 if not found |
| PUT | /admin/tenants/{tenantId} | Update tenant | Body optional fields |
| DELETE | /admin/tenants/{tenantId} | Delete tenant | Returns 204 |
| POST | /admin/tenants/{tenantId}/keys/rotate | Rotate API key | Returns plaintext once |
| GET | /admin/tenants/{tenantId}/keys/metadata | Key metadata | Created at timestamp |
| PUT | /admin/tenants/{tenantId}/configs/{bridgeName} | Upsert tenant bridge config | Arbitrary JSON document |
| GET | /admin/tenants/{tenantId}/configs/{bridgeName} | Get tenant bridge config | 404 if missing |

## Error Model

Standard error shape:

```json
{
  "error": "Not Found",
  "status_code": 404,
  "message": "The endpoint 'GET /foo' was not found",
  "timestamp": "2025-09-22T10:00:00Z"
}
```

## Ownership & Direction Cheat Sheet

| Mapping sync_direction | Owner | Non-owner behavior | Re-creation |
|------------------------|-------|--------------------|-------------|
| source_to_target | Source (bridge_from) | Target changes skipped | Source recreates deleted target event |
| target_to_source | Target (bridge_to) | Source changes skipped | Target recreates deleted source event |
| bidirectional | Shared | Both may update | Depends on deletion policy |

## Security Notes

1. Prefer per-tenant keys via `X-API-Key` header over global `API_KEY`.
2. Restrict webhook endpoints by IP or shared secret at reverse proxy.
3. Employ HTTPS everywhere in production.
4. Rotate keys regularly (`/admin/tenants/{id}/keys/rotate`).
5. Avoid leaking validation tokens in logs.

See `security_hardening.md` for extended guidance.

---

For full architectural context see `architecture.md`. For operational flows see `usage.md` and `operations.md`.
