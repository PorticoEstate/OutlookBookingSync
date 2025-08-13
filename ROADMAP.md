# Roadmap and Future Enhancements

A concise plan for the bridge’s current scope and what’s next.

## Current Capabilities (Summary)
- Bridge architecture connecting multiple calendar systems (Outlook, booking system)
- Resource discovery, mapping management, and calendar item retrieval
- Sync engine: create/update/delete with deletion queue support
- Webhook handling per-bridge (optional), polling-friendly operation
- Health, alerts, and monitoring endpoints (+ dashboard JSON)
- Scripts and cron-friendly operations

See README_BRIDGE.md for the full API, and doc/DEVELOPMENT.md + doc/MAINTENANCE.md for usage and ops.

## Near-term (0–1 quarter)
- Stabilize and harden existing endpoints (types, error responses, docs parity)
- Improve tests and add a minimal CI pipeline (lint + smoke tests)
- Rate limiting and request validation improvements
- Consistent pagination/filters across discovery and stats endpoints
- Tighten API key handling and auditing on critical operations

## Mid-term (1–2 quarters)
- Add Google Calendar bridge (Graph-like adapter) and/or CalDAV bridge
- Async job processing option for long-running syncs (queue/worker pattern)
- Caching for discovery and health endpoints (configurable TTL)
- Expand metrics and structured logging for better observability
- Role-based access control for admin/ops endpoints

## Long-term
- Multi-tenant architecture (database-backed configs, key management)
	- Data model:
		- Add tenants table (id, name, created_at, is_active)
		- Add tenant_id nullable columns to: bridge_resource_mappings, bridge_mappings, bridge_sync_logs, bridge_queue, bridge_subscriptions, bridge_configs
		- Index tenant_id on all above tables; include tenant_id in unique constraints where applicable
	- Routing/API:
		- Introduce route group /tenants/{tenantId}/... mirroring existing endpoints
		- Add TenantMiddleware to resolve/validate tenantId and pass it in request attributes
		- Backward compatibility: non-tenant routes map to a default/global tenant
	- Services/Queries:
		- Thread tenantId through BridgeManager, DeletionSyncService, controllers, and SyncLogService
		- Ensure all SELECT/INSERT/UPDATE include tenant_id = :tenant_id
	- Security:
		- Option A: single global API key (short-term)
		- Option B: per-tenant API keys mapped in tenants table or bridge_configs (mid-term)
		- Audit: include tenant_id in logs and alerts
	- Configuration:
		- Allow per-tenant bridge credentials in bridge_configs keyed by (tenant_id, bridge_type)
		- Support overrides for schedules and limits per tenant
	- Migration:
		- Phase 1: add columns (NULL), code supports both scoped/unscoped
		- Phase 2: choose default tenant for existing data and backfill tenant_id
		- Phase 3: enable tenant routes and enforce scoping; deprecate global if desired
	- Testing:
		- Add fixtures for two tenants; ensure isolation (no cross-tenant leakage)
		- Smoke tests for route groups and permission checks
- Pluggable mapping strategies and field transformation rules
- Admin UI for mappings, schedules, and monitoring
- Horizontal scaling guidance and deployment blueprints

## Deprecations and Flags
- Legacy webhook mirror endpoints are gated by ENABLE_LEGACY_WEBHOOKS=false (default)
- Keep README_BRIDGE routes table authoritative; update when routes change
- Remove or archive stale docs as code evolves to avoid drift

---
Contributions welcome—see CONTRIBUTING.md. Align new features here before implementation.
