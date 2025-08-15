# Bridge-Based Deletion & Cancellation Detection

This document explains how the bridge architecture handles event cancellation and deletion detection between calendar systems. The bridge system provides robust bidirectional deletion sync capabilities.

## Overview

The bridge system provides automatic deletion/cancellation sync through:

1. Real-time webhooks from Microsoft Graph
2. Queue-based processing for reliability
3. Optional periodic processing to catch missed notifications

## Bridge Architecture Benefits

- Universal: Works with any calendar system that implements the bridge interface
- Bidirectional: Handles deletions/cancellations in both directions
- Reliable: Queue-based processing with error handling and retry logic
- Extensible: Easy to add new calendar systems without changing core logic

### Prerequisites

1. Microsoft Graph permissions. Ensure your app registration has:
   - Calendars.ReadWrite.All
   - Calendars.Read.Shared
   - Calendars.ReadWrite.Shared

2. Public webhook endpoint. Your server must be accessible from the internet for Microsoft to send notifications.

3. Environment variables (in `.env`):
```bash
APP_BASE_URL=https://your-server.com
WEBHOOK_CLIENT_SECRET=your-secret-key-for-validation
API_KEY=your_api_key
```

## Setup webhooks

### 1) Create webhook subscriptions

Provide the explicit webhook URL that supports Microsoft’s GET validation. Use the legacy-compatible endpoint which maps to the bridge handler.

```bash
curl -X POST -H "api_key: YOUR_API_KEY" -H "X-Tenant-Id: tenantA" "http://localhost:8082/bridges/outlook/subscriptions" \
  -H "Content-Type: application/json" \
  -H "api_key: YOUR_API_KEY" -H "X-Tenant-Id: tenantA" \
  -d '{
    "webhook_url": "http://localhost:8082/webhook/outlook-notifications",
    "calendar_ids": ["room1@company.com", "room2@company.com"]
  }'
```

Expected response (shape may vary):
```json
{
  "success": true,
  "bridge": "outlook",
  "subscriptions": [
    {
      "calendar_id": "room1@company.com",
      "subscription_id": "abcd-1234-efgh-5678",
      "webhook_url": "http://localhost:8082/webhook/outlook-notifications"
    }
  ],
  "errors": [],
  "total_subscriptions": 2,
  "total_errors": 0
}
```

### 2) Test webhook validation

Microsoft Graph performs a GET validation with a validationToken query param. Test locally with:

```bash
curl "http://localhost:8082/webhook/outlook-notifications?validationToken=test"
```

### 3) Subscription expiry and renewal

Graph subscriptions are short-lived (typically up to 3 days). Renewal automation is not exposed as a separate endpoint in this service yet. As a workaround, re-run the subscriptions creation periodically with the same `webhook_url` (the bridge stores active subscriptions in `bridge_subscriptions`). Monitor expiring counts via health endpoints (see below).

Optional cron example (adjust timing as needed):

```bash
0 */12 * * * www-data curl -sS -X POST "http://localhost:8082/bridges/outlook/subscriptions" \
  -H "Content-Type: application/json" -H "api_key: YOUR_API_KEY" -H "X-Tenant-Id: tenantA" \
  -d '{"webhook_url":"https://your-server.com/webhook/outlook-notifications"}' > /dev/null 2>&1
```

Webhook delivery endpoint (Graph posts notifications here):

```text
POST http://localhost:8082/webhook/outlook-notifications
```

## How it works

1. Event deleted in Outlook → Microsoft Graph sends webhook notification
2. Webhook received → Service validates and queues a deletion check
3. Queue processor confirms deletion and updates the booking system
4. Mapping updated → `sync_status` set to `cancelled`

## Automation setup

Add to cron (examples):

```bash
# General bridge health checks
0 */4 * * * www-data curl -sS "http://localhost:8082/bridges/health" -H "api_key: YOUR_API_KEY" -H "X-Tenant-Id: tenantA" > /dev/null 2>&1
```

## Optional periodic processing (fallback)

Use the bridge endpoints to process queued work or to catch missed changes.

### Useful endpoints

- Trigger manual deletion sync (detects cancellations across mappings):
  - POST /bridges/sync-deletions

- Process queued deletion checks (from webhooks):
  - POST /bridges/process-deletion-queue

- Process pending syncs for Outlook specifically:
  - POST /bridges/process-pending-syncs/outlook

- Stats and monitoring:
  - GET /bridges/sync-stats/outlook
  - GET /bridges/cancelled-events/outlook
  - GET /health/sync-status

### Cron examples

```bash
# Detect and sync deletions every 15 minutes
*/15 * * * * www-data curl -sS -X POST "http://localhost:8082/bridges/sync-deletions" -H "api_key: YOUR_API_KEY" > /dev/null 2>&1

# Process queued deletion checks
*/15 * * * * www-data curl -sS -X POST "http://localhost:8082/bridges/process-deletion-queue" -H "api_key: YOUR_API_KEY" > /dev/null 2>&1

# Process pending syncs for Outlook
*/30 * * * * www-data curl -sS -X POST "http://localhost:8082/bridges/process-pending-syncs/outlook" -H "api_key: YOUR_API_KEY" -H "Content-Type: application/json" -d '{"batch_size": 100}' > /dev/null 2>&1

# Daily detailed system health
0 2 * * * www-data curl -sS "http://localhost:8082/health/system" -H "api_key: YOUR_API_KEY" > /dev/null 2>&1
```

## What happens when events are cancelled in Outlook

### Booking system events

When a booking system event is cancelled in Outlook:

1. Detection: System detects the event no longer exists
2. Booking update: Sets `active = 0` in your booking system's event table
3. Description update: Appends "--- Cancelled from Outlook ---" to description
4. Mapping update: Sets sync status to `cancelled`
5. Logging: Records the cancellation for audit purposes

### Example database changes

Before cancellation:
```sql
-- Your booking system event table
-- id: 78268, active: 1, description: "Team meeting in conference room"

-- bridge_mappings table
-- sync_status: 'synced', target_event_id: 'AAMkAGU...'
```

After Outlook-side cancellation:
```sql
-- Your booking system event table
-- id: 78268, active: 0, description: "Team meeting in conference room\n\n--- Cancelled from Outlook ---"

-- bridge_mappings table
-- sync_status: 'cancelled', target_event_id: 'AAMkAGU...'
```

## Monitoring and troubleshooting

### Check status

```bash
# Overall bridge health (includes subscription counts per bridge)
curl -X GET "http://localhost:8082/bridges/health" -H "api_key: YOUR_API_KEY" -H "X-Tenant-Id: tenantA"

# Detailed sync status view
curl -X GET "http://localhost:8082/health/sync-status" -H "api_key: YOUR_API_KEY" -H "X-Tenant-Id: tenantA"

# Outlook-specific sync stats
curl -X GET "http://localhost:8082/bridges/sync-stats/outlook" -H "api_key: YOUR_API_KEY" -H "X-Tenant-Id: tenantA"

# Recently cancelled events (Outlook → Booking)
curl -X GET "http://localhost:8082/bridges/cancelled-events/outlook" -H "api_key: YOUR_API_KEY" -H "X-Tenant-Id: tenantA"
```

### Common issues

#### Webhooks not working

1) Check active/expiring subscriptions via health and/or DB:
```bash
curl -X GET "http://localhost:8082/bridges/health" -H "api_key: YOUR_API_KEY" -H "X-Tenant-Id: tenantA"
```

2) Verify webhook endpoint is reachable (Graph validation simulation):
```bash
curl "https://your-server.com/webhook/outlook-notifications?validationToken=test"
```

3) Recreate subscriptions if expired:
```bash
curl -X POST -H "api_key: YOUR_API_KEY" -H "X-Tenant-Id: tenantA" "http://localhost:8082/bridges/outlook/subscriptions" \
  -H "Content-Type: application/json" -H "api_key: YOUR_API_KEY" -H "X-Tenant-Id: tenantA" \
  -d '{"webhook_url":"https://your-server.com/webhook/outlook-notifications"}'
```

#### Processing didn’t catch a cancellation

1) Confirm the event truly no longer exists in Outlook
2) Verify mapping record has the correct Outlook event ID
3) Check Graph API permissions
4) Run manual processing:
```bash
curl -sS -X POST "http://localhost:8082/bridges/sync-deletions" -H "api_key: YOUR_API_KEY" -H "X-Tenant-Id: tenantA"
curl -sS -X POST "http://localhost:8082/bridges/process-deletion-queue" -H "api_key: YOUR_API_KEY" -H "X-Tenant-Id: tenantA"
curl -sS -X POST "http://localhost:8082/bridges/process-pending-syncs/outlook" -H "api_key: YOUR_API_KEY" -H "X-Tenant-Id: tenantA"
```

### Database monitoring

```sql
-- Active webhook subscriptions
SELECT * FROM bridge_subscriptions WHERE is_active = true ORDER BY expires_at ASC NULLS LAST;

-- Recent sync operations (success/error and counts)
SELECT * FROM bridge_sync_logs 
WHERE created_at >= NOW() - INTERVAL '1 hour' 
ORDER BY created_at DESC;

-- Pending or failed queued tasks (including deletion checks)
SELECT * FROM bridge_queue 
WHERE status IN ('pending','processing') 
ORDER BY priority ASC, scheduled_at ASC;

-- Cancelled mappings
SELECT * FROM bridge_mappings 
WHERE sync_status = 'cancelled' 
ORDER BY updated_at DESC;
```

## API endpoints summary

### Webhook management
- POST `/bridges/{bridge}/subscriptions` – Create webhook subscriptions (e.g., `{bridge}=outlook`)
- POST `/bridges/webhook/{bridge}` – Webhook handler (legacy GET/POST `/webhook/outlook-notifications` also supported)
- GET `/bridges/health` – Health across bridges (includes subscription counts)

### Deletion and processing
- POST `/bridges/sync-deletions` – Detect and sync deletions across mappings
- POST `/bridges/process-deletion-queue` – Process queued deletion checks
- POST `/bridges/process-pending-syncs[/{bridge}]` – Process pending syncs (all or specific bridge)
- GET `/bridges/cancelled-events[/{bridge}]` – View cancelled events
- GET `/bridges/sync-stats[/{bridge}]` – Sync statistics

### Health
- GET `/health` – Quick health
- GET `/health/system` – Detailed system status
- GET `/health/sync-status` – Sync status details

The system provides comprehensive Outlook-side cancellation detection with real-time webhooks and robust queue-backed processing, plus optional periodic processing to ensure no cancellations are missed.
