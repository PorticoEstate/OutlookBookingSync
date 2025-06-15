# Generic Calendar Bridge

A flexible, extensible calendar synchronization service that acts as middleware between different calendar systems using REST APIs. Built with PHP/Slim4, this bridge can synchronize events between any calendar systems that support REST API communication.

## 🎯 Overview

The Generic Calendar Bridge transforms calendar synchronization from a single-purpose solution into a universal platform that can connect any calendar system to any other. It provides:

- **Universal Bridge Pattern**: Extensible architecture supporting any calendar system
- **REST API Communication**: Standard HTTP/REST interfaces for all integrations  
- **Self-Hosted Solution**: Full control and customization for organizations
- **Production Ready**: Enterprise-grade reliability and monitoring
- **Developer Friendly**: Easy to extend with new calendar system adapters

## 🏗️ Architecture

```
┌─────────────────┐    REST API    ┌─────────────────┐    REST API    ┌─────────────────┐
│                 │◄──────────────►│                 │◄──────────────►│                 │
│ Booking System  │                │ Calendar Bridge │                │ Microsoft Graph │
│                 │                │   (Middleware)  │                │      API        │
└─────────────────┘                └─────────────────┘                └─────────────────┘
```

### Core Components

- **AbstractCalendarBridge**: Base class defining standard interface for all calendar systems
- **BridgeManager**: Central orchestrator managing multiple bridge instances  
- **OutlookBridge**: Microsoft Graph API implementation
- **BookingSystemBridge**: Generic booking system implementation with REST API and database fallback
- **BridgeController**: RESTful API endpoints for bridge operations

## 📁 Project Structure

```
OutlookCalendarBridge/
├── src/
│   ├── Bridge/
│   │   ├── AbstractCalendarBridge.php     # Base bridge class
│   │   ├── OutlookBridge.php               # Microsoft Graph integration
│   │   └── BookingSystemBridge.php         # Booking system integration
│   ├── Services/
│   │   ├── BridgeManager.php               # Bridge orchestration
│   │   ├── DeletionSyncService.php         # Deletion/cancellation sync
│   │   └── OutlookEventDetectionService.php # Event change detection
│   ├── Controller/
│   │   ├── BridgeController.php            # REST API endpoints
│   │   ├── BridgeBookingController.php     # Bridge booking operations
│   │   └── ResourceMappingController.php   # Resource mapping management
│   └── Middleware/
├── database/
│   └── bridge_schema.sql                   # Bridge database schema
├── scripts/
│   ├── setup_bridge_database.sh           # Database setup
│   ├── test_bridge.sh                     # Testing script
│   └── enhanced_process_deletions.sh       # Automated deletion processing
└── docker-compose.yml                      # Container orchestration
```

## 🚀 Getting Started

### Prerequisites

- PHP 8.4+
- PostgreSQL 12+
- Composer
- Microsoft 365 tenant (for Outlook integration)

### Installation

1. **Clone and setup dependencies:**
```bash
git clone <repository>
cd OutlookBookingSync
composer install
```

2. **Configure environment variables:**
```bash
cp .env.example .env
# Edit .env with your configuration
```

Required environment variables:
```env
# Database
DB_HOST=localhost
DB_PORT=5432
DB_NAME=outlook_sync
DB_USER=postgres
DB_PASS=your_password

# Microsoft Graph API
OUTLOOK_CLIENT_ID=your_client_id
OUTLOOK_CLIENT_SECRET=your_client_secret  
OUTLOOK_TENANT_ID=your_tenant_id
OUTLOOK_GROUP_ID=your_group_id_for_room_calendars

# Booking System API (optional)
BOOKING_SYSTEM_API_URL=http://your-booking-system/api
BOOKING_SYSTEM_API_KEY=your_api_key

# Application
APP_BASE_URL=http://localhost:8080
API_KEY=your_api_key
```

3. **Setup database:**
```bash
./setup_bridge_database.sh
```

4. **Start the service:**
```bash
# Development
php -S localhost:8080 -t . index.php

# Production (Docker)
docker-compose up -d
```

## 📚 API Documentation

### Bridge Management

#### List All Bridges
```http
GET /bridges
```

Response:
```json
{
  "success": true,
  "bridges": {
    "outlook": {
      "name": "outlook",
      "type": "outlook", 
      "capabilities": {
        "supports_webhooks": true,
        "supports_recurring": true,
        "max_events_per_request": 999
      },
      "health": {
        "status": "healthy",
        "response_time_ms": 245
      }
    },
    "booking_system": {
      "name": "booking_system",
      "type": "booking_system",
      "capabilities": {
        "supports_webhooks": true,
        "supports_recurring": false,
        "max_events_per_request": 100
      },
      "health": {
        "status": "healthy",
        "response_time_ms": 15
      }
    }
  }
}
```

#### Get Bridge Calendars
```http
GET /bridges/{bridgeName}/calendars
```

Example: `GET /bridges/outlook/calendars`

Response:
```json
{
  "success": true,
  "bridge_name": "outlook",
  "bridge_type": "outlook",
  "calendars": [
    {
      "id": "room1@company.com",
      "name": "Conference Room 1", 
      "email": "room1@company.com",
      "type": "room",
      "bridge_type": "outlook"
    }
  ]
}
```

### Synchronization

#### Sync Between Bridges
```http
POST /bridges/sync/{sourceBridge}/{targetBridge}
```

Example: `POST /bridges/sync/outlook/booking_system`

Request body:
```json
{
  "source_calendar_id": "room1@company.com",
  "target_calendar_id": "123",
  "start_date": "2025-06-14",
  "end_date": "2025-06-21",
  "handle_deletions": true,
  "dry_run": false
}
```

Response:
```json
{
  "success": true,
  "sync_results": {
    "source_bridge": "outlook",
    "target_bridge": "booking_system", 
    "source_events_found": 5,
    "created": 3,
    "updated": 2,
    "deleted": 0,
    "errors": []
  }
}
```

### Webhooks

#### Handle Bridge Webhook
```http
POST /bridges/webhook/{bridgeName}
```

Used by calendar systems to notify of changes. Automatically queues sync operations.

#### Create Webhook Subscriptions
```http
POST /bridges/{bridgeName}/subscriptions
```

Request body:
```json
{
  "webhook_url": "https://your-bridge.com/bridges/webhook/outlook",
  "calendar_ids": ["room1@company.com", "room2@company.com"]
}
```

### Health & Monitoring

#### Check Bridge Health
```http
GET /bridges/health
```

Response:
```json
{
  "success": true,
  "overall_health": "healthy",
  "summary": {
    "total_bridges": 2,
    "healthy_bridges": 2,
    "unhealthy_bridges": 0
  },
  "bridges": {
    "outlook": {
      "status": "healthy",
      "response_time_ms": 245
    },
    "booking_system": {
      "status": "healthy", 
      "response_time_ms": 15
    }
  }
}
```

## 🔧 Extending the Bridge

### Adding a New Calendar System

1. **Create a new bridge class:**

```php
<?php

namespace App\Bridge;

class GoogleCalendarBridge extends AbstractCalendarBridge
{
    public function getBridgeType(): string
    {
        return 'google_calendar';
    }
    
    public function getEvents($calendarId, $startDate, $endDate): array
    {
        // Implement Google Calendar API calls
    }
    
    public function createEvent($calendarId, $event): string
    {
        // Implement event creation
    }
    
    // ... implement other abstract methods
}
```

2. **Register the bridge:**

```php
// In index.php
$manager->registerBridge('google_calendar', \App\Bridge\GoogleCalendarBridge::class, [
    'api_key' => $_ENV['GOOGLE_API_KEY'],
    'client_id' => $_ENV['GOOGLE_CLIENT_ID']
]);
```

3. **Use the new bridge:**

```bash
# Sync from Google Calendar to Outlook
curl -X POST http://localhost/bridges/sync/google_calendar/outlook \
  -H "Content-Type: application/json" \
  -d '{"source_calendar_id": "primary", "target_calendar_id": "room@company.com"}'
```

### Custom Event Mapping

Override mapping methods in your bridge:

```php
protected function formatEventForBridge($genericEvent): array
{
    // Custom transformation logic
    return [
        'title' => $genericEvent['subject'],
        'start_datetime' => $genericEvent['start'],
        'custom_field' => 'bridge_import'
    ];
}
```

## 🔒 Security

- **API Key Authentication**: All endpoints protected with API key middleware
- **Environment Configuration**: Sensitive data stored in environment variables  
- **HTTPS Support**: Production deployment with SSL/TLS
- **Webhook Validation**: Microsoft Graph webhook validation supported
- **Error Handling**: Comprehensive error handling without exposing sensitive information

## 📊 Monitoring & Logging

### Built-in Monitoring

- **Health Checks**: Real-time bridge health monitoring
- **Sync Statistics**: Detailed sync operation tracking
- **Error Logging**: Comprehensive error logging with context
- **Performance Metrics**: Response time and throughput monitoring

### Database Views

- `v_bridge_health`: Overall bridge health status
- `v_bridge_sync_stats`: Sync operation statistics  
- `v_active_bridge_mappings`: Active event mappings

### Log Analysis

```bash
# View recent sync operations
SELECT * FROM bridge_sync_logs ORDER BY created_at DESC LIMIT 10;

# Check bridge health
SELECT * FROM v_bridge_health;

# Find performance issues
SELECT source_bridge, target_bridge, AVG(duration_ms) as avg_duration
FROM bridge_sync_logs 
WHERE created_at > NOW() - INTERVAL '1 day'
GROUP BY source_bridge, target_bridge;
```

### Web Dashboard

The bridge service includes a real-time monitoring dashboard at `/public/dashboard.html`:

**Features:**
- Real-time system health monitoring
- Bridge connection status
- Resource mapping overview  
- Recent sync activity
- Error tracking and analysis
- Performance metrics
- Quick action buttons for manual operations

**Access the Dashboard:**
```bash
# Via web browser
http://localhost:8080/dashboard.html

# Or if using Docker
http://localhost:8080/dashboard.html
```

**Dashboard Sections:**
- **System Overview**: Active mappings, sync counts, and error summary
- **Bridge Status**: Connection health for Outlook and booking system bridges
- **Health Checks**: Database, cron jobs, and system resource monitoring
- **Recent Activity**: Latest sync operations and their status
- **Error Summary**: Recent errors with frequency and details
- **Performance Metrics**: Memory usage, throughput, and response times
- **Deletion Sync**: Queue status and cancellation detection metrics
- **Quick Actions**: Manual sync, deletion processing, and cancellation detection

**Dashboard API Endpoints:**
- `/health/system` - System health monitoring
- `/health/dashboard` - Dashboard metrics  
- `/bridges/sync/{source}/{target}` - Manual bidirectional sync
- `/bridges/sync-deletions` - Process deletion queue and detect cancellations
- `/bridges/process-deletion-queue` - Process webhook deletion notifications

**Auto-refresh:**
The dashboard automatically refreshes every 30 seconds to provide real-time monitoring.

## 🧪 Testing

### Run Test Suite
```bash
./test_bridge.sh
```

### Manual Testing

```bash
# Test bridge health
curl -H "X-API-Key: your_key" http://localhost/bridges/health

# Test calendar discovery
curl -H "X-API-Key: your_key" http://localhost/bridges/outlook/calendars

# Test dry run sync
curl -X POST -H "Content-Type: application/json" -H "X-API-Key: your_key" \
  http://localhost/bridges/sync/outlook/booking_system \
  -d '{"source_calendar_id": "room@company.com", "target_calendar_id": "123", "dry_run": true}'
```

## 🚀 Production Deployment

### Docker Deployment

```yaml
# docker-compose.yml
version: '3.8'
services:
  calendar-bridge:
    build: .
    ports:
      - "8080:80"
    environment:
      - DB_HOST=postgres
      - OUTLOOK_CLIENT_ID=${OUTLOOK_CLIENT_ID}
    depends_on:
      - postgres
      
  postgres:
    image: postgres:14
    environment:
      POSTGRES_DB: outlook_sync
```

### Scaling Considerations

- **Horizontal Scaling**: Multiple bridge instances behind load balancer
- **Database Connection Pooling**: Configure connection limits
- **Webhook Handling**: Use queue system (Redis) for high-volume webhooks
- **Rate Limiting**: Implement rate limiting for external API calls

## 🔄 Migration from Single-Purpose Sync

If migrating from the original booking system sync:

1. **Database Migration**: Bridge tables are additive - existing data preserved
2. **API Compatibility**: Existing endpoints maintained for backwards compatibility  
3. **Configuration**: Update environment variables for bridge configuration
4. **Testing**: Use dry run mode to verify migration before going live

## 📈 Roadmap

### Phase 1: Core Bridge ✅
- [x] Abstract bridge architecture
- [x] Outlook and Booking System bridges
- [x] Basic sync operations
- [x] Health monitoring

### Phase 2: Enhanced Features
- [ ] Google Calendar bridge
- [ ] CalDAV bridge support
- [ ] Advanced conflict resolution
- [ ] Web UI for management

### Phase 3: Enterprise Features  
- [ ] Multi-tenant support
- [ ] Custom field mapping
- [ ] Advanced analytics
- [ ] Plugin marketplace

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/new-bridge`)
3. Implement your bridge following the `AbstractCalendarBridge` interface
4. Add tests and documentation
5. Submit a pull request

## 📄 License

This project is licensed under the MIT License - see the LICENSE file for details.

## 🆘 Support

- **Documentation**: Check this README and inline code comments
- **Issues**: Submit GitHub issues for bugs and feature requests
- **Testing**: Use the provided test scripts to verify functionality
- **Monitoring**: Check bridge health endpoints for operational status

---

**Generic Calendar Bridge** - Universal calendar synchronization platform 🗓️✨

## 🔌 Booking System API Requirements

The Generic Calendar Bridge can integrate with booking systems in two ways:
1. **REST API Mode**: Your booking system exposes REST endpoints (recommended)
2. **Direct Database Mode**: Bridge accesses your booking system database directly (fallback)

### Required REST API Endpoints

If you want to use REST API mode (recommended), your booking system needs to implement these endpoints:

#### **1. List Resources/Calendars**
```http
GET /api/resources
```

**Response Format:**
```json
{
  "success": true,
  "resources": [
    {
      "id": "123",
      "name": "Conference Room 1",
      "description": "Main conference room with projector",
      "type": "room",
      "capacity": 12,
      "location": "Building A, Floor 2",
      "active": true
    },
    {
      "id": "124", 
      "name": "Meeting Room 2",
      "description": "Small meeting room",
      "type": "room",
      "capacity": 6,
      "location": "Building A, Floor 2",
      "active": true
    }
  ],
  "count": 2
}
```

#### **2. Get Events for a Resource**
```http
GET /api/resources/{resourceId}/events?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD&format=json
```

**Example:** `GET /api/resources/123/events?start_date=2025-06-14&end_date=2025-06-21`

**Response Format:**
```json
{
  "success": true,
  "resource_id": "123",
  "events": [
    {
      "id": "456",
      "title": "Team Meeting",
      "name": "Team Meeting",
      "start_time": "2025-06-14T10:00:00Z",
      "end_time": "2025-06-14T11:00:00Z", 
      "description": "Weekly team sync",
      "contact_name": "John Doe",
      "contact_email": "john@company.com",
      "organization": "Engineering Team",
      "status": "confirmed",
      "created_at": "2025-06-13T09:00:00Z",
      "updated_at": "2025-06-13T09:00:00Z"
    }
  ],
  "count": 1,
  "date_range": {
    "start": "2025-06-14",
    "end": "2025-06-21"
  }
}
```

#### **3. Create New Event**
```http
POST /api/resources/{resourceId}/events
```

**Request Body:**
```json
{
  "title": "New Meeting",
  "name": "New Meeting",
  "start_time": "2025-06-15T14:00:00Z",
  "end_time": "2025-06-15T15:00:00Z",
  "description": "Important client meeting",
  "contact_name": "Jane Smith", 
  "contact_email": "jane@company.com",
  "attendees": ["jane@company.com", "client@external.com"],
  "source": "calendar_bridge",
  "bridge_import": true
}
```

**Response:**
```json
{
  "success": true,
  "event_id": "789",
  "message": "Event created successfully",
  "event": {
    "id": "789",
    "title": "New Meeting",
    "start_time": "2025-06-15T14:00:00Z",
    "end_time": "2025-06-15T15:00:00Z",
    "resource_id": "123"
  }
}
```

#### **4. Update Existing Event**
```http
PUT /api/resources/{resourceId}/events/{eventId}
```

**Request Body:** (same as create, but for updating)
```json
{
  "title": "Updated Meeting Title",
  "name": "Updated Meeting Title", 
  "start_time": "2025-06-15T14:30:00Z",
  "end_time": "2025-06-15T15:30:00Z",
  "description": "Updated description"
}
```

**Response:**
```json
{
  "success": true,
  "event_id": "789",
  "message": "Event updated successfully"
}
```

#### **5. Delete Event**
```http
DELETE /api/resources/{resourceId}/events/{eventId}
```

**Response:**
```json
{
  "success": true,
  "event_id": "789", 
  "message": "Event deleted successfully"
}
```

#### **6. Webhook Management (Optional but Recommended)**

**Subscribe to Changes:**
```http
POST /api/webhooks/subscribe
```

**Request Body:**
```json
{
  "resource_id": "123",
  "callback_url": "https://your-bridge.com/bridges/webhook/booking_system",
  "events": ["created", "updated", "deleted"]
}
```

**Response:**
```json
{
  "success": true,
  "subscription_id": "sub_123456",
  "resource_id": "123",
  "callback_url": "https://your-bridge.com/bridges/webhook/booking_system",
  "events": ["created", "updated", "deleted"],
  "created_at": "2025-06-14T10:00:00Z"
}
```

**Unsubscribe:**
```http
DELETE /api/webhooks/{subscriptionId}
```

### Webhook Payload Format

When your booking system detects changes, it should POST to the bridge webhook URL:

```http
POST https://your-bridge.com/bridges/webhook/booking_system
Content-Type: application/json
```

**Payload:**
```json
{
  "action": "created",  // "created", "updated", "deleted"
  "resource_id": "123",
  "event_id": "456", 
  "event": {
    "id": "456",
    "title": "New Event",
    "start_time": "2025-06-15T10:00:00Z",
    "end_time": "2025-06-15T11:00:00Z"
  },
  "timestamp": "2025-06-14T10:00:00Z",
  "source": "booking_system"
}
```

### Authentication

Include API key in requests:

```http
Authorization: Bearer your_api_key
Content-Type: application/json
Accept: application/json
```

### Error Handling

**Standard Error Response:**
```json
{
  "success": false,
  "error": "Resource not found",
  "error_code": "RESOURCE_NOT_FOUND",
  "details": {
    "resource_id": "999"
  }
}
```

**Common HTTP Status Codes:**
- `200` - Success
- `201` - Created
- `400` - Bad Request (validation errors)
- `401` - Unauthorized (invalid API key)
- `404` - Not Found (resource/event doesn't exist)
- `409` - Conflict (time slot already booked)
- `500` - Internal Server Error

### Example Implementation (PHP)

Here's a basic PHP implementation for your booking system:

```php
<?php
// BookingSystemApiController.php

class BookingSystemApiController
{
    private $db;
    private $bridgeWebhookUrl = 'https://your-bridge.com/bridges/webhook/booking_system';
    
    public function getResources()
    {
        $sql = "SELECT id, name, description FROM bb_resource WHERE active = 1";
        $resources = $this->db->query($sql)->fetchAll();
        
        return [
            'success' => true,
            'resources' => $resources,
            'count' => count($resources)
        ];
    }
    
    public function getResourceEvents($resourceId, $startDate, $endDate) 
    {
        $sql = "
            SELECT e.id, e.name as title, e.start_time, e.end_time,
                   e.description, e.contact_name, e.contact_email
            FROM bb_event e
            JOIN bb_event_resource er ON e.id = er.event_id  
            WHERE er.resource_id = :resource_id
            AND e.start_time >= :start_date
            AND e.end_time <= :end_date
            AND e.active = 1
            ORDER BY e.start_time
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'resource_id' => $resourceId,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        
        return [
            'success' => true,
            'resource_id' => $resourceId,
            'events' => $stmt->fetchAll(),
            'count' => $stmt->rowCount(),
            'date_range' => ['start' => $startDate, 'end' => $endDate]
        ];
    }
    
    public function createEvent($resourceId, $eventData)
    {
        $this->db->beginTransaction();
        
        try {
            // Insert event
            $sql = "
                INSERT INTO bb_event (name, description, start_time, end_time, 
                                     contact_name, contact_email, active, created_at)
                VALUES (:name, :description, :start_time, :end_time,
                        :contact_name, :contact_email, 1, CURRENT_TIMESTAMP)
                RETURNING id
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'name' => $eventData['title'],
                'description' => $eventData['description'] ?? '',
                'start_time' => $eventData['start_time'],
                'end_time' => $eventData['end_time'],
                'contact_name' => $eventData['contact_name'] ?? '',
                'contact_email' => $eventData['contact_email'] ?? ''
            ]);
            
            $eventId = $stmt->fetchColumn();
            
            // Link to resource
            $sql = "INSERT INTO bb_event_resource (event_id, resource_id) VALUES (?, ?)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$eventId, $resourceId]);
            
            $this->db->commit();
            
            // Trigger webhook
            $this->triggerWebhook('created', $resourceId, $eventId, $eventData);
            
            return [
                'success' => true,
                'event_id' => $eventId,
                'message' => 'Event created successfully'
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function updateEvent($resourceId, $eventId, $eventData)
    {
        $sql = "
            UPDATE bb_event SET
                name = :name,
                description = :description, 
                start_time = :start_time,
                end_time = :end_time,
                contact_name = :contact_name,
                contact_email = :contact_email
            WHERE id = :event_id
        ";
        
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([
            'event_id' => $eventId,
            'name' => $eventData['title'],
            'description' => $eventData['description'] ?? '',
            'start_time' => $eventData['start_time'],
            'end_time' => $eventData['end_time'],
            'contact_name' => $eventData['contact_name'] ?? '',
            'contact_email' => $eventData['contact_email'] ?? ''
        ]);
        
        if ($result) {
            // Trigger webhook
            $this->triggerWebhook('updated', $resourceId, $eventId, $eventData);
            
            return [
                'success' => true,
                'event_id' => $eventId,
                'message' => 'Event updated successfully'
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Event not found or update failed'
            ];
        }
    }
    
    public function deleteEvent($resourceId, $eventId)
    {
        // Soft delete
        $sql = "UPDATE bb_event SET active = 0 WHERE id = :event_id";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute(['event_id' => $eventId]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Trigger webhook
            $this->triggerWebhook('deleted', $resourceId, $eventId);
            
            return [
                'success' => true,
                'event_id' => $eventId,
                'message' => 'Event deleted successfully'
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Event not found'
            ];
        }
    }
    
    private function triggerWebhook($action, $resourceId, $eventId, $eventData = null)
    {
        $payload = [
            'action' => $action,
            'resource_id' => $resourceId,
            'event_id' => $eventId,
            'timestamp' => date('c'),
            'source' => 'booking_system'
        ];
        
        if ($eventData) {
            $payload['event'] = $eventData;
        }
        
        // Send async webhook (fire and forget)
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($payload),
                'timeout' => 5
            ]
        ]);
        
        @file_get_contents($this->bridgeWebhookUrl, false, $context);
    }
}
```

### Testing Your API

Use these curl commands to test your booking system API:

```bash
# Test resource listing
curl -H "Authorization: Bearer your_api_key" \
     http://your-booking-system/api/resources

# Test getting events
curl -H "Authorization: Bearer your_api_key" \
     "http://your-booking-system/api/resources/123/events?start_date=2025-06-14&end_date=2025-06-21"

# Test creating an event
curl -X POST -H "Authorization: Bearer your_api_key" \
     -H "Content-Type: application/json" \
     -d '{"title":"Test Meeting","start_time":"2025-06-15T10:00:00Z","end_time":"2025-06-15T11:00:00Z"}' \
     http://your-booking-system/api/resources/123/events
```

## 🔗 Resource Mapping Management

The bridge provides a comprehensive API to manage resource mappings between your booking system and calendar systems like Outlook.

### Resource Mapping Endpoints

#### **1. Get All Resource Mappings**
```http
GET /mappings/resources?bridge_from=booking_system&bridge_to=outlook&active_only=true
```

**Response:**
```json
{
  "success": true,
  "mappings": [
    {
      "id": 1,
      "bridge_from": "booking_system",
      "bridge_to": "outlook", 
      "resource_id": "123",
      "calendar_id": "room1@company.com",
      "calendar_name": "Conference Room 1",
      "sync_direction": "bidirectional",
      "is_active": true,
      "sync_enabled": true,
      "last_synced_at": "2025-06-14T10:00:00Z",
      "sync_freshness": "recent",
      "mapped_events": 5
    }
  ],
  "count": 1
}
```

#### **2. Create Resource Mapping**
```http
POST /mappings/resources
```

**Request Body:**
```json
{
  "bridge_from": "booking_system",
  "bridge_to": "outlook",
  "resource_id": "123",
  "calendar_id": "room1@company.com", 
  "calendar_name": "Conference Room 1",
  "sync_direction": "bidirectional"
}
```

**Response:**
```json
{
  "success": true,
  "mapping_id": 1,
  "message": "Resource mapping created successfully"
}
```

#### **3. Update Resource Mapping**
```http
PUT /mappings/resources/{id}
```

**Request Body:**
```json
{
  "calendar_name": "Updated Room Name",
  "sync_enabled": false
}
```

#### **4. Get Mapping by Resource ID**
```http
GET /mappings/resources/by-resource/{resourceId}?bridge_from=booking_system
```

This endpoint is particularly useful for your booking system to check if a resource is mapped before creating events:

```json
{
  "success": true,
  "resource_id": "123",
  "mappings": [
    {
      "id": 1,
      "bridge_to": "outlook",
      "calendar_id": "room1@company.com",
      "sync_direction": "bidirectional",
      "is_active": true
    }
  ],
  "count": 1
}
```

#### **5. Trigger Resource Sync**
```http
POST /mappings/resources/{id}/sync
```

**Response:**
```json
{
  "success": true,
  "mapping_id": 1,
  "message": "Resource sync queued successfully"
}
```

### Integration in Your Booking System

You can integrate resource mapping checks directly into your booking system:

```php
<?php
// Before creating/updating events, check for mappings
function getResourceMappings($resourceId) {
    $bridgeUrl = 'https://your-bridge.com';
    $url = "{$bridgeUrl}/mappings/resources/by-resource/{$resourceId}";
    
    $response = file_get_contents($url);
    $data = json_decode($response, true);
    
    return $data['success'] ? $data['mappings'] : [];
}

// Use in your event creation/update logic
function createOrUpdateEvent($resourceId, $eventData) {
    // Check if resource has calendar mappings
    $mappings = getResourceMappings($resourceId);
    
    if (!empty($mappings)) {
        // Resource is mapped - events will be synced automatically
        $eventData['bridge_import'] = true;
        $eventData['sync_mappings'] = $mappings;
    }
    
    // Create/update your event as normal
    $eventId = $this->createEvent($resourceId, $eventData);
    
    // Trigger bridge sync if mapped
    if (!empty($mappings)) {
        foreach ($mappings as $mapping) {
            $this->triggerBridgeSync($mapping['id']);
        }
    }
    
    return $eventId;
}

private function triggerBridgeSync($mappingId) {
    $bridgeUrl = 'https://your-bridge.com';
    $url = "{$bridgeUrl}/mappings/resources/{$mappingId}/sync";
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'timeout' => 5
        ]
    ]);
    
    @file_get_contents($url, false, $context);
}
```

### Testing Resource Mappings

```bash
# Create a resource mapping
curl -X POST http://localhost:8080/mappings/resources \
  -H "Content-Type: application/json" \
  -d '{
    "bridge_from": "booking_system",
    "bridge_to": "outlook", 
    "resource_id": "123",
    "calendar_id": "room1@company.com",
    "calendar_name": "Conference Room 1"
  }'

# Check mapping for a resource
curl http://localhost:8080/mappings/resources/by-resource/123

# Get all mappings
curl http://localhost:8080/mappings/resources

# Trigger sync for a mapping  
curl -X POST http://localhost:8080/mappings/resources/1/sync
```

## 🗑️ Deletion Sync Handling

The bridge system automatically handles event deletions from Outlook and syncs them to your booking system to maintain data consistency.

### **How Deletion Sync Works:**

1. **Webhook Detection**: When an Outlook event is deleted, Microsoft Graph sends a webhook notification
2. **Deletion Queue**: The bridge queues a deletion check to verify the event was actually deleted
3. **Verification**: The system attempts to fetch the event from Outlook to confirm deletion
4. **Sync Deletion**: If confirmed deleted, the corresponding booking system event is marked as inactive
5. **Cleanup**: The bridge mapping is removed to maintain clean data

### **Deletion Sync Endpoints:**

#### **Manual Deletion Sync**
```http
POST /bridges/sync-deletions
```

Manually check all recent mappings for deleted Outlook events:

```bash
curl -X POST http://localhost:8080/bridges/sync-deletions
```

**Response:**
```json
{
  "success": true,
  "message": "Deletion sync completed",
  "results": {
    "checked": 25,
    "deleted": 3,
    "errors": []
  }
}
```

#### **Process Deletion Queue**
```http
POST /bridges/process-deletion-queue
```

Process pending deletion checks from the webhook queue:

```bash
curl -X POST http://localhost:8080/bridges/process-deletion-queue
```

**Response:**
```json
{
  "success": true,
  "message": "Deletion queue processed", 
  "results": {
    "processed": 10,
    "deletions_found": 2,
    "errors": []
  }
}
```

### **Automatic Deletion Detection:**

The bridge automatically detects deletions through:

1. **Real-time Webhooks**: Microsoft Graph notifications trigger immediate deletion checks
2. **Regular Sync**: The `sync` operation compares source and target events and removes orphaned mappings
3. **Manual Verification**: You can trigger manual deletion checks for recent events

### **Booking System Deletion:**

When an Outlook event is deleted, the bridge:

1. **Soft Delete**: Sets `active = 0` in your booking system database
2. **API Delete**: Calls `DELETE /api/resources/{id}/events/{eventId}` if using REST API mode
3. **Mapping Cleanup**: Removes the bridge mapping to prevent orphaned data
4. **Audit Trail**: Logs the deletion operation in `bridge_sync_logs`

### **Monitoring Deletions:**

Check deletion sync activity:

```bash
# View recent deletion operations
curl "http://localhost:8080/bridges/health" | jq '.logs[] | select(.operation == "delete")'

# Check bridge mappings for consistency
curl "http://localhost:8080/mappings/resources?active_only=true"
```

This ensures your booking system stays in sync when events are deleted from Outlook calendars.

## 🚫 **Cancellation & Inactive Event Handling**

The bridge automatically handles when events become inactive in your booking system and need to be removed from Outlook calendars.

### **Use Case: Booking System Event Becomes Inactive**

**Scenario**: You create an event in your booking system, it syncs to Outlook, then you set the event to inactive (`active = 0`) and want the Outlook event deleted automatically.

**How it works:**

1. **Event Creation**: Event created in booking system → automatically synced to Outlook
2. **Set Inactive**: You set `bb_event.active = 0` in your booking system database
3. **Automatic Detection**: Bridge detects the inactive event during cancellation check
4. **Outlook Deletion**: Corresponding Outlook event is automatically deleted
5. **Mapping Cleanup**: Bridge mapping is updated to 'cancelled' status

### **🔧 Cancellation API Endpoints**

#### **Automatic Cancellation Detection**
```http
POST /bridges/sync-deletions
```

Scans for inactive events in booking system and deletes corresponding Outlook events:

```bash
curl -X POST http://localhost:8080/bridges/sync-deletions
```

Response:
```json
{
  "success": true,
  "message": "Cancellation detection completed",
  "results": {
    "detected": 3,
    "processed": 3,
    "cancelled_events": [
      {
        "id": "12345",
        "name": "Conference Room Meeting",
        "active": 0,
        "outlook_event_id": "AAMkAGI...",
        "mapping_id": "67890"
      }
    ],
    "errors": []
  }
}
```

#### **Manual Cancellation**
```http
DELETE /cancel/reservation/{reservationType}/{reservationId}/{resourceId}
```

Immediately cancel a specific reservation and delete its Outlook event:

```bash
curl -X DELETE http://localhost:8080/cancel/reservation/event/12345/67
```

#### **Check Reservation Status**
```http
GET /cancel/check/{reservationType}/{reservationId}
```

Check if a reservation is cancelled:

```bash
curl http://localhost:8080/cancel/check/event/12345
```

#### **Bulk Cancellation Processing**
```http
POST /cancel/bulk
```

Process multiple cancellations at once:

```bash
curl -X POST http://localhost:8080/cancel/bulk \
  -H "Content-Type: application/json" \
  -d '{
    "reservations": [
      {"type": "event", "id": 12345, "resource_id": 67},
      {"type": "event", "id": 12346, "resource_id": 67}
    ]
  }'
```

### **⚙️ Automated Bridge Processing**

Set up comprehensive automation for the bridge system with cron jobs:

```bash
# === CORE BRIDGE SYNCHRONIZATION ===
# Sync from booking system to Outlook every 5 minutes
*/5 * * * * curl -X POST http://localhost:8080/bridges/sync/booking_system/outlook \
  -H "Content-Type: application/json" -d '{"start_date":"$(date +%Y-%m-%d)","end_date":"$(date -d \"+7 days\" +%Y-%m-%d)"}'

# Sync from Outlook to booking system every 10 minutes  
*/10 * * * * curl -X POST http://localhost:8080/bridges/sync/outlook/booking_system \
  -H "Content-Type: application/json" -d '{"start_date":"$(date +%Y-%m-%d)","end_date":"$(date -d \"+7 days\" +%Y-%m-%d)"}'

# === DELETION & CANCELLATION PROCESSING ===
# Process deletion queue from webhooks every 5 minutes
*/5 * * * * curl -X POST http://localhost:8080/bridges/process-deletion-queue

# Detect and process cancellations (inactive events) every 5 minutes
*/5 * * * * curl -X POST http://localhost:8080/bridges/sync-deletions

# Manual deletion sync check every 30 minutes
*/30 * * * * curl -X POST http://localhost:8080/bridges/sync-deletions

# Alternative: Use the enhanced deletion processor script
*/5 * * * * /scripts/enhanced_process_deletions.sh

# === SYSTEM MONITORING ===
# Check bridge health every 10 minutes
*/10 * * * * curl -X GET http://localhost:8080/bridges/health

# Run comprehensive system health checks every 15 minutes
*/15 * * * * curl -X GET http://localhost:8080/health/system
```

**Production Cron Setup** (add to `/etc/cron.d/bridge-sync`):
```bash
# Generic Calendar Bridge - Production Automation
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

# Core sync operations
*/5 * * * * www-data curl -s -X POST "http://localhost/bridges/sync/booking_system/outlook" -H "Content-Type: application/json" -d '{"start_date":"$(date +%Y-%m-%d)","end_date":"$(date -d \"+7 days\" +%Y-%m-%d)"}' >/dev/null 2>&1
*/10 * * * * www-data curl -s -X POST "http://localhost/bridges/sync/outlook/booking_system" -H "Content-Type: application/json" -d '{"start_date":"$(date +%Y-%m-%d)","end_date":"$(date -d \"+7 days\" +%Y-%m-%d)"}' >/dev/null 2>&1

# Deletion and cancellation processing  
*/5 * * * * www-data curl -s -X POST "http://localhost/bridges/process-deletion-queue" >/dev/null 2>&1
*/5 * * * * www-data curl -s -X POST "http://localhost/bridges/sync-deletions" >/dev/null 2>&1

# System monitoring
*/10 * * * * www-data curl -s -X GET "http://localhost/bridges/health" >/dev/null 2>&1
# Run comprehensive system health checks every 15 minutes
*/15 * * * * www-data curl -s -X GET "http://localhost/health/system" >/dev/null 2>&1
```

### **📊 Monitoring Cancellations**

#### **Cancellation Statistics**
```bash
# Get cancellation stats
curl http://localhost:8080/cancel/stats
```

#### **View Cancelled Reservations**
```bash
# List recently cancelled reservations
curl http://localhost:8080/cancel/cancelled-reservations
```

#### **Detection Statistics**
```bash
# Get detection performance stats
curl http://localhost:8080/bridges/sync-deletionsion-stats
```

### **🔄 Re-enabling Events**

The system also handles when cancelled events are reactivated:

1. **Set Active**: Change `bb_event.active = 1` in booking system
2. **Detection**: Bridge detects the reactivated event
3. **Outlook Recreation**: Creates new Outlook event for the reactivated reservation
4. **Mapping Reset**: Resets mapping status from 'cancelled' to 'active'

```bash
# Detect and process re-enabled events
curl -X POST http://localhost:8080/bridges/sync-deletions-reenabled
```

### **🎯 Key Benefits**

- ✅ **Automatic**: No manual intervention needed for cancellations
- ✅ **Bidirectional**: Handles cancellations from both booking system and Outlook
- ✅ **Reliable**: Comprehensive error handling and retry mechanisms
- ✅ **Auditable**: Complete logging of all cancellation operations
- ✅ **Efficient**: Bulk processing for multiple cancellations
- ✅ **Reversible**: Supports re-enabling cancelled events

### **💡 Implementation Notes**

The cancellation system monitors these tables:
- `bb_event` - Events/reservations
- `bb_booking` - Bookings (if available)
- `bb_allocation` - Resource allocations (if available)

Events are considered cancelled when `active != 1` in these tables. The bridge maintains sync mappings in `outlook_calendar_mapping` and updates their status appropriately.

---

## 🚫 **Working Without Webhooks**

**Perfect for systems not reachable from the internet!**

The bridge system works excellently without webhooks using polling-based synchronization. This is ideal for:
- Internal networks behind firewalls
- Systems without public IP addresses  
- Development/testing environments
- High-security environments

### **✅ Full Functionality Without Webhooks:**

- **✅ Bidirectional Sync**: Complete event synchronization both ways
- **✅ Cancellation Detection**: Inactive events → Outlook deletion (your use case!)
- **✅ Real-time Performance**: 5-minute polling provides near-instant sync
- **✅ Reliability**: Often more reliable than webhook delivery
- **✅ No Configuration**: No firewall rules or public endpoints needed

### **🔧 Optimized Polling Configuration:**

The default cron jobs are already optimized for webhook-free operation:

```bash
# Current default (recommended)
*/5 * * * * curl -X POST http://localhost:8080/bridges/sync/booking_system/outlook
*/10 * * * * curl -X POST http://localhost:8080/bridges/sync/outlook/booking_system  
*/5 * * * * curl -X POST http://localhost:8080/bridges/sync-deletions

# For faster response (every 2 minutes)
*/2 * * * * curl -X POST http://localhost:8080/bridges/sync-deletions
```

### **🎯 Your Inactive Event Use Case:**

This works perfectly with polling:

1. **Set Event Inactive**: `UPDATE bb_event SET active = 0 WHERE id = 12345`
2. **Automatic Detection**: Within 5 minutes, cron job runs `/bridges/sync-deletions`
3. **Outlook Deletion**: Corresponding Outlook event automatically deleted
4. **No Webhooks Needed**: Pure polling-based detection

**See [WEBHOOK_FREE_OPERATION.md](WEBHOOK_FREE_OPERATION.md) for detailed configuration options.**

## 🔧 Microsoft Graph API Setup

To configure Outlook integration, you'll need to set up an application in Azure Active Directory:

#### **1. Create Azure AD Application**

1. Go to [Azure Portal](https://portal.azure.com) → **Azure Active Directory** → **App registrations**
2. Click **New registration**
3. Configure your application:
   - **Name**: `Outlook Calendar Bridge`
   - **Supported account types**: `Accounts in this organizational directory only`
   - **Redirect URI**: Leave blank (not needed for service-to-service)

#### **2. Get Required Credentials**

After creating the app, collect these values for your `.env` file:

- **OUTLOOK_CLIENT_ID**: Found on app's **Overview** page → **Application (client) ID**
- **OUTLOOK_TENANT_ID**: Found on app's **Overview** page → **Directory (tenant) ID**
- **OUTLOOK_CLIENT_SECRET**: 
  1. Go to **Certificates & secrets** → **Client secrets**
  2. Click **New client secret**
  3. Copy the **Value** (not the Secret ID)

#### **3. Configure API Permissions**

1. Go to **API permissions** → **Add a permission** → **Microsoft Graph** → **Application permissions**
2. Add these permissions:
   - `Calendars.ReadWrite` - Read and write calendars
   - `User.Read.All` - Read user profiles
   - `Group.Read.All` - Read group information and members
   - `Place.Read.All` - Read room and resource mailboxes

3. Click **Grant admin consent** for your organization

#### **4. Find Your OUTLOOK_GROUP_ID**

The `OUTLOOK_GROUP_ID` is used to discover room calendars from a specific Outlook distribution group:

**Option A: Use Graph Explorer**
1. Go to [Graph Explorer](https://developer.microsoft.com/en-us/graph/graph-explorer)
2. Sign in and run: `GET https://graph.microsoft.com/v1.0/groups`
3. Find your room calendars group and copy its `id`

**Option B: Use PowerShell**
```powershell
# Connect to Microsoft Graph
Connect-MgGraph -Scopes "Group.Read.All"

# List all groups to find your room calendars group
Get-MgGroup | Where-Object {$_.DisplayName -like "*room*"} | Select-Object DisplayName, Id

# Example output:
# DisplayName          Id
# -----------          --
# Room Calendars       12345678-1234-1234-1234-123456789abc
```

**Option C: Use Azure Portal**
1. Go to **Azure Active Directory** → **Groups**
2. Find your group containing room calendars
3. Click on the group → copy the **Object ID**

#### **5. Configure Calendar Discovery**

**With OUTLOOK_GROUP_ID** (Recommended for specific room groups):
```env
OUTLOOK_GROUP_ID=12345678-1234-1234-1234-123456789abc
```
- Bridge will discover calendars from group members
- Perfect for curated lists of room calendars
- Supports rooms, resources, and mailbox-enabled users

**Without OUTLOOK_GROUP_ID** (Default):
```env
# OUTLOOK_GROUP_ID=  # Leave empty or omit
```
- Bridge will use `/places/microsoft.graph.room` endpoint
- Discovers all room mailboxes in your tenant
- May include rooms you don't want to sync

#### **6. Test Your Configuration**

```bash
# Test calendar discovery
curl http://localhost:8080/bridges/outlook/calendars

# Expected response:
{
  "calendars": [
    {
      "id": "room1@company.com",
      "name": "Conference Room A",
      "email": "room1@company.com", 
      "type": "room",
      "bridge_type": "outlook"
    }
  ]
}
```

## 🔧 Configurable API Integration

The BookingSystemBridge supports **configurable API mappings** to adapt to any booking system's API structure without code changes. Configure endpoints, field mappings, and authentication through the bridge configuration.

#### **Configuration Options**

**Basic Configuration:**
```php
// In index.php or bridge registration
$manager->registerBridge('booking_system', \App\Bridge\BookingSystemBridge::class, [
    'api_base_url' => 'http://your-booking-system.com',
    'api_key' => 'your_api_key'
]);
```

**Advanced Configuration with Custom Mappings:**
```php
$manager->registerBridge('booking_system', \App\Bridge\BookingSystemBridge::class, [
    'api_base_url' => 'http://your-booking-system.com',
    'api_key' => 'your_api_key',
    
    // Custom API endpoint mappings
    'api_endpoints' => [
        'list_events' => [
            'method' => 'GET',
            'url' => '/api/v2/bookings',  // Your custom endpoint
            'params' => ['room_id', 'from_date', 'to_date', 'status' => 'active']
        ],
        'create_event' => [
            'method' => 'POST', 
            'url' => '/api/v2/bookings'
        ],
        'update_event' => [
            'method' => 'PATCH',  // Some systems use PATCH instead of PUT
            'url' => '/api/v2/bookings/{event_id}'
        ],
        'delete_event' => [
            'method' => 'DELETE',
            'url' => '/api/v2/bookings/{event_id}'
        ],
        'list_resources' => [
            'method' => 'GET',
            'url' => '/api/v2/rooms'
        ]
    ],
    
    // Custom field mappings
    'field_mappings' => [
        'to_booking_system' => [
            'subject' => 'booking_title',        // Bridge 'subject' → Your 'booking_title'
            'start' => 'start_datetime',         // Bridge 'start' → Your 'start_datetime'
            'end' => 'end_datetime',             // Bridge 'end' → Your 'end_datetime'
            'description' => 'notes',            // Bridge 'description' → Your 'notes'
            'organizer' => 'booked_by',          // Bridge 'organizer' → Your 'booked_by'
            'attendees' => 'participant_email'   // Bridge 'attendees' → Your 'participant_email'
        ],
        'from_booking_system' => [
            'booking_title' => 'subject',        // Your 'booking_title' → Bridge 'subject'
            'start_datetime' => 'start',         // Your 'start_datetime' → Bridge 'start'
            'end_datetime' => 'end',             // Your 'end_datetime' → Bridge 'end'
            'notes' => 'description',            // Your 'notes' → Bridge 'description'
            'booked_by' => 'organizer',          // Your 'booked_by' → Bridge 'organizer'
            'participant_email' => 'attendees'   // Your 'participant_email' → Bridge 'attendees'
        ]
    ],
    
    // Authentication configuration
    'auth' => [
        'type' => 'api_key',       // 'bearer', 'basic', 'api_key', 'header'
        'header' => 'X-API-Key',   // Custom header name
        'prefix' => ''             // No prefix for API key
    ]
]);
```

#### **Supported API Patterns**

**1. Different URL Structures:**
```php
// Default: /api/resources/{resource_id}/events
'url' => '/api/resources/{resource_id}/events'

// Alternative patterns:
'url' => '/api/bookings'                    // Flat structure
'url' => '/api/rooms/{resource_id}/events'  // Different naming
'url' => '/api/v2/calendar/{resource_id}'   // Versioned API
'url' => '/bookings/room/{resource_id}'     // No /api prefix
```

**2. Different HTTP Methods:**
```php
'update_event' => [
    'method' => 'PATCH',  // Instead of PUT
    'url' => '/api/bookings/{event_id}'
],
'delete_event' => [
    'method' => 'POST',   // Some systems use POST for deletion
    'url' => '/api/bookings/{event_id}/cancel'
]
```

**3. Custom Parameters:**
```php
'list_events' => [
    'method' => 'GET',
    'url' => '/api/bookings',
    'params' => [
        'room_id',           // Dynamic parameter (filled by bridge)
        'from_date',         // Dynamic parameter (filled by bridge) 
        'to_date',           // Dynamic parameter (filled by bridge)
        'format' => 'json',  // Static parameter
        'status' => 'active', // Static parameter
        'include' => 'details' // Static parameter
    ]
]
```

**4. Authentication Methods:**
```php
// Bearer token (default)
'auth' => ['type' => 'bearer', 'header' => 'Authorization', 'prefix' => 'Bearer ']

// API Key in header
'auth' => ['type' => 'api_key', 'header' => 'X-API-Key', 'prefix' => '']

// Custom header
'auth' => ['type' => 'header', 'header' => 'X-Auth-Token', 'prefix' => 'Token ']

// Basic authentication
'auth' => ['type' => 'basic']
```

#### **Real-World Examples**

**Example 1: Laravel-based Booking System**
```php
'api_endpoints' => [
    'list_events' => [
        'method' => 'GET',
        'url' => '/api/bookings',
        'params' => ['resource_id', 'start_date', 'end_date']
    ],
    'create_event' => [
        'method' => 'POST',
        'url' => '/api/bookings'
    ]
],
'field_mappings' => [
    'to_booking_system' => [
        'subject' => 'title',
        'start' => 'starts_at',
        'end' => 'ends_at',
        'organizer' => 'user_name'
    ],
    'from_booking_system' => [
        'title' => 'subject',
        'starts_at' => 'start',
        'ends_at' => 'end',
        'user_name' => 'organizer'
    ]
]
```

**Example 2: Legacy System with Different Structure**
```php
'api_endpoints' => [
    'list_events' => [
        'method' => 'GET',
        'url' => '/bookings/list',
        'params' => ['room' => 'resource_id', 'from', 'to', 'type' => 'calendar']
    ],
    'create_event' => [
        'method' => 'POST',
        'url' => '/bookings/new'
    ],
    'delete_event' => [
        'method' => 'POST',
        'url' => '/bookings/cancel/{event_id}'
    ]
],
'auth' => [
    'type' => 'header',
    'header' => 'X-Legacy-Token'
]
```

#### **Response Format Flexibility**

The bridge automatically handles different response formats:

```json
// Option 1: Wrapped in 'events' key (default)
{"events": [{"id": 1, "title": "Meeting"}]}

// Option 2: Wrapped in 'data' key  
{"data": [{"id": 1, "title": "Meeting"}]}

// Option 3: Direct array
[{"id": 1, "title": "Meeting"}]

// Option 4: Single object (for creates/updates)
{"event_id": 123, "status": "created"}
```

#### **Migration from Fixed API**

**Before (Fixed API):**
```php
// Bridge was hardcoded to expect:
// GET /api/resources/{id}/events
// Field: 'title' maps to 'subject'
```

**After (Configurable API):**
```php
// Bridge adapts to your existing API:
'api_endpoints' => [
    'list_events' => [
        'url' => '/your/existing/endpoint/{resource_id}'
    ]
],
'field_mappings' => [
    'from_booking_system' => [
        'your_title_field' => 'subject'
    ]
]
```

**This means you don't need to change your existing booking system API - just configure the bridge to match your API!**
