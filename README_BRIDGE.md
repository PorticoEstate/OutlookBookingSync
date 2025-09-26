# Generic Calendar Bridge

A flexible, extensible calendar synchronization service that acts as middleware between different calendar systems using REST APIs. Built with PHP/Slim4, this bridge can synchronize events between any calendar systems that support REST API communication.

## 🎯 Overview

The Generic Calendar Bridge transforms calendar synchronization from a single-purpose solution into a universal platform that can connect any calendar system to any other. It provides:

- **Universal Bridge Pattern**: Extensible architecture supporting any calendar system
- **REST API Communication**: Standard HTTP/REST interfaces for all integrations  
- **Self-Hosted Solution**: Full control and customization for organizations
- **Production Ready**: Enterprise-grade reliability and monitoring with comprehensive sync status tracking
- **Developer Friendly**: Easy to extend with new calendar system adapters
- **Sync Status Management**: Real-time monitoring, error recovery, and retry mechanisms

## 📘 Documentation

Core docs live under `doc/`:

| Topic | File |
|-------|------|
| Architecture & Concepts | `doc/architecture.md` |
| Usage Flows | `doc/usage.md` |
| Configuration (Env Vars) | `doc/configuration.md` |
| Operations & Monitoring | `doc/operations.md` |
| Development Quickstart | `doc/development.md` |
| API Endpoints (Canonical) | `doc/api_endpoints.md` |
| Multi-Tenancy | `doc/multi_tenancy.md` |
| Security Hardening | `doc/security_hardening.md` |
| Changelog | `CHANGELOG.md` |

## 🔗 Booking System Integration

The detailed booking system adapter specification (required endpoints, webhook contract, polling operation, resource mapping workflow, deletions/cancellations & reactivation handling) has moved to:

`doc/booking_system_adapter.md`


### Manual Testing

```bash
# Test bridge health
curl -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/health

# Test calendar discovery
curl -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/outlook/calendars

# Test dry run sync
curl -X POST -H "Content-Type: application/json" -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" \
  http://localhost:8082/bridges/sync/outlook/booking_system \
  -d '{"source_calendar_id": "room@company.com", "target_calendar_id": "123", "dry_run": true}'
```

## 🚀 Production Deployment

```yaml
# Enable Xdebug for a dev build:
# docker compose build --build-arg ENABLE_XDEBUG=true
services:
  portico_outlook:
    container_name: portico_outlook
    hostname: portico_outlook
    build:
        context: .
        dockerfile: Dockerfile
        args:
           http_proxy: ${http_proxy}
           https_proxy: ${https_proxy}
    ports:
      - "8082:80"
    volumes:
      - .:/var/www/html
    environment:
      - APACHE_RUN_USER=www-data
      - APACHE_RUN_GROUP=www-data
    env_file:
      - .env.compose
    depends_on: []
    extra_hosts:
      - "host.docker.internal:host-gateway"
    networks:
      - portico_internal

networks:
  portico_internal:
    external: true  # Reference the existing external network
```

**Key Configuration Notes:**

- **Port**: Service runs on `8082:80` (not 8080)
- **Environment**: Uses `.env.compose` file for configuration
- **Network**: Uses external `portico_internal` network
- **Volumes**: Development setup with live code mounting
- **Proxy Support**: HTTP/HTTPS proxy args for corporate environments
- **No Database**: Uses external database (configured in .env.compose)

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

## 📈 Roadmap & Changes

See `CHANGELOG.md` for dated changes. Future feature ideas (non-exhaustive): Google/CalDAV bridges, advanced conflict policies, plugin system, analytics, tracing.

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

All protected endpoints require an API key sent as header `X-API-Key`.

Examples:

```http
GET /bridges/health
X-API-Key: your_api_key
```


```bash
curl -H "X-API-Key: your_api_key" http://localhost:8082/bridges/health
curl -X POST -H "Content-Type: application/json" -H "X-API-Key: your_api_key" \
  -d '{"start_date":"2025-06-14","end_date":"2025-06-21"}' \
  http://localhost:8082/bridges/sync/outlook/booking_system
```

Dashboard usage:

- Open /dashboard. You’ll be prompted for the API key once; it’s stored in your browser (localStorage).
- To update the key later, press Ctrl+K on the dashboard.
- The dashboard automatically includes the `X-API-Key` header on all API calls.

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
        $sql = "SELECT id, name, description FROM your_resource_table WHERE active = 1";
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
            FROM your_event_table e
            JOIN your_event_resource_table er ON e.id = er.event_id  
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
                INSERT INTO your_event_table (name, description, start_time, end_time, 
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
            $sql = "INSERT INTO your_event_resource_table (event_id, resource_id) VALUES (?, ?)";
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
            UPDATE your_event_table SET
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
        $sql = "UPDATE your_event_table SET active = 0 WHERE id = :event_id";
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

Use these curl commands to test the bridge endpoints (replace with your values). If you’re testing your own booking system API, use whatever auth your API requires; the bridge itself uses `X-API-Key` header.


```bash
# Test resource listing
curl -H "X-API-Key: your_api_key" -H "X-Tenant-Id: tenantA" \
  http://localhost:8082/bridges/outlook/available-resources

# Test getting events
curl -H "X-API-Key: your_api_key" -H "X-Tenant-Id: tenantA" \
  "http://localhost:8082/bridges/outlook/resources/room1@company.com/calendar-items?startDate=2025-06-14&endDate=2025-06-21"

# Test creating an event
curl -X POST -H "X-API-Key: your_api_key" -H "X-Tenant-Id: tenantA" \
  -H "Content-Type: application/json" \
  -d '{"source_calendar_id":"room1@company.com","target_calendar_id":"123","start_date":"2025-06-14","end_date":"2025-06-21"}' \
  http://localhost:8082/bridges/sync/outlook/booking_system
```

## 🔧 Resource Mapping Management

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
  "source_calendar_id": "123",
  "target_calendar_id": "room1@company.com",
  "source_calendar_name": "Room 123",
  "target_calendar_name": "Conference Room 1",
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
  "source_calendar_id": "123",
  "target_calendar_id": "room1@company.com", 
  "source_calendar_name": "Room 123",
  "target_calendar_name": "Conference Room 1",
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
  "source_calendar_id": "123",
  "mappings": [
    {
      "id": 1,
      "bridge_to": "outlook",
      "target_calendar_id": "room1@company.com",
      "sync_direction": "bidirectional",
      "is_active": true
    }
  ],
  "count": 1
}
```

#### Sync Direction and Ownership Model

The `sync_direction` field controls **event ownership** and determines which bridge has authority over events:

**🏆 Ownership-Based Sync Directions:**

- **`sync_direction = "source_to_target"`** - **Source owns events**
  - Source bridge can create, update, delete events
  - Target bridge is read-only (events are pushed TO it)
  - If target event deleted externally → Source recreates it
  - Use case: Booking system authoritative, Outlook display-only

- **`sync_direction = "target_to_source"`** - **Target owns events**  
  - Target bridge can create, update, delete events
  - Source bridge is read-only (events are pushed FROM target)
  - If source event deleted externally → Target recreates it
  - Use case: Outlook authoritative, booking system display-only

- **`sync_direction = "bidirectional"`** - **Shared ownership**
  - Both bridges have equal ownership and can modify events
  - True two-way collaboration between systems
  - Configurable deletion handling via `respect_target_deletions` option
  - Use case: Equal partnership between calendar systems

**📋 Configuration Examples:**

For most cases, use a single mapping row per calendar pair:

- `bridge_from = booking_system`, `bridge_to = outlook`
- `source_calendar_id` = booking resource ID  
- `target_calendar_id` = Outlook calendar address/ID
- `sync_direction` = ownership model (see above)

Create mapping (tenant-scoped):

```http
POST /mappings/resources
X-Tenant-Id: tenantA
X-API-Key: <tenant-or-admin-key>
Content-Type: application/json

{
  "bridge_from": "booking_system",
  "bridge_to": "outlook", 
  "source_calendar_id": "room_123",
  "target_calendar_id": "conference-room-a@company.com",
  "sync_direction": "source_to_target"
}
```

**🔄 Triggering Sync Operations:**

The sync direction ($sourceBridge → $targetBridge) is controlled by endpoint parameters, while ownership is controlled by the mapping's `sync_direction` field:

- **Booking → Outlook**: `POST /bridges/sync/booking_system/outlook`
  - Respects ownership model from mapping configuration
  - May skip operations if ownership policy forbids them

- **Outlook → Booking**: `POST /bridges/sync/outlook/booking_system`  
  - Respects ownership model from mapping configuration
  - May skip operations if ownership policy forbids them

**⚠️ Ownership Enforcement:**

- Non-owner bridges cannot modify events (operations skipped with `ownership_policy_violation`)
- Owner bridges automatically recreate events deleted on non-owner side
- All ownership actions are logged for transparency

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
curl -X POST -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/mappings/resources \
  -H "Content-Type: application/json" \
  -d '{
    "bridge_from": "booking_system",
    "bridge_to": "outlook", 
    "resource_id": "123",
    "calendar_id": "room1@company.com",
    "calendar_name": "Conference Room 1"
  }'

# Check mapping for a resource
curl -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/mappings/resources/by-resource/123

# Get all mappings
curl -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/mappings/resources

# Trigger sync for a mapping  
curl -X POST -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/mappings/resources/1/sync
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
curl -X POST -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync-deletions
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
curl -X POST -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/process-deletion-queue
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
curl -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" "http://localhost:8082/bridges/health" | jq '.logs[] | select(.operation == "delete")'

# Check bridge mappings for consistency
curl -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" "http://localhost:8082/mappings/resources?active_only=true"
```

This ensures your booking system stays in sync when events are deleted from Outlook calendars.

## 🚫 **Cancellation & Inactive Event Handling**

The bridge automatically handles when events become inactive in your booking system and need to be removed from Outlook calendars.

### **Use Case: Booking System Event Becomes Inactive**

**Scenario**: You create an event in your booking system, it syncs to Outlook, then you set the event to inactive (`active = 0`) and want the Outlook event deleted automatically.

**How it works:**

1. **Event Creation**: Event created in booking system → automatically synced to Outlook
2. **Set Inactive**: You set `your_event_table.active = 0` in your booking system database
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
curl -X POST -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync-deletions
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

> Note: Direct legacy cancellation endpoints are deprecated and removed. Use POST `/bridges/sync-deletions` to detect and process cancellations, and GET `/bridges/cancelled-events[/{bridge}]` and `/bridges/sync-stats[/{bridge}]` to monitor results.

### **⚙️ Automated Bridge Processing**

Set up comprehensive automation for the bridge system with cron jobs:

```bash
# === CORE BRIDGE SYNCHRONIZATION ===
# Sync from booking system to Outlook every 5 minutes
*/5 * * * * curl -X POST -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync/booking_system/outlook \
  -H "Content-Type: application/json" -d '{"start_date":"$(date +%Y-%m-%d)","end_date":"$(date -d \"+7 days\" +%Y-%m-%d)"}'

# Sync from Outlook to booking system every 10 minutes  
*/10 * * * * curl -X POST -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync/outlook/booking_system \
  -H "Content-Type: application/json" -d '{"start_date":"$(date +%Y-%m-%d)","end_date":"$(date -d \"+7 days\" +%Y-%m-%d)"}'

# === DELETION & CANCELLATION PROCESSING ===
# Process deletion queue from webhooks every 5 minutes
*/5 * * * * curl -X POST -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/process-deletion-queue

# Detect and process cancellations (inactive events) every 5 minutes
*/5 * * * * curl -X POST -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync-deletions

# Manual deletion sync check every 30 minutes
*/30 * * * * curl -X POST -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync-deletions

# Alternative: Use the enhanced deletion processor script
*/5 * * * * /scripts/enhanced_process_deletions.sh

# === SYSTEM MONITORING ===
# Check bridge health every 10 minutes
*/10 * * * * curl -X GET -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/health

# Run comprehensive system health checks every 15 minutes
*/15 * * * * curl -X GET -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/health/system
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
# Get cancellation/sync stats
curl -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync-stats
curl -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync-stats/outlook
```

#### **View Cancelled Events**

```bash
# List recently cancelled events
curl -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/cancelled-events
curl -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/cancelled-events/outlook
```

#### **Manual Processing Triggers**

```bash
# Detect deletions and process webhook-driven queue
curl -X POST -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync-deletions
curl -X POST -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/process-deletion-queue
```

### **🔄 Re-enabling Events**

The system also handles when cancelled events are reactivated:

1. **Set Active**: Change `your_event_table.active = 1` in booking system
2. **Detection**: Bridge detects the reactivated event
3. **Outlook Recreation**: Creates new Outlook event for the reactivated reservation
4. **Mapping Reset**: Resets mapping status from 'cancelled' to 'active'

```bash
# Detect and process re-enabled events
curl -X POST -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync-deletions-reenabled
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

- `your_event_table` - Events/reservations
- `your_booking_table` - Bookings (if available)
- `your_allocation_table` - Resource allocations (if available)

Events are considered cancelled when `active != 1` in these tables. The bridge maintains sync mappings in `bridge_mappings` and updates their status appropriately.

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
*/5 * * * * curl -X POST -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync/booking_system/outlook
*/10 * * * * curl -X POST -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync/outlook/booking_system  
*/5 * * * * curl -X POST -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync-deletions

# For faster response (every 2 minutes)
*/2 * * * * curl -X POST -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync-deletions
```

### **🎯 Your Inactive Event Use Case:**

This works perfectly with polling:

1. **Set Event Inactive**: `UPDATE your_event_table SET active = 0 WHERE id = 12345`
2. **Automatic Detection**: Within 5 minutes, cron job runs `/bridges/sync-deletions`
3. **Outlook Deletion**: Corresponding Outlook event automatically deleted
4. **No Webhooks Needed**: Pure polling-based detection

For webhook-free deployment patterns see polling guidance in `doc/operations.md` (section: Webhook-Free Operation).

## 🔧 Microsoft Graph API Setup

To configure Outlook integration, you'll need to set up an application in Azure Active Directory:

### **1. Create Azure AD Application**

1. Go to [Azure Portal](https://portal.azure.com) → **Azure Active Directory** → **App registrations**
2. Click **New registration**
3. Configure your application:
   - **Name**: `Outlook Calendar Bridge`
   - **Supported account types**: `Accounts in this organizational directory only`
   - **Redirect URI**: Leave blank (not needed for service-to-service)

#### **2. Get Required Credentials**

After creating the app, collect these values for your `.env` file:

- These values are captured per tenant via the Admin API, not via environment variables:
  - Client ID (Application ID)
  - Tenant ID (Directory ID)
  - Client Secret
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

#### Option A: Use Graph Explorer

1. Go to [Graph Explorer](https://developer.microsoft.com/en-us/graph/graph-explorer)
2. Sign in and run: `GET https://graph.microsoft.com/v1.0/groups`
3. Find your room calendars group and copy its `id`

#### Option B: Use PowerShell

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

#### Option C: Use Azure Portal

1. Go to **Azure Active Directory** → **Groups**
2. Find your group containing room calendars
3. Click on the group → copy the **Object ID**

#### **5. Configure Calendar Discovery**

With OUTLOOK_GROUP_ID (recommended for specific room groups):

```env
OUTLOOK_GROUP_ID=12345678-1234-1234-1234-123456789abc
```

- Bridge will discover calendars from group members
- Perfect for curated lists of room calendars
- Supports rooms, resources, and mailbox-enabled users

Without OUTLOOK_GROUP_ID (default):

```env
# OUTLOOK_GROUP_ID=  # Leave empty or omit
```

- Bridge will use `/places/microsoft.graph.room` endpoint
- Discovers all room mailboxes in your tenant
- May include rooms you don't want to sync

#### **6. Test Your Configuration**

```bash
# Test calendar discovery
curl -H "X-API-Key: your_key" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/outlook/calendars

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
