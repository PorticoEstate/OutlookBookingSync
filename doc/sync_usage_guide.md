# Calendar Bridge Usage Guide

This guide explains how to use the **production-ready calendar bridge system** to connect any calendar system with any other calendar system through standardized APIs using the modern Bridge Pattern architecture.

## Overview

The bridge system provides seamless integration between calendar systems using a unified bridge pattern that supports multiple calendar providers (Outlook, Google Calendar, etc.) through a single, consistent API interface.

### 🏗️ **New Bridge Architecture (Current Implementation)**

The system now uses a **modular bridge pattern** where each calendar system is implemented as a separate bridge:

```
┌─────────────────┐    ┌─────────────────────────────────┐    ┌─────────────────┐
│   Your System   │◄──►│        Calendar Bridge         │◄──►│ Target Calendar │
│                 │    │  ┌─────────────────────────┐   │    │  (Outlook, etc) │
│ - Your API      │    │  │   Bridge Controller     │   │    │ - Graph API     │
│ - Your Schema   │    │  │  - Resource Mapping     │   │    │ - OAuth2        │
│ - Your Logic    │    │  │  - Event Translation    │   │    │ - Webhooks      │
└─────────────────┘    │  │  - Sync Management      │   │    └─────────────────┘
                       │  └─────────────────────────┘   │    
                       │  ┌─────────────────────────┐   │    ┌─────────────────┐
                       │  │    OutlookBridge        │   │◄──►│ Google Calendar │
                       │  │  - Microsoft Graph SDK  │   │    │ - Calendar API  │
                       │  │  - Proxy Support        │   │    │ - Service Acct  │
                       │  │  - Group Management     │   │    │ - Webhooks      │
                       │  └─────────────────────────┘   │    └─────────────────┘
                       └─────────────────────────────────┘
```

### ✅ **Production-Ready Bridge Features**

This bridge system is **production-ready** with the following verified capabilities:
- ✅ **Modern Bridge Pattern** - Modular, extensible architecture for any calendar system
- ✅ **Microsoft Graph SDK Integration** - Production-grade Outlook/Office 365 support
- ✅ **Proxy Support** - Enterprise firewall compatibility with HTTP proxy configuration
- ✅ **Bidirectional Sync** - Events flow seamlessly in both directions
- ✅ **Real-time Webhooks** - Instant synchronization with Graph API webhooks
- ✅ **Group Member Discovery** - Automatic resource discovery from Outlook groups
- ✅ **Resource Filtering** - Advanced name-based filtering for resources and calendars
- ✅ **Server-Side Pagination** - Efficient pagination using Microsoft Graph native parameters (`$top`, `$skip`)
- ✅ **Flexible Resource Mapping** - Complete calendar resource management with composite key support
- ✅ **RESTful API Design** - Clean, consistent API endpoints
- ✅ **Database Integration** - Persistent mapping and sync state management
- ✅ **Health Monitoring** - Real-time status tracking and alerting
- ✅ **Error Recovery** - Comprehensive error handling and retry mechanisms
- ✅ **Production Tested** - Verified with Microsoft Graph API and enterprise environments

## Prerequisites

1. **Bridge System**: Running and accessible with proper environment configuration
2. **Microsoft Graph API**: App registration with appropriate permissions
3. **Database**: PostgreSQL database for mapping and sync state storage
4. **Network**: HTTP proxy configuration if behind corporate firewall
5. **API Authentication**: Proper OAuth2/client credentials setup

## Environment Configuration

### Required Environment Variables

```env
# Outlook/Microsoft Graph Configuration
OUTLOOK_CLIENT_ID=your_client_id
OUTLOOK_CLIENT_SECRET=your_client_secret
OUTLOOK_TENANT_ID=your_tenant_id
OUTLOOK_GROUP_ID=your_group_id_for_resource_discovery

# Proxy Configuration (if behind firewall)
httpproxy_server=your.proxy.server.com
httpproxy_port=8080

# Database Configuration
DATABASE_URL=postgresql://user:password@localhost:5432/bridge_db

# Bridge Configuration
BRIDGE_BASE_URL=http://your-bridge-server
```

### Microsoft Graph Permissions Required

Your app registration needs these Graph API permissions:
- `Calendars.ReadWrite` - Read and write calendar events
- `Group.Read.All` - Read group membership for resource discovery
- `User.Read.All` - Read user information for calendar access
- `Places.Read.All` - Read room/resource information

## Composite ID System

The bridge system implements a **composite ID system** for universal event identification and mapping across different calendar systems.

### **Composite ID Format**

All events in the bridge system use composite IDs in the format: `{type}_{original_id}`

**Supported Event Types:**
- `event_` - Standard calendar events (Priority: 1 - Highest)
- `booking_` - Booking system reservations (Priority: 2)
- `allocation_` - Resource allocation entries (Priority: 3 - Lowest)
- `meeting_` - Meeting room bookings (Priority: 2)
- `appointment_` - Appointment entries (Priority: 2)

### **Composite ID Examples**

```bash
# Original booking system events
Event ID: 78269, Type: event → Composite ID: event_78269
Booking ID: 123, Type: booking → Composite ID: booking_123
Allocation ID: 456, Type: allocation → Composite ID: allocation_456
```

### **Bridge Mapping with Composite IDs**

The `bridge_mappings` table stores relationships using composite IDs:

```sql
-- Example mapping record
source_bridge: "booking_system"
source_id: "event_78269"
target_bridge: "outlook"
target_id: "AAMkAGU4NzE5ZGZjLTBhNzUtNDY0OS1iMzMwLTY3..."
```

### **Bidirectional Sync with Composite IDs**

#### **Booking System → Outlook Sync**
1. **Event Detection**: Booking system event detected (ID: 78269, Type: event)
2. **Composite ID Creation**: System creates composite ID `event_78269`
3. **Outlook Sync**: Event synced to Outlook, gets Graph API ID
4. **Mapping Storage**: Relationship stored with composite ID

```bash
# Sync booking system events to Outlook
curl -X POST "http://your-bridge/bridges/sync/booking_system/outlook" \
  -H "Content-Type: application/json" \
  -d '{
    "source_calendar_id": "room_123",
    "target_calendar_id": "conference-room-a@company.com"
  }'
```

#### **Outlook → Booking System Sync**
1. **Outlook Event**: Modified in Outlook (Graph ID: AAMkAGU...)
2. **Mapping Lookup**: System finds `source_id: "event_78269"`
3. **ID Resolution**: Extracts type `event` and original ID `78269`
4. **Booking System Update**: Updates event 78269 using correct API endpoint

```bash
# Sync Outlook changes back to booking system
curl -X POST "http://your-bridge/bridges/sync/outlook/booking_system" \
  -H "Content-Type: application/json" \
  -d '{
    "source_calendar_id": "conference-room-a@company.com",
    "target_calendar_id": "room_123"
  }'
```

## Priority Filtering Implementation

The bridge system implements **intelligent priority filtering** to handle overlapping reservations across different calendar systems.

### **Priority Hierarchy**

When multiple events overlap the same resource and time slot, the system applies this priority order:

1. **Event** (Priority 1) - Standard calendar events - **HIGHEST PRIORITY**
2. **Booking** (Priority 2) - Booking system reservations
3. **Meeting** (Priority 2) - Meeting room bookings  
4. **Appointment** (Priority 2) - Appointment entries
5. **Allocation** (Priority 3) - Resource allocation entries - **LOWEST PRIORITY**

### **Priority Filtering Logic**

#### **Scenario: Multiple Overlapping Reservations**

```
Resource: Conference Room A
Time Slot: 2024-06-18 14:00-15:00

Available Events:
- allocation_456 (Type: allocation, Priority: 3)
- booking_123 (Type: booking, Priority: 2)
- event_78269 (Type: event, Priority: 1) ← SELECTED FOR SYNC

System Action:
✅ event_78269 → Synced to Outlook
⚠️ booking_123 → Logged as lower priority conflict
⚠️ allocation_456 → Logged as lower priority conflict
```

#### **Priority Filtering API**

The system automatically applies priority filtering during sync operations:

```bash
# Automatic priority filtering during sync
curl -X POST "http://your-bridge/bridges/sync/booking_system/outlook" \
  -H "Content-Type: application/json" \
  -d '{
    "source_calendar_id": "room_123",
    "target_calendar_id": "conference-room-a@company.com",
    "apply_priority_filter": true
  }'

# Response includes priority filtering details
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
      "status": "filtered_due_to_priority",
      "reason": "Lower priority than event_78269"
    },
    {
      "composite_id": "allocation_456", 
      "priority": 3,
      "status": "filtered_due_to_priority",
      "reason": "Lower priority than event_78269"
    }
  ]
}
```

### **Conflict Resolution Logging**

All priority filtering decisions are logged for audit purposes:

```bash
# View priority filtering logs
curl -X GET "http://your-bridge/bridges/sync-logs" \
  -H "Content-Type: application/json" \
  -d '{
    "filter_type": "priority_conflict",
    "date_from": "2024-06-18",
    "date_to": "2024-06-19"
  }'

# Response includes detailed conflict resolution
{
  "success": true,
  "conflicts": [
    {
      "resource_id": "room_123",
      "time_slot": "2024-06-18T14:00:00Z - 2024-06-18T15:00:00Z",
      "selected_event": {
        "composite_id": "event_78269",
        "priority": 1,
        "reason": "Highest priority event"
      },
      "filtered_events": [
        {
          "composite_id": "booking_123",
          "priority": 2,
          "reason": "Lower priority than selected event"
        }
      ],
      "timestamp": "2024-06-18T10:30:00Z"
    }
  ]
}
```

### **Custom Priority Configuration**

Priority levels can be configured per bridge deployment:

```env
# Environment configuration for priority levels
PRIORITY_EVENT=1
PRIORITY_BOOKING=2
PRIORITY_MEETING=2
PRIORITY_APPOINTMENT=2
PRIORITY_ALLOCATION=3

# Enable/disable priority filtering
ENABLE_PRIORITY_FILTERING=true
LOG_PRIORITY_CONFLICTS=true
```

## Core Bridge Operations

### 1. Resource Discovery

#### Get Available Calendars (Group Members)
```bash
# Get all group members
curl -X GET "http://your-bridge/calendars"

# Filter by name
curl -X GET "http://your-bridge/calendars?name=conference"
```

#### Get Available Resources with Filtering
```bash
# Get all resources
curl -X GET "http://your-bridge/resources"

# Filter by name (searches display name, email, UPN)
curl -X GET "http://your-bridge/resources?name=mr.ok23"
curl -X GET "http://your-bridge/resources?name=e4.475"
curl -X GET "http://your-bridge/resources?name=svgdrift.no"
```

#### Pagination Support
```bash
# Get first 10 resources
curl -X GET "http://your-bridge/resources?limit=10"

# Get resources 11-20 (skip first 10, return next 10)
curl -X GET "http://your-bridge/resources?limit=10&offset=10"

# Skip first 20 resources, return all remaining
curl -X GET "http://your-bridge/resources?offset=20"

# Combine filtering and pagination
curl -X GET "http://your-bridge/resources?name=meeting&limit=5&offset=0"
```

**Pagination Parameters:**
- `limit` - Maximum number of resources to return (0 = no limit)
- `offset` - Number of resources to skip (0 = start from beginning)  
- `query` - Name filter (same as `name` parameter)

**Performance Note:** 
- **OutlookBridge**: Uses Microsoft Graph's server-side pagination (`$top`, `$skip`) for optimal performance
- **BookingSystemBridge**: Passes pagination parameters directly to your booking system API
- Server-side pagination significantly reduces data transfer and improves response times for large datasets

**Response Example:**
```json
{
  "success": true,
  "bridge_name": "outlook",
  "bridge_type": "outlook",
  "name_filter": "e4.475",
  "resources": [
    {
      "id": "27786b01-e7e2-4459-9842-702faf4e2eee",
      "name": "mr.ok23.e4.475",
      "email": "mr.ok23.e4.475@svgdrift.no",
      "userPrincipalName": "mr.ok23.e4.475@svgdrift.no",
      "type": "user",
      "bridge_type": "outlook"
    }
  ],
  "count": 1
}
```

**Pagination Response Example:**
```json
{
  "success": true,
  "bridge": "booking_system",
  "resources": [
    // ... resource objects ...
  ],
  "count": 5,
  "total_records": 127,
  "pagination": {
    "limit": 5,
    "offset": 10,
    "returned_count": 5,
    "total_records": 127
  }
}
```

**Response Fields:**
- `count` - Number of resources returned in this response
- `total_records` - Total number of resources available from the API (when supported)
- `pagination.returned_count` - Same as `count` 
- `pagination.total_records` - Total records available for pagination calculations

#### Get Available Groups with Filtering and Pagination
```bash
# Get all groups
curl -X GET "http://your-bridge/bridges/outlook/available-groups"

# Filter groups by name (searches display name, description, email)
curl -X GET "http://your-bridge/bridges/outlook/available-groups?query=meeting"

# Get first 5 groups
curl -X GET "http://your-bridge/bridges/outlook/available-groups?limit=5"

# Get groups 6-10 (skip first 5, return next 5)
curl -X GET "http://your-bridge/bridges/outlook/available-groups?limit=5&offset=5"

# Combine filtering and pagination
curl -X GET "http://your-bridge/bridges/outlook/available-groups?query=team&limit=10&offset=0"
```

**Groups Response Example:**
```json
{
  "success": true,
  "bridge": "outlook",
  "groups": [
    {
      "id": "12345678-1234-1234-1234-123456789abc",
      "name": "Meeting Rooms Team",
      "description": "Group for managing meeting rooms",
      "email": "meetingrooms@company.com",
      "group_types": ["Unified"],
      "bridge_type": "outlook"
    }
  ],
  "count": 1,
  "total_records": 25,
  "pagination": {
    "limit": 5,
    "offset": 0,
    "returned_count": 1,
    "total_records": 25
  }
}
```

### 2. Resource Mapping Management

#### Create Resource Mapping
```bash
curl -X POST "http://your-bridge/mappings/resources" \
  -H "Content-Type: application/json" \
  -d '{
    "bridge_from": "your_system",
    "bridge_to": "outlook", 
    "resource_id": "room_123",
    "resource_name": "Conference Room A",
    "calendar_id": "mr.ok23.e4.475@svgdrift.no",
    "calendar_name": "mr.ok23.e4.475"
  }'
```

#### Get Resource Mappings with Filtering
```bash
# Get all mappings
curl -X GET "http://your-bridge/mappings/resources"

# Filter by bridge type
curl -X GET "http://your-bridge/mappings/resources?bridge_from=your_system"
curl -X GET "http://your-bridge/mappings/resources?bridge_to=outlook"

# Filter by name
curl -X GET "http://your-bridge/mappings/resources?name=conference"
```

#### Delete Resource Mapping by Composite Key
```bash
# Delete specific mapping using business keys
curl -X DELETE "http://your-bridge/mappings/resources/by-key/your_system/room_123/mr.ok23.e4.475@svgdrift.no"

# Delete with name filter for additional safety
curl -X DELETE "http://your-bridge/mappings/resources/by-key/your_system/room_123/mr.ok23.e4.475@svgdrift.no?name=Conference"
```

### 3. Event Management with Composite ID Support

#### Create Calendar Event with Composite ID
```bash
# Create event in booking system, gets composite ID automatically
curl -X POST "http://your-bridge/events" \
  -H "Content-Type: application/json" \
  -d '{
    "resource_email": "mr.ok23.e4.475@svgdrift.no",
    "title": "Team Meeting",
    "description": "Weekly team sync",
    "start_datetime": "2025-06-16T10:00:00Z",
    "end_datetime": "2025-06-16T11:00:00Z",
    "attendees": [
      {
        "email": "user@example.com",
        "name": "John Doe"
      }
    ],
    "location": "Conference Room A",
    "event_type": "event",
    "booking_id": "78269"
  }'

# Response includes composite ID
{
  "success": true,
  "composite_id": "event_78269",
  "outlook_event_id": "AAMkAGU4NzE5ZGZjLT...",
  "sync_status": "synced"
}
```

#### Get Resource Calendar Events with Composite ID Information
```bash
# Get calendar events for a specific resource through a bridge
curl -X GET "http://your-bridge/bridges/outlook/resources/mr.ok23.e4.475@svgdrift.no/calendar-items"

# Response includes composite ID information
{
  "success": true,
  "events": [
    {
      "id": "AAMkAGU4NzE5ZGZjLT...",
      "composite_id": "event_78269",
      "event_type": "event",
      "original_id": "78269",
      "subject": "Team Meeting",
      "start": "2025-06-16T10:00:00Z",
      "end": "2025-06-16T11:00:00Z",
      "priority": 1,
      "sync_source": "booking_system"
    }
  ]
}

# With date filtering
curl -X GET "http://your-bridge/bridges/outlook/resources/mr.ok23.e4.475@svgdrift.no/calendar-items?startDate=2025-06-16T00:00:00Z&endDate=2025-06-17T00:00:00Z"

# For booking system bridge with priority filtering
curl -X GET "http://your-bridge/bridges/booking_system/resources/room_123/calendar-items?startDate=2025-06-16&endDate=2025-06-17&apply_priority_filter=true"
```

#### Update Event Using Composite ID
```bash
# Update event using composite ID for proper addressing
curl -X PUT "http://your-bridge/events/event_78269" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Updated Team Meeting",
    "description": "Weekly team sync - Updated agenda",
    "start_datetime": "2025-06-16T10:30:00Z",
    "end_datetime": "2025-06-16T11:30:00Z"
  }'

# System automatically resolves composite ID to:
# - Type: "event"
# - Original ID: "78269"
# - Updates both booking system and Outlook
```

#### Delete Event Using Composite ID
```bash
# Delete event using composite ID
curl -X DELETE "http://your-bridge/events/event_78269"

# System handles:
# 1. Resolves composite ID (event_78269 → type=event, id=78269)
# 2. Deletes from booking system using original ID and type
# 3. Removes from Outlook using mapped Graph API ID
# 4. Cleans up bridge mapping
```

#### Sync Operations with Priority Filtering
```bash
# Sync with automatic priority filtering
curl -X POST "http://your-bridge/bridges/sync/booking_system/outlook" \
  -H "Content-Type: application/json" \
  -d '{
    "source_calendar_id": "room_123",
    "target_calendar_id": "mr.ok23.e4.475@svgdrift.no",
    "apply_priority_filter": true
  }'

# Response includes priority filtering results
{
  "success": true,
  "synced_events": [
    {
      "composite_id": "event_78269",
      "priority": 1,
      "status": "synced",
      "outlook_event_id": "AAMkAGU4NzE5ZGZjLT..."
    }
  ],
  "filtered_events": [
    {
      "composite_id": "booking_123",
      "priority": 2,
      "status": "filtered_due_to_priority",
      "reason": "Lower priority than event_78269",
      "conflict_with": "event_78269"
    },
    {
      "composite_id": "allocation_456",
      "priority": 3,
      "status": "filtered_due_to_priority", 
      "reason": "Lower priority than event_78269",
      "conflict_with": "event_78269"
    }
  ],
  "summary": {
    "total_events": 3,
    "synced_events": 1,
    "filtered_events": 2,
    "conflicts_resolved": 1
  }
}
```

#### Get Calendar Events (Legacy - Still Supported)
```bash
# Get events for specific calendar (legacy endpoint)
curl -X GET "http://your-bridge/events/mr.ok23.e4.475@svgdrift.no?start=2025-06-16T00:00:00Z&end=2025-06-17T00:00:00Z"
```

### 4. Webhook Management

#### Subscribe to Calendar Changes
```bash
curl -X POST "http://your-bridge/webhooks/subscribe" \
  -H "Content-Type: application/json" \
  -d '{
    "calendar_id": "mr.ok23.e4.475@svgdrift.no",
    "webhook_url": "https://your-system.com/webhook/outlook-changes"
  }'
```

## Important Implementation Notes

### Calendar ID Usage

Always use **email addresses** as calendar IDs, not GUIDs:

✅ **Correct:** `mr.ok23.e4.475@svgdrift.no`  
❌ **Incorrect:** `27786b01-e7e2-4459-9842-702faf4e2eee`

### Group ID Usage

For Outlook groups, use the **GUID** format:

✅ **Correct:** `90ba4505-3855-4739-81fa-6b0008ae9216`  
❌ **Incorrect:** `group@svgdrift.no`

### Proxy Configuration

If behind a corporate firewall, ensure proxy settings are configured:

```env
httpproxy_server=proxy.company.com
httpproxy_port=8080
```

The bridge automatically uses proxy for:
- Microsoft Graph authentication
- Graph API calls
- Webhook subscriptions

### Resource Mapping Strategy

Use meaningful, stable identifiers:
- **`resource_id`**: Your internal system's resource ID
- **`calendar_id`**: Target calendar email address
- **Names**: Human-readable names for filtering and identification

## Advanced Features

### Composite Key Deletion

Delete mappings using business logic keys instead of database IDs:

```bash
DELETE /mappings/resources/by-key/{bridge_from}/{resource_id}/{calendar_id}
```

This provides safer, more predictable deletion based on your business logic rather than internal database IDs.

### Multi-field Name Filtering

Resource filtering searches across multiple fields:
- Display name (e.g., "mr.ok23.e4.475")
- Email address (e.g., "mr.ok23.e4.475@svgdrift.no")
- User Principal Name (e.g., "mr.ok23.e4.475@stavangerkommune.onmicrosoft.com")

### Debug and Troubleshooting

```bash
# Debug group information
curl -X GET "http://your-bridge/debug/group/{group_id}"

# Health check
curl -X GET "http://your-bridge/health"

# Bridge status
curl -X GET "http://your-bridge/status"
```

## Migration from Legacy System

If migrating from an older bridge implementation:

1. **Update Environment Variables**: Change from `GRAPH_*` to `OUTLOOK_*` prefixes
2. **Review API Endpoints**: Use the new RESTful endpoint structure
3. **Update Resource Mapping**: Use the new composite key approach
4. **Test Proxy Configuration**: Verify firewall compatibility
5. **Validate Group Discovery**: Confirm group member access

## Error Handling and Recovery

The bridge provides comprehensive error handling:

- **Authentication Errors**: Automatic token refresh and retry
- **Network Errors**: Proxy fallback and connection retry
- **API Rate Limits**: Intelligent backoff and retry strategies
- **Webhook Failures**: Graceful degradation to polling
- **Mapping Conflicts**: Clear error messages and resolution guidance

## Production Deployment Checklist

- [ ] Environment variables configured
- [ ] Microsoft Graph permissions granted
- [ ] Database schema deployed
- [ ] Proxy settings configured (if needed)
- [ ] Resource mappings created
- [ ] Webhook endpoints tested
- [ ] Health monitoring enabled
- [ ] Error alerting configured
- [ ] Backup and recovery procedures in place

The calendar bridge system is production-ready and designed to scale with your integration needs using modern, maintainable architecture patterns.

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
curl -X POST "http://your-bridge/mappings/resources" \
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
curl -X POST "http://your-bridge/bridges/sync/booking_system/outlook"
```

**Sync from Outlook to your booking system:**
```bash
curl -X POST "http://your-bridge/bridges/sync/outlook/booking_system"
```

**Process any pending sync operations:**
```bash
curl -X POST "http://your-bridge/bridge/process-pending"
```

#### Step 4: Monitor Bridge Health

```bash
curl -X GET "http://your-bridge/bridges/health"
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
