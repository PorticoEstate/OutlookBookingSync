# Calendar Bridge Usage Guide

This guide explains how to use the **production-ready calendar bridge system** to connect any calendar system with any other calendar system through standardized APIs.

## Overview

The bridge system provides seamless integration between calendar systems with five main phases:

1. **Setup Phase**: Configure bridge connections and resource mappings
2. **Bridge Sync Phase**: Transfer events bidirectionally between any connected systems
3. **Real-time Processing**: Handle webhook notifications for instant synchronization
4. **Deletion Handling**: Manage cancellations and deletions across systems
5. **Health Monitoring**: Track bridge status and handle errors

### ✅ Production-Ready Bridge Features

This bridge system is **production-ready** with the following verified capabilities:
- ✅ **Universal Bridge Pattern** - Connect any calendar system to any other
- ✅ **Bidirectional Sync** - Events flow seamlessly in both directions
- ✅ **Real-time Webhooks** - Instant synchronization when available
- ✅ **Polling Fallback** - Automatic fallback when webhooks unavailable
- ✅ **Deletion Detection** - Robust cancellation handling across systems
- ✅ **Resource Mapping** - Complete calendar resource management
- ✅ **System Agnostic** - Works with any calendar or booking system
- ✅ **API-Based Communication** - Pure REST interface, no direct database coupling
- ✅ **Health Monitoring** - Real-time status tracking and alerting
- ✅ **Error Recovery** - Comprehensive error handling and retry mechanisms
- ✅ **Production Tested** - Verified with multiple calendar systems

## Prerequisites

1. Bridge system is running and accessible
2. Target calendar systems are configured (Outlook, Google Calendar, etc.)
3. Your booking/calendar system exposes required REST API endpoints
4. Resource mappings are configured between systems
5. API authentication is properly set up

## Bridge Architecture Overview

The bridge acts as a **translation layer** between calendar systems:

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Your System   │◄──►│ Calendar Bridge │◄──►│ Target Calendar │
│                 │    │                 │    │  (Outlook, etc) │
│ - Your API      │    │ - Translation   │    │ - External API  │
│ - Your Schema   │    │ - Mapping       │    │ - Their Schema  │
│ - Your Logic    │    │ - Sync Status   │    │ - Their Logic   │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

**Key Principle**: The bridge handles **communication** and **mapping**, while each system maintains full autonomy over its internal implementation.

## Webhook Setup (Real-time Sync)

The system supports both **webhook-based real-time sync** and **polling-based sync**. Webhooks provide immediate synchronization when Outlook events change, while polling is a fallback mechanism.

### Steps to Get Webhooks Working

#### 1. Update Environment Variables

First, update your `.env` file with your actual server URL:

```bash
# Change from placeholder to your actual server URL
WEBHOOK_BASE_URL=https://your-domain.com
```

#### 2. Prerequisites for Webhooks

**A. Public Internet Access**
- Your server must be accessible from the internet for Microsoft Graph to send webhook notifications
- The webhook endpoint needs to be reachable at: `https://your-domain.com/webhook/outlook-notifications`

**B. SSL Certificate Required**
- Microsoft Graph **requires HTTPS** for webhook endpoints
- You need a valid SSL certificate for your domain
- Self-signed certificates will not work

**C. Microsoft Graph App Permissions**
Your app registration needs these permissions (likely already configured):
- `Calendars.ReadWrite.All`
- `Calendars.Read.Shared` 
- `Calendars.ReadWrite.Shared`

#### 3. Create Webhook Subscriptions

Once your server is publicly accessible with HTTPS, create webhook subscriptions:

```bash
# Create webhook subscription for a specific calendar
curl -X POST "https://your-domain.com/webhook/create" \
  -H "Content-Type: application/json" \
  -d '{
    "calendar_id": "room1@company.com"
  }'

# List active subscriptions
curl "https://your-domain.com/webhook/subscriptions"

# Test webhook endpoint (should return validation
curl "https://your-domain.com/webhook/outlook-notifications"
```

#### 4. Webhook Endpoints

The system provides several webhook management endpoints:

- `POST /webhook/create` - Create new webhook subscription
- `GET /webhook/subscriptions` - List active subscriptions  
- `POST /webhook/outlook-notifications` - Receive webhook notifications (Microsoft Graph calls this)
- `DELETE /webhook/delete/{subscriptionId}` - Delete subscription
- `POST /webhook/renew/{subscriptionId}` - Renew expiring subscription

#### 5. Webhook Validation

Microsoft Graph requires webhook endpoint validation. The system automatically handles:
- **Validation Token Response** - Returns validation token during subscription creation
- **Notification Processing** - Processes incoming change notifications
- **Subscription Renewal** - Automatically renews subscriptions before expiration

#### 6. Production Deployment Considerations

**For Production Webhook Setup:**

1. **Domain and SSL**
   ```bash
   # Example with Let's Encrypt
   certbot --nginx -d your-domain.com
   ```

2. **Firewall Configuration**
   ```bash
   # Allow HTTPS traffic
   ufw allow 443
   ```

3. **Reverse Proxy (Nginx)**
   ```nginx
   server {
       listen 443 ssl;
       server_name your-domain.com;
       
       ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
       ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;
       
       location / {
           proxy_pass http://localhost:8082;
           proxy_set_header Host $host;
           proxy_set_header X-Real-IP $remote_addr;
       }
   }
   ```

4. **Docker Port Mapping**
   ```yaml
   # docker-compose.yml
   services:
     portico_outlook:
       ports:
         - "8082:80"  # Internal container port
   ```

#### 7. Fallback to Polling

If webhooks cannot be configured, the system automatically falls back to polling mode:
- Polling runs every 15 minutes via cron
- Detects changes by comparing event modification dates
- Provides reliable sync without real-time capabilities

**Verify Polling Status:**
```bash
curl "http://localhost:8082/polling/stats"
```

### Webhook vs Polling Comparison

| Feature | Webhooks | Polling |
|---------|----------|---------|
| **Real-time** | ✅ Immediate | ❌ 15-minute delay |
| **Setup Complexity** | ❌ High (SSL, public IP) | ✅ Low |
| **Reliability** | ❌ Depends on network | ✅ High |
| **Resource Usage** | ✅ Low | ❌ Higher API calls |
| **Production Ready** | ✅ Yes (if configured) | ✅ Yes |

**Recommendation**: Use webhooks for real-time requirements, polling for simpler deployments or as a reliable fallback.

## Bridge API Endpoints

### 1. Bridge Discovery and Health

#### List Available Bridges

```bash
# Get all configured bridges and their capabilities
curl -X GET "http://localhost:8082/bridges"
```

#### Check Bridge Health

```bash
# Check health status of all bridges
curl -X GET "http://localhost:8082/bridges/health"
```

#### Get Bridge Calendars

```bash
# Get available calendars for a specific bridge
curl -X GET "http://localhost:8082/bridges/outlook/calendars"
curl -X GET "http://localhost:8082/bridges/booking_system/calendars"
```

### 2. Resource Mapping Management

#### Create Resource Mapping

```bash
# Map a resource between systems
curl -X POST "http://localhost:8082/mappings/resources" \
  -H "Content-Type: application/json" \
  -d '{
    "booking_system_resource_id": "room_123",
    "calendar_system": "outlook",
    "calendar_resource_id": "conference-room-a@company.com",
    "calendar_resource_name": "Conference Room A",
    "active": true
  }'
```

#### Get Resource Mappings

```bash
# Get all resource mappings
curl -X GET "http://localhost:8082/mappings/resources"

# Get mapping by booking system resource ID
curl -X GET "http://localhost:8082/mappings/resources/by-resource/room_123"
```

### 3. Bridge Sync Operations

#### Bidirectional Bridge Sync

```bash
# Sync from your system to target calendar (e.g., Outlook)
curl -X POST "http://localhost:8082/bridges/sync/booking_system/outlook"

# Sync from target calendar to your system  
curl -X POST "http://localhost:8082/bridges/sync/outlook/booking_system"

# Sync between any two configured bridges
curl -X POST "http://localhost:8082/bridges/sync/{source_bridge}/{target_bridge}"
```

#### Process Pending Bridge Operations

```bash
# Process all pending sync operations
curl -X POST "http://localhost:8082/bridge/process-pending"
```

#### Handle Deletions and Cancellations

```bash
# Detect and sync deletions between systems
curl -X POST "http://localhost:8082/bridges/sync-deletions"

# Process webhook deletion queue
curl -X POST "http://localhost:8082/bridges/process-deletion-queue"
```

### 4. Webhook Processing

#### Handle Bridge Webhooks

```bash
# Webhook endpoint for any bridge system
POST /bridges/webhook/{bridge_name}

# Example: Outlook webhook
POST /bridges/webhook/outlook

# Example: Google Calendar webhook  
POST /bridges/webhook/google_calendar
```

```bash
# Sync events from Outlook to your booking system
curl -X POST "http://localhost:8082/bridges/sync/outlook/booking_system"

# Sync events from a specific date range (if supported by your booking system API)
curl -X POST "http://localhost:8082/bridges/sync/outlook/booking_system" \
  -H "Content-Type: application/json" \
  -d '{"from_date": "2025-06-01", "to_date": "2025-07-01"}'
```

### 4. Bridge Integration Endpoints

#### Process Pending Bridge Operations

Process any pending sync operations between connected systems.

```bash
# Process pending sync operations
curl -X POST "http://localhost:8082/bridge/process-pending"
```

**What this endpoint does:**
- Processes queued sync operations between any connected bridges
- Calls your booking system's REST API with standardized event data
- Tracks sync status in `bridge_mappings` table
- Handles retry logic for failed operations
- Returns summary of processed operations

**Expected Response:**
```json
{
  "success": true,
  "message": "Bridge sync operations completed",
  "results": {
    "processed": 11,
    "successful": 10,
    "errors": 1,
    "success_rate": "91%",
    "operations": [
      {
        "bridge_mapping_id": 1234,
        "source_system": "outlook",
        "target_system": "booking_system", 
        "external_id": "AAMkAGUxZWM3YWY2...",
        "internal_id": "78268",
        "operation": "create",
        "status": "completed"
      }
    ]
  }
}
```

#### Get Bridge Statistics

```bash
# Get statistics about bridge operations
curl -X GET "http://localhost:8082/bridge/stats"
```

Shows overall statistics about bridge sync operations between all connected systems.

### 5. Bridge Status and Monitoring

#### Get Bridge Statistics

```bash
# Get comprehensive bridge operation statistics
curl -X GET "http://localhost:8082/bridge/stats"
```

#### Get Bridge Processing Queue

```bash
# View pending operations in the bridge queue
curl -X GET "http://localhost:8082/bridge/pending"

# View completed operations
curl -X GET "http://localhost:8082/bridge/completed"
```

### 6. Deletion and Cancellation Handling

The bridge system provides robust deletion detection and synchronization across connected systems.

#### Detect and Process Deletions

```bash
# Detect and sync deletions between all connected systems
curl -X POST "http://localhost:8082/bridges/sync-deletions"
```

**What this endpoint does:**
- Detects when events no longer exist in source systems
- Communicates deletions to target systems via their APIs
- Updates bridge mapping status to track deletions
- Handles deletion conflicts and errors gracefully
- Works with any connected bridge systems

#### Process Webhook Deletion Queue

```bash
# Process deletions received via webhooks
curl -X POST "http://localhost:8082/bridges/process-deletion-queue"
```

**Expected Response:**
```json
{
  "success": true,
  "message": "Bridge deletion processing completed",
  "results": {
    "detected_deletions": 4,
    "processed_deletions": 4,
    "success_rate": "100%",
    "systems_affected": ["outlook", "booking_system"],
    "bridge_operations": [
      {
        "bridge_mapping_id": 1234,
        "source_system": "outlook",
        "target_system": "booking_system", 
        "external_id": "AAMkAGUxZWM3YWY2...",
        "internal_id": "78265",
        "operation": "delete",
        "status": "completed"
      }
    ],
    "summary": {
      "outlook_deletions": 2,
      "booking_system_deletions": 2,
      "errors": 0
    }
  }
}
```

### 7. Your System Integration Requirements

For the bridge to work with your system, you need to provide these API endpoints:

#### Required API Endpoints (Your System)

```bash
# Create new event in your system
POST /api/events
Content-Type: application/json
{
  "external_id": "outlook-event-123",
  "title": "Meeting Title",
  "start_time": "2024-12-15T10:00:00Z",
  "end_time": "2024-12-15T11:00:00Z",
  "location": "Conference Room A",
  "description": "Meeting description",
  "organizer": {
    "name": "John Doe",
    "email": "john@company.com"
  }
}

# Update existing event
PUT /api/events/{your_event_id}

# Delete/cancel event  
DELETE /api/events/{your_event_id}

# List events for sync (optional, for bridge-initiated sync)
GET /api/events?since=2024-12-15T00:00:00Z
```

**Your Implementation Freedom:**
- Structure your internal data however you want
- Use any database schema
- Apply any business rules
- Handle validation your way
- Implement your cancellation logic
curl -X GET "http://localhost:8082/bridges/sync-deletionsion-stats"
```

Shows statistics about reservations that may be cancelled based on their active status.

#### View Cancelled Reservations

### 8. Bridge Monitoring and Statistics

#### Get Bridge System Statistics

```bash
# Get comprehensive bridge operation statistics
curl -X GET "http://localhost:8082/bridge/stats"
```

#### Get Bridge Health Status

```bash
# Get health status of all bridge connections
curl -X GET "http://localhost:8082/bridges/health"
```

#### Clean Up Orphaned Mappings

```bash
# Clean up bridge mappings that no longer have valid references
curl -X DELETE "http://localhost:8082/bridge/cleanup-orphaned"
```

```bash
# Remove mappings for deleted calendar items
curl -X DELETE "http://localhost:8082/sync/cleanup-orphaned"
```

### 6. Polling-Based Change Detection

When webhook endpoints aren't publicly accessible, the system provides robust polling-based change detection as an alternative to real-time webhooks.

#### Initialize Polling State

```bash
# Initialize polling for all room calendars
curl -X POST "http://localhost:8082/polling/initialize"
```

**Response includes:**
### 9. Automated Operations

#### Polling for Changes (Fallback Mode)

When webhooks are not available, the bridge can use polling:

```bash
# Poll all configured bridges for changes
curl -X POST "http://localhost:8082/bridges/poll-changes"
```

#### Get Automated Operation Statistics

```bash
# Monitor automated operation health and status
curl -X GET "http://localhost:8082/bridge/automation-stats"
```

**Recommended Automation:**
- Set up cron jobs for regular bridge sync operations
- Use webhooks for real-time updates when available
- Implement polling as a fallback mechanism
- Monitor bridge health regularly

## Your System Integration Contract

### Required API Endpoints (Your Implementation)

The calendar bridge follows the **separation of concerns** principle:

#### 🌉 Bridge Responsibilities (What the Bridge Does)
- **Event Transport**: Move event data between systems via standardized APIs
- **Format Translation**: Convert between different calendar formats (iCal, JSON, etc.)
- **Status Tracking**: Track sync status in `bridge_mappings` table
- **Resource Mapping**: Map resources between systems via `bridge_resource_mappings`
- **Error Handling**: Log sync failures and provide retry mechanisms

#### 🏢 Booking System Responsibilities (What Your System Does)
- **Internal Data Structure**: How you organize events, dates, resources, etc.
- **Business Logic**: How you handle event creation, validation, conflicts
- **Database Schema**: Your table structure, relationships, constraints
- **Event Processing**: How you process incoming events (create, update, cancel)
- **Cancellation Handling**: How you mark events as cancelled in your system

### Bridge API Contract

The bridge communicates with your booking system through **standardized REST APIs**:

#### Incoming Events (Bridge → Your System)
```bash
POST /api/events
{
    "external_id": "outlook-event-123",
    "title": "Team Meeting",
    "description": "Weekly team sync",
    "start_time": "2024-12-15T10:00:00Z",
    "end_time": "2024-12-15T11:00:00Z",
    "organizer": {
        "name": "John Doe",
        "email": "john@company.com"
    },
    "location": "Conference Room A",
    "source_system": "outlook"
}
```

**Your System's Response**: Your booking system handles this however it wants:
- Create events across multiple tables
- Apply your business rules
- Return your internal event ID

#### Event Updates (Bridge → Your System)
```bash
PUT /api/events/{your_internal_id}
```

#### Event Cancellations (Bridge → Your System)
```bash
DELETE /api/events/{your_internal_id}
```

**Your Implementation**: You decide how to handle cancellations:
- Set `active = 0`
- Move to archive table
- Add cancellation notes
- Trigger notifications

### Implementation Example

This is **your booking system's responsibility**, not the bridge's:

```php
// YOUR booking system API endpoint
class BookingSystemEventController {
    public function createEvent(Request $request) {
        // YOUR business logic
        $this->db->beginTransaction();
        try {
            // YOUR data structure
            $eventId = $this->createEventRecord($request->data);
            $this->createEventDates($eventId, $request->times);
            $this->assignResources($eventId, $request->location);
            $this->applyBusinessRules($eventId);
            
            $this->db->commit();
            return response()->json(['id' => $eventId]);
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
```

### Bridge Usage Steps

#### Step 1: Set Up Resource Mappings

Map your booking system resources to calendar resources:

```bash
curl -X POST "http://localhost:8082/mappings/resources" \
  -H "Content-Type: application/json" \
  -d '{
    "booking_system_resource_id": "123",
    "calendar_system": "outlook",
    "calendar_resource_id": "room-calendar-id",
    "calendar_resource_name": "Conference Room A",
    "active": true
  }'
```

#### Step 2: Configure Your Booking System API

Ensure your booking system exposes the required endpoints:

```bash
# Your booking system should provide:
POST   /api/events          # Create new events
PUT    /api/events/{id}     # Update existing events  
DELETE /api/events/{id}     # Cancel/delete events
GET    /api/events          # List events for sync
```

#### Step 3: Sync Between Systems

**Sync from your booking system to Outlook:**
```bash
curl -X POST "http://localhost:8082/bridges/sync/booking_system/outlook"
```

**Sync from Outlook to your booking system:**
```bash
curl -X POST "http://localhost:8082/bridges/sync/outlook/booking_system"
```

**Process any pending sync operations:**
```bash
curl -X POST "http://localhost:8082/bridge/process-pending"
```

#### Step 4: Monitor Bridge Health

```bash
curl -X GET "http://localhost:8082/bridges/health"
```

Expected response:
```json
{
  "success": true,
  "bridges": {
    "outlook": {
      "status": "healthy",
      "last_sync": "2024-12-15T10:30:00Z",
      "calendars_available": 5
    },
    "booking_system": {
      "status": "healthy", 
      "api_accessible": true,
      "last_response_time": "0.2s"
    }
  },
  "mappings": {
    "total": 150,
    "active": 145,
    "pending": 3,
    "errors": 2
  }
}
    "summary": {
      "pending": 5,
      "synced": 140,
      "error": 3,
      "conflict": 2
    },
    "by_type": {
      "event": {
        "synced": 85,
        "pending": 2,
        "error": 1
      },
      "booking": {
        "synced": 35,
        "pending": 2
      },
      "allocation": {
        "synced": 20,
        "pending": 1,
        "error": 2
      }
    }
  }
}
```

## Complete Production Workflow

### Full Bidirectional Sync Process

For complete production synchronization, follow this comprehensive workflow:

#### 1. **Initial Setup** (One-time)

```bash
# Populate from booking system to create mappings
curl -X POST "http://localhost:8082/sync/populate-mapping"

# Import existing Outlook events
curl -X POST "http://localhost:8082/sync/from-outlook"
```

#### 2. **Sync Booking System → Outlook**

```bash
# Check what's pending from booking system
curl -X GET "http://localhost:8082/sync/pending-items"

# Sync to Outlook
curl -X POST "http://localhost:8082/sync/to-outlook"
```

#### 3. **Import Outlook Events → Booking System**

```bash
# Check for new Outlook events not in booking system
curl -X GET "http://localhost:8082/sync/outlook-events"

# Add them to mapping table for processing
curl -X POST "http://localhost:8082/sync/from-outlook"

# Check pending imports ready for conversion
curl -X GET "http://localhost:8082/booking/pending-imports"

# Convert Outlook events to complete booking system entries
curl -X POST "http://localhost:8082/booking/process-imports"

# Verify processed imports with reservation IDs
curl -X GET "http://localhost:8082/booking/processed-imports"
```

#### 4. **Handle Cancellations and Re-enables (Both Directions)**

```bash
# Automatically detect cancelled and re-enabled reservations in booking system
curl -X POST "http://localhost:8082/bridges/sync-deletions"

# View cancellation and re-enable statistics
curl -X GET "http://localhost:8082/cancel/stats"

# View all cancelled reservations
curl -X GET "http://localhost:8082/cancel/cancelled-reservations"
```

**Re-enable Workflow:**
When you re-enable a cancelled reservation in your booking system (`UPDATE your_event_table SET active = 1 WHERE id = X`):

1. **Detection**: `/bridges/sync-deletions` automatically finds reservations with `active = 1` but `sync_status = 'cancelled'`
2. **Reset**: Mapping status changes from 'cancelled' to 'pending', old Outlook event ID is cleared
3. **Sync**: Normal sync process (`/sync/to-outlook`) creates a fresh Outlook event
4. **Result**: Re-enabled reservation gets a completely new Outlook calendar event

#### 5. **Monitor and Maintain**

```bash
# Get comprehensive sync statistics
curl -X GET "http://localhost:8082/sync/stats"

# Check processing statistics
curl -X GET "http://localhost:8082/booking/processing-stats"

# Cleanup orphaned mappings
curl -X DELETE "http://localhost:8082/sync/cleanup-orphaned"
```

### Production Results Verification

After running the complete workflow, you should see:

**Booking System Integration Results:**
```json
{
  "success": true,
  "message": "Import processing completed",
  "results": {
    "processed": 11,
    "successful": 11,
    "errors": 0,
    "success_rate": "100%",
    "reservation_ids": [78268, 78269, 78270, 78271, 78272, 78273, 78274, 78275, 78276, 78277, 78278]
  }
}
```

**Cancellation and Re-enable Processing Results:**
```json
{
  "success": true,
  "message": "Cancellation detection and processing completed",
  "results": {
    "detected": 4,
    "processed": 4,
    "success_rate": "100%",
    "cancelled_events": [
      {
        "id": 78265,
        "active": 0,
        "resource_id": 431,
        "mapping_id": 8
      }
    ],
    "reenabled_events": [
      {
        "id": 78266,
        "active": 1,
        "resource_id": 431,
        "mapping_id": 9
      },
      {
        "id": 78267,
        "active": 1,
        "resource_id": 431,
        "mapping_id": 10
      }
    ],
    "outlook_deletions": 1,
    "pending_resets": 2,
    "errors": 0
  }
}
```

**Re-enable Sync Results:**
```json
{
  "success": true,
  "message": "Sync completed",
  "results": {
    "processed": 2,
    "created": 2,
    "updated": 0,
    "errors": 0,
    "details": [
      {
        "item_type": "event",
        "item_id": 78266,
        "resource_id": 431,
        "action": "created",
        "outlook_event_id": "AAMkAGUxZWM3YWY2...AFJA42AAA=",
        "title": "Test på outlook integrasjon"
      },
      {
        "item_type": "event",
        "item_id": 78267,
        "resource_id": 431,
        "action": "created",
        "outlook_event_id": "AAMkAGUxZWM3YWY2...AFJA43AAA=",
        "title": "Test på outlook integrasjon"
      }
    ]
  }
}
```

## Error Handling and Troubleshooting

### Common Errors and Solutions

#### 1. **Sync-Related Errors**

**"Calendar item not found for mapping"**
- The calendar item was deleted from the booking system
- **Solution**: Run cleanup to remove orphaned mappings
  ```bash
  curl -X DELETE "http://localhost:8082/sync/cleanup-orphaned"
  ```

**"No Outlook event ID to delete"**
- Trying to delete an event that wasn't created in Outlook yet
- **Solution**: Check the mapping status first

#### 2. **Database Integration Errors**

**"Transaction failed during event creation"**
- Database constraint violation or connection issue
- **Solution**: Check database logs, verify table structures, retry operation

**"HTML to text conversion failed"**
- Invalid HTML content in Outlook event description
- **Solution**: System handles this gracefully with fallback to original content

#### 3. **Cancellation Processing Errors**

**"Reservation not found for cancellation"**
- Trying to cancel a reservation that doesn't exist
- **Solution**: Verify reservation ID and check if already cancelled

**"Outlook event deletion failed"**
- Graph API permissions or connectivity issue
- **Solution**: Check Graph API credentials and permissions

#### 4. **Authentication Errors**

**Graph API Authentication Errors**
- Check your environment variables (client ID, secret, tenant ID)
- Verify Graph API permissions include:
  - `Calendars.ReadWrite`
  - `Calendars.ReadWrite.Shared`

### Retry Mechanisms

The system includes automatic retry handling:

**Failed Sync Items**
```bash
# Failed items remain in "error" status and can be retried
curl -X GET "http://localhost:8082/sync/pending-items"
curl -X POST "http://localhost:8082/sync/to-outlook"
```

**Failed Import Processing**
```bash
# Retry failed import processing
curl -X POST "http://localhost:8082/booking/process-imports"
```

**Failed Cancellation Detection**
```bash
# Retry cancellation detection and processing
curl -X POST "http://localhost:8082/bridges/sync-deletions"
```

### Debug and Monitoring

#### Real-time Statistics

Monitor system health with comprehensive statistics:

```bash
# Overall sync statistics
curl -X GET "http://localhost:8082/sync/stats"

# Booking system integration statistics
curl -X GET "http://localhost:8082/booking/processing-stats"

# Cancellation processing statistics
curl -X GET "http://localhost:8082/cancel/stats"
```

#### Database Verification

Check database mapping status directly:

```sql
-- Overall mapping status
SELECT reservation_type, sync_status, COUNT(*) 
FROM bridge_mappings 
GROUP BY reservation_type, sync_status;

-- Recent processing results
SELECT * FROM bridge_mappings 
WHERE reservation_id IS NOT NULL 
ORDER BY created_at DESC LIMIT 10;

-- Cancellation tracking
SELECT * FROM bridge_mappings 
WHERE sync_status = 'cancelled' 
ORDER BY updated_at DESC;
```

#### System Health Checks

```bash
# Check for orphaned mappings
curl -X GET "http://localhost:8082/sync/pending-items" | jq '.count'

# Verify processing pipeline
curl -X GET "http://localhost:8082/booking/pending-imports" | jq '.count'

# Monitor cancellation detection
curl -X GET "http://localhost:8082/bridges/sync-deletionsion-stats"
```

#### Reset and Recovery

If you need to reset the entire synchronization state:

```sql
-- Reset all mappings to pending (use with caution)
UPDATE bridge_mappings 
SET sync_status = 'pending', 
    outlook_event_id = NULL,
    reservation_id = NULL
WHERE sync_status != 'cancelled';
```

```bash
# Re-sync everything after reset
curl -X POST "http://localhost:8082/sync/to-outlook"
curl -X POST "http://localhost:8082/booking/process-imports"
```

#### Partial Recovery

For specific issues:

```bash
# Re-process specific import failures
curl -X GET "http://localhost:8082/booking/pending-imports"
curl -X POST "http://localhost:8082/booking/process-imports"

# Re-detect missed cancellations
curl -X POST "http://localhost:8082/bridges/sync-deletions"

# Clean up orphaned entries
curl -X DELETE "http://localhost:8082/sync/cleanup-orphaned"
```

## Production Deployment and Automation

### Automated Scheduling

For production environments, set up automated synchronization with cron jobs:

#### Complete Sync Automation

```bash
# /etc/cron.d/outlook-sync
SHELL=/bin/bash
PATH=/usr/local/bin:/usr/bin:/bin

# Full bidirectional sync every 15 minutes
*/15 * * * * www-data curl -X POST "http://localhost:8082/sync/to-outlook?limit=100" > /dev/null 2>&1

# Import new Outlook events hourly
0 * * * * www-data curl -X POST "http://localhost:8082/sync/from-outlook" > /dev/null 2>&1

# Process imported events every 30 minutes
*/30 * * * * www-data curl -X POST "http://localhost:8082/booking/process-imports" > /dev/null 2>&1

# Detect and process cancellations and re-enables every 10 minutes
*/10 * * * * www-data curl -X POST "http://localhost:8082/bridges/sync-deletions" > /dev/null 2>&1

# Cleanup orphaned mappings daily at 2 AM
0 2 * * * www-data curl -X DELETE "http://localhost:8082/sync/cleanup-orphaned" > /dev/null 2>&1
```

#### Monitoring and Alerts

```bash
# /etc/cron.d/outlook-sync-monitoring
# Health check every 5 minutes with logging
*/5 * * * * www-data /opt/OutlookBookingSync/scripts/health-check.sh

# Daily summary report
0 8 * * * www-data /opt/OutlookBookingSync/scripts/daily-report.sh
```

### Health Check Script

Create a health check script for monitoring:

```bash
#!/bin/bash
# /opt/OutlookBookingSync/scripts/health-check.sh

LOG_FILE="/var/log/outlook-sync/health-check.log"
ERROR_THRESHOLD=5
PENDING_THRESHOLD=50

# Check sync statistics
STATS=$(curl -s "http://localhost:8082/sync/stats")
PENDING=$(echo "$STATS" | jq -r '.statistics.summary.pending // 0')
ERRORS=$(echo "$STATS" | jq -r '.statistics.summary.error // 0')

# Log current status
echo "$(date): Pending: $PENDING, Errors: $ERRORS" >> "$LOG_FILE"

# Alert if thresholds exceeded
if [ "$ERRORS" -gt "$ERROR_THRESHOLD" ]; then
    echo "$(date): HIGH ERROR COUNT: $ERRORS errors detected" >> "$LOG_FILE"
    # Add your alerting mechanism here (email, Slack, etc.)
fi

if [ "$PENDING" -gt "$PENDING_THRESHOLD" ]; then
    echo "$(date): HIGH PENDING COUNT: $PENDING items pending" >> "$LOG_FILE"
    # Add your alerting mechanism here
fi
```

### Integration with Booking System

#### Real-time Sync Integration

Integrate sync calls directly into your booking system for real-time updates:

```php
<?php
// In your booking system after creating/updating a reservation

class BookingSystemIntegration {
    private $syncBaseUrl = 'http://localhost:8082';
    
    public function afterBookingCreated($reservationType, $reservationId, $resourceId) {
        $this->triggerSync($reservationType, $reservationId, $resourceId);
    }
    
    public function afterBookingUpdated($reservationType, $reservationId, $resourceId) {
        $this->triggerSync($reservationType, $reservationId, $resourceId);
    }
    
    public function afterBookingCancelled($reservationType, $reservationId, $resourceId) {
        $url = "{$this->syncBaseUrl}/cancel/booking/{$reservationType}/{$reservationId}/{$resourceId}";
        $this->makeRequest($url, 'POST');
    }
    
    private function triggerSync($reservationType, $reservationId, $resourceId) {
        $url = "{$this->syncBaseUrl}/sync/item/{$reservationType}/{$reservationId}/{$resourceId}";
        $this->makeRequest($url, 'POST');
    }
    
    private function makeRequest($url, $method = 'GET') {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => 30,
                'header' => 'Content-Type: application/json'
            ]
        ]);
        
        return file_get_contents($url, false, $context);
    }
}
```

#### Event Hooks

```php
// Hook into your booking system events
$integration = new BookingSystemIntegration();

// After creating a booking
register_booking_created_hook(function($booking) use ($integration) {
    $integration->afterBookingCreated('booking', $booking->id, $booking->resource_id);
});

// After updating a booking
register_booking_updated_hook(function($booking) use ($integration) {
    $integration->afterBookingUpdated('booking', $booking->id, $booking->resource_id);
});

// After cancelling a booking
register_booking_cancelled_hook(function($booking) use ($integration) {
    $integration->afterBookingCancelled('booking', $booking->id, $booking->resource_id);
});
```

### Performance Optimization

#### Batch Processing

For high-volume environments, implement batch processing:

```bash
# Process large batches during off-hours
# /etc/cron.d/bridge-sync-batch
0 1 * * * www-data curl -X POST "http://localhost:8082/bridges/sync/booking_system/outlook" > /dev/null 2>&1
0 2 * * * www-data curl -X POST "http://localhost:8082/bridge/process-pending" > /dev/null 2>&1
```

#### Load Balancing

For multiple servers, distribute the bridge load:

```bash
# Server 1: Handle booking system to Outlook sync
*/15 * * * * www-data curl -X POST "http://localhost:8082/bridges/sync/booking_system/outlook" > /dev/null 2>&1

# Server 2: Handle Outlook to booking system sync  
*/15 * * * * www-data curl -X POST "http://localhost:8082/bridges/sync/outlook/booking_system" > /dev/null 2>&1
*/30 * * * * www-data curl -X POST "http://localhost:8082/bridge/process-pending" > /dev/null 2>&1

# Server 3: Handle deletion processing
*/10 * * * * www-data curl -X POST "http://localhost:8082/bridges/sync-deletions" > /dev/null 2>&1
*/10 * * * * www-data curl -X POST "http://localhost:8082/bridges/process-deletion-queue" > /dev/null 2>&1
```

### Backup and Recovery

#### Database Backup

```bash
#!/bin/bash
# /opt/OutlookBookingSync/scripts/backup.sh

BACKUP_DIR="/var/backups/calendar-bridge"
DATE=$(date +"%Y%m%d_%H%M%S")
DB_NAME="${DB_NAME:-calendar_bridge}"
DB_USER="${DB_USER:-bridge_user}"
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-5432}"

# Full database backup (recommended for PostgreSQL)
pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
    --no-password --clean --if-exists --create \
    > "$BACKUP_DIR/calendar_bridge_full_$DATE.sql"

# Optional: Schema-only backup for quick recovery
pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
    --no-password --schema-only \
    > "$BACKUP_DIR/calendar_bridge_schema_$DATE.sql"

# Compress and clean old backups
gzip "$BACKUP_DIR/calendar_bridge_full_$DATE.sql"
gzip "$BACKUP_DIR/calendar_bridge_schema_$DATE.sql"
find "$BACKUP_DIR" -name "*.gz" -mtime +30 -delete

# Log backup completion
echo "$(date): Database backup completed - calendar_bridge_full_$DATE.sql.gz" >> "$BACKUP_DIR/backup.log"
```

#### Database Recovery

```bash
#!/bin/bash
# /opt/OutlookBookingSync/scripts/restore.sh

BACKUP_DIR="/var/backups/calendar-bridge"
DB_NAME="${DB_NAME:-calendar_bridge}"
DB_USER="${DB_USER:-bridge_user}"
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-5432}"

# Find the latest backup
LATEST_BACKUP=$(ls -t "$BACKUP_DIR"/calendar_bridge_full_*.sql.gz | head -1)

if [ -z "$LATEST_BACKUP" ]; then
    echo "No backup files found in $BACKUP_DIR"
    exit 1
fi

echo "Restoring from: $LATEST_BACKUP"

# Decompress and restore
gunzip -c "$LATEST_BACKUP" | psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d postgres

echo "Database restored successfully from $LATEST_BACKUP"
```

#### Automated Backup Schedule

Add to crontab for automated daily backups:

```bash
# Daily full backup at 2 AM
0 2 * * * /opt/OutlookBookingSync/scripts/backup.sh > /dev/null 2>&1

# Weekly verification that backups are working
0 3 * * 0 ls -la /var/backups/calendar-bridge/*.gz | tail -7
```

This comprehensive guide now covers all aspects of the production-ready bridge-based calendar synchronization system, from basic usage to advanced automation and monitoring.

## Summary: Bridge-Based Integration

The Calendar Bridge system provides a clean, extensible architecture for connecting any calendar system to any other.

### System Independence
- No Database Coupling: Bridge doesn't access your database directly
- API-Based Communication: Pure REST interface for all interactions  
- Implementation Freedom: Structure your data however you want
- Business Logic Autonomy: Apply your own validation and rules

### Production Ready
- Error Handling: Comprehensive error recovery and retry mechanisms
- Health Monitoring: Real-time status tracking and alerting
- Automated Operations: Cron-based sync with webhook fallback
- Deletion Handling: Robust cancellation detection and processing

### Extensible Architecture
- Bridge Pattern: Easy to add new calendar systems
- Generic Design: Works with any calendar or booking system
- Resource Mapping: Flexible resource management between systems
- Bidirectional Sync: Events flow seamlessly in both directions

## Quick Start Checklist

1. Configure Bridge: Set up environment variables and credentials
2. Implement Your API: Provide required REST endpoints
3. Set Up Resource Mappings: Map resources between systems
4. Test Sync Operations: Verify bidirectional event flow
5. Enable Automation: Set up cron jobs for regular sync
6. Monitor Health: Use bridge health endpoints for monitoring

The calendar bridge system is production-ready and designed to scale with your integration needs.
