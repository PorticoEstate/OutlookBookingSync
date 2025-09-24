# Architecture & Concepts

## Goals

Provide an extensible, tenant-aware bridge that synchronizes events between heterogeneous calendar systems with reliability, observability, and minimal coupling.

## Core Components

| Component | Responsibility |
|----------|----------------|
| BridgeManager | Registers bridges, orchestrates sync ops, provides per-tenant bridge instances |
| AbstractCalendarBridge | Contract for bridges (fetch, create, update, delete, transform) |
| OutlookBridge | Microsoft Graph implementation (calendars, subscriptions, events) |
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

1. Webhook → queued (bridge_queue)
2. Batch processing (process-webhook-queue) → materialize sync tasks
3. Pending sync flush (process-pending-syncs) → perform CRUD via bridges
4. Deletion sweep (sync-deletions / process-deletion-queue) → confirm & propagate removal

 
 
 
## Error & Retry Model

| Scenario | Strategy |
|----------|----------|
| Transient provider failure | Mark failed, retry via pending sync batch |
| Ownership violation | Logged as skip (no retry) |
| Rate limit (429) | Backoff (future enhancement) |
| Webhook validation | Allow unauthenticated GET with validationToken |

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

Container cron (see `docker-entrypoint.sh`) triggers:

- Periodic sync passes (booking_system ↔ outlook)
- Webhook queue processing: `POST /bridges/process-webhook-queue`
- Deletion/cancellation sweep: `POST /bridges/sync-deletions`
- Deletion verification queue: `POST /bridges/process-deletion-queue`
- Log cleanup: `POST /maintenance/cleanup-logs`
- Subscription renewal: `POST /maintenance/renew-subscriptions`

 
 
 
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
