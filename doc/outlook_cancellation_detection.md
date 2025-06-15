# Bridge-Based Deletion & Cancellation Detection

This document explains how the bridge architecture handles event cancellation and deletion detection between calendar systems. The bridge system provides robust bidirectional deletion sync capabilities.

## Overview

The bridge system provides **automatic deletion/cancellation sync** through:

1. **🔄 Real-time Webhooks** - Instant notifications from Microsoft Graph
2. **📊 Bridge Polling** - Periodic checking via bridge endpoints
3. **🎯 Queue Processing** - Reliable async deletion handling

## Bridge Architecture Benefits

- **Universal**: Works with any calendar system that implements the bridge interface
- **Bidirectional**: Handles deletions/cancellations in both directions
- **Reliable**: Queue-based processing with error handling and retry logic
- **Extensible**: Easy to add new calendar systems without changing core logic

### Prerequisites

1. **Microsoft Graph Permissions**: Ensure your app registration has these permissions:
   ```
   Calendars.ReadWrite.All
   Calendars.Read.Shared
   Calendars.ReadWrite.Shared
   ```

2. **Public Webhook Endpoint**: Your server must be accessible from the internet for Microsoft to send notifications.

3. **Environment Variables**: Add these to your `.env` file:
   ```bash
   WEBHOOK_BASE_URL=https://your-server.com
   WEBHOOK_CLIENT_SECRET=your-secret-key-for-validation
   ```

### Setup Webhooks

#### 1. Create Webhook Subscriptions

```bash
# Create webhook subscriptions for all room calendars
curl -X POST "http://localhost:8082/webhook/create-subscriptions"
```

**Expected Response:**
```json
{
  "success": true,
  "message": "Webhook subscriptions created successfully",
  "subscriptions_created": 5,
  "subscriptions": [
    {
      "calendar_id": "room1@company.com",
      "resource_id": 431,
      "subscription_id": "abcd-1234-efgh-5678",
      "expires_at": "2025-06-16 15:30:00"
    }
  ],
  "errors": []
}
```

#### 2. Monitor Webhook Health

```bash
# Get webhook subscription statistics
curl -X GET "http://localhost:8082/webhook/stats"
```

#### 3. Renew Expiring Subscriptions

Microsoft Graph subscriptions expire every 3 days. Set up automatic renewal:

```bash
# Renew expiring subscriptions (run every few hours)
curl -X POST "http://localhost:8082/webhook/renew-subscriptions"
```

### How It Works

1. **Event Deleted in Outlook** → Microsoft Graph sends webhook notification
2. **Webhook Received** → System validates and processes notification
3. **Cancellation Processed** → Booking system updated with "--- Cancelled from Outlook ---"
4. **Mapping Updated** → Sync status set to 'cancelled'

### Automation Setup

Add to your cron jobs:

```bash
# Renew webhook subscriptions every 4 hours
0 */4 * * * www-data curl -X POST "http://localhost:8082/webhook/renew-subscriptions" > /dev/null 2>&1

# Check webhook health daily
0 9 * * * www-data curl -X GET "http://localhost:8082/webhook/stats" > /var/log/outlook-sync/webhook-health.log
```

## Method 2: Polling Detection (Fallback)

### When to Use Polling

- Webhooks are not available or failing
- Network restrictions prevent webhook delivery
- As a backup to catch missed webhook notifications
- During development/testing

### Setup Polling Detection

#### 1. Detect Outlook Changes

```bash
# Run change detection to find deleted events
curl -X POST "http://localhost:8082/outlook/detect-changes"
```

**Expected Response:**
```json
{
  "success": true,
  "message": "Outlook change detection completed",
  "detected_changes": 3,
  "deleted_events": 2,
  "processed_deletions": 2,
  "errors": []
}
```

#### 2. Monitor Detection Statistics

```bash
# Get detection statistics
curl -X GET "http://localhost:8082/outlook/detection-stats"
```

**Response includes:**
```json
{
  "success": true,
  "statistics": {
    "last_24_hours": [
      {
        "change_type": "deleted",
        "processing_status": "processed",
        "count": 5
      }
    ],
    "mapping_overview": {
      "total_tracked_mappings": 150,
      "mappings_with_outlook_events": 120,
      "synced_mappings": 115,
      "error_mappings": 5
    },
    "recent_changes": [
      {
        "calendar_id": "room1@company.com",
        "event_id": "AAMkAGU...",
        "change_type": "deleted",
        "processing_status": "processed",
        "detected_at": "2025-06-13 14:30:00"
      }
    ]
  }
}
```

#### 3. Detect New External Events

```bash
# Find new Outlook events that weren't created by our system
curl -X GET "http://localhost:8082/outlook/detect-new-events"

# With date range
curl -X GET "http://localhost:8082/outlook/detect-new-events?from_date=2025-06-01&to_date=2025-07-01"
```

### Automation Setup

Add to your cron jobs:

```bash
# Run change detection every 15 minutes
*/15 * * * * www-data curl -X POST "http://localhost:8082/outlook/detect-changes" > /dev/null 2>&1

# Detect new external events hourly
0 * * * * www-data curl -X GET "http://localhost:8082/outlook/detect-new-events" > /dev/null 2>&1

# Clean up old detection logs daily
0 2 * * * www-data curl -X DELETE "http://localhost:8082/outlook/cleanup-logs?days=30" > /dev/null 2>&1
```

## Combined Approach (Recommended)

Use both methods for maximum reliability:

1. **Primary**: Set up webhooks for real-time detection
2. **Backup**: Run polling detection every 30 minutes to catch missed changes

```bash
# Combined cron setup
# Webhook subscription renewal
0 */4 * * * www-data curl -X POST "http://localhost:8082/webhook/renew-subscriptions" > /dev/null 2>&1

# Fallback polling detection
*/30 * * * * www-data curl -X POST "http://localhost:8082/outlook/detect-changes" > /dev/null 2>&1

# Daily cleanup and health checks
0 2 * * * www-data curl -X DELETE "http://localhost:8082/outlook/cleanup-logs?days=30" > /dev/null 2>&1
0 9 * * * www-data curl -X GET "http://localhost:8082/webhook/stats" > /var/log/outlook-sync/health.log
```

## What Happens When Events Are Cancelled in Outlook

### Booking System Events

When a booking system event is cancelled in Outlook:

1. **Detection**: System detects the event no longer exists
2. **Booking Update**: Sets `active = 0` in your booking system's event table  
3. **Description Update**: Appends "--- Cancelled from Outlook ---" to description
4. **Mapping Update**: Sets sync status to 'cancelled'
5. **Logging**: Records the cancellation for audit purposes

### Example Database Changes

Before cancellation:
```sql
-- Your booking system event table
id: 78268, active: 1, description: "Team meeting in conference room"

-- bridge_mappings table  
sync_status: 'synced', external_id: 'AAMkAGU...'
```

After Outlook-side cancellation:
```sql
-- Your booking system event table
id: 78268, active: 0, description: "Team meeting in conference room\n\n--- Cancelled from Outlook ---"

-- bridge_mappings table
sync_status: 'cancelled', external_id: 'AAMkAGU...'
```

## Monitoring and Troubleshooting

### Check Detection Health

```bash
# Overall system health
curl -X GET "http://localhost:8082/sync/stats"

# Webhook-specific health  
curl -X GET "http://localhost:8082/webhook/stats"

# Detection statistics
curl -X GET "http://localhost:8082/outlook/detection-stats"

# Recent cancellations
curl -X GET "http://localhost:8082/cancel/cancelled-reservations"
```

### Common Issues

#### Webhooks Not Working

1. **Check subscription status**:
   ```bash
   curl -X GET "http://localhost:8082/webhook/stats"
   ```

2. **Verify webhook endpoint is accessible**:
   ```bash
   curl -X POST "https://your-server.com/webhook/outlook-notifications?validationToken=test"
   ```

3. **Recreate subscriptions if expired**:
   ```bash
   curl -X POST "http://localhost:8082/webhook/create-subscriptions"
   ```

#### Polling Detection Missing Changes

1. **Check if events still exist in Outlook**
2. **Verify mapping table has correct outlook_event_id**
3. **Check Graph API permissions**
4. **Review detection logs**:
   ```bash
   curl -X GET "http://localhost:8082/outlook/detection-stats"
   ```

### Database Monitoring

Check the new database tables:

```sql
-- Webhook subscriptions
SELECT * FROM outlook_webhook_subscriptions WHERE is_active = true;

-- Recent webhook notifications
SELECT * FROM outlook_webhook_notifications 
WHERE created_at >= NOW() - INTERVAL '1 hour' 
ORDER BY created_at DESC;

-- Recent detected changes
SELECT * FROM outlook_event_changes 
WHERE detected_at >= NOW() - INTERVAL '1 hour'
ORDER BY detected_at DESC;

-- Cancelled mappings
SELECT * FROM bridge_mappings 
WHERE sync_status = 'cancelled' 
ORDER BY updated_at DESC;
```

## API Endpoints Summary

### Webhook Management
- `POST /webhook/create-subscriptions` - Create webhook subscriptions
- `POST /webhook/renew-subscriptions` - Renew expiring subscriptions  
- `GET /webhook/stats` - Get webhook statistics
- `POST /webhook/outlook-notifications` - Handle incoming notifications

### Polling Detection
- `POST /outlook/detect-changes` - Detect changes in Outlook events
- `GET /outlook/detect-new-events` - Find new external Outlook events
- `GET /outlook/detection-stats` - Get detection statistics
- `DELETE /outlook/cleanup-logs` - Clean up old detection logs

### Cancellation Management (Enhanced)
- `POST /bridges/sync-deletions` - Detect booking system cancellations
- `GET /cancel/cancelled-reservations` - View cancelled reservations
- `GET /cancel/stats` - Cancellation statistics

The system now provides comprehensive Outlook-side cancellation detection with both real-time webhooks and polling fallback, ensuring no cancellations are missed regardless of network conditions or webhook availability.
