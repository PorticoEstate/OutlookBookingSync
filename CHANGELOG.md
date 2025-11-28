# Changelog

All notable changes to this project will be documented in this file. This project follows a pragmatic, human‑readable changelog—grouped chronologically. Dates are in ISO format (YYYY-MM-DD).

## [Unreleased]

### Planned

- Google / CalDAV bridge implementations
- Advanced conflict resolution strategies
- Plugin / extension loading system
- Rate limiting & adaptive backoff policy
- Structured OpenTelemetry tracing spans

## [2025-11-28] Queue-Based Sync Architecture

### Added

- **Unified Queue Processor**: Single endpoint `POST /bridges/process-queue` handles webhook, sync, and deletion queues
- **Queue Management API**: 
  - `GET /bridges/queue/failed` - retrieve failed queue items with filtering
  - `POST /bridges/queue/{id}/retry` - manually retry failed items
  - `DELETE /bridges/queue/{id}` - permanently delete queue items
- **Auto-Retry Logic**: Queue items automatically retry up to 3 attempts before permanent failure
- **Duplicate Prevention**: JSONB containment operators prevent duplicate queue items for same operation
- **Queue Cleanup**: `POST /maintenance/cleanup-queue` removes old completed/failed items (30+ days default)
- **Immediate Processing**: Optional PHP-FPM immediate processing after webhook response via `fastcgi_finish_request()`
- **Comprehensive Documentation**: 
  - `doc/cron-examples.sh` - 100+ lines of cron configuration examples
  - Updated `doc/operations.md` with unified processor approach
  - Queue architecture documented in `doc/architecture.md`

### Changed

- **`POST /bridges/sync/{source}/{target}`**: Now queue-based instead of synchronous processing
- **Queue Processing**: Unified processor replaces separate webhook/deletion queue endpoints (legacy endpoints still supported)
- **Cron Configuration**: Simplified from 5+ separate jobs to single unified processor job (recommended)
- **Database Schema**: `bridge_queue` table supports multiple queue types: 'webhook', 'sync', 'deletion'
- **Error Handling**: Consistent retry/failure handling across all queue types

### Fixed

- Race conditions from concurrent sync operations eliminated via queue-based processing
- Duplicate webhook processing prevented by enqueue-time duplicate detection
- Better error isolation - single failed item doesn't block entire batch

### Performance

- Reduced cron job overhead (single unified processor vs multiple separate jobs)
- Configurable batch sizes per queue type for resource optimization
- Immediate webhook processing reduces latency when PHP-FPM available

### Security

- Queue management endpoints require API key authentication
- Failed queue items don't expose sensitive data in error messages
- Automatic cleanup prevents queue table bloat

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
