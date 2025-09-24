# Usage Flows

## Typical Timeline

1. Configure environment / per-tenant keys
2. Discover resources (`/bridges/{bridge}/available-resources`)
3. Create resource mappings (ownership specified via `sync_direction`)
4. Run initial sync (dry run optional)
5. Enable regular sync & deletion jobs (cron)
6. Monitor health, queue, alerts

## Resource Discovery

Use resources/groups endpoints for target selection; filter with `query` for large directories.

## Creating a Mapping

POST `/mappings/resources` with body fields:

| Field | Description |
|-------|-------------|
| bridge_from | Source semantic bridge (e.g. booking_system) |
| bridge_to | Target semantic bridge (e.g. outlook) |
| source_calendar_id | Resource id from source system |
| target_calendar_id | Resource id / email / calendar id in target (Outlook requires email format) |
| sync_direction | Ownership model (see architecture) |
| sync_enabled | Toggle without deleting mapping |

## Bridge-Specific Requirements

### Outlook Bridge

- **Calendar ID Format**: Must use email address format (e.g., `conference-room-a@company.com`)
- **Validation**: Email format is enforced at API level and admin interface
- **Examples**: `room123@contoso.com`, `boardroom@company.org`

### Booking System Bridge

- **Calendar ID Format**: must use numeric IDs
- **Examples**: `456`, `789`

## Running a Sync

POST `/bridges/sync/{source}/{target}` with optional body:

| Field | Purpose |
|-------|---------|
| source_calendar_id / target_calendar_id | Limit to single pair |
| start_date / end_date | Date window (YYYY-MM-DD) |
| dry_run | Report actions without changes |
| handle_deletions | Include deletion reconciliation in pass |

## Deletion & Cancellation Handling

| Mechanism | Endpoint |
|-----------|----------|
| Real time via webhooks | `/bridges/webhook/{bridge}` + queue processors |
| Poll-based sweep | `/bridges/sync-deletions` |
| Verification queue | `/bridges/process-deletion-queue` |

## Monitoring

| Aspect | Endpoint |
|--------|----------|
| Health summary | `/bridges/health` |
| System diagnostics | `/health/system` |
| Queue stats | `/health/queue-stats` |
| Sync statistics | `/bridges/sync-stats` |
| Cancelled events | `/bridges/cancelled-events` |

## Webhook Notes

| Direction | Needs Subscription | Notes |
|-----------|--------------------|-------|
| Outlook → Bridge | Yes | Graph subscription + validation token GET |
| Booking System → Bridge | No | Direct POST (optional signing) |

For the full rationale, prerequisites, creation, renewal and troubleshooting steps of Outlook subscriptions see the "Outlook Webhook Subscriptions" section in `operations.md`.

## Multi-Tenant Invocation

Add headers:

```text
X-API-Key: <tenant-or-global-key>
X-Tenant-Id: <tenant>
```

## Troubleshooting Quick Table

| Symptom | Likely Cause | Action |
|---------|--------------|--------|
| 401 Unauthorized | Missing/invalid key | Verify header `X-API-Key` |
| Events skip updates | Ownership violation | Check mapping `sync_direction` |
| Stale webhook processing | Subscription expired | Renew via maintenance endpoint |
| Missing deletion propagation | Deletion queue not processed | Run `/bridges/process-deletion-queue` |
| Empty resource list | Wrong bridge/group scope | Verify config (e.g. `OUTLOOK_GROUP_ID`) |

---

See `operations.md` for automation & scheduling.
