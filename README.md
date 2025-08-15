# OutlookBookingSync - Generic Calendar Bridge

A **production-ready, extensible calendar synchronization platform** that acts as a universal bridge between any calendar systems. Built with PHP/Slim4, this system can synchronize events between Outlook (Microsoft 365) and any other calendar system using REST APIs.

## 🎯 Overview

**OutlookBookingSync** has been transformed into a **Generic Calendar Bridge** - a flexible, extensible platform that can connect any calendar system to any other calendar system. While it started as an Outlook-specific solution, it now supports universal calendar synchronization.

### **What Makes This Universal:**
- 🌐 **Bridge Pattern Architecture** - Extensible to any calendar system
- 🔗 **REST API Communication** - Standard HTTP interfaces for all integrations
- 🏠 **Self-Hosted Solution** - Full control and customization
- 🏢 **Production Ready** - Enterprise-grade reliability and monitoring
- 👨‍💻 **Developer Friendly** - Easy to extend with new calendar adapters

## 🚀 Key Features

- ✅ **Universal Bridge System** - Connect any calendar to any other calendar
- ✅ **Complete Bidirectional Sync** - Events flow seamlessly between systems
- ✅ **Comprehensive Sync Status Tracking** - Real-time monitoring, error recovery, and retry mechanisms
- ✅ **Automatic Deletion Handling** - Detects and syncs deletions across systems
- ✅ **Resource Mapping Management** - Map booking resources to calendar systems
- ✅ **Webhook-Free Operation** - Works perfectly with polling (no public IP needed)
- ✅ **Real-time Webhooks** - Optional instant sync for internet-accessible systems
- ✅ **RESTful API** - Comprehensive endpoints for all operations including sync status management
- ✅ **Health Monitoring** - Statistics, logs, and real-time sync status monitoring
- ✅ **Interactive Dashboard** - Web-based monitoring interface with sync management controls
- ✅ **Docker Containerized** - Easy deployment and scaling

## 🏗️ Architecture

```
┌─────────────────┐    REST API    ┌─────────────────┐    REST API    ┌─────────────────┐
│                 │◄──────────────►│                 │◄──────────────►│                 │
│ Booking System  │                │ Calendar Bridge │                │ Microsoft Graph │
│                 │                │   (Middleware)  │                │      API        │
└─────────────────┘                └─────────────────┘                └─────────────────┘
```

### **Supported Calendar Systems:**
- ✅ **Microsoft Outlook/Graph API** (Full implementation)
- ✅ **Generic Booking Systems** (REST API)
- 🔄 **Google Calendar** (Extensible - implement GoogleBridge)
- 🔄 **CalDAV Systems** (Extensible - implement CalDAVBridge)
- 🔄 **Any Custom System** (Implement AbstractCalendarBridge)

## 📋 System Requirements

- PHP 8.4+
- PostgreSQL Database
- Docker & Docker Compose (recommended)
- Microsoft Graph API Credentials (for Outlook integration)
- Network access to target calendar systems

## 🏗️ Quick Start

### 1. Clone and Setup

```bash
git clone <repository-url>
cd OutlookBookingSync
```

### 2. Configure Environment

```bash
cp .env.example .env
cp .env.compose.example .env.compose
# Edit .env with your database and Microsoft Graph credentials
```

### 3. Setup Database

```bash
# Create bridge database schema
scripts/setup_bridge_database.sh
```
or just apply the database script directly in your PostgreSQL client.

database/bridge_schema.sql


### 4. Start the Bridge

```bash
# Using Docker (recommended)
docker compose up -d

# Or run directly with PHP
php -S localhost:8082 index.php
```

### 5. Verify Installation

```bash
# Check bridge health
curl -H "api_key: change-me-strong-random" http://localhost:8082/bridges/health

# List available bridges
curl -H "api_key: change-me-strong-random" http://localhost:8082/bridges

# Test resource discovery (example with outlook bridge)
curl -H "api_key: change-me-strong-random" http://localhost:8082/bridges/outlook/available-resources
curl -H "api_key: change-me-strong-random" http://localhost:8082/bridges/outlook/available-groups

# Test resource mapping API
curl -H "api_key: change-me-strong-random" http://localhost:8082/mappings/resources
```

### 6. Setup Your Booking System API

See [README_BRIDGE.md](README_BRIDGE.md) for detailed booking system API requirements.

### Roadmap and Operations

- Future plan: see [ROADMAP.md](ROADMAP.md)
- Development guide: [doc/DEVELOPMENT.md](doc/DEVELOPMENT.md)
- Maintenance runbook: [doc/MAINTENANCE.md](doc/MAINTENANCE.md)

### Authentication and Dashboard

- Set an API key via `.env` (development) or `.env.compose` (Docker). The application checks `$_ENV['API_KEY']`.
- The dashboard prompts for the API key on first load and stores it in your browser. Press Ctrl+K to update it.
- For curl or scripts, send the header: `api_key: <your key>`.

#### Multi-tenant authentication

- Requests are scoped by a resolved `tenant_id` (from route `tenantId`, header `X-Tenant-Id`, or `DEFAULT_TENANT_ID`).
- API keys can be:
  - Global: the `API_KEY` from environment (backward compatible; also used for admin endpoints).
  - Per-tenant: either configured via environment JSON map `TENANT_API_KEYS_JSON` (development) or securely stored hashed in DB (`tenant_api_keys`).
- The middleware validates in this order: per-tenant env map → per-tenant DB hash (password_verify) → global API key.
- Webhook endpoints are exempt from API key checks (Graph validation flow), all others require a key.

#### How to configure multi-tenant (step-by-step)

1. Prerequisites

- Apply the database schema (ensures `tenants`, `tenant_api_keys`, `bridge_configs` exist): see `database/bridge_schema.sql` and `database/migrations/`.
- Set a global admin API key for management operations:
  - Docker Compose: set `API_KEY` in `.env.compose` (the container reads this).
  - Bare metal/dev: set `API_KEY` in `.env`.
- Start the app and open the dashboard at `/dashboard`.

1. Admin access in the browser

- In your browser console, store the global admin key:

  ```js
  localStorage.dashboard_api_key = 'your-global-admin-key'
  ```

- The admin UI sends this as `api_key` (and `X-API-Key`) for all `/admin/...` requests.
- Admin endpoints always use the global admin key (per-tenant keys are not accepted for admin).

1. Create tenants and rotate per-tenant keys

- Navigate to `/admin-tenants` (or `/admin-tenants.html` if pretty URLs aren’t enabled).
- Create each tenant (id, name). Use the “Rotate key” action to generate a tenant key.
  - The plaintext key is shown once; copy and store it securely. The hash is stored in `tenant_api_keys`.

1. Move per-tenant configs from .env to the database

- Use `/admin-configs` to manage JSON configs per tenant and per bridge.
- For each tenant, create configs like:

  Outlook bridge (`bridgeName = outlook`)

  ```json
  {
    "client_id": "<OUTLOOK_CLIENT_ID>",
    "client_secret": "<OUTLOOK_CLIENT_SECRET>",
    "tenant_id": "<OUTLOOK_TENANT_ID>",
    "group_id": "<OUTLOOK_GROUP_ID>",
    "timezone": "Europe/Oslo"
  }
  ```

  Booking system bridge (`bridgeName = booking_system`)

  ```json
  {
    "api_base_url": "<BOOKING_SYSTEM_API_URL>",
    "login": "<BOOKING_SYSTEM_LOGIN>",
    "password": "<BOOKING_SYSTEM_PASSWORD>",
    "domain": "<BOOKING_SYSTEM_DOMAIN>",
    "proxy": "<BOOKING_SYSTEM_PROXY>",
    "timezone": "<BOOKING_SYSTEM_TIMEZONE>",
    "defaults": {
      "agegroup_id": 1,
      "targetaudience_id": 7,
      "activity_id": 1
    }
  }
  ```

- What to keep in `.env` (global):
  - DB_*, SESSION_*, APP_BASE_URL, `API_KEY` (global admin), container-level proxies.
  - `BOOKING_SYSTEM_THROW_ON_FAILURE`.
  - `WEBHOOK_BASE_URL`, `WEBHOOK_CLIENT_SECRET` can remain global; move per-tenant later only if each tenant needs its own values.

- What to move into per-tenant DB configs:
  - Outlook: `OUTLOOK_CLIENT_ID`, `OUTLOOK_CLIENT_SECRET`, `OUTLOOK_TENANT_ID`, `OUTLOOK_GROUP_ID`.
  - Booking system: `BOOKING_SYSTEM_API_URL`, `BOOKING_SYSTEM_LOGIN`, `BOOKING_SYSTEM_PASSWORD`, `BOOKING_SYSTEM_DOMAIN`, `BOOKING_SYSTEM_PROXY`, `BOOKING_SYSTEM_TIMEZONE`, default IDs.

1. Calling APIs with a tenant context

- Provide a tenant in one of these ways (TenantResolver):
  - Header: `X-Tenant-Id: <tenant_id>`
  - Route parameter (endpoints that include `{tenantId}`)
  - Fallback: set `DEFAULT_TENANT_ID` in the environment (optional)
- Authenticate with either:
  - Per-tenant key: `api_key: <tenant-key>` (recommended for tenant-scoped endpoints)
  - Global key: `api_key: <global-admin-key>` (primarily for admin and backward compatibility)

1. Verifying

- GET `/admin/tenants` with the global key should list tenants.
- GET `/admin/tenants/{id}/configs/outlook` should return that tenant’s Outlook config JSON.
- Non-admin endpoints should work with `X-Tenant-Id` and the tenant’s API key.

1. Troubleshooting

- 401 Unauthorized: check you sent `api_key` and the right tenant via `X-Tenant-Id`.
- 403 Admin access required: admin calls need the global `API_KEY`.
- Browser header issues with underscores: the UI also sends `X-API-Key`.
- If you’re using a reverse proxy, ensure it forwards custom headers and doesn’t strip underscores, or rely on the hyphenated header.

#### Bidirectional configuration (per tenant)

Bidirectional sync is configured via the `bridge_resource_mappings` table and the `sync_direction` field. For one tenant, you typically define a single mapping row per calendar pair and set `sync_direction` according to your needs:

- `bidirectional` — a single row enables both flows (booking_system → outlook and outlook → booking_system)
- `source_to_target` — only forward flow from `bridge_from` to `bridge_to`
- `target_to_source` — only reverse flow (useful when you want to allow just the opposite direction)

Recommended model per tenant:

- Keep the columns semantic: use `bridge_from = booking_system`, `bridge_to = outlook`,
  - `source_calendar_id` = your booking system resource ID
  - `target_calendar_id` = the Outlook calendar address/ID
  - `sync_direction = bidirectional` when you want two-way sync

At runtime, the bridge uses this single row to handle both directions. When syncing the reverse direction, the controller flips which calendar is treated as the source versus target based on the API you call.

Example: create a bidirectional mapping for a tenant

- Scope the write with `X-Tenant-Id: tenantA` and authenticate with that tenant’s API key (or the global admin key if operating centrally):

```http
POST /mappings/resources
X-Tenant-Id: tenantA
api_key: <tenant-or-admin-key>
Content-Type: application/json

{
  "bridge_from": "booking_system",
  "bridge_to": "outlook",
  "source_calendar_id": "room_123",
  "target_calendar_id": "conference-room-a@company.com",
  "sync_direction": "bidirectional"
}
```

Then you can trigger either direction using the same mapping row:

- Booking system → Outlook
  - `POST /bridges/sync/booking_system/outlook` with JSON body `{ "source_calendar_id": "room_123", "target_calendar_id": "conference-room-a@company.com" }`

- Outlook → Booking system
  - `POST /bridges/sync/outlook/booking_system` with JSON body `{ "source_calendar_id": "conference-room-a@company.com", "target_calendar_id": "room_123" }`

One-way scenarios:

- Only booking → Outlook: set `sync_direction = source_to_target` on the mapping above.
- Only Outlook → booking: either
  - define the mapping with `bridge_from = outlook`, `bridge_to = booking_system`, `sync_direction = source_to_target`, or
  - keep the semantic mapping (booking_system → outlook) and set `sync_direction = target_to_source`.

Notes:

- All mappings and sync operations are tenant-scoped. Include `X-Tenant-Id` on reads and writes.
- You generally don’t need two rows for the same pair; prefer a single row with the appropriate `sync_direction`.
- The app also exposes a convenience view `v_active_resource_mappings` that shows only active/enabled rows with some derived stats.

#### Admin UI

- Minimal admin pages are provided under `/public`:
  - `/admin-tenants.html` — list/create/delete tenants and rotate keys (shortcut to rotation)
  - `/admin-keys.html` — view key metadata and rotate keys (plaintext shown once)
  - `/admin-configs.html` — view/edit per-tenant bridge configs (JSON)
- These pages require the global admin API key in the browser: set once via dashboard (Ctrl+K) or console: `localStorage.api_key = 'your-admin-key'`.

#### Admin security (CSRF and IP allowlist)

- CSRF protection is enforced on all unsafe admin operations (POST/PUT/PATCH/DELETE). The UI obtains a token from `GET /admin/csrf` and sends it in the `X-CSRF-Token` header. Tokens are session-based, so the browser must keep cookies for the site.
- Admin endpoints require the global API key (header `api_key: <GLOBAL_API_KEY>`). Per-tenant keys are not accepted for admin.
- You can optionally restrict admin access to specific IPs/CIDRs via the environment variable `ADMIN_IP_ALLOWLIST` (comma-separated values, e.g., `192.168.1.10,10.0.0.0/8`). If set, requests from non-allowed IPs will be rejected for admin routes.

#### Admin API quick examples

```bash
# List tenants
curl -H "api_key: $API_KEY" http://localhost:8082/admin/tenants | jq

# Create tenant
curl -X POST -H "api_key: $API_KEY" -H "Content-Type: application/json" \
  -d '{"id":"tenantA","name":"Tenant A","active":true}' \
  http://localhost:8082/admin/tenants | jq

# Rotate API key (plaintext returned once)
curl -X POST -H "api_key: $API_KEY" http://localhost:8082/admin/tenants/tenantA/keys/rotate | jq

# Get key metadata (no secret)
curl -H "api_key: $API_KEY" http://localhost:8082/admin/tenants/tenantA/keys/metadata | jq

# Upsert per-tenant bridge config
curl -X PUT -H "api_key: $API_KEY" -H "Content-Type: application/json" \
  -d '{"client_id":"xxx","client_secret":"yyy"}' \
  http://localhost:8082/admin/tenants/tenantA/configs/outlook | jq

# Get per-tenant bridge config
curl -H "api_key: $API_KEY" http://localhost:8082/admin/tenants/tenantA/configs/outlook | jq
```

### Maintenance: sync log retention

- Endpoint: `POST /maintenance/cleanup-logs?days=30` removes old rows from `bridge_sync_logs` (defaults to 30 days if omitted).
- Example:

```bash
curl -s -X POST "http://localhost:8082/maintenance/cleanup-logs?days=30" \
  -H "api_key: change-me-strong-random"
```

- Cron: The container runs this daily at 03:00. Override retention via `CLEANUP_DAYS` in `.env.compose`.

## 🛡️ Production readiness

Use this checklist before exposing the service in production.

Security
- Set a strong, unique `API_KEY` (store in `.env.compose` or a secret manager). Rotate periodically.
- Disable legacy endpoints: set `ENABLE_LEGACY_WEBHOOKS=false`.
- Terminate TLS at a reverse proxy (nginx/Traefik) and prefer private network exposure.
- Add proxy protections: rate limiting, request size limits, and optional IP allowlist for admin endpoints and `/dashboard`.

Operations and resilience
- Run with Docker restart policy and a container healthcheck.
- Ensure PHP runs with production settings (display_errors off; error logging on).
- Verify cron schedules do not overlap and timezone is correct; keep `API_KEY` available to cron (entrypoint already wires the header).

Observability
- Centralize logs (Apache/PHP/app) and alert on `/health/system` degradation.
- Track cron success/failure and set up basic metrics dashboards.

Data and database
- Apply migrations on deploy; set up automated backups and retention.
- Validate DB performance and connection limits under expected load.

CI/CD quality gates
- Add a minimal pipeline: `php -l`, static analysis (PHPStan), and a few unit/integration tests.

Optional docker-compose hardening

```yaml
services:
  portico_outlook:
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-fsS", "http://localhost/health"]
      interval: 30s
      timeout: 5s
      retries: 3
```

### Example: Bridge-Based Deletion Handling

```bash
# Example: Handle booking system cancellation (sets event to inactive)
# The bridge system will automatically detect and sync the deletion to Outlook

# 1. Set booking system event to inactive (via your booking system)
curl -X PUT http://your-booking-system/api/events/123 \
  -d '{"status": "inactive"}'

# 2. Run deletion detection to sync to Outlook
curl -X POST -H "api_key: change-me-strong-random" http://localhost:8082/bridges/sync-deletions

# Example: Handle Outlook deletion
# When an Outlook event is deleted, webhooks or polling will detect it
# and automatically mark the corresponding booking system event as inactive
```

## 🆕 Advanced Features

### **Composite ID System**
Universal event identification across different calendar systems:
- **Format**: `{type}_{original_id}` (e.g., `event_78269`, `booking_123`)
- **Bidirectional Support**: Works seamlessly in both sync directions
- **Type Safety**: Preserves original event type and ID for accurate API calls
- **Universal Mapping**: Enables correct addressing across any calendar system

**Supported Event Types:**
- `event_` - Standard calendar events (Priority: 1 - Highest)
- `booking_` - Booking system reservations (Priority: 2)
- `allocation_` - Resource allocation entries (Priority: 3 - Lowest)
- `meeting_` - Meeting room bookings (Priority: 2)
- `appointment_` - Appointment entries (Priority: 2)

### **Priority Filtering System**
Intelligent conflict resolution for overlapping reservations:
- **Automatic Priority Resolution**: Handles multiple events in the same time slot
- **Configurable Hierarchy**: Event > Booking > Allocation priority levels
- **Conflict Logging**: Detailed audit trail of all filtering decisions
- **Performance Optimized**: Minimal overhead with efficient filtering algorithms

### **Session-Based Authentication**
Enterprise-grade authentication for booking system integrations:
- **Login Flow**: Secure session establishment with username/password
- **Session Management**: Automatic token refresh and session maintenance
- **API Security**: Session tokens used for all API communications
- **Fallback Support**: Graceful handling of session expiration

## 🔧 Core Bridge Operations

### **Resource Discovery**
```bash
# Discover available calendar resources
curl -X GET "http://localhost:8082/bridges/outlook/available-resources?query=conference&limit=10"

# Get calendar groups
curl -X GET "http://localhost:8082/bridges/outlook/available-groups?query=meeting&limit=5"
```

### **Event Synchronization with Composite IDs**
```bash
# Sync events between systems (automatic composite ID handling)
curl -X POST "http://localhost:8082/bridges/sync/booking_system/outlook" \
  -H "Content-Type: application/json" \
  -d '{
    "source_calendar_id": "room_123",
    "target_calendar_id": "conference-room-a@company.com",
    "apply_priority_filter": true
  }'

# Response includes composite ID information
{
  "success": true,
  "synced_events": [
    {
      "composite_id": "event_78269",
      "priority": 1,
      "status": "synced"
    }
  ],
  "filtered_events": [
    {
      "composite_id": "booking_123",
      "priority": 2,
      "status": "filtered_due_to_priority"
    }
  ]
}
```

### **Resource Mapping Management**
```bash
# Create resource mapping
curl -X POST "http://localhost:8082/mappings/resources" \
  -H "Content-Type: application/json" \
  -d '{
    "bridge_from": "booking_system",
    "bridge_to": "outlook",
    "resource_id": "room_123", 
    "calendar_id": "conference-room-a@company.com"
  }'

# Delete mapping by composite key
curl -X DELETE "http://localhost:8082/mappings/resources/by-key/booking_system/room_123/conference-room-a@company.com"
```

## 🔧 Configuration

### Environment Variables

- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` - Database configuration
- `OUTLOOK_CLIENT_ID`, `OUTLOOK_CLIENT_SECRET`, `OUTLOOK_TENANT_ID`, `OUTLOOK_GROUP_ID` - Microsoft Graph API
- `APP_BASE_URL` - Base URL for this service (used in links/webhooks)
- `API_KEY` - API key for endpoint security (send as header `api_key`)
- `CLEANUP_DAYS` - Days to keep sync logs (used by daily cleanup cron)
- `RENEW_MINUTES` - Renewal threshold in minutes for webhook subscriptions (hourly cron)

### Bridge Configuration

Bridges are automatically registered on service startup using environment variables. Configure your credentials in the `.env` file:

```env
# Microsoft Graph API
OUTLOOK_CLIENT_ID=your_client_id
OUTLOOK_CLIENT_SECRET=your_client_secret
OUTLOOK_TENANT_ID=your_tenant_id
OUTLOOK_GROUP_ID=your_group_id

# Booking System API (Session-based authentication)
BOOKING_SYSTEM_API_URL=http://your-booking-system/api
BOOKING_SYSTEM_LOGIN=your_username
BOOKING_SYSTEM_PASSWORD=your_password
BOOKING_SYSTEM_DOMAIN=your_domain
BOOKING_SYSTEM_THROW_ON_FAILURE=true

# Application
APP_BASE_URL=https://bridge.example.com
API_KEY=replace_me
```

The bridges will be automatically available once the service starts.

## 📊 API Endpoints

### Bridge Management

- `GET /bridges` - List all available bridges
- `GET /bridges/{bridge}/calendars` - Get calendars for a bridge
- `GET /bridges/{bridge}/available-resources` - Get available resources (rooms/equipment) for a bridge
- `GET /bridges/{bridge}/available-groups` - Get available groups/collections for a bridge
- `GET /bridges/{bridge}/resources/{resourceId}/calendar-items` - Get calendar items for a specific resource on a bridge
- `POST /bridges/sync/{from}/{to}` - Sync events between bridges
- `POST /bridges/webhook/{bridge}` - Handle bridge webhooks (legacy `/webhook/outlook-notifications` is redirected)
- `POST /bridges/{bridge}/subscriptions` - Create webhook subscriptions for a bridge
- `GET /bridges/health` - Get health status of all bridges

### Resource Mapping

- `GET /mappings/resources` - Get all resource mappings
- `POST /mappings/resources` - Create new resource mapping
- `PUT /mappings/resources/{id}` - Update resource mapping
- `DELETE /mappings/resources/by-key/{bridge_from}/{source_calendar_id}/{target_calendar_id}` - Delete resource mapping by composite key
- `GET /mappings/resources/by-resource/{source_calendar_id}` - Get mappings by booking system resource ID

### Deletion & Cancellation Sync

- `POST /bridges/sync-deletions` - Detect and sync deletions across bridge systems
- `POST /bridges/process-deletion-queue` - Process webhook-based deletion notifications

### Health & Monitoring

- `GET /health` - Quick health check
- `GET /health/system` - Comprehensive system health
- `GET /health/dashboard` - Dashboard data
- `GET /health/sync-status` - Detailed sync status
- `POST /health/re-enable-failed` - Re-enable failed events (all bridges)
- `POST /bridges/process-pending-syncs[/{bridge}]` - Process pending syncs
- `POST /bridges/re-enable-failed[/{bridge}]` - Re-enable failed events
- `GET /bridges/sync-stats[/{bridge}]` - Sync statistics
- `GET /bridges/cancelled-events[/{bridge}]` - Cancelled events
- `GET /bridges/{bridge}/pending-events` - Events pending sync
- `POST /alerts/check` - Run alert checks
- `GET /alerts` - Recent alerts
- `GET /alerts/stats` - Alert statistics
- `POST /alerts/{id}/acknowledge` - Acknowledge an alert
- `DELETE /alerts/old` - Clear old alerts

### Maintenance

- `POST /maintenance/cleanup-logs` - Cleanup old sync logs (query: `?days=int`, default 30)
- `POST /maintenance/renew-subscriptions` - Renew expiring webhook subscriptions (query: `?bridge=outlook&renew_before_minutes=int&limit=int`)

### 📖 Documentation

For complete technical documentation and API reference:

- **[README_BRIDGE.md](README_BRIDGE.md)** - Complete technical documentation with detailed API specs, configuration examples, and implementation guides
- **[Database Schema](database/bridge_schema.sql)** - Bridge database tables and views
- **[Calendar Sync Service Plan](doc/calendar_sync_service_plan.md)** - Architecture and design documentation
- **[Setup Scripts](setup_bridge_database.sh)** - Database initialization and testing tools

### Legacy Endpoints (Redirected/Removed)

The following legacy endpoints have been removed or redirected to bridge equivalents:

- `POST /sync/to-outlook` → Use `POST /bridges/sync/booking_system/outlook`
- `POST /sync/from-outlook` → Use `POST /bridges/sync/outlook/booking_system`
- `GET /sync/pending-items` → Use `GET /mappings/resources`
- `DELETE /cancel/reservation/{type}/{id}/{resourceId}` → Use bridge deletion sync (`/bridges/sync-deletions`)
- `POST /cancel/bulk` → Use `POST /bridges/process-deletion-queue`
- `GET /cancel/stats` → Use health endpoints (`/health/system`, `/bridges/sync-stats`)
- `POST /webhook/outlook-notifications` → Prefer `POST /bridges/webhook/outlook` (legacy is still supported)

## ⚙️ Automated Processing

The system supports automated processing through cron jobs that use bridge endpoints:

```bash
# Bidirectional bridge synchronization
*/5 * * * * curl -X POST http://localhost:8082/bridges/sync/booking_system/outlook \
  -H "Content-Type: application/json" \
  -d '{"start_date":"$(date +%Y-%m-%d)","end_date":"$(date -d \"+7 days\" +%Y-%m-%d)"}'

*/10 * * * * curl -X POST http://localhost:8082/bridges/sync/outlook/booking_system \
  -H "Content-Type: application/json" \
  -d '{"start_date":"$(date +%Y-%m-%d)","end_date":"$(date -d \"+7 days\" +%Y-%m-%d)"}'

# Enhanced deletion processing (recommended)
*/5 * * * * /scripts/enhanced_process_deletions.sh

# Alternative: Individual deletion sync calls
*/5 * * * * curl -X POST http://localhost:8082/bridges/process-deletion-queue
*/5 * * * * curl -X POST http://localhost:8082/bridges/sync-deletions

# Health monitoring
*/10 * * * * curl -X GET http://localhost:8082/bridges/health
*/15 * * * * curl -X GET http://localhost:8082/health/system
```

## 📚 Documentation

### **Comprehensive Guides:**

- **[README_BRIDGE.md](README_BRIDGE.md)** - **Complete bridge documentation** with API specs, booking system requirements, and examples
- **[CLEANUP_SUMMARY.md](CLEANUP_SUMMARY.md)** - Summary of code cleanup and obsolete routes
- **[Calendar Sync Service Plan](doc/calendar_sync_service_plan.md)** - Architecture and design documentation

### **Technical References:**

- **[Database Schema](database/bridge_schema.sql)** - Bridge database tables and views
- **[Setup Script](setup_bridge_database.sh)** - Database initialization
- **[Test Script](test_bridge.sh)** - API endpoint testing
- **[Deletion Processor](scripts/enhanced_process_deletions.sh)** - Automated deletion sync

### **Legacy Documentation:**

- [Sync Usage Guide](doc/sync_usage_guide.md) - Original sync documentation
- [Cancellation Detection](doc/outlook_cancellation_detection.md) - Cancellation handling
- [Monitoring System](doc/monitoring_system_guide.md) - Health monitoring

## 🌐 Service Architecture

### **Current Implementation:**

```text
Generic Calendar Bridge (Port 8080)
├── Bridge Management API (/bridges/*)
├── Resource Mapping API (/mappings/*)
├── Health Monitoring (/health/*)
├── Deletion Sync Processing
└── Webhook Handling (Real-time sync)
```

### **Supported Integrations:**

- ✅ **Microsoft Outlook/365** (Full webhook + API support)
- ✅ **Booking Systems** (REST API)
- 🔄 **Extensible** (Add new calendar systems by implementing AbstractCalendarBridge)

## 🚀 Getting Started

1. **For New Users**: Start with [README_BRIDGE.md](README_BRIDGE.md) - Complete setup guide
2. **For Developers**: See booking system API requirements in README_BRIDGE.md
3. **For Testing**: Use `./test_bridge.sh` to validate all endpoints

## 🔍 Troubleshooting

### Bridge Health Check

```bash
# Check overall bridge health
curl -H "api_key: your_key" http://localhost:8082/bridges/health

# Test specific bridge
curl -H "api_key: your_key" http://localhost:8082/bridges/outlook/calendars

# View dashboard data (JSON)
curl -H "api_key: your_key" http://localhost:8082/health/dashboard | jq
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
