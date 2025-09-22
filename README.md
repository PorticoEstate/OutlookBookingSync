# OutlookBookingSync - Generic Calendar Bridge

A **production-ready, extensible calendar synchronization platform** that acts as a universal bridge between any calendar systems. Built with PHP/Slim4, this system can synchronize events between Outl#### **Configuration Examples**

Configure ownership via the `bridge_resource_mappings` table using the `sync_direction` field:

**Example 1: Booking System Owns Events**
```json
{
  "bridge_from": "booking_system",
  "bridge_to": "outlook", 
  "source_calendar_id": "room_123",
  "target_calendar_id": "conference-room-a@company.com",
  "sync_direction": "source_to_target"
}
# Generic Calendar Bridge (OutlookBookingSync)

A production-ready, extensible synchronization platform connecting Outlook (Microsoft 365) and booking / other calendar systems via a pluggable bridge architecture.

This README is intentionally slim. Deep technical details live under `doc/`.

## 🎯 Overview

Core highlights:
- Extensible bridge pattern (add new calendar systems quickly)
- Ownership-based synchronization with conflict-safe policies
- Works with or without webhooks (polling friendly)
- Multi-tenant: per-tenant API keys & per-bridge configuration
- Operational observability (health, queue stats, sync logs, alerts)

## 🚀 Feature Summary

| Area | Highlights |
|------|-----------|
| Sync | Bidirectional or one-way with automatic recreation logic |
| Ownership | `sync_direction` enforces authoritative side |
| Deletions & Cancellations | Queue + verification + recreation safeguards |
| Multi-Tenancy | API key hierarchy, scoped configs, tenant admin UI |
| Monitoring | Health, queue stats, sync stats, cancelled events, alerts |
| Extensibility | Implement `AbstractCalendarBridge` for new systems |
| Security | Key-based auth, CSRF for admin, optional IP allowlist |
| Deployment | Docker / bare metal, cron-friendly endpoints |

## 🏗️ Architecture (Snapshot)

Bridges implement a common contract (fetch/create/update/delete/transform). The `BridgeManager` orchestrates sync passes, honoring ownership rules and writing audit entries to `bridge_sync_logs`. See `doc/architecture.md` for a diagram and deeper explanation.

## 🏁 Quick Start

1. Clone
```bash
git clone <repository-url>
cd OutlookBookingSync
```
2. Configure env
```bash
cp .env.example .env; cp .env.compose.example .env.compose
# edit DB + Outlook creds
```
3. Init DB
```bash
scripts/setup_bridge_database.sh
```
4. Run (Docker recommended)
```bash
docker compose up -d
# or: php -S localhost:8082 index.php
```
5. Smoke test
```bash
curl -H "api_key: change-me-strong-random" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/health
```
6. Mapping example
```http
POST /mappings/resources
{
  "bridge_from": "booking_system",
  "bridge_to": "outlook",
  "source_calendar_id": "room_123",
  "target_calendar_id": "conference-room-a@company.com",
  "sync_direction": "source_to_target"
}
```

## 🔐 Authentication (Essentials)

Send `api_key: <key>` header. For multi-tenant usage also send `X-Tenant-Id`. Global admin key manages tenants & configs; per-tenant keys manage scoped sync/mappings. Webhook endpoints skip auth (Graph validation flow).

## 🔄 Ownership Model (Essentials)

`sync_direction` values: `source_to_target`, `target_to_source`, `bidirectional`.
Non-owner modifications are skipped and logged. Deleted non-owner events are recreated by the owner unless respecting deletions is explicitly enabled. Details & rationale: `doc/architecture.md` and examples in `doc/usage.md`.

## 📊 Endpoint Reference

Complete, regularly updated list: `doc/api_endpoints.md`.

## 📚 Documentation Index

| Area | Doc |
|------|-----|
| Architecture & Concepts | `doc/architecture.md` |
| Usage Flows | `doc/usage.md` |
| Configuration | `doc/configuration.md` |
| Operations & Monitoring | `doc/operations.md` |
| Development Guide | `doc/development.md` |
| Booking System Adapter | `doc/booking_system_adapter.md` |
| Multi-Tenancy | `doc/multi_tenancy.md` |
| Security Hardening | `doc/security_hardening.md` |
| API Endpoints | `doc/api_endpoints.md` |
| Changelog | `CHANGELOG.md` |

Legacy fragmented docs were consolidated (see 2025-09-22 changelog entry).

## 🧱 Extending

Implement a new bridge by extending `AbstractCalendarBridge` (fetch resources, list events, CRUD). Wire it into the container and register it with `BridgeManager`. See extension notes in `doc/development.md`.

## 🛠 Operations

Cron-friendly endpoints: sync passes, deletion sync, subscription renewal, log cleanup. See schedules & guidance in `doc/operations.md`.

## 🧪 Local Dev

Hot reload-friendly: run PHP built-in server, use seeded test data, inspect logs via dashboard or `/health/*` endpoints. Full setup & contribution workflow: `doc/development.md`.

## 🔐 Security Snapshot

API key auth + tenant scoping, CSRF tokens for admin mutations, optional IP allowlist, minimal exposed surface. Hardening recommendations: `doc/security_hardening.md`.

## 📦 Booking System Adapter

Expected endpoints & payload conventions plus optional webhook payload contract: `doc/booking_system_adapter.md`.

## ✅ Status & Roadmap

Core platform stable; upcoming focus (see `ROADMAP.md`): additional bridges (Google / CalDAV), richer conflict policies, tracing/metrics enhancements.

## 🤝 Contributing

Issues & PRs welcome. Please read `doc/development.md` for coding style & extension notes before submitting.

## 📄 License

See `LICENSE`.

---
This README intentionally stays concise; treat the `doc/` directory as the canonical single source of deeper truth.

### Bridge Health Check

```bash
# Check overall bridge health
curl -H "api_key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/health

# Test specific bridge
curl -H "api_key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/outlook/calendars

# View dashboard data (JSON)
curl -H "api_key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/health/dashboard | jq
```

### Common Issues

- Ensure `.env` file is properly configured (DB, Outlook, API_KEY)
- Verify Microsoft Graph API permissions
- Confirm resource mappings exist before syncing
- Run deletion processor if deletions aren’t syncing
- Verify webhook subscriptions are active (if using webhooks)
- Check database connectivity and credentials
- Confirm network access to Microsoft 365

## 📝 Production Readiness

✅ **Verified Production Features:**

- Transaction safety with rollback support
- Zero error rate in sync operations
- Loop prevention mechanisms
- Comprehensive audit logging
- Real-time statistics and monitoring
- Graceful error handling and recovery

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## 📄 License

See [LICENSE](LICENSE) file for details.

## ✅ Implementation Status

### 🎉 Transformation complete — ready for production

OutlookBookingSync has been successfully transformed into a **Generic Calendar Bridge** platform:

### **✅ Architecture Transformation (COMPLETED)**

- **Bridge Pattern**: Full migration to extensible bridge architecture
- **Generic Interface**: AbstractCalendarBridge base class implemented
- **REST API**: Pure REST communication for all calendar systems
- **Database Schema**: Complete bridge schema for mappings and configurations

### **✅ Working Bridges (COMPLETED)**

- **OutlookBridge**: Microsoft Graph API with webhook support and resource discovery
- **BookingSystemBridge**: Generic booking system with REST API + DB fallback and configurable endpoints
- **BridgeManager**: Central orchestration service managing all bridges
- **Resource Discovery**: All bridges support available-resources, available-groups, and user calendar queries

### **✅ Production Features (COMPLETED)**

- **Bidirectional Sync**: Events sync seamlessly between any bridge types
- **Deletion Handling**: Robust deletion detection and synchronization
- **Real-time Webhooks**: Instant updates via webhook notifications
- **Resource Mapping**: Calendar resource management system
- **Health Monitoring**: Comprehensive system monitoring and logging
- **API Security**: Authentication and secure endpoint access

### **✅ Code Organization (COMPLETED)**

- **Clean Architecture**: Obsolete code moved to `obsolete/` directories  
- **Modern API**: RESTful endpoints replacing legacy interfaces
- **Documentation**: Complete guides and API documentation
- **Production Scripts**: Setup, testing, and automation tools

### **🚀 Ready for Extension**

The bridge platform is now ready to support additional calendar systems:

- Google Calendar (implement GoogleCalendarBridge)
- CalDAV systems (implement CalDAVBridge)  
- Exchange Server (implement ExchangeBridge)
- Any custom calendar system (extend AbstractCalendarBridge)

---
