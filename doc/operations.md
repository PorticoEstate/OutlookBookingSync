# Operations & Monitoring

## Automation (Cron Examples)

### Recommended Configuration (Unified Queue-Based)

| Purpose | Cron | Endpoint | Notes |
|---------|------|----------|-------|
| **Unified queue processor** | `*/5 * * * *` | POST /bridges/process-queue | Processes webhook + sync queues (recommended) |
| Subscription renewal | `*/30 * * * *` | POST /maintenance/renew-subscriptions | Keep subscriptions active |
| Queue cleanup | `0 2 * * *` | POST /maintenance/cleanup-queue?days=30 | Daily at 2 AM |
| Log cleanup | `0 3 * * *` | POST /maintenance/cleanup-logs?days=30 | Daily at 3 AM |

### Legacy Configuration (Separate Jobs)

Still supported for backward compatibility:

| Purpose | Cron | Endpoint |
|---------|------|----------|
| Forward sync (booking → outlook) | `*/5 * * * *` | POST /bridges/sync/booking_system/outlook |
| Reverse sync (outlook → booking) | `*/10 * * * *` | POST /bridges/sync/outlook/booking_system |
| Deletion sweep | `*/5 * * * *` | POST /bridges/sync-deletions |
| Deletion queue process | `*/5 * * * *` | POST /bridges/process-deletion-queue |
| Webhook queue process | `*/5 * * * *` | POST /bridges/process-webhook-queue |

### Unified Queue Processor Configuration

The unified queue processor (`POST /bridges/process-queue`) handles multiple queue types in a single call:

**Default behavior (no body)**:
```bash
# Processes both webhook and sync queues with batch_size=50
curl -X POST \
  -H "X-API-Key: <KEY>" \
  -H "X-Tenant-Id: <TENANT>" \
  https://bridge.example.com/bridges/process-queue
```

**Custom configuration**:
```bash
# Process specific queue types with custom batch size
curl -X POST \
  -H "X-API-Key: <KEY>" \
  -H "X-Tenant-Id: <TENANT>" \
  -H "Content-Type: application/json" \
  -d '{"queue_types": ["webhook", "sync", "deletion"], "batch_size": 100}' \
  https://bridge.example.com/bridges/process-queue
```

**Benefits of unified processor**:
- Single cron job instead of separate jobs for each queue type
- Consistent batch processing across all queues
- Combined statistics and error reporting
- Reduced cron job complexity

### Queue Cleanup Configuration

**Automatic cleanup**:
```bash
# Remove completed/failed items older than 30 days (default)
curl -X POST \
  -H "X-API-Key: <KEY>" \
  -H "X-Tenant-Id: <TENANT>" \
  https://bridge.example.com/maintenance/cleanup-queue
```

**Custom retention period**:
```bash
# Keep only last 7 days
curl -X POST \
  -H "X-API-Key: <KEY>" \
  -H "X-Tenant-Id: <TENANT>" \
  https://bridge.example.com/maintenance/cleanup-queue?days=7
```

## Health & Metrics

| Endpoint | Description |
|----------|-------------|
| /bridges/health | Per-bridge status summary |
| /health/system | System composite metrics |
| /health/queue-stats | Queue depth counts |
| /bridges/sync-stats | Aggregated sync KPIs |
| /bridges/cancelled-events | Cancelled reconciliation list |

## Alerting

- Run `POST /alerts/check` via schedule; fetch with `/alerts` & `/alerts/stats`.
- Integrate with external notification (webhook or email) by polling and forwarding critical alerts.

## Webhook-Free Operation

Polling strategy still achieves near-real-time with 2–5 minute cadence. Keep subscription renewal disabled if not creating subscriptions.

## Outlook Webhook Subscriptions

Real-time inbound change detection for Outlook calendars uses Microsoft Graph subscriptions. Without them the bridge still works via polling, but latency and provider/API load are higher. Implement subscriptions when you need:

- Lower end-to-end latency (< 1 minute typical vs 2–10 minute polling window)
- Reduced redundant list/delta queries (cost savings, throttling safety)
- More reliable deletion & cancellation detection (fewer race windows)

### Rationale vs Polling

| Aspect | Polling Only | With Subscriptions |
|--------|--------------|--------------------|
| Latency to detect create/update | 2–10 min (cron dependent) | <1–2 min (provider push + queue process) |
| Deletion/cancellation gap | Possible until next sweep | Narrowed to next webhook batch |
| API call volume | Higher (frequent deltas) | Lower (event-driven follow-up) |
| Operational complexity | Simpler | Requires renewal scheduling & endpoint exposure |

### Prerequisites

1. Publicly reachable HTTPS endpoint for `GET/POST /bridges/webhook/outlook` (validation + notifications).
2. `WEBHOOK_BASE_URL` (or equivalent config) set so subscription callback URL is constructed correctly (e.g. `https://bridge.example.com/bridges/webhook/outlook`).
3. Microsoft Entra app registration with permissions (minimum) `Calendars.Read` for read-only or `Calendars.ReadWrite` if updates are performed, plus `offline_access` for refresh tokens.
4. Stored OAuth tokens per tenant (or global) allowing Graph subscription creation (app or delegated pattern—must match implemented auth flow).
5. Cron (or scheduler) to call `POST /maintenance/renew-subscriptions` every 30 minutes (or shorter than half of your chosen subscription lifetime buffer).

### Creating Subscriptions

Use the bridge endpoint which orchestrates one or more Graph subscription creates/renews:

`POST /bridges/outlook/subscriptions`

Optional JSON body fields (implementation may evolve; check code/comments):

| Field | Purpose | Notes |
|-------|---------|-------|
| resource_ids | Limit to specific calendar/resource IDs | If omitted the bridge may subscribe to all mapped resources or a default scope |
| force_renew | Force renewal even if not near expiry | Safety for drift or repair |

Example (all resources):

```bash
curl -X POST \
  -H "X-API-Key: <KEY>" \
  -H "X-Tenant-Id: tenantA" \
  https://bridge.example.com/bridges/outlook/subscriptions
```

Example (specific resource):

```bash
curl -X POST \
  -H "X-API-Key: <KEY>" \
  -H "X-Tenant-Id: tenantA" \
  -H "Content-Type: application/json" \
  -d '{"resource_ids":["room1@example.com"]}' \
  https://bridge.example.com/bridges/outlook/subscriptions
```

Response (illustrative):

```json
{
  "created": [
    { "resource_id": "room1@example.com", "subscription_id": "<guid>", "expires_at": "2025-09-23T11:55:12Z" }
  ],
  "renewed": [],
  "skipped": []
}
```

### Validation Flow

1. Bridge issues Graph subscription create request with `notificationUrl` = `<WEBHOOK_BASE_URL>/bridges/webhook/outlook`.
2. Microsoft Graph sends `GET` with `validationToken` query param.
3. Bridge must echo the token (already implemented in the webhook controller) within 10 seconds.
4. Subsequent notifications arrive as `POST` payloads, queued for processing (`/bridges/process-webhook-queue`).

### Renewal Lifecycle

Outlook (Graph) subscriptions have a maximum TTL (often 4230–43200 minutes depending on resource type & permissions). The bridge stores `expires_at` in `bridge_subscriptions` and renewal job:

1. Queries subscriptions expiring within a safety window (e.g. < 1 day remaining).
2. Calls Graph to renew; updates expiry in DB.
3. Emits alert if renewal fails (ensure alert checks cover this).

Schedule: `*/30 * * * *` (see table above). Adjust more frequently if short-lived test tenants (< 1 hour TTL scenarios).

### Processing Notifications

1. Inbound POST enqueued (lightweight validation & tenant lookup by subscription id).
2. Batch processor (`/bridges/process-webhook-queue`) expands notification into targeted delta or event fetches.
3. Normal sync pipelines apply ownership, dedupe, and persistence logic.

### Troubleshooting

| Symptom | Diagnosis Steps | Remedy |
|---------|-----------------|--------|
| No validation GET received | Check reverse proxy logs; confirm public DNS; verify Graph app permission | Fix routing / firewall, re-run create |
| Immediate 403 on create | Missing Graph permission scope | Add required scopes; re-consent |
| Rapid expiry / not renewing | Cron not calling renewal endpoint | Add/repair scheduler; run manual POST |
| Webhooks stop suddenly | Subscription expired (missed renewal) | Re-create via create endpoint; verify alerting |
| High duplicate fetches | Processor not batching notifications | Confirm queue processor cadence; tune batch size |
| Mixed tenant events | Incorrect subscription -> tenant mapping | Verify `bridge_subscriptions` rows have correct `tenant_id` |

### Disabling Subscriptions

If you wish to revert to polling only:

1. Stop the renewal cron.
2. Optionally delete existing subscriptions in Graph (future improvement: DELETE endpoint TBD) or allow natural expiry.
3. Ensure polling sync + deletion sweeps remain scheduled.

### Security Considerations

- Restrict webhook endpoint by IP allow list or shared secret at the proxy.
- Do not log raw validation tokens or full payload bodies in production.
- Monitor subscription expiry lead time; alert when < 60 minutes remaining to avoid blind periods.

### Future Enhancements (Roadmap)

Planned: explicit DELETE and LIST endpoints for subscription management; consolidation of batch renewal metrics into `/bridges/health`.

## Failure Handling Patterns

| Failure | Handling |
|---------|----------|
| Outbound provider error | Mark failed, retry with next batch |
| Ownership violation | Skip, log, no retry |
| Deletion race (event recreated) | Re-verify presence before final delete |

## Housekeeping

| Task | Endpoint | Notes |
|------|----------|-------|
| Sync log pruning | /maintenance/cleanup-logs | Controlled by CLEANUP_DAYS |
| Subscription renewal | /maintenance/renew-subscriptions | Renew before expiry window |
| Failed event re-enable | /bridges/re-enable-failed | Use after bulk transient failures |

## KPIs (Track)

| KPI | Rationale |
|-----|-----------|
| Mean sync latency | Detect slowdown |
| Failed sync ratio | Reliability metric |
| Queue backlog size | Scaling signal |
| Subscription renewal success | Webhook continuity |

---

For security controls see `security_hardening.md`; for architecture see `architecture.md`.
