# Architecture & Concepts

## Goals

Provide an extensible, tenant-aware bridge that synchronizes events between heterogeneous calendar systems with reliability, observability, and minimal coupling.

## Core Components

| Component | Responsibility |
|----------|----------------|
| BridgeManager | Registers bridges, orchestrates sync ops, provides per-tenant bridge instances |
| AbstractCalendarBridge | Contract for bridges (fetch, create, update, delete, transform) |
| OutlookBridge | Microsoft Graph implementation (calendars, subscriptions, events) - requires email format for calendar_id |
| BookingSystemBridge | Generic booking API integration (resources/events) |
| DeletionSyncService | Reconciles deletions & cancellations (poll + webhook/deletion queue) |
| SyncLogService | Persists audit trail of sync actions, errors, ownership decisions |
| AlertService | Evaluates and records alert conditions |
| Controllers | BridgeController, ResourceMappingController, BridgeResourceController, MaintenanceController, HealthController, AlertController, AdminController, MigrationController |
| Middleware | ApiKeyMiddleware, TenantResolverMiddleware, AdminRoleMiddleware, CsrfMiddleware |

## Data Model Highlights

| Table | Purpose | Notes |
|-------|---------|-------|
| bridge_mappings | Cross-system event pairing + ownership state | Contains sync_direction & status fields |
| bridge_queue | Pending webhook/event sync tasks | Types: webhook, deletion, pending_sync |
| bridge_subscriptions | Provider subscription records (Outlook) | Expiry based renewal |
| bridge_sync_logs | Event-level audit (create/update/delete/skip) | Includes ownership policy outcomes |
| outlook_sync_alerts | Operational alerts | Threshold & anomaly tracking |
| tenant_api_keys | Hashed per-tenant API keys | Rotated via admin endpoint |

Notes:
- Exact schema is defined in `database/` migrations; this table lists high-level responsibilities.

 
 
 
## Ownership & sync_direction

| Value | Owner | Non-Owner Behavior | Deletion Recreation |
|-------|-------|--------------------|---------------------|
| source_to_target | source (bridge_from) | Target modifications skipped | Yes (recreate target) |
| target_to_source | target (bridge_to) | Source modifications skipped | Yes (recreate source) |
| bidirectional | Shared | Both modify allowed | Conditional (policy) |

## Queues & Processing

### Queue Types

| Type | Purpose | Enqueued By | Processed By |
|------|---------|-------------|-------------|
| webhook | Inbound provider notifications | WebhookService | Unified processor or webhook-specific |
| sync | Manual/scheduled sync operations | BridgeController::syncBridges() | Unified processor |
| deletion | Deletion verification | DeletionSyncService | Unified processor or deletion-specific |

### Processing Flow

1. **Enqueue**: Events queued via `BridgeQueueRepository::enqueueIfNotExists()` with duplicate prevention
2. **Immediate Processing** (optional): If PHP-FPM available, process after `fastcgi_finish_request()`
3. **Batch Processing**: Unified queue processor handles multiple types in single cron job
4. **Auto-Retry**: Failed items retry up to 3 attempts, then marked permanently failed
5. **Manual Intervention**: Failed items can be retried/deleted via API endpoints
6. **Cleanup**: Old completed/failed items removed after 30 days (configurable)

### Unified Queue Processor (Recommended)

**Endpoint**: `POST /bridges/process-queue`

**Body**:
```json
{
  "queue_types": ["webhook", "sync", "deletion"],
  "batch_size": 50
}
```

**Benefits**:
- Single cron job instead of multiple separate jobs
- Consistent error handling across queue types
- Better resource utilization
- Simplified operations

 
 
 
## Error & Retry Model

| Scenario | Strategy |
|----------|----------|
| Transient provider failure | Auto-retry up to 3 attempts, then mark permanently failed |
| Permanent failure (3+ attempts) | Manual retry via `POST /bridges/queue/{id}/retry` or delete via `DELETE /bridges/queue/{id}` |
| Ownership violation | Logged as skip (no retry) |
| Rate limit (429) | Backoff (future enhancement) |
| Webhook validation | Allow unauthenticated GET with validationToken |
| Duplicate queue items | Prevented via JSONB containment check on enqueue |

### Auto-Retry Logic

1. Queue item fails → increment `attempts` counter
2. If `attempts < 3` → status remains `pending` (auto-retry on next batch)
3. If `attempts >= 3` → status changes to `failed` (permanent)
4. Failed items retrievable via `GET /bridges/queue/failed`
5. Manual retry resets `attempts` to 0 and status to `pending`

## Multi-Tenancy

Resolved via `TenantResolverMiddleware` → attaches `tenant_id` attribute. All mutating queries must include tenant scope. Config separation is achieved by per-tenant bridge config records and environment defaults. If a client omits `X-Tenant-Id`, the system falls back to `DEFAULT_TENANT_ID` (if set) or `default`.

Headers:
 
- `X-API-Key: <tenant-or-global-key>` (required)
- `X-Tenant-Id: <tenantId>` (recommended; optional with `DEFAULT_TENANT_ID`)

## Observability

- Health endpoints: `/health`, `/health/system`, `/bridges/health`
- Sync/queue stats: `/health/sync-status`, `/health/queue-stats`, `/bridges/sync-stats`, `/bridges/cancelled-events`
- Structured sync logs (status, tenant, bridge, operation, ownership decision)
- Static dashboard UI (`public/dashboard.html`) consumes JSON endpoints

 
 
 
## Security Layers

| Layer | Control |
|-------|--------|
| Transport | HTTPS termination at reverse proxy |
| Auth | X-API-Key (tenant hashed or global fallback) |
| Admin | Global key + CSRF token + optional IP allowlist |
| Webhooks | Signature / IP filter recommended externally |

Middleware order (Slim LIFO application; these run in reverse of add order):

1. AdminRoleMiddleware (protects admin routes)
2. CsrfMiddleware (admin mutations)
3. ApiKeyMiddleware (auth)
4. TenantResolverMiddleware (tenant scoping)
5. Routing + Error middleware wrappers

## Extending

1. Create bridge class implementing abstract methods.
2. Register via BridgeManager with config array.
3. Map resource IDs (mappings) and perform test sync.
4. Add adapter-specific transformation logic sparingly; prefer generic canonical event schema.

## Webhooks & Subscriptions

- Webhook endpoint: `POST /bridges/webhook/{bridgeName}` (and `GET` for Microsoft validation token).
- Subscription management:
  - Create: `POST /bridges/{bridgeName}/subscriptions`
  - List: `GET /bridges/{bridgeName}/subscriptions`
  - Delete: `DELETE /bridges/{bridgeName}/subscriptions/{subscriptionId}`
- Renewal job: `POST /maintenance/renew-subscriptions`

 
 
 
## Jobs & Maintenance

Container cron (see `docker-entrypoint.sh` and `doc/cron-examples.sh`) triggers:

### Recommended Configuration (Unified Processor)
- **Queue Processing**: `POST /bridges/process-queue` (every 5 min) - handles webhook, sync, deletion queues
- **Sync Operations**: `POST /bridges/sync/{source}/{target}` (every 30 min) - enqueues sync operations
- **Deletion Sweep**: `POST /bridges/sync-deletions` (every hour) - enqueues deletion verifications
- **Queue Cleanup**: `POST /maintenance/cleanup-queue` (daily 2 AM) - removes old items (30+ days)
- **Log Cleanup**: `POST /maintenance/cleanup-logs` (daily 3 AM) - removes old logs (90+ days)
- **Subscription Renewal**: `POST /maintenance/renew-subscriptions` (daily 4 AM) - renews expiring subscriptions

### Legacy Configuration (Separate Processors)
- Webhook queue: `POST /bridges/process-webhook-queue`
- Deletion queue: `POST /bridges/process-deletion-queue`
- Individual processing per queue type (still supported for backward compatibility)

See `doc/cron-examples.sh` for complete configuration examples.

 
 
 
## Future Enhancements

- Conflict resolution policies (priority, timestamp win, merge)
- Plugin architecture for custom transformers
- Circuit breakers per tenant / provider
- Tracing (OpenTelemetry) with span-level redaction

---

See also:

- `doc/api_endpoints.md` — complete endpoint reference
- `doc/booking_system_adapter.md` — booking system contract & patterns
- `doc/operations.md` — runtime ops and scheduling
