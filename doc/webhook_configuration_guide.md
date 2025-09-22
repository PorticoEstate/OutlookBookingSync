# Webhook Configuration Guide

This guide provides comprehensive instructions for setting up and configuring webhooks in the OutlookBookingSync bridge system.

## Overview

The webhook system enables real-time synchronization between Microsoft Outlook and your booking system. When events change in either system, webhooks immediately notify the bridge to trigger synchronization.

## Understanding When Subscriptions Are Needed

**IMPORTANT**: Webhook subscriptions are only required for ONE direction of webhook flow.

### Subscription Requirements by Direction

#### Outlook → Bridge Webhooks (REQUIRES Subscriptions)

**When Microsoft Graph sends webhooks to your bridge:**
- ✅ **Subscriptions Required**: Must create via Microsoft Graph API
- ✅ **Subscription Management**: Automatic renewal needed (expire in ~3 days)
- ✅ **Validation Required**: Microsoft Graph validates webhook endpoints
- ✅ **Used For**: Real-time notification when Outlook events change

```
Outlook Event Changes → Microsoft Graph → Subscription → Webhook → Bridge
```

#### Booking System → Bridge Webhooks (NO Subscriptions Needed)

**When your booking system sends webhooks to the bridge:**
- ❌ **No Subscriptions**: Direct HTTP POST to bridge endpoint
- ❌ **No Expiration**: Your system controls the webhook calls
- ❌ **No Validation**: You control both systems
- ✅ **Used For**: Real-time notification when booking system events change

```
Booking System Changes → Direct HTTP POST → Bridge
```

### Why This Difference?

**Microsoft Graph Design**: As a third-party service, Microsoft Graph requires formal subscription registration to validate webhook endpoints and manage notification delivery.

**Your Booking System**: Since you control your booking system, it can directly call the bridge webhook endpoint without any registration process.

## Prerequisites

### 1. Public HTTPS Endpoint

**Microsoft Graph Requirements:**
- Your server must be accessible from the internet
- HTTPS is mandatory (Microsoft Graph rejects HTTP endpoints)
- Valid SSL certificate required (self-signed certificates will not work)
- Webhook endpoint: `https://your-domain.com/bridges/webhook/outlook`

### 2. SSL Certificate Setup

**Option A: Let's Encrypt (Recommended)**
```bash
# Install certbot
sudo apt install certbot python3-certbot-nginx

# Obtain SSL certificate
sudo certbot --nginx -d your-domain.com

# Auto-renewal (usually configured automatically)
sudo crontab -e
# Add: 0 12 * * * /usr/bin/certbot renew --quiet
```

**Option B: Commercial SSL Certificate**
- Purchase from certificate authority
- Install according to your web server configuration

### 3. Environment Configuration

Update your `.env` file:
```env
# Replace with your actual public domain
APP_BASE_URL=https://your-domain.com

# Database configuration
DB_HOST=localhost
DB_PORT=5432
DB_NAME=outlook_sync
DB_USER=postgres
DB_PASS=your_password

# Microsoft Graph credentials
GRAPH_CLIENT_ID=your_client_id
GRAPH_CLIENT_SECRET=your_client_secret
GRAPH_TENANT_ID=your_tenant_id
```

## Step-by-Step Configuration

### Step 1: Create Resource Mappings

Before webhooks can process events, you need to map Outlook calendars to booking system resources:

```bash
# Create resource mapping for Bergen Kommune example
curl -X POST "http://localhost:8082/resource-mappings" \
  -H "api_key: your_api_key" \
  -H "X-Tenant-Id: bergen" \
  -H "Content-Type: application/json" \
  -d '{
    "resource_type": "outlook",
    "resource_email": "reslandgan@bergen.kommune.no",
    "target_resource_type": "booking_system",
    "target_resource_id": "452"
  }'
```

**Response:**
```json
{
  "success": true,
  "message": "Resource mapping created successfully",
  "mapping_id": 123
}
```

### Step 2: Test Webhook Endpoint Accessibility

Verify your webhook endpoint is accessible from the internet:

```bash
# Test from external network
curl -X GET "https://your-domain.com/bridges/webhook/outlook?validationToken=test123"

# Expected response: test123
```

### Step 3: Create Webhook Subscriptions

Create Microsoft Graph webhook subscriptions for your calendars:

```bash
# Create subscription for specific calendar
curl -X POST "https://your-domain.com/bridges/outlook/subscriptions" \
  -H "Content-Type: application/json" \
  -H "api_key: your_api_key" \
  -H "X-Tenant-Id: bergen" \
  -d '{
    "calendar_ids": ["reslandgan@bergen.kommune.no"]
  }'
```

**Success Response:**
```json
{
  "success": true,
  "subscriptions": [
    {
      "calendar_id": "reslandgan@bergen.kommune.no",
      "subscription_id": "abcd-1234-efgh-5678",
      "webhook_url": "https://your-domain.com/bridges/webhook/outlook",
      "expires_at": "2025-09-23T00:00:00Z"
    }
  ],
  "errors": []
}
```

### Step 4: Test Webhook Processing

Test webhook processing with a sample notification:

```bash
# Send test webhook
curl -X POST "https://your-domain.com/bridges/webhook/outlook" \
  -H "X-Tenant-Id: bergen" \
  -H "Content-Type: application/json" \
  -d '{
    "value": [
      {
        "subscriptionId": "abcd-1234-efgh-5678",
        "resource": "Users('\''reslandgan@bergen.kommune.no'\'')/Events('\''test-event-id'\'')",
        "changeType": "updated",
        "clientState": null,
        "subscriptionExpirationDateTime": "2025-09-23T00:00:00Z",
        "tenantId": "your-tenant-id"
      }
    ]
  }'
```

**Expected Response:**
```json
{
  "success": true,
  "message": "Webhook processed and sync queued",
  "queued_events": 1
}
```

### Step 5: Process Webhook Queue

Process the webhook queue to execute synchronization:

```bash
# Process webhook queue
curl -X POST "http://localhost:8082/bridges/process-webhook-queue" \
  -H "api_key: your_api_key" \
  -H "X-Tenant-Id: bergen" \
  -H "Content-Type: application/json" \
  -d '{"batch_size": 5}'
```

**Success Response:**
```json
{
  "success": true,
  "processed": 1,
  "successful": 1,
  "errors": 0,
  "details": [
    {
      "id": 123,
      "event_type": "outlook_updated",
      "resource_id": "reslandgan@bergen.kommune.no",
      "status": "processed"
    }
  ]
}
```

## Webhook Format Specifications

### Microsoft Graph Notification Format

Microsoft Graph sends notifications in this format:

```json
{
  "value": [
    {
      "subscriptionId": "subscription-uuid",
      "resource": "Users('user@domain.com')/Calendars('calendar-id')/Events('event-id')",
      "changeType": "updated|created|deleted",
      "clientState": null,
      "subscriptionExpirationDateTime": "2025-09-23T00:00:00Z",
      "tenantId": "tenant-uuid"
    }
  ]
}
```

### Internal Processing Format

The bridge automatically transforms Graph notifications to internal format:

```json
{
  "event_type": "outlook_updated",
  "resource_id": "user@domain.com",
  "event_id": "event-id",
  "timestamp": "2025-09-22T10:00:00Z"
}
```

### Booking System Webhook Format

Your booking system should send webhooks in this format:

```json
{
  "event_type": "booking_created|booking_updated|booking_deleted",
  "resource_id": "booking_system_resource_id",
  "event_id": "booking_event_id",
  "timestamp": "2025-09-22T10:00:00Z"
}
```

## Monitoring and Maintenance

### Check Queue Status

Monitor webhook queue processing:

```bash
# Check queue statistics
curl -X GET "http://localhost:8082/bridges/queue-stats" \
  -H "api_key: your_api_key" \
  -H "X-Tenant-Id: bergen"
```

### Monitor Subscription Health

Check webhook subscription status:

```bash
# List active subscriptions
curl -X GET "http://localhost:8082/bridges/outlook/subscriptions" \
  -H "api_key: your_api_key" \
  -H "X-Tenant-Id: bergen"
```

### Automatic Subscription Renewal

The system automatically renews expiring subscriptions. Configure renewal settings:

```env
# Renew subscriptions 24 hours before expiration
RENEW_MINUTES=1440
```

## Troubleshooting

### Common Issues and Solutions

#### 1. "No resource mapping found"

**Problem:** Webhook received but no mapping exists between Outlook and booking system.

**Solution:**
```bash
# Create resource mapping
curl -X POST "http://localhost:8082/resource-mappings" \
  -H "api_key: your_api_key" \
  -H "X-Tenant-Id: bergen" \
  -H "Content-Type: application/json" \
  -d '{
    "resource_type": "outlook",
    "resource_email": "calendar@domain.com",
    "target_resource_type": "booking_system",
    "target_resource_id": "resource_id"
  }'
```

#### 2. SSL Certificate Errors

**Problem:** Microsoft Graph cannot reach webhook endpoint due to SSL issues.

**Solution:**
- Verify certificate validity: `openssl s_client -connect your-domain.com:443`
- Check certificate chain completeness
- Ensure certificate matches domain name

#### 3. Subscription Creation Failures

**Problem:** Cannot create Microsoft Graph subscriptions.

**Solutions:**
- Verify Microsoft Graph API permissions
- Check public URL accessibility from external networks
- Confirm tenant configuration and client credentials

#### 4. Webhook Endpoint Not Accessible

**Problem:** Webhook endpoint returns 404 or connection errors.

**Solutions:**
- Check firewall settings: `sudo ufw status`
- Verify web server configuration
- Test internal connectivity: `curl -I http://localhost:8082/bridges/webhook/outlook`

### Debug Commands

#### Enable Debug Logging

Add to `.env`:
```env
LOG_LEVEL=debug
```

#### Test Webhook Transformation

```bash
# Test Microsoft Graph format transformation
curl -X POST "http://localhost:8082/bridges/webhook/outlook" \
  -H "X-Tenant-Id: bergen" \
  -H "Content-Type: application/json" \
  -d '{
    "value": [
      {
        "subscriptionId": "test-123",
        "resource": "Users('\''test@domain.com'\'')/Events('\''event-123'\'')",
        "changeType": "updated"
      }
    ]
  }' \
  -v
```

#### Check Processing Logs

```bash
# View webhook processing logs
tail -f storage/logs/bridge.log | grep webhook

# View specific error logs
tail -f storage/logs/bridge.log | grep ERROR
```

## Production Deployment

### Firewall Configuration

```bash
# Allow HTTPS traffic
sudo ufw allow 443

# Allow HTTP (for certificate challenges)
sudo ufw allow 80

# Check firewall status
sudo ufw status
```

### Nginx Configuration Example

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
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### Docker Deployment

Update `docker-compose.yml` for production:

```yaml
version: '3.8'
services:
  bridge:
    build: .
    ports:
      - "8082:8082"
    environment:
      - APP_BASE_URL=https://your-domain.com
    volumes:
      - ./storage:/app/storage
    restart: unless-stopped
```

### Monitoring Setup

Set up automated monitoring:

```bash
# Create monitoring script
cat > /opt/webhook-monitor.sh << 'EOF'
#!/bin/bash
WEBHOOK_URL="https://your-domain.com/bridges/webhook/outlook?validationToken=monitor"
RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$WEBHOOK_URL")

if [ "$RESPONSE" != "200" ]; then
    echo "Webhook endpoint down: HTTP $RESPONSE"
    # Add alerting logic here
fi
EOF

# Make executable and add to cron
chmod +x /opt/webhook-monitor.sh
echo "*/5 * * * * /opt/webhook-monitor.sh" | sudo crontab -
```

This guide should provide comprehensive coverage for webhook configuration and troubleshooting. For additional support, refer to the Microsoft Graph webhook documentation and the bridge API documentation.