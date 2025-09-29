# OutlookBookingSync - Generic Calendar Bridge

A **production-ready, extensible calendar synchronization platform** that acts as a universal bridge between any calendar systems. Built with PHP/Slim4, this system can synchronize events between Outlook (Microsoft 365) and booking/other calendar systems via a pluggable bridge architecture.

## 🎯 Overview

**Core Features:**

- **Extensible Bridge Pattern**: Add new calendar systems quickly by extending `AbstractCalendarBridge`
- **Ownership-Based Sync**: Conflict-safe policies with `sync_direction` control
- **Multi-Tenant Architecture**: Per-tenant API keys & per-bridge configuration
- **Webhook & Polling Support**: Real-time updates or scheduled synchronization
- **Production Ready**: Comprehensive monitoring, health checks, and error handling
- **Operational Observability**: Health endpoints, queue stats, sync logs, and alerts

## 🚀 Feature Summary

| Area | Highlights |
|------|-----------|
| **Sync Types** | Bidirectional or one-way with automatic recreation logic |
| **Ownership Control** | `sync_direction` enforces authoritative side per mapping |
| **Deletion Handling** | Queue + verification + recreation safeguards |
| **Multi-Tenancy** | API key hierarchy, scoped configs, tenant admin UI |
| **Monitoring** | Health endpoints, queue stats, sync logs, alerts |
| **Extensibility** | Implement `AbstractCalendarBridge` for new systems |
| **Security** | Key-based auth, CSRF protection, optional IP allowlist |
| **Deployment** | Docker / bare metal, cron-friendly endpoints |

## 🏗️ Architecture

Bridges implement a common contract (`AbstractCalendarBridge`): fetch/create/update/delete/transform events. The `BridgeManager` orchestrates sync passes, honoring ownership rules and writing audit entries to `bridge_sync_logs`. See `doc/architecture.md` for detailed diagrams and explanations.

## 🏁 Quick Start

1. **Clone and setup**

   ```bash
   git clone <repository-url>
   cd OutlookBookingSync
   cp .env.example .env
   # Edit .env with your database and Outlook credentials
   ```

2. **Initialize database**

   ```bash
   scripts/setup_bridge_database.sh
   ```

3. **Run the service**

   ```bash
   # Docker (recommended)
   docker compose up -d
   
   # Or local PHP server
   php -S localhost:8082 index.php
   ```

4. **Verify health**

   ```bash
   curl -H "X-API-Key: change-me-strong-random" -H "X-Tenant-Id: tenantA" \
        http://localhost:8082/bridges/health
   ```

5. **Create a resource mapping**

   ```bash
   curl -X POST -H "Content-Type: application/json" \
        -H "X-API-Key: change-me-strong-random" -H "X-Tenant-Id: tenantA" \
        http://localhost:8082/mappings/resources \
        -d '{
          "bridge_from": "booking_system",
          "bridge_to": "outlook",
          "source_calendar_id": "room_123",
          "target_calendar_id": "conference-room-a@company.com",
          "sync_direction": "source_to_target"
        }'
   ```

## 🔐 Authentication

- **API Key**: Send `X-API-Key: <your-key>` header
- **Multi-Tenant**: Add `X-Tenant-Id: <tenant>` header  
- **Permissions**: Global admin keys manage tenants; per-tenant keys manage scoped operations
- **Webhooks**: Skip auth (Microsoft Graph validation flow)

## 🔄 Ownership Model

Control sync behavior with `sync_direction`:

- `source_to_target`: Source system owns events, target modifications are skipped
- `target_to_source`: Target system owns events, source modifications are skipped  
- `bidirectional`: Both systems can modify events

Non-owner modifications are logged and skipped. Deleted events are recreated by the owner unless deletion respect is explicitly enabled.

## 📚 Documentation

| Topic | Location | Purpose |
|-------|----------|---------|
| **Architecture & Concepts** | `doc/architecture.md` | Core components, data model, ownership rules |
| **Usage & Examples** | `doc/usage.md` | Creating mappings, running syncs, troubleshooting |
| **Configuration** | `doc/configuration.md` | Environment variables, tenant configs |
| **Operations** | `doc/operations.md` | Cron setup, monitoring, webhooks |
| **Development** | `doc/development.md` | Local setup, extending bridges, testing |
| **Multi-Tenancy** | `doc/multi_tenancy.md` | Tenant management, API key hierarchy |
| **Security** | `doc/security_hardening.md` | Security best practices, hardening |
| **API Reference** | `doc/api_endpoints.md` | Complete endpoint documentation |
| **Booking Systems** | `doc/booking_system_adapter.md` | Integration requirements |

## 🧱 Extending with New Bridges

1. Extend `AbstractCalendarBridge` class
2. Implement required methods: `getEvents`, `createEvent`, `updateEvent`, `deleteEvent`  
3. Register with `BridgeManager` in DI container
4. Add configuration support

See `doc/development.md` for detailed extension guide.

## 🛠️ Operations & Monitoring

- **Health Endpoints**: `/health/system`, `/health/database`, `/health/dashboard`
- **Cron Jobs**: Sync passes, deletion processing, subscription renewal
- **Monitoring**: Queue statistics, sync logs, error tracking
- **Webhooks**: Real-time Microsoft Graph notifications

Full operational guide: `doc/operations.md`

## ✅ Status

**Production Ready** - Core platform is stable with comprehensive monitoring and error handling.

**Upcoming Features** (see `ROADMAP.md`):

- Google Calendar bridge
- CalDAV support  
- Enhanced conflict resolution
- Advanced metrics and tracing

## 🚨 Troubleshooting

**Common Issues:**

- Ensure `.env` file is properly configured (DB, Outlook credentials, API key)
- Verify Microsoft Graph API permissions and app registration
- Confirm resource mappings exist before attempting sync
- Check webhook subscriptions are active for real-time updates
- Verify network access to Microsoft 365 and target booking systems

**Health Checks:**

```bash
# System health
curl -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" \
     http://localhost:8082/bridges/health

# Bridge-specific test  
curl -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" \
     http://localhost:8082/bridges/outlook/calendars
```

## 🤝 Contributing

Issues and pull requests welcome! Please read `doc/development.md` for coding standards and extension patterns before contributing.

## 📄 License

See [LICENSE](LICENSE) file for details.

---

*This README stays concise by design. For detailed technical information, see the `doc/` directory.*