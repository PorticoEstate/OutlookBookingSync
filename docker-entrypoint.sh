#!/bin/bash

# Docker entrypoint script to run Apache and cron together

# Create the cron log file
touch /var/log/cron.log

# Create the crontab file for www-data user
cat > /tmp/crontab << 'EOF'
# Generic Calendar Bridge Cron Jobs - Production Ready

# 1. BIDIRECTIONAL SYNC OPERATIONS
# Sync from booking system to Outlook every 5 minutes
*/5 * * * * curl -s -X POST "http://localhost/bridges/sync/booking_system/outlook" -H "Content-Type: application/json" -d '{"start_date":"$(date +%Y-%m-%d)","end_date":"$(date -d \"+7 days\" +%Y-%m-%d)"}' > /dev/null 2>&1

# Sync from Outlook to booking system every 10 minutes
*/10 * * * * curl -s -X POST "http://localhost/bridges/sync/outlook/booking_system" -H "Content-Type: application/json" -d '{"start_date":"$(date +%Y-%m-%d)","end_date":"$(date -d \"+7 days\" +%Y-%m-%d)"}' > /dev/null 2>&1

# 2. DELETION & CANCELLATION HANDLING (COORDINATED)
# Use centralized deletion processor instead of individual API calls
*/5 * * * * /scripts/enhanced_process_deletions.sh > /dev/null 2>&1

# Alternative: If you prefer individual API calls, use these instead:
# */5 * * * * curl -s -X POST "http://localhost/bridges/process-deletion-queue" > /dev/null 2>&1
# */30 * * * * curl -s -X POST "http://localhost/bridges/sync-deletions" > /dev/null 2>&1

# 3. SYSTEM HEALTH & MONITORING
# Check bridge health every 10 minutes
*/10 * * * * curl -s -X GET "http://localhost/bridges/health" > /dev/null 2>&1

# Run system health checks every 15 minutes
*/15 * * * * curl -s -X GET "http://localhost/health/system" > /dev/null 2>&1

# Run alert checks every 15 minutes
*/15 * * * * curl -s -X POST "http://localhost/alerts/check" > /dev/null 2>&1

# 4. MAINTENANCE OPERATIONS
# Log bridge statistics daily at 8 AM
0 8 * * * curl -s -X GET "http://localhost/bridges/health" >> /var/log/bridge-stats.log 2>&1

# Log cancellation statistics daily at 8:30 AM  
30 8 * * * curl -s -X GET "http://localhost/cancel/stats" >> /var/log/bridge-stats.log 2>&1

# Clean up old alerts weekly on Sunday at 2 AM
0 2 * * 0 curl -s -X DELETE "http://localhost/alerts/old?days=7" > /dev/null 2>&1

# 5. RESOURCE MAPPING MAINTENANCE
# Validate resource mappings weekly on Monday at 1 AM
0 1 * * 1 curl -s -X GET "http://localhost/mappings/resources" > /dev/null 2>&1
EOF

# Install the crontab for www-data user
crontab -u www-data /tmp/crontab

# Remove the temporary file
rm /tmp/crontab

# Start cron service
service cron start

# Start Apache in foreground
exec apache2-foreground
