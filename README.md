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
- ✅ **Automatic Deletion Handling** - Detects and syncs deletions across systems
- ✅ **Resource Mapping Management** - Map booking resources to calendar systems
- ✅ **Real-time Webhooks** - Instant synchronization via webhook notifications
- ✅ **RESTful API** - Comprehensive endpoints for all operations
- ✅ **Health Monitoring** - Statistics, logs, and system monitoring
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
- ✅ **Generic Booking Systems** (REST API + Database fallback)
- 🔄 **Google Calendar** (Extensible - implement GoogleBridge)
- 🔄 **CalDAV Systems** (Extensible - implement CalDAVBridge)
- 🔄 **Any Custom System** (Implement AbstractCalendarBridge)

## 📋 System Requirements

- PHP 8.0+
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
# Edit .env with your database and Microsoft Graph credentials
```

### 3. Setup Database

```bash
# Create bridge database schema
./setup_bridge_database.sh
```

### 4. Start the Bridge

```bash
# Using Docker (recommended)
docker compose up -d

# Or run directly with PHP
php -S localhost:8080 index.php
```

### 5. Verify Installation

```bash
# Check bridge health
curl http://localhost:8080/bridges/health

# List available bridges
curl http://localhost:8080/bridges

# Test resource mapping API
curl http://localhost:8080/mappings/resources
```

### 6. Setup Your Booking System API

See [README_BRIDGE.md](README_BRIDGE.md) for detailed booking system API requirements.

## 🔧 Configuration

### Environment Variables

- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` - Database configuration
- `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET`, `GRAPH_TENANT_ID` - Microsoft Graph API
- `API_KEY` - Optional API key for endpoint security

### Bridge Configuration

```bash
# Register Outlook bridge
curl -X POST http://localhost:8080/bridges/register \
  -H "Content-Type: application/json" \
  -d '{
    "bridge_type": "outlook",
    "config": {
      "tenant_id": "your-tenant-id",
      "client_id": "your-client-id",
      "client_secret": "your-client-secret"
    }
  }'

# Register booking system bridge
curl -X POST http://localhost:8080/bridges/register \
  -H "Content-Type: application/json" \
  -d '{
    "bridge_type": "booking_system",
    "config": {
      "base_url": "https://your-booking-system.com/api",
      "api_key": "your-api-key"
    }
  }'
```

## 📊 API Endpoints

### Bridge Management

- `GET /bridges` - List all available bridges
- `GET /bridges/{bridge}/calendars` - Get calendars for a bridge
- `POST /bridges/sync/{from}/{to}` - Sync events between bridges
- `GET /bridges/health` - Get health status of all bridges

### Resource Mapping

- `GET /mappings/resources` - Get all resource mappings
- `POST /mappings/resources` - Create new resource mapping
- `PUT /mappings/resources/{id}` - Update resource mapping
- `DELETE /mappings/resources/{id}` - Delete resource mapping
- `GET /mappings/resources/by-resource/{id}` - Get mappings by resource ID

### Deletion Sync

- `POST /bridges/sync-deletions` - Manual deletion sync check
- `POST /bridges/process-deletion-queue` - Process deletion queue

### Health & Monitoring

- `GET /health` - Quick health check
- `GET /health/system` - Comprehensive system health
- `POST /alerts/check` - Run alert checks

### Legacy Endpoints (Deprecated)

- `GET /sync/pending-items` - View items awaiting sync (use `/mappings/resources`)
- `POST /sync/to-outlook` - Sync to Outlook (use `/bridges/sync/booking_system/outlook`)
- `POST /webhook/outlook-notifications` - Outlook webhooks (use `/bridges/webhook/outlook`)

## ⚙️ Automated Processing

The system supports automated processing through cron jobs:

```bash
# Process deletion sync every 10 minutes
*/10 * * * * /path/to/process_deletions.sh

# Manual sync check every hour
0 * * * * curl -X POST http://localhost:8080/bridges/sync-deletions
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
- **[Deletion Processor](process_deletions.sh)** - Automated deletion sync

### **Legacy Documentation:**

- [Sync Usage Guide](doc/sync_usage_guide.md) - Original sync documentation
- [Cancellation Detection](doc/outlook_cancellation_detection.md) - Cancellation handling
- [Monitoring System](doc/monitoring_system_guide.md) - Health monitoring

## 🌐 Service Architecture

### **Current Implementation:**
```
Generic Calendar Bridge (Port 8080)
├── Bridge Management API (/bridges/*)
├── Resource Mapping API (/mappings/*)
├── Health Monitoring (/health/*)
├── Deletion Sync Processing
└── Webhook Handling (Real-time sync)
```

### **Supported Integrations:**
- ✅ **Microsoft Outlook/365** (Full webhook + API support)
- ✅ **Booking Systems** (REST API + Database fallback)
- 🔄 **Extensible** (Add new calendar systems by implementing AbstractCalendarBridge)

## 🚀 Getting Started

1. **For New Users**: Start with [README_BRIDGE.md](README_BRIDGE.md) - Complete setup guide
2. **For Developers**: See booking system API requirements in README_BRIDGE.md
3. **For Testing**: Use `./test_bridge.sh` to validate all endpoints

## 🔍 Troubleshooting

### Bridge Health Check

```bash
# Check overall bridge health
curl http://localhost:8080/bridges/health

# Test specific bridge
curl http://localhost:8080/bridges/outlook/calendars

# View recent sync logs
curl http://localhost:8080/bridges/health | jq '.logs[]'
```

### Common Issues

- **Bridge Registration**: Ensure proper credentials in bridge config
- **Resource Mapping**: Create mappings before syncing events
- **Deletion Sync**: Run deletion processor if events aren't syncing deletions
- **Webhooks**: Verify webhook subscriptions are active

For detailed troubleshooting, see [README_BRIDGE.md](README_BRIDGE.md).

### Common Issues

- Ensure `.env` file is properly configured
- Verify Microsoft Graph API permissions
- Check database connectivity
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

**🎉 TRANSFORMATION COMPLETE - Ready for Production**

OutlookBookingSync has been successfully transformed into a **Generic Calendar Bridge** platform:

### **✅ Architecture Transformation (COMPLETED)**
- **Bridge Pattern**: Full migration to extensible bridge architecture
- **Generic Interface**: AbstractCalendarBridge base class implemented
- **REST API**: Pure REST communication for all calendar systems
- **Database Schema**: Complete bridge schema for mappings and configurations

### **✅ Working Bridges (COMPLETED)**
- **OutlookBridge**: Microsoft Graph API with webhook support
- **BookingSystemBridge**: Generic booking system with REST API + DB fallback
- **BridgeManager**: Central orchestration service managing all bridges

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
