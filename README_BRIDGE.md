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
│   ├── Service/
│   │   └── BridgeManager.php               # Bridge orchestration
│   ├── Controller/
│   │   └── BridgeController.php            # REST API endpoints
│   └── Model/
├── database/
│   └── bridge_schema.sql                   # Database schema
├── config/
└── scripts/
    ├── setup_bridge_database.sh           # Database setup
    └── test_bridge.sh                     # Testing script
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
