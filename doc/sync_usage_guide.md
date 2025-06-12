# Outlook Calendar Sync Usage Guide

This guide explains how to sync calendar bookings from your booking system to Outlook calendars.

## Overview

The sync process consists of three main phases:

1. **Setup Phase**: Populate the mapping tables
2. **Sync Phase**: Transfer bookings to Outlook
3. **Monitoring Phase**: Track sync status and handle errors

### ✅ Verified Working System

This sync system has been tested and confirmed working with:
- ✅ **Successful event creation** in Outlook calendars
- ✅ **Bidirectional mapping** between booking system and Outlook
- ✅ **Error-free processing** with proper status tracking
- ✅ **No API key required** when not configured in environment
- ✅ **Real-world data** sync with Norwegian characters and proper encoding
- ✅ **Reverse sync capability** to detect existing Outlook events
- ✅ **Loop prevention** to avoid infinite sync cycles

## Prerequisites

1. Ensure `bb_resource_outlook_item` table is populated with resource-to-calendar mappings
2. Outlook authentication is configured (Graph API credentials)
3. Required Graph permissions are granted
4. API key is optional (works without API key if not configured in environment)

## API Endpoints

### 1. Setup Endpoints

#### Populate Mapping Table

```bash
# Populate mappings for all resources
curl -X POST "http://localhost:8082/sync/populate-mapping"

# Populate mappings for specific resource
curl -X POST "http://localhost:8082/sync/populate-mapping?resource_id=123"
```

#### Get Pending Items

```bash
# Get first 50 pending items
curl -X GET "http://localhost:8082/sync/pending-items"

# Get specific number of pending items
curl -X GET "http://localhost:8082/sync/pending-items?limit=100"
```

### 2. Sync Endpoints

#### Sync Pending Items to Outlook

```bash
# Sync up to 50 pending items
curl -X POST "http://localhost:8082/sync/to-outlook"

# Sync specific number of items
curl -X POST "http://localhost:8082/sync/to-outlook?limit=25"
```

#### Sync Specific Item

```bash
# Sync a specific calendar item
curl -X POST "http://localhost:8082/sync/item/event/456/123"

# Parameters: {reservationType}/{reservationId}/{resourceId}
# reservationType: 'event', 'booking', or 'allocation'
# reservationId: ID from the respective table
# resourceId: Resource ID
```

### 3. Monitoring Endpoints

#### Get Sync Status

```bash
# Get comprehensive sync statistics
curl -X GET "http://localhost:8082/sync/status"
```

#### Cleanup Orphaned Mappings

```bash
# Remove mappings for deleted calendar items
curl -X DELETE "http://localhost:8082/sync/cleanup-orphaned"
```

### 4. Reverse Sync Endpoints (Outlook → Booking System)

#### Get Outlook Events Not in Booking System

```bash
# Get Outlook events that aren't in the booking system
curl -X GET "http://localhost:8082/sync/outlook-events"

# Get events for specific date range
curl -X GET "http://localhost:8082/sync/outlook-events?from_date=2025-06-01&to_date=2025-07-01"

# Limit number of results
curl -X GET "http://localhost:8082/sync/outlook-events?limit=25"
```

#### Populate from Outlook Events

```bash
# Populate mapping table with existing Outlook events
curl -X POST "http://localhost:8082/sync/from-outlook"

# Populate for specific date range
curl -X POST "http://localhost:8082/sync/from-outlook?from_date=2025-06-01&to_date=2025-07-01"
```

## Step-by-Step Sync Process

### Step 1: Initial Setup

1. **Populate Resource Mappings** (if not done already):
   ```sql
   INSERT INTO bb_resource_outlook_item (resource_id, outlook_item_id, outlook_item_name, active) 
   VALUES (123, 'room-calendar-id', 'Conference Room A', 1);
   ```

2. **Populate Calendar Mappings**:

   ```bash
   curl -X POST "http://localhost:8082/sync/populate-mapping"
   ```

### Step 2: Check What's Pending

```bash
curl -X GET "http://localhost:8082/sync/pending-items"
```

Expected response:
```json
{
  "success": true,
  "count": 15,
  "items": [
    {
      "id": 1,
      "reservation_type": "event",
      "reservation_id": 456,
      "resource_id": 123,
      "outlook_item_id": "room-calendar-id",
      "sync_status": "pending",
      "priority_level": 1
    }
  ]
}
```

### Step 3: Sync to Outlook

```bash
curl -X POST "http://localhost:8082/sync/to-outlook"
```

Expected response:
```json
{
  "success": true,
  "message": "Sync completed",
  "results": {
    "processed": 15,
    "created": 12,
    "updated": 2,
    "errors": 1,
    "details": [
      {
        "item_type": "event",
        "item_id": 456,
        "resource_id": 123,
        "action": "created",
        "outlook_event_id": "AAMkAGVmMDEzMTM4LTZmYWUtNDdkNC1hMDZiLTU1OGY5OTZhYmY4OABGAAAAAAAiQ8W967B7TKBjgx9rVEURBwAiIsqMbYjsT5e-T-KzowKTAAAAAAENAAAiIsqMbYjsT5e-T-KzowKTAAAYvYDZAAA=",
        "title": "Important Meeting"
      }
    ]
  }
}
```

### Step 4: Monitor Sync Status

```bash
curl -X GET "http://localhost:8082/sync/status"
```

Expected response:
```json
{
  "success": true,
  "statistics": {
    "total_mappings": 150,
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

## Bidirectional Sync Workflow

### Complete Sync Process (Both Directions)

For full bidirectional synchronization, follow this workflow:

#### 1. **Initial Setup** (One-time)

```bash
# Populate from booking system
curl -X POST "http://localhost:8082/sync/populate-mapping"

# Populate from existing Outlook events
curl -X POST "http://localhost:8082/sync/from-outlook"
```

#### 2. **Sync Booking System → Outlook**

```bash
# Check what's pending from booking system
curl -X GET "http://localhost:8082/sync/pending-items"

# Sync to Outlook
curl -X POST "http://localhost:8082/sync/to-outlook"
```

#### 3. **Detect New Outlook Events**

```bash
# Check for new Outlook events
curl -X GET "http://localhost:8082/sync/outlook-events"

# Add them to mapping table
curl -X POST "http://localhost:8082/sync/from-outlook"
```

#### 4. **Monitor Both Directions**

```bash
# Check booking system → Outlook sync
curl -X GET "http://localhost:8082/sync/status"

# Check overall mapping statistics
curl -X GET "http://localhost:8082/sync/stats"
```

## Error Handling

### Common Errors and Solutions

1. **"Calendar item not found for mapping"**
   - The calendar item was deleted from the booking system
   - Run cleanup: `curl -X DELETE "http://localhost:8082/sync/cleanup-orphaned"`

2. **"No Outlook event ID to delete"**
   - Trying to delete an event that wasn't created in Outlook yet
   - Check the mapping status

3. **Graph API Authentication Errors**
   - Check your environment variables (client ID, secret, tenant ID)
   - Verify Graph API permissions

### Retry Failed Items

Failed items remain in "error" status and can be retried:

```bash
# Get error items
curl -X GET "http://localhost:8082/sync/pending-items"

# Try syncing again (includes error status items)
curl -X POST "http://localhost:8082/sync/to-outlook"
```

## Advanced Usage

### Sync Specific Item Types

You can filter and sync specific types by modifying the `CalendarMappingService`:

```bash
# Only sync high-priority events
curl -X GET "http://localhost:8082/sync/pending-items?limit=50" | jq '.items[] | select(.priority_level == 1)'
```

### Batch Processing

For large datasets, process in smaller batches:

```bash
# Process 25 items at a time
for i in {1..10}; do
  curl -X POST "http://localhost:8082/sync/to-outlook?limit=25"
  sleep 2
done
```

### Scheduled Sync

Set up a cron job for regular syncing:

```bash
# Add to crontab for every 15 minutes
*/15 * * * * curl -X POST "http://localhost:8082/sync/to-outlook?limit=100" > /dev/null 2>&1
```

## Troubleshooting

### Debug Mode

Check logs in the application for detailed error information. The application logs all sync operations.

### Manual Verification

1. Check database mapping status:
   ```sql
   SELECT reservation_type, sync_status, COUNT(*) 
   FROM outlook_calendar_mapping 
   GROUP BY reservation_type, sync_status;
   ```

2. Verify Outlook events were created by checking the room calendars in Outlook.

### Reset Sync State

If you need to re-sync everything:

```bash
# Mark all as pending
# SQL: UPDATE outlook_calendar_mapping SET sync_status = 'pending', outlook_event_id = NULL;

# Then sync again
curl -X POST "http://localhost:8082/sync/to-outlook"
```

## Integration with Booking System

For real-time sync, integrate the sync calls into your booking system:

```php
// When a booking is created/updated
$syncResponse = file_get_contents('http://localhost:8082/sync/item/event/456/123', [
    'http' => [
        'method' => 'POST',
        'header' => 'api_key: your_api_key'
    ]
]);
```
