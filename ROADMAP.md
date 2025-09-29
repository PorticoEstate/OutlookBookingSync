# Roadmap and Future Enhancements

A concise plan for the bridge’s current scope and what’s next.

## Current Capabilities (Summary)
- Bridge architecture connecting multiple calendar systems (Outlook, booking system)
- Resource discovery, mapping management, and calendar item retrieval
- Sync engine: create/update/delete with deletion queue support
- Webhook handling per-bridge (optional), polling-friendly operation
- Health, alerts, and monitoring endpoints (+ dashboard JSON)
- Scripts and cron-friendly operations

See `doc/` directory for full architecture, usage, and ops documentation. The main `README.md` provides a complete documentation index.

## Near-term (0–1 quarter)

- Harden endpoints (types / consistent errors / pagination)
- CI pipeline: lint + lightweight integration smoke sync
- Expand test coverage for ownership + deletion edge cases
- Add structured tracing & metrics (OpenTelemetry spans around sync passes)
- Basic rate limiting + request validation layer

## Mid-term (1–2 quarters)

- Google Calendar bridge implementation
- CalDAV bridge prototype
- Async job/worker mode (queue backed) for large sync windows
- Configurable caching layer for discovery + health endpoints
- Advanced conflict / priority policy doc + implementation toggle
- Role-based admin scopes (beyond global admin key)

## Long-term

- Additional bridges (Exchange, custom domain-specific systems)
- Pluggable field transform & mapping strategy engine
- Granular RBAC + audit event streaming
- Horizontal scaling / sharding guidance & deployment blueprints
- Advanced scheduling (incremental window adaptation, predictive prefetch)
- Policy-driven data retention & compliance tooling

## Recently Delivered (2025 Q3)

- Multi-tenant architecture (tenant configs, key rotation UI, scoped logging)
- Consolidated documentation set (single-source files under `doc/`)
- Ownership-based sync direction enforcement & recreation logic

---
Contributions welcome—see CONTRIBUTING.md. Align new features here before implementation.
