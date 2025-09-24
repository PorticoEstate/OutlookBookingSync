# Changelog

All notable changes to this project will be documented in this file. This project follows a pragmatic, human‑readable changelog—grouped chronologically. Dates are in ISO format (YYYY-MM-DD).

## [Unreleased]

### Planned

- Google / CalDAV bridge implementations
- Advanced conflict resolution strategies
- Plugin / extension loading system
- Rate limiting & adaptive backoff policy
- Structured OpenTelemetry tracing spans

## [2025-09-22] Documentation Consolidation & Multi‑Tenant Enhancements

### Added (Deletion & Cancellation)

- Consolidated documentation set: `architecture.md`, `usage.md`, `configuration.md`, `operations.md`, `development.md` replacing 11 fragmented guides.
- Multi-tenant tenant resolution (header `X-Tenant-Id`) and admin tenant CRUD endpoints (see `AdminController`).
- Webhook queue + deletion queue processing endpoints (`/bridges/process-webhook-queue`, `/bridges/process-deletion-queue`).
- Sync status management endpoints (`/bridges/sync-stats`, `/bridges/cancelled-events`, `/bridges/process-pending-syncs`, `/bridges/re-enable-failed`, `/health/sync-status`, `/health/queue-stats`).
- Per-tenant API key rotation & storage (hashed) via new admin endpoints.

### Changed

- Replaced scattered webhook, monitoring, cancellation, and composite ID docs with unified sections in consolidated docs.
- Replaced legacy `README_BRIDGE.md` with slim root README + `doc/` index.
- Enhanced security model: preference for per-tenant X-API-Key headers over global `API_KEY`.

### Removed

- Legacy duplicated docs: architecture guide variants, monitoring guide, cancellation deep dive, composite id deep dive, docker setup, maintenance, planning drafts.
- Deprecated legacy resource mapping route (replaced by `/mappings/resources`).

### Fixed

- Inconsistent environment variable references for Outlook integration unified under `OUTLOOK_*` naming.
- Clarified webhook subscription necessity (required only for Outlook → Bridge direction).

### Security

- Documented fallback auth path ordering (tenant keys JSON → DB hashed key → global key) in security guide (pending addition).

## [2025-08-15] Subscription Renewal Improvements (Approx.)

### Added (Initial Release)

- Automatic Outlook subscription renewal endpoint `/maintenance/renew-subscriptions` with configurable renewal window.
- Improved webhook validation handling (GET validation token support).

## [2025-07-10] Deletion & Cancellation Processing (Approx.)

### Added

- Unified deletion & cancellation detection endpoint `/bridges/sync-deletions`.
- Enhanced `DeletionSyncService` with verification + orphan mapping cleanup.

## [2025-06-01] Initial Public Bridge Refactor

### Added

- Abstract bridge architecture (`AbstractCalendarBridge`) with concrete `OutlookBridge` & `BookingSystemBridge`.
- Basic synchronization endpoint `/bridges/sync/{source}/{target}`.
- Health endpoints `/health` & `/bridges/health`.

---

Historical earlier changelog entries can be reconstructed from commit history if required.
