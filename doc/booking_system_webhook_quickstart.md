# Booking System Webhook Integration - Quick Start Guide

## 🎯 Objective

Enable real-time event synchronization from your booking system to OutlookBookingSync bridge using webhooks instead of polling.

## ✅ Status: Implementation Complete

All bridge-side webhook functionality has been implemented and tested. You only need to:
1. Ensure your booking system implements the required endpoints
2. Configure the bridge
3. Create webhook subscriptions

---

## Prerequisites

- OutlookBookingSync bridge running (v1.0+)
- Booking system with webhook support
- Valid API key and tenant configuration
- Network connectivity between booking system and bridge

---

## Booking System Requirements

Your booking system **MUST** implement these endpoints:

### 1. Create Subscription
```http
POST /booking/webhooks/subscriptions
Content-Type: application/json

{
  "calendar_id": "resource_123",
  "webhook_url": "https://bridge.example.com/bridges/webhook/booking_system?tenant_id=tenant1",
  "events": ["created", "updated", "deleted"],
  "client_state": "secret-validation-token"
}
```

**Response**:
```json
{
  "subscription_id": "sub_abc123",
  "expires_at": "2025-11-12T10:30:00Z",
  "expires_in_seconds": 2592000
}
```

### 2. Renew Subscription
```http
PATCH /booking/webhooks/subscriptions/{subscription_id}
```

**Response**:
```json
{
  "subscription_id": "sub_abc123",
  "expires_at": "2025-12-12T10:30:00Z",
  "renewed": true
}
```

### 3. Delete Subscription
```http
DELETE /booking/webhooks/subscriptions/{subscription_id}
```

**Response**:
```json
{
  "success": true,
  "deleted": true
}
```

### 4. Send Notifications

When a booking is created/updated/deleted, POST to the `webhook_url`:

```http
POST {webhook_url}
Content-Type: application/json

{
  "event_type": "booking.created",
  "booking_id": "booking_456",
  "resource_id": "resource_123",
  "timestamp": "2025-10-13T10:30:00Z",
  "client_state": "secret-validation-token",
  "data": {
    "title": "Customer Appointment",
    "from_": "2025-10-14 09:00:00",
    "to_": "2025-10-14 10:00:00",
    "contact_name": "John Doe",
    "contact_email": "john@example.com"
  }
}
```

**Event Types**: `booking.created`, `booking.updated`, `booking.deleted`, `booking.cancelled`

---

## Bridge Configuration

### Step 1: Environment Variables

Add to your `.env` file:

```env
# Optional: Static client state for validation
BOOKING_SYSTEM_CLIENT_STATE=your-random-secret-string-here

# Enable immediate webhook processing (recommended)
WEBHOOK_IMMEDIATE_PROCESSING=true
```

If not set, the bridge generates random client states per subscription.

### Step 2: Verify Bridge Endpoints

The bridge automatically configures these endpoints for your booking system:

```php
// Already configured in BookingSystemBridge.php
'subscribe_webhook' => [
    'method' => 'POST',
    'url' => '/booking/webhooks/subscriptions'
],
'renew_webhook' => [
    'method' => 'PATCH',
    'url' => '/booking/webhooks/subscriptions/{subscription_id}'
],
'unsubscribe_webhook' => [
    'method' => 'DELETE',
    'url' => '/booking/webhooks/subscriptions/{subscription_id}'
]
```

**No code changes needed** - these are built-in!

---

## Usage

### Create Webhook Subscriptions

Create subscriptions for all resources (or specific ones):

```bash
# Subscribe to all resources
curl -X POST http://localhost:8082/bridges/booking_system/subscriptions \
  -H "X-API-Key: your-api-key" \
  -H "X-Tenant-Id: your-tenant-id" \
  -H "Content-Type: application/json" \
  -d '{
    "webhook_url": "http://localhost:8082/bridges/webhook/booking_system?tenant_id=your-tenant-id"
  }'

# Subscribe to specific resources
curl -X POST http://localhost:8082/bridges/booking_system/subscriptions \
  -H "X-API-Key: your-api-key" \
  -H "X-Tenant-Id: your-tenant-id" \
  -H "Content-Type: application/json" \
  -d '{
    "webhook_url": "http://localhost:8082/bridges/webhook/booking_system?tenant_id=your-tenant-id",
    "calendar_ids": ["resource_123", "resource_456"]
  }'
```

**Response**:
```json
{
  "success": true,
  "bridge": "booking_system",
  "subscriptions": [
    {
      "calendar_id": "resource_123",
      "subscription_id": "sub_abc123",
      "webhook_url": "http://localhost:8082/bridges/webhook/booking_system?tenant_id=your-tenant-id"
    }
  ],
  "total_subscriptions": 1
}
```

### List Active Subscriptions

```bash
curl http://localhost:8082/bridges/booking_system/subscriptions \
  -H "X-API-Key: your-api-key" \
  -H "X-Tenant-Id: your-tenant-id"
```

### Delete Subscription

```bash
curl -X DELETE http://localhost:8082/bridges/booking_system/subscriptions/sub_abc123 \
  -H "X-API-Key: your-api-key" \
  -H "X-Tenant-Id: your-tenant-id"
```

---

## Testing

### Automated Test Suite

Run the comprehensive test script:

```bash
# Full test with default settings
php scripts/test_booking_system_webhooks.php --verbose

# Custom configuration
php scripts/test_booking_system_webhooks.php \
  --bridge-url=http://localhost:8082 \
  --booking-url=http://your-booking-system:8081 \
  --api-key=your-api-key \
  --tenant-id=your-tenant-id \
  --verbose
```

**Tests Performed**:
1. ✅ Create webhook subscription
2. ✅ Validation handshake
3. ✅ Send test notifications (created, updated, deleted)
4. ✅ List subscriptions
5. ✅ Renew subscription
6. ✅ Delete subscription

### Manual Test: Send Notification

Simulate a booking system webhook:

```bash
curl -X POST http://localhost:8082/bridges/webhook/booking_system?tenant_id=your-tenant-id \
  -H "X-API-Key: your-api-key" \
  -H "X-Tenant-Id: your-tenant-id" \
  -H "Content-Type: application/json" \
  -d '{
    "event_type": "booking.created",
    "booking_id": "booking_test_123",
    "resource_id": "resource_123",
    "timestamp": "2025-10-13T10:30:00Z",
    "data": {
      "title": "Test Booking",
      "from_": "2025-10-14 09:00:00",
      "to_": "2025-10-14 10:00:00",
      "contact_name": "Test User",
      "contact_email": "test@example.com"
    }
  }'
```

**Expected Response** (HTTP 202):
```json
{
  "success": true,
  "message": "Webhook processed and sync queued",
  "bridge": "booking_system",
  "target_bridge": "outlook"
}
```

---

## Monitoring

### Check Webhook Health

```bash
curl http://localhost:8082/bridges/booking_system/health \
  -H "X-API-Key: your-api-key" \
  -H "X-Tenant-Id: your-tenant-id"
```

**Response**:
```json
{
  "bridge": "booking_system",
  "status": "healthy",
  "webhook_support": true,
  "active_subscriptions": 5,
  "warnings": []
}
```

### View Logs

```bash
# Watch webhook activity in real-time
tail -f storage/logs/bridge.log | grep "booking_system"

# Filter for webhook-specific events
tail -f storage/logs/bridge.log | grep "webhook"
```

### Database Monitoring

```sql
-- Active subscriptions
SELECT * FROM bridge_subscriptions 
WHERE bridge_type = 'booking_system' AND is_active = true;

-- Recent webhook deliveries
SELECT created_at, payload->>'event_type' as event_type, status
FROM bridge_queue
WHERE source_bridge = 'booking_system'
ORDER BY created_at DESC
LIMIT 20;

-- Subscriptions expiring soon (next 24 hours)
SELECT subscription_id, calendar_id, expires_at
FROM bridge_subscriptions
WHERE bridge_type = 'booking_system'
  AND expires_at <= NOW() + INTERVAL '24 hours'
ORDER BY expires_at;
```

---

## Subscription Renewal

### Automatic Renewal (Recommended)

Set up a cron job to renew expiring subscriptions:

```bash
# Add to crontab (runs daily at 2 AM)
0 2 * * * curl -X POST http://localhost:8082/bridges/booking_system/renew-expiring \
  -H "X-API-Key: your-api-key" \
  -H "X-Tenant-Id: your-tenant-id"
```

### Manual Renewal

```bash
curl -X POST http://localhost:8082/bridges/booking_system/subscriptions/sub_abc123/renew \
  -H "X-API-Key: your-api-key" \
  -H "X-Tenant-Id: your-tenant-id"
```

---

## Troubleshooting

### Issue: No Notifications Received

**Checklist**:
- ✅ Subscription exists: Check `/bridges/booking_system/subscriptions`
- ✅ Webhook URL is accessible from booking system
- ✅ API key and tenant ID are correct
- ✅ Client state matches (if configured)
- ✅ Check logs: `tail -f storage/logs/bridge.log | grep webhook`

**Test webhook endpoint directly**:
```bash
curl -X POST http://localhost:8082/bridges/webhook/booking_system?tenant_id=your-tenant-id \
  -H "X-API-Key: your-api-key" \
  -H "Content-Type: application/json" \
  -d '{"event_type": "booking.created", "booking_id": "test", "resource_id": "test"}'
```

### Issue: Client State Validation Fails

**Logs show**: "Booking system webhook clientState mismatch"

**Solution**:
1. Check if `BOOKING_SYSTEM_CLIENT_STATE` is set in `.env`
2. Verify booking system sends same client state in notifications
3. Recreate subscription if needed

### Issue: Subscription Expired

**Logs show**: Subscription not found or inactive

**Solution**:
```bash
# Check expiration
curl http://localhost:8082/bridges/booking_system/subscriptions \
  -H "X-API-Key: your-api-key" \
  -H "X-Tenant-Id: your-tenant-id"

# Renew or recreate
curl -X POST http://localhost:8082/bridges/booking_system/subscriptions/sub_abc123/renew \
  -H "X-API-Key: your-api-key" \
  -H "X-Tenant-Id: your-tenant-id"
```

---

## Migration from Polling

### Before (Polling Every 15 Minutes)
```
Cron: */15 * * * * curl -X POST .../bridges/sync/booking_system/outlook
```

### After (Real-time Webhooks)

1. **Create subscriptions** (one-time):
   ```bash
   curl -X POST http://localhost:8082/bridges/booking_system/subscriptions ...
   ```

2. **Reduce polling frequency** (backup only):
   ```
   Cron: 0 */6 * * * curl -X POST .../bridges/sync/booking_system/outlook
   ```

3. **Monitor webhook deliveries**:
   ```bash
   tail -f storage/logs/bridge.log | grep webhook
   ```

**Result**: Near-instant synchronization (< 1 second vs 15 minutes)

---

## Security Best Practices

1. **Use HTTPS in production**:
   ```
   webhook_url: https://bridge.example.com/bridges/webhook/...
   ```

2. **Configure client state validation**:
   ```env
   BOOKING_SYSTEM_CLIENT_STATE=generate-random-string-here
   ```

3. **Restrict webhook endpoint access**:
   - Use API key authentication
   - Whitelist booking system IP addresses
   - Use tenant-specific URLs

4. **Monitor for anomalies**:
   ```sql
   -- Unusual webhook activity
   SELECT DATE(created_at) as date, COUNT(*) as webhooks
   FROM bridge_queue
   WHERE source_bridge = 'booking_system'
   GROUP BY DATE(created_at)
   ORDER BY date DESC;
   ```

---

## Summary

### ✅ What's Already Implemented

- Webhook subscription management (create, renew, delete)
- Webhook reception endpoint with validation
- Client state security validation
- Notification transformation and queuing
- Comprehensive test suite
- Database persistence and monitoring

### 📋 What You Need to Do

1. Implement webhook endpoints in your booking system
2. Configure `BOOKING_SYSTEM_CLIENT_STATE` in `.env`
3. Create webhook subscriptions via API
4. Set up automatic renewal cron job
5. Monitor webhook deliveries in logs

### 🚀 Ready to Go!

The bridge is **100% ready** to receive webhooks. Once your booking system implements the required endpoints, you can start using real-time synchronization immediately.

---

**For detailed technical documentation**, see: `doc/booking_system_webhook_integration_bridge_side.md`

**For testing**, run: `php scripts/test_booking_system_webhooks.php --verbose`

**For support**, check logs: `tail -f storage/logs/bridge.log | grep webhook`
