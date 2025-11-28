#!/bin/bash
# OutlookBookingSync - Cron Job Examples
# 
# This file provides example cron configurations for the queue-based sync system.
# Copy the appropriate lines to your crontab and adjust API_KEY, TENANT_ID, and BASE_URL.

# Configuration
API_KEY="your-api-key-here"
TENANT_ID="your-tenant-id-here"
BASE_URL="http://localhost:8082"

# ==============================================================================
# RECOMMENDED: Unified Queue Processor (Single Job)
# ==============================================================================
# Processes all queue types (webhook, sync, deletion) in one call
# Runs every 5 minutes
# */5 * * * * curl -X POST -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" ${BASE_URL}/bridges/process-queue

# ==============================================================================
# MAINTENANCE JOBS
# ==============================================================================

# Renew webhook subscriptions (every 30 minutes)
# */30 * * * * curl -X POST -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" ${BASE_URL}/maintenance/renew-subscriptions

# Cleanup old queue items (daily at 2 AM) - removes items older than 30 days
# 0 2 * * * curl -X POST -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" ${BASE_URL}/maintenance/cleanup-queue?days=30

# Cleanup old sync logs (daily at 3 AM) - removes logs older than 30 days
# 0 3 * * * curl -X POST -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" ${BASE_URL}/maintenance/cleanup-logs?days=30

# ==============================================================================
# ALTERNATIVE: Separate Queue Processors (Legacy)
# ==============================================================================
# Use these if you need fine-grained control over different queue types

# Process webhook queue only (every 5 minutes)
# */5 * * * * curl -X POST -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" -H "Content-Type: application/json" -d '{"batch_size":50}' ${BASE_URL}/bridges/process-webhook-queue

# Process sync queue only (every 5 minutes)
# */5 * * * * curl -X POST -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" -H "Content-Type: application/json" -d '{"queue_types":["sync"],"batch_size":50}' ${BASE_URL}/bridges/process-queue

# Process deletion queue (every 5 minutes)
# */5 * * * * curl -X POST -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" -H "Content-Type: application/json" -d '{"batch_size":50}' ${BASE_URL}/bridges/process-deletion-queue

# ==============================================================================
# MULTI-TENANT EXAMPLES
# ==============================================================================

# Process queues for multiple tenants (iterate through tenant IDs)
# */5 * * * * for TENANT in tenant1 tenant2 tenant3; do curl -X POST -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT}" ${BASE_URL}/bridges/process-queue; done

# ==============================================================================
# DOCKER CRON SETUP
# ==============================================================================
# If running in Docker, add these to docker-entrypoint.sh or use a cron sidecar

# Example docker-entrypoint.sh addition:
# cat > /etc/cron.d/outlookbookingsync <<EOF
# */5 * * * * root curl -X POST -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" http://localhost:9000/bridges/process-queue
# */30 * * * * root curl -X POST -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" http://localhost:9000/maintenance/renew-subscriptions
# 0 2 * * * root curl -X POST -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" http://localhost:9000/maintenance/cleanup-queue?days=30
# EOF
# cron && tail -f /var/log/cron.log

# ==============================================================================
# MONITORING & ALERTING
# ==============================================================================

# Check for failed queue items (every hour)
# 0 * * * * FAILED=$(curl -s -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" ${BASE_URL}/bridges/queue/failed | jq '.count'); [ $FAILED -gt 10 ] && echo "WARNING: $FAILED failed queue items" | mail -s "Queue Alert" admin@example.com

# ==============================================================================
# ADVANCED: Custom Queue Processing
# ==============================================================================

# Process specific queue types with large batch size (useful for backlog clearing)
# 0 1 * * * curl -X POST -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" -H "Content-Type: application/json" -d '{"queue_types":["sync"],"batch_size":500}' ${BASE_URL}/bridges/process-queue

# Process only webhook queue during business hours (9 AM - 6 PM)
# */5 9-18 * * 1-5 curl -X POST -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" -H "Content-Type: application/json" -d '{"queue_types":["webhook"],"batch_size":100}' ${BASE_URL}/bridges/process-queue

# ==============================================================================
# TROUBLESHOOTING
# ==============================================================================

# Manually trigger immediate sync (queues items for processing)
# curl -X POST -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" -H "Content-Type: application/json" -d '{"start_date":"2025-11-01","end_date":"2025-11-30"}' ${BASE_URL}/bridges/sync/outlook/booking_system

# Check queue statistics
# curl -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" ${BASE_URL}/health/queue-stats

# View failed queue items
# curl -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" ${BASE_URL}/bridges/queue/failed

# Retry a specific failed item
# curl -X POST -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" ${BASE_URL}/bridges/queue/123/retry

# Delete a stuck queue item
# curl -X DELETE -H "X-API-Key: ${API_KEY}" -H "X-Tenant-Id: ${TENANT_ID}" ${BASE_URL}/bridges/queue/123
