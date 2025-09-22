# Development Quickstart

## Setup

```bash
composer install
cp .env.example .env
php -S localhost:8082 -t . index.php
```

## Running Local Sync

```bash
curl -X POST -H "api_key: change-me" -H "X-Tenant-Id: dev" \
  -H "Content-Type: application/json" \
  -d '{"source_calendar_id":"room@company.com","target_calendar_id":"123","dry_run":true}' \
  http://localhost:8082/bridges/sync/outlook/booking_system
```

## Adding a Bridge

1. Create class extending `AbstractCalendarBridge`
2. Implement required CRUD + transformation methods
3. Register in container / BridgeManager with config array
4. Add mapping & run dry run sync
5. Add tests / logging & docs snippet

## Testing Ideas

| Area | Example |
|------|---------|
| Ownership enforcement | Attempt disallowed update from non-owner |
| Deletion propagation | Delete source & verify target removal |
| Queue drain | Enqueue multiple webhook events, process batch |
| Multi-tenant isolation | Same event id under different tenants stays isolated |

## Debug Tips

- Use `GET /health/queue-stats` to inspect queue depths
- Add temporary `INFO` logs around provider API calls (remove before commit)
- Inspect `bridge_sync_logs` for ownership decisions

## Contribution Style

- Small, focused PRs
- Update `CHANGELOG.md` when altering behavior or adding endpoints
- Include doc reference additions when introducing new concepts

---

See `api_endpoints.md` for full HTTP surface and `architecture.md` for concepts.
