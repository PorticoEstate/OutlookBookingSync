# Generic Calendar Bridge Service - Architecture Documentation

## 1. **Overview**

The Generic Calendar Bridge Service is a production-ready, extensible platform that enables seamless synchronization between any calendar systems. Built using the bridge pattern, it provides a universal middleware layer for calendar integration.

### **Core Principles:**
- 🌐 **Universal Integration**: Bridge pattern supports any calendar system
- 🔗 **REST API Communication**: Standard HTTP interfaces for all connections
- 🏠 **Self-Hosted Control**: Complete ownership and customization
- 🏢 **Production Ready**: Enterprise-grade reliability and monitoring
- 👨‍💻 **Developer Friendly**: Easy extension with new calendar adapters

---

## 2. **Architecture Overview**

### **Bridge Pattern Implementation**

```
┌─────────────────┐    Bridge API    ┌─────────────────┐    Bridge API    ┌─────────────────┐
│                 │◄────────────────►│                 │◄────────────────►│                 │
│ Booking System  │                  │ Calendar Bridge │                  │ Microsoft Graph │
│   (Any API)     │                  │   (Middleware)  │                  │      API        │
└─────────────────┘                  └─────────────────┘                  └─────────────────┘
```

### **Core Components**

- **AbstractCalendarBridge**: Base interface for all calendar systems
- **BridgeManager**: Central orchestrator managing bridge instances
- **DeletionSyncService**: Handles cancellation/deletion synchronization
- **OutlookBridge**: Microsoft Graph API implementation
- **BookingSystemBridge**: Generic booking system adapter
- **ResourceMappingController**: Calendar resource management

### **Database Schema**

The bridge system uses these core tables:
- `bridge_mappings`: Event synchronization relationships with composite ID support
- `bridge_resource_mappings`: Calendar resource mappings
- `bridge_sync_logs`: Audit trail and monitoring
- `bridge_queue`: Asynchronous operation processing

### **Composite ID System**

The bridge system implements a **composite ID system** for universal event identification:

- **Format**: `{type}_{original_id}` (e.g., `event_78269`, `booking_123`, `allocation_456`)
- **Purpose**: Enables correct mapping and addressing across different calendar systems
- **Bidirectional Support**: Works seamlessly in both sync directions
- **Type Safety**: Preserves original event type and ID for accurate API calls

**Supported Event Types:**
- `event_` - Standard calendar events
- `booking_` - Booking system reservations
- `allocation_` - Resource allocation entries
- `meeting_` - Meeting room bookings
- `appointment_` - Appointment entries

### **Priority Filtering System**

The bridge implements **intelligent priority filtering** for overlapping reservations:

**Priority Hierarchy (Highest to Lowest):**
1. **Event** - Standard calendar events (highest priority)
2. **Booking** - Booking system reservations
3. **Allocation** - Resource allocation entries (lowest priority)

**Conflict Resolution:**
- When multiple reservations overlap the same time slot and resource
- System automatically selects the highest priority event for synchronization
- Lower priority events are logged but not synced to prevent conflicts
- Detailed conflict resolution logging for audit purposes

---

## 3. **Bidirectional Synchronization**

### **Booking System → Outlook Flow**

1. **Detection**: Booking system events detected via API polling or webhooks
2. **Composite ID Processing**: Events assigned composite IDs (e.g., `event_78269`, `booking_123`)
3. **Priority Filtering**: Overlapping events filtered by priority hierarchy
4. **Bridge Processing**: `BookingSystemBridge` fetches filtered events via REST API
5. **Event Mapping**: Generic event format converted to Outlook format
6. **Sync Operation**: `OutlookBridge` creates/updates events via Microsoft Graph
7. **Mapping Storage**: Relationship stored in `bridge_mappings` with composite IDs

### **Outlook → Booking System Flow**

1. **Detection**: Outlook changes detected via webhooks or polling
2. **Bridge Processing**: `OutlookBridge` fetches events via Microsoft Graph
3. **Event Mapping**: Outlook format converted to generic event format
4. **Composite ID Resolution**: Target composite ID extracted for proper addressing
5. **Sync Operation**: `BookingSystemBridge` creates/updates events using original ID and type
6. **Mapping Storage**: Relationship stored in `bridge_mappings` with composite IDs

### **Composite ID Sync Examples**

**Example 1: Event Creation**
```
Booking System Event: ID=78269, Type=event
Composite ID: event_78269
Outlook Event: Created with bridge mapping
Bridge Mapping: source_id="event_78269", target_id="AAMkAGU..."
```

**Example 2: Bidirectional Update**
```
Outlook Update: Event "AAMkAGU..." modified
Mapping Lookup: target_id="AAMkAGU..." → source_id="event_78269"
ID Resolution: "event_78269" → type="event", id="78269"
Booking System Update: Updates event ID 78269 using correct API endpoint
```

### **Priority Filtering in Action**

**Scenario: Overlapping Reservations**
```
Resource: Conference Room A
Time Slot: 2024-06-18 14:00-15:00

Available Events:
- allocation_456 (Priority: 3)
- booking_123 (Priority: 2)  
- event_78269 (Priority: 1) ← Selected for sync

Result: Only event_78269 synced to Outlook, others logged as conflicts
```

### **Deletion/Cancellation Sync**

The system provides robust deletion handling in both directions with composite ID tracking:

- **Outlook Deletions**: Detected via webhooks, mapped back to original composite ID
- **Booking System Cancellations**: Detected via polling, composite ID used for Outlook cleanup
- **Queue Processing**: `DeletionSyncService` handles asynchronous deletion operations
- **Mapping Cleanup**: Bridge mappings removed using composite ID relationships

---

## 4. **API Endpoints**

### **Bridge Management**
```
GET    /bridges                                  - List available bridges
GET    /bridges/{bridge}/calendars               - Get calendars for bridge
POST   /bridges/sync/{source}/{target}          - Sync between bridges
GET    /bridges/health                          - Bridge health status
```

### **Resource Mapping**
```
GET    /mappings/resources                      - List resource mappings
POST   /mappings/resources                      - Create resource mapping
PUT    /mappings/resources/{id}                 - Update resource mapping
DELETE /mappings/resources/{id}                 - Delete resource mapping
```

### **Deletion Sync**
```
POST   /bridges/sync-deletions                  - Detect and sync deletions
POST   /bridges/process-deletion-queue          - Process deletion queue
```

### **Health & Monitoring**
```
GET    /health                                  - Quick health check
GET    /health/system                           - Comprehensive system health
POST   /alerts/check                            - Run alert checks
```

---

## 5. **Automated Processing**

### **Production Cron Jobs**

The system uses these automated processes:

```bash
# Bidirectional sync operations
*/5 * * * * curl -X POST "http://localhost/bridges/sync/booking_system/outlook"
*/10 * * * * curl -X POST "http://localhost/bridges/sync/outlook/booking_system"

# Deletion processing (coordinated)
*/5 * * * * /scripts/enhanced_process_deletions.sh

# Health monitoring
*/10 * * * * curl -X GET "http://localhost/bridges/health"
*/15 * * * * curl -X GET "http://localhost/health/system"
```

### **Enhanced Deletion Processing**

The `enhanced_process_deletions.sh` script provides coordinated deletion sync:

1. **Webhook Deletions**: Process Microsoft Graph webhook notifications
2. **Cancellation Detection**: Check for inactive booking system events
3. **Manual Sync**: Verify all recent mappings for deleted events
4. **Error Handling**: Comprehensive retry and error logging

---

## 6. **Configuration**

### **Bridge Configuration**

Bridges are configured via environment variables:

```env
# Microsoft Graph API
OUTLOOK_CLIENT_ID=your_client_id
OUTLOOK_CLIENT_SECRET=your_client_secret
OUTLOOK_TENANT_ID=your_tenant_id

# Booking System API
BOOKING_SYSTEM_API_URL=http://your-booking-system/api
BOOKING_SYSTEM_API_KEY=your_api_key
```

### **Booking System API Requirements**

Your booking system must provide these REST endpoints:

```
GET    /api/events                              - List events
POST   /api/events                              - Create event
PUT    /api/events/{id}                         - Update event
DELETE /api/events/{id}                         - Delete/deactivate event
GET    /api/resources                           - List resources
```

---

## 7. **Extension Points**

### **Adding New Calendar Systems**

To add support for Google Calendar, Exchange, or any other system:

1. **Implement AbstractCalendarBridge**:
```php
class GoogleCalendarBridge extends AbstractCalendarBridge
{
    public function listEvents($calendarId, $startDate, $endDate): array { }
    public function createEvent($calendarId, $event): string { }
    public function updateEvent($calendarId, $eventId, $event): bool { }
    public function deleteEvent($calendarId, $eventId): bool { }
    // ... implement other required methods
}
```

2. **Register in BridgeManager**:
```php
$bridgeManager->registerBridge('google_calendar', new GoogleCalendarBridge($config));
```

3. **Configure endpoints**: The same API endpoints work with any bridge type

### **Multi-Tenant Support**

The system supports multiple tenants/organizations:

```
POST   /tenants/{tenant}/bridges/sync/{source}/{target}
GET    /tenants/{tenant}/bridges/health
POST   /tenants/{tenant}/bridges/sync-deletions
```

---

## 8. **Production Features**

### **Reliability**
- ✅ Transaction safety with rollback support
- ✅ Loop prevention mechanisms
- ✅ Graceful error handling and recovery
- ✅ Comprehensive audit logging

### **Monitoring**
- ✅ Real-time health checks
- ✅ Sync operation statistics
- ✅ Error reporting and alerting
- ✅ Performance metrics

### **Security**
- ✅ API key authentication
- ✅ Secure credential storage
- ✅ Request validation
- ✅ Rate limiting support

---

## 9. **Deployment**

### **Docker Setup**
```bash
# Clone repository
git clone <repository-url>
cd OutlookBookingSync

# Configure environment
cp .env.example .env
# Edit .env with your credentials

# Setup database
./setup_bridge_database.sh

# Start bridge service
docker compose up -d
```

### **Health Verification**
```bash
# Check bridge health
curl http://localhost:8082/bridges/health

# List available bridges
curl http://localhost:8082/bridges

# Test sync operation
curl -X POST http://localhost:8082/bridges/sync/booking_system/outlook \
  -H "Content-Type: application/json" \
  -d '{"source_calendar_id": "123", "target_calendar_id": "room1@company.com"}'
```

---

## 10. **Migration from Legacy Systems**

### **Legacy vs Bridge Architecture**

| **Legacy Approach** | **Bridge Architecture** |
|-------------------|------------------------|
| Direct database coupling | REST API communication |
| System-specific code | Generic bridge pattern |
| Limited extensibility | Universal integration |
| Manual configuration | Automated setup |

### **Migration Benefits**

- 🚀 **Extensibility**: Add new calendar systems without code changes
- 🔧 **Maintainability**: Standardized interface for all integrations
- 🏢 **Scalability**: Independent scaling of calendar systems
- 🛡️ **Reliability**: Better error handling and recovery mechanisms

---

This architecture provides a solid foundation for universal calendar synchronization while maintaining simplicity and extensibility.
