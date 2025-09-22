# Operations & Monitoring

## Automation (Cron Examples)

| Purpose | Cron | Endpoint |
|---------|------|----------|
| Forward sync (booking → outlook) | `*/5 * * * *` | POST /bridges/sync/booking_system/outlook |
| Reverse sync (outlook → booking) | `*/10 * * * *` | POST /bridges/sync/outlook/booking_system |
| Deletion sweep | `*/5 * * * *` | POST /bridges/sync-deletions |
| Deletion queue process | `*/5 * * * *` | POST /bridges/process-deletion-queue |
| Webhook queue process | `*/5 * * * *` | POST /bridges/process-webhook-queue |
| Subscription renewal | `*/30 * * * *` | POST /maintenance/renew-subscriptions |
| Log cleanup | `3 3 * * *` | POST /maintenance/cleanup-logs?days=30 |

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
