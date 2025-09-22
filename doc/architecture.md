# Architecture & Concepts

## Goals

Provide an extensible, tenant-aware bridge that synchronizes events between heterogeneous calendar systems with reliability, observability, and minimal coupling.

## Core Components

| Component | Responsibility |
|----------|----------------|
| BridgeManager | Registers bridges, orchestrates sync operations |
| AbstractCalendarBridge | Contract for concrete bridges (fetch, create, update, delete, transform) |
| OutlookBridge | Microsoft Graph implementation |
| BookingSystemBridge | Generic booking API integration |
| DeletionSyncService | Reconciles deletions & cancellations (poll + webhook queue) |
| OutlookEventDetectionService | Translates Graph notifications into internal queue items |
| SyncLogService | Persists audit trail of sync actions, errors, ownership events |
| AlertService | Evaluates and records alert conditions |
| Middleware (ApiKey, TenantResolver, AdminRole, Csrf) | Authentication, tenancy scoping, admin protections |

## Data Model Highlights

| Table | Purpose | Notes |
|-------|---------|-------|
| bridge_mappings | Cross-system event pairing + ownership state | Contains sync_direction & status fields |
| bridge_queue | Pending webhook/event sync tasks | Types: webhook, deletion, pending_sync |
| bridge_subscriptions | Provider subscription records (Outlook) | Expiry based renewal |
| bridge_sync_logs | Event-level audit (create/update/delete/skip) | Includes ownership policy outcomes |
| outlook_sync_alerts | Operational alerts | Threshold & anomaly tracking |
| tenant_api_keys | Hashed per-tenant API keys | Rotated via admin endpoint |

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

Resolved via `TenantResolverMiddleware` → attaches `tenant_id` attribute. All mutating queries must include tenant scope. Config separation achieved by per-tenant bridge config records (future) or central env mapping during transition.

## Observability

- Health endpoints (`/health`, `/bridges/health`, `/health/system`)
- Sync + queue stats endpoints
- Structured sync log entries (status, tenant, bridge, operation, ownership decision)
- Dashboard HTML reading JSON endpoints for live UI

## Security Layers

| Layer | Control |
|-------|--------|
| Transport | HTTPS termination at reverse proxy |
| Auth | API key (tenant hashed or global fallback) |
| Admin | Global key + CSRF token + optional IP allowlist |
| Webhooks | Signature / IP filter recommended externally |

## Extending

1. Create bridge class implementing abstract methods.
2. Register via BridgeManager with config array.
3. Map resource IDs (mappings) and perform test sync.
4. Add adapter-specific transformation logic sparingly; prefer generic canonical event schema.

## Future Enhancements

- Conflict resolution policies (priority, timestamp win, merge)
- Plugin architecture for custom transformers
- Circuit breakers per tenant / provider
- Tracing (OpenTelemetry) with span-level redaction

---

See `usage.md` for operational flows and `operations.md` for runtime management.
