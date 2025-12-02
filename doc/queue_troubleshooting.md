# Queue Processing Troubleshooting Guide

This guide helps diagnose and resolve common issues with the queue-based sync architecture.

## Quick Diagnostics

### Check Queue Status

```bash
# Get queue statistics
curl -H "X-API-Key: your-key" \
  -H "X-Tenant-Id: your-tenant" \
  http://localhost:8082/health/queue-stats

# Check failed queue items
curl -H "X-API-Key: your-key" \
  -H "X-Tenant-Id: your-tenant" \
  http://localhost:8082/bridges/queue/failed
```

### Check Recent Sync Logs

```bash
# View sync status
curl -H "X-API-Key: your-key" \
  -H "X-Tenant-Id: your-tenant" \
  http://localhost:8082/health/sync-status
```

## Common Issues

### 1. Queue Items Not Processing

**Symptoms**: Queue items remain in `pending` status indefinitely.

**Possible Causes**:
- Cron job not configured or not running
- Immediate processing disabled and no fallback cron
- PHP-FPM not available for immediate processing

**Solutions**:

```bash
# Verify cron job is running (Docker)
docker exec outlook-bridge-app crontab -l

# Manually trigger queue processing
curl -X POST -H "X-API-Key: your-key" \
  -H "X-Tenant-Id: your-tenant" \
  -H "Content-Type: application/json" \
  -d '{"queue_types":["webhook","sync","deletion"],"batch_size":50}' \
  http://localhost:8082/bridges/process-queue

# Check if PHP-FPM is available
php -r "echo function_exists('fastcgi_finish_request') ? 'Available' : 'Not available';"

# Enable immediate processing (if PHP-FPM available)
# In .env file:
SYNC_IMMEDIATE_PROCESSING=true
```

### 2. Queue Items Failing Repeatedly

**Symptoms**: Items move to `failed` status after 3 attempts.

**Possible Causes**:
- Invalid credentials or expired tokens
- API endpoint unavailable
- Network connectivity issues
- Data validation errors

**Solutions**:

```bash
# Get detailed error information
curl -H "X-API-Key: your-key" \
  -H "X-Tenant-Id: your-tenant" \
  "http://localhost:8082/bridges/queue/failed?limit=10"

# Check bridge health
curl -H "X-API-Key: your-key" \
  -H "X-Tenant-Id: your-tenant" \
  http://localhost:8082/bridges/health

# Test specific bridge connectivity
curl -H "X-API-Key: your-key" \
  -H "X-Tenant-Id: your-tenant" \
  http://localhost:8082/bridges/outlook/calendars

# Manually retry a failed item (after fixing underlying issue)
curl -X POST -H "X-API-Key: your-key" \
  -H "X-Tenant-Id: your-tenant" \
  http://localhost:8082/bridges/queue/{item-id}/retry
```

### 3. Duplicate Queue Items

**Symptoms**: Same operation queued multiple times.

**Possible Causes**:
- Duplicate prevention not working (PostgreSQL version < 9.4)
- Concurrent webhook deliveries from provider
- Manual sync triggered multiple times

**Solutions**:

```bash
# Verify PostgreSQL version (JSONB @> operator requires 9.4+)
docker exec outlook-bridge-db psql -U bridge_user -d bridge_db -c "SELECT version();"

# Check for duplicates in queue
docker exec outlook-bridge-db psql -U bridge_user -d bridge_db -c \
  "SELECT queue_type, COUNT(*), payload FROM bridge_queue 
   WHERE status = 'pending' 
   GROUP BY queue_type, payload 
   HAVING COUNT(*) > 1;"

# If duplicates exist, manually delete extras (keep oldest)
curl -X DELETE -H "X-API-Key: your-key" \
  -H "X-Tenant-Id: your-tenant" \
  http://localhost:8082/bridges/queue/{duplicate-item-id}
```

### 4. Queue Table Growing Too Large

**Symptoms**: `bridge_queue` table has millions of rows, queries slow.

**Possible Causes**:
- Cleanup job not configured or not running
- Retention period too long
- High volume with infrequent cleanup

**Solutions**:

```bash
# Check queue table size
docker exec outlook-bridge-db psql -U bridge_user -d bridge_db -c \
  "SELECT COUNT(*), status FROM bridge_queue GROUP BY status;"

# Manually trigger cleanup (default: 30 days)
curl -X POST -H "X-API-Key: your-key" \
  http://localhost:8082/maintenance/cleanup-queue

# Cleanup with custom retention (7 days)
curl -X POST -H "X-API-Key: your-key" \
  "http://localhost:8082/maintenance/cleanup-queue?days=7"

# Configure daily cleanup cron (add to crontab)
0 2 * * * curl -X POST -H "X-API-Key: change-me" http://localhost:8082/maintenance/cleanup-queue >> /var/log/cron.log 2>&1
```

### 5. Webhook Processing Delayed

**Symptoms**: Webhook notifications take several minutes to process.

**Possible Causes**:
- Immediate processing disabled
- PHP-FPM not available
- Cron job frequency too low

**Solutions**:

```bash
# Enable immediate processing
# In .env file:
SYNC_IMMEDIATE_PROCESSING=true

# Verify PHP-FPM configuration (Docker)
docker exec outlook-bridge-app php-fpm -v

# Increase cron frequency for queue processing
# Change from every 5 minutes to every minute:
* * * * * curl -X POST -H "X-API-Key: change-me" -H "Content-Type: application/json" -d '{"queue_types":["webhook"],"batch_size":50}' http://localhost:8082/bridges/process-queue >> /var/log/cron.log 2>&1

# Or use unified processor for all types:
* * * * * curl -X POST -H "X-API-Key: change-me" -H "Content-Type: application/json" -d '{"queue_types":["webhook","sync","deletion"],"batch_size":50}' http://localhost:8082/bridges/process-queue >> /var/log/cron.log 2>&1
```

### 6. Queue Processing Consumes Too Many Resources

**Symptoms**: High CPU/memory usage during queue processing.

**Possible Causes**:
- Batch size too large
- Processing too many queue types simultaneously
- Inefficient event processing logic

**Solutions**:

```bash
# Reduce batch size (default: 50)
curl -X POST -H "X-API-Key: your-key" \
  -H "Content-Type: application/json" \
  -d '{"queue_types":["webhook","sync"],"batch_size":25}' \
  http://localhost:8082/bridges/process-queue

# Process queue types separately (stagger cron jobs)
# Webhook queue every minute
* * * * * curl -X POST -H "X-API-Key: change-me" -d '{"queue_types":["webhook"],"batch_size":25}' http://localhost:8082/bridges/process-queue

# Sync queue every 5 minutes
*/5 * * * * curl -X POST -H "X-API-Key: change-me" -d '{"queue_types":["sync"],"batch_size":25}' http://localhost:8082/bridges/process-queue

# Deletion queue every 15 minutes
*/15 * * * * curl -X POST -H "X-API-Key: change-me" -d '{"queue_types":["deletion"],"batch_size":10}' http://localhost:8082/bridges/process-queue
```

## Monitoring Best Practices

### Set Up Regular Health Checks

```bash
# Add to monitoring script (every 5 minutes)
#!/bin/bash
QUEUE_STATS=$(curl -s -H "X-API-Key: your-key" http://localhost:8082/health/queue-stats)
FAILED_COUNT=$(echo $QUEUE_STATS | jq '.failed_items')

if [ "$FAILED_COUNT" -gt 10 ]; then
  echo "ALERT: High number of failed queue items: $FAILED_COUNT"
  # Send notification (email, Slack, etc.)
fi
```

### Log Queue Metrics

```bash
# Log queue statistics hourly
0 * * * * curl -s -H "X-API-Key: your-key" http://localhost:8082/health/queue-stats >> /var/log/queue-metrics.log
```

### Alert on Stale Queues

```bash
# Check for old pending items (over 1 hour old)
docker exec outlook-bridge-db psql -U bridge_user -d bridge_db -c \
  "SELECT COUNT(*) FROM bridge_queue 
   WHERE status = 'pending' 
   AND created_at < NOW() - INTERVAL '1 hour';"
```

## Database Maintenance

### Vacuum Queue Table

```bash
# Reclaim space after large cleanups
docker exec outlook-bridge-db psql -U bridge_user -d bridge_db -c \
  "VACUUM ANALYZE bridge_queue;"
```

### Check Index Performance

```bash
# Verify indexes exist and are used
docker exec outlook-bridge-db psql -U bridge_user -d bridge_db -c \
  "SELECT indexname, indexdef FROM pg_indexes 
   WHERE tablename = 'bridge_queue';"

# Analyze query performance
docker exec outlook-bridge-db psql -U bridge_user -d bridge_db -c \
  "EXPLAIN ANALYZE 
   SELECT * FROM bridge_queue 
   WHERE status = 'pending' 
   AND queue_type = 'webhook' 
   ORDER BY priority DESC, created_at ASC 
   LIMIT 50;"
```

## Recovery Procedures

### Reset Failed Items (Mass Retry)

```bash
# Get all failed item IDs
FAILED_IDS=$(curl -s -H "X-API-Key: your-key" \
  "http://localhost:8082/bridges/queue/failed?limit=1000" | \
  jq -r '.items[].id')

# Retry each failed item
for id in $FAILED_IDS; do
  curl -X POST -H "X-API-Key: your-key" \
    "http://localhost:8082/bridges/queue/$id/retry"
  sleep 1
done
```

### Clear Stuck Queue Items

```bash
# Delete items stuck in pending for over 24 hours
docker exec outlook-bridge-db psql -U bridge_user -d bridge_db -c \
  "DELETE FROM bridge_queue 
   WHERE status = 'pending' 
   AND created_at < NOW() - INTERVAL '24 hours';"
```

### Emergency Queue Reset

```bash
# WARNING: Only use in disaster recovery scenarios
# This deletes ALL queue items

docker exec outlook-bridge-db psql -U bridge_user -d bridge_db -c \
  "TRUNCATE bridge_queue;"

# Or delete specific tenant's queue items
docker exec outlook-bridge-db psql -U bridge_user -d bridge_db -c \
  "DELETE FROM bridge_queue WHERE tenant_id = 'your-tenant';"
```

## Performance Tuning

### Optimize Batch Sizes

| Queue Type | Recommended Batch Size | Frequency |
|------------|----------------------|-----------|
| webhook | 50-100 | Every 1-5 minutes |
| sync | 25-50 | Every 5-15 minutes |
| deletion | 10-25 | Every 15-30 minutes |

### Adjust Based on Load

```bash
# High load (many events per minute)
- Smaller batch sizes (25-50)
- Higher frequency (every 1-2 minutes)
- Separate queue type processing

# Low load (few events per hour)
- Larger batch sizes (100-200)
- Lower frequency (every 5-10 minutes)
- Unified queue processor
```

## Related Documentation

- **Configuration**: See `doc/operations.md` for cron job setup
- **Architecture**: See `doc/architecture.md` for queue processing flow
- **API Reference**: See `doc/api_endpoints.md` for endpoint details
- **Examples**: See `doc/cron-examples.sh` for configuration templates
