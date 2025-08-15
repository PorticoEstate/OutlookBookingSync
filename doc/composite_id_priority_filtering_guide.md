# Composite ID System and Priority Filtering - Implementation Guide

This document provides comprehensive implementation details for the Composite ID System and Priority Filtering features in the OutlookBookingSync bridge system.

## Overview

The bridge system implements two critical features for enterprise calendar synchronization:

1. **Composite ID System** - Universal event identification across different calendar systems
2. **Priority Filtering System** - Intelligent conflict resolution for overlapping reservations

These features work together to provide robust, bidirectional synchronization with automatic conflict resolution.

## Composite ID System

### Purpose and Benefits

The Composite ID system solves the fundamental challenge of mapping events between different calendar systems that use different ID formats and addressing schemes.

**Key Benefits:**
- **Universal Identification**: Single ID format works across all calendar systems
- **Type Safety**: Preserves original event type information for proper API addressing  
- **Bidirectional Sync**: Seamless event updates in both sync directions
- **Conflict Prevention**: Prevents ID collisions between different event types
- **Audit Trail**: Clear tracking of event origins and relationships

### Composite ID Format

```
{type}_{original_id}
```

**Examples:**
- `event_78269` - Calendar event with original ID 78269
- `booking_123` - Booking reservation with original ID 123
- `allocation_456` - Resource allocation with original ID 456
- `meeting_789` - Meeting room booking with original ID 789

### Supported Event Types

| Type | Priority | Description | Example |
|------|----------|-------------|---------|
| `event` | 1 (Highest) | Standard calendar events | `event_78269` |
| `booking` | 2 | Booking system reservations | `booking_123` |
| `meeting` | 2 | Meeting room bookings | `meeting_789` |  
| `appointment` | 2 | Appointment entries | `appointment_101` |
| `allocation` | 3 (Lowest) | Resource allocation entries | `allocation_456` |

### Implementation Details

#### Bridge Mapping Storage

The `bridge_mappings` table stores relationships using composite IDs:

```sql
CREATE TABLE bridge_mappings (
    id SERIAL PRIMARY KEY,
    source_bridge VARCHAR(100) NOT NULL,
    source_id VARCHAR(255) NOT NULL,        -- Composite ID (e.g., "event_78269")
    target_bridge VARCHAR(100) NOT NULL,
    target_id VARCHAR(255) NOT NULL,        -- Target system ID (e.g., Graph API ID)
    last_synced_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- Example record
INSERT INTO bridge_mappings VALUES (
    1,
    'booking_system',
    'event_78269',                          -- Composite ID
    'outlook', 
    'AAMkAGU4NzE5ZGZjLTBhNzUtNDY0OS1i...',  -- Graph API ID
    NOW(),
    NOW(),
    NOW()
);
```

#### Composite ID Creation

```php
// BookingSystemBridge.php - Creating composite IDs
public function createCompositeId($originalId, $type)
{
    // Validate type
    $allowedTypes = ['event', 'booking', 'allocation', 'meeting', 'appointment'];
    if (!in_array($type, $allowedTypes)) {
        throw new \InvalidArgumentException("Invalid event type: {$type}");
    }
    
    // Create composite ID
    return $type . '_' . $originalId;
}

// Usage example
$compositeId = $this->createCompositeId(78269, 'event'); // Returns: "event_78269"
```

#### Composite ID Resolution

```php
// BookingSystemBridge.php - Resolving composite IDs  
public function resolveCompositeId($compositeId)
{
    if (strpos($compositeId, '_') === false) {
        // Legacy ID format - assume 'event' type
        return ['type' => 'event', 'id' => $compositeId];
    }
    
    $parts = explode('_', $compositeId, 2);
    if (count($parts) !== 2) {
        throw new \InvalidArgumentException("Invalid composite ID format: {$compositeId}");
    }
    
    return [
        'type' => $parts[0],
        'id' => $parts[1]
    ];
}

// Usage example
$resolved = $this->resolveCompositeId('event_78269');
// Returns: ['type' => 'event', 'id' => '78269']
```

### Bidirectional Sync Implementation

#### Booking System → Outlook Flow

```php
// 1. Event detected in booking system
$bookingSystemEvent = [
    'id' => 78269,
    'type' => 'event',
    'title' => 'Team Meeting',
    'start' => '2025-06-18T14:00:00Z',
    'end' => '2025-06-18T15:00:00Z'
];

// 2. Create composite ID
$compositeId = $this->createCompositeId($bookingSystemEvent['id'], $bookingSystemEvent['type']);
// Result: "event_78269"

// 3. Sync to Outlook
$outlookEventId = $outlookBridge->createEvent($calendarId, $bookingSystemEvent);
// Result: "AAMkAGU4NzE5ZGZjLT..."

// 4. Store mapping
$this->storeBridgeMapping([
    'source_bridge' => 'booking_system',
    'source_id' => $compositeId,                    // "event_78269"
    'target_bridge' => 'outlook',
    'target_id' => $outlookEventId                  // Graph API ID
]);
```

#### Outlook → Booking System Flow

```php
// 1. Event modified in Outlook
$outlookEventId = 'AAMkAGU4NzE5ZGZjLT...';

// 2. Find mapping using Outlook event ID
$mapping = $this->findMappingByTargetId($outlookEventId);
// Returns: ['source_id' => 'event_78269', 'target_id' => '...']

// 3. Resolve composite ID
$resolved = $this->resolveCompositeId($mapping['source_id']);
// Returns: ['type' => 'event', 'id' => '78269']

// 4. Update booking system using original ID and type
$success = $this->updateBookingSystemEvent($resolved['type'], $resolved['id'], $updatedEventData);
// Updates event ID 78269 in booking system using correct API endpoint
```

## Priority Filtering System

### Purpose and Benefits

The Priority Filtering system handles overlapping reservations across different calendar systems by automatically selecting the highest priority event for synchronization.

**Key Benefits:**
- **Automatic Conflict Resolution**: No manual intervention required
- **Configurable Priorities**: Adjust priority levels per deployment
- **Comprehensive Logging**: Full audit trail of filtering decisions
- **Performance Optimized**: Minimal overhead during sync operations
- **Resource Protection**: Prevents double-booking scenarios

### Priority Hierarchy

| Priority | Event Type | Description | Sync Behavior |
|----------|------------|-------------|---------------|
| 1 | Event | Standard calendar events | **Always synced** |
| 2 | Booking | Booking system reservations | Synced if no higher priority |
| 2 | Meeting | Meeting room bookings | Synced if no higher priority |
| 2 | Appointment | Appointment entries | Synced if no higher priority |
| 3 | Allocation | Resource allocation entries | **Lowest priority** |

### Implementation Details

#### Priority Assignment Logic

```php
// BookingSystemBridge.php - Priority assignment
private function getEventPriority($eventType)
{
    $priorities = [
        'event' => 1,      // Highest priority
        'booking' => 2,
        'meeting' => 2, 
        'appointment' => 2,
        'allocation' => 3   // Lowest priority
    ];
    
    return $priorities[$eventType] ?? 2; // Default to priority 2
}

// Usage example
$priority = $this->getEventPriority('event');     // Returns: 1
$priority = $this->getEventPriority('booking');   // Returns: 2
$priority = $this->getEventPriority('allocation'); // Returns: 3
```

#### Conflict Detection and Resolution

```php
// BookingSystemBridge.php - Priority filtering implementation
public function filterEventsByPriority($events, $resourceId, $timeSlot)
{
    $groupedEvents = [];
    $filteredEvents = [];
    
    // Group events by time slot and resource
    foreach ($events as $event) {
        $key = $resourceId . '_' . $timeSlot;
        if (!isset($groupedEvents[$key])) {
            $groupedEvents[$key] = [];
        }
        $groupedEvents[$key][] = $event;
    }
    
    // Apply priority filtering to each group
    foreach ($groupedEvents as $key => $eventGroup) {
        if (count($eventGroup) <= 1) {
            // No conflicts - include all events
            $filteredEvents = array_merge($filteredEvents, $eventGroup);
        } else {
            // Multiple events - apply priority filtering
            $selectedEvent = $this->selectHighestPriorityEvent($eventGroup);
            $filteredEvents[] = $selectedEvent;
            
            // Log filtered events
            foreach ($eventGroup as $event) {
                if ($event['composite_id'] !== $selectedEvent['composite_id']) {
                    $this->logFilteredEvent($event, $selectedEvent, 'priority_conflict');
                }
            }
        }
    }
    
    return $filteredEvents;
}

private function selectHighestPriorityEvent($events)
{
    usort($events, function($a, $b) {
        $priorityA = $this->getEventPriorityFromCompositeId($a['composite_id']);
        $priorityB = $this->getEventPriorityFromCompositeId($b['composite_id']);
        
        // Lower priority number = higher priority (1 > 2 > 3)
        return $priorityA - $priorityB;
    });
    
    return $events[0]; // Return highest priority event
}
```

#### Filtering Example Scenario

```php
// Example: Three overlapping events for Conference Room A at 14:00-15:00

$events = [
    [
        'composite_id' => 'allocation_456',
        'type' => 'allocation',
        'priority' => 3,
        'title' => 'Room Setup',
        'start' => '2025-06-18T14:00:00Z',
        'end' => '2025-06-18T15:00:00Z'
    ],
    [
        'composite_id' => 'booking_123', 
        'type' => 'booking',
        'priority' => 2,
        'title' => 'Client Meeting',
        'start' => '2025-06-18T14:00:00Z',
        'end' => '2025-06-18T15:00:00Z'
    ],
    [
        'composite_id' => 'event_78269',
        'type' => 'event', 
        'priority' => 1,
        'title' => 'Board Meeting',
        'start' => '2025-06-18T14:00:00Z',
        'end' => '2025-06-18T15:00:00Z'
    ]
];

// Apply priority filtering
$filtered = $this->filterEventsByPriority($events, 'room_123', '14:00-15:00');

// Result: Only event_78269 (priority 1) is selected for sync
// booking_123 and allocation_456 are logged as filtered events
```

### Monitoring and Logging

#### Priority Filtering Logs

```sql
-- Priority filtering audit table
CREATE TABLE bridge_priority_logs (
    id SERIAL PRIMARY KEY,
    resource_id VARCHAR(255) NOT NULL,
    time_slot VARCHAR(100) NOT NULL,
    selected_event_id VARCHAR(255) NOT NULL,
    selected_priority INTEGER NOT NULL,
    filtered_events JSONB NOT NULL,
    resolution_reason TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- Example log entry
INSERT INTO bridge_priority_logs VALUES (
    1,
    'room_123',
    '2025-06-18T14:00:00Z - 2025-06-18T15:00:00Z',
    'event_78269',
    1,
    '[
        {
            "composite_id": "booking_123",
            "priority": 2,
            "reason": "Lower priority than selected event"
        },
        {
            "composite_id": "allocation_456", 
            "priority": 3,
            "reason": "Lower priority than selected event"
        }
    ]',
    'Priority-based conflict resolution',
    NOW()
);
```

#### API Endpoints for Monitoring

```bash
# Get priority filtering statistics
curl -X GET -H "api_key: your_key" -H "X-Tenant-Id: tenantA" "http://localhost:8082/monitoring/priority-filtering"

# Response example
{
  "success": true,
  "filtering_stats": {
    "total_operations_24h": 48,
    "operations_with_conflicts": 12,
    "conflicts_resolved": 37,
    "filtering_effectiveness": "92.5%",
    "priority_breakdown": {
      "priority_1_selected": 25,
      "priority_2_selected": 8, 
      "priority_3_selected": 3
    }
  }
}

# Get current priority conflicts
curl -X GET -H "api_key: your_key" -H "X-Tenant-Id: tenantA" "http://localhost:8082/monitoring/priority-conflicts"

# Response shows active conflicts and resolutions
{
  "success": true,
  "active_conflicts": [
    {
      "resource_id": "room_123",
      "time_slot": "2025-06-18T14:00:00Z to 2025-06-18T15:00:00Z",
      "selected_event": {
        "composite_id": "event_78269",
        "priority": 1
      },
      "filtered_events": [
        {
          "composite_id": "booking_123",
          "priority": 2
        }
      ]
    }
  ]
}
```

## Configuration

### Environment Variables

```env
# Composite ID System Configuration
ENABLE_COMPOSITE_IDS=true
DEFAULT_EVENT_TYPE=event
COMPOSITE_ID_VALIDATION=strict

# Priority Filtering Configuration  
ENABLE_PRIORITY_FILTERING=true
PRIORITY_EVENT=1
PRIORITY_BOOKING=2
PRIORITY_MEETING=2
PRIORITY_APPOINTMENT=2
PRIORITY_ALLOCATION=3

# Logging Configuration
LOG_PRIORITY_CONFLICTS=true
LOG_COMPOSITE_ID_OPERATIONS=true
PRIORITY_LOG_RETENTION_DAYS=30
```

### Bridge Configuration

```php
// config/bridge_config.php
return [
    'composite_ids' => [
        'enabled' => true,
        'default_type' => 'event',
        'allowed_types' => ['event', 'booking', 'allocation', 'meeting', 'appointment'],
        'validation' => 'strict'
    ],
    
    'priority_filtering' => [
        'enabled' => true,
        'priorities' => [
            'event' => 1,
            'booking' => 2,
            'meeting' => 2,
            'appointment' => 2,
            'allocation' => 3
        ],
        'log_conflicts' => true,
        'performance_monitoring' => true
    ]
];
```

## Best Practices

### Composite ID Usage

1. **Always Use Composite IDs**: Ensure all new events use composite ID format
2. **Type Validation**: Validate event types before creating composite IDs
3. **Migration Strategy**: Plan migration path for existing non-composite IDs
4. **Error Handling**: Implement fallback for malformed composite IDs

### Priority Filtering

1. **Document Priority Levels**: Clearly document priority hierarchy for your organization
2. **Monitor Conflicts**: Regularly review priority filtering logs
3. **Performance Optimization**: Monitor filtering overhead during high-volume periods
4. **Customization**: Adjust priorities based on business requirements

### Testing and Validation

```bash
# Test composite ID creation and resolution
curl -X POST -H "api_key: your_key" -H "X-Tenant-Id: tenantA" "http://localhost:8082/test/composite-id" \
  -H "Content-Type: application/json" \
  -d '{
    "original_id": "78269",
    "event_type": "event"
  }'

# Test priority filtering with mock conflicts
curl -X POST -H "api_key: your_key" -H "X-Tenant-Id: tenantA" "http://localhost:8082/test/priority-filtering" \
  -H "Content-Type: application/json" \
  -d '{
    "events": [
      {"composite_id": "event_1", "priority": 1},
      {"composite_id": "booking_2", "priority": 2},
      {"composite_id": "allocation_3", "priority": 3}
    ]
  }'
```

## Troubleshooting

### Common Issues

#### Composite ID Problems
- **Malformed IDs**: Check ID format matches `{type}_{id}` pattern
- **Type Validation Errors**: Ensure event type is in allowed types list
- **Resolution Failures**: Verify composite ID exists in mapping table

#### Priority Filtering Issues
- **Unexpected Filtering**: Review priority configuration and event types
- **Performance Impact**: Monitor filtering operation timing
- **Missing Conflicts**: Check time slot grouping logic

### Debug Commands

```bash
# Debug composite ID system
curl -X GET -H "api_key: your_key" -H "X-Tenant-Id: tenantA" "http://localhost:8082/debug/composite-ids"

# Debug priority filtering
curl -X GET -H "api_key: your_key" -H "X-Tenant-Id: tenantA" "http://localhost:8082/debug/priority-filtering"

# Validate specific composite ID
curl -X GET -H "api_key: your_key" -H "X-Tenant-Id: tenantA" "http://localhost:8082/debug/composite-id/event_78269"
```

## Conclusion

The Composite ID System and Priority Filtering implementation provides enterprise-grade calendar synchronization with automatic conflict resolution. These systems work together to ensure reliable, bidirectional sync operations while maintaining data integrity and preventing conflicts across different calendar systems.

For additional support or customization requirements, refer to the main bridge architecture documentation or contact the development team.
