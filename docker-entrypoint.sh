#!/bin/bash

# Docker entrypoint script to run Apache and cron together

# Create the cron log file and enable logging
touch /var/log/cron.log
chmod 666 /var/log/cron.log

# Enable cron logging by uncommenting the line in rsyslog config
# (if rsyslog is available)
if [ -f /etc/rsyslog.d/50-default.conf ]; then
    sed -i 's/^#cron\.\*/cron.*/' /etc/rsyslog.d/50-default.conf
fi

# Create a specific log for our bridge cron jobs
touch /var/log/bridge-cron.log
chmod 666 /var/log/bridge-cron.log

# Create the crontab file for www-data user with better logging
# Ensure API_KEY is available to cron jobs and configure cleanup days (default 30)
echo "API_KEY=${API_KEY}" > /tmp/crontab
CLEANUP_DAYS=${CLEANUP_DAYS:-30}
echo "CLEANUP_DAYS=${CLEANUP_DAYS}" >> /tmp/crontab

cat >> /tmp/crontab << 'EOF'
# Generic Calendar Bridge Cron Jobs - Production Ready with sync_method tracking

# 1. BIDIRECTIONAL SYNC OPERATIONS (Updated with sync_method=cron)
# Sync from booking system to Outlook every 5 minutes
*/5 * * * * START_DATE=$(date +\%Y-\%m-\%d); END_DATE=$(date -d "+7 days" +\%Y-\%m-\%d); curl -s -X POST "http://localhost/bridges/sync/booking_system/outlook?sync_method=cron&start_date=$START_DATE&end_date=$END_DATE" -H "api_key: $API_KEY" >> /var/log/bridge-cron.log 2>&1

# Sync from Outlook to booking system every 10 minutes with deletion handling
*/10 * * * * START_DATE=$(date +\%Y-\%m-\%d); END_DATE=$(date -d "+7 days" +\%Y-\%m-\%d); curl -s -X POST "http://localhost/bridges/sync/outlook/booking_system?sync_method=cron&handle_deletions=1&start_date=$START_DATE&end_date=$END_DATE" -H "api_key: $API_KEY" >> /var/log/bridge-cron.log 2>&1

# 2. DELETION & CANCELLATION HANDLING (COORDINATED)
# Use centralized deletion processor instead of individual API calls
*/5 * * * * if [ -f /scripts/enhanced_process_deletions.sh ]; then API_KEY="$API_KEY" /scripts/enhanced_process_deletions.sh >> /var/log/bridge-cron.log 2>&1; else echo "$(date): Script not found: /scripts/enhanced_process_deletions.sh" >> /var/log/bridge-cron.log; fi

# 3. SYSTEM HEALTH & MONITORING
# Check bridge health every 10 minutes
*/10 * * * * curl -s -X GET "http://localhost/bridges/health" -H "api_key: $API_KEY" >> /var/log/bridge-cron.log 2>&1

# Run system health checks every 15 minutes
*/15 * * * * curl -s -X GET "http://localhost/health/system" -H "api_key: $API_KEY" >> /var/log/bridge-cron.log 2>&1

# Run alert checks every 15 minutes (this will now detect cron activity properly)
*/15 * * * * curl -s -X POST "http://localhost/alerts/check" -H "api_key: $API_KEY" >> /var/log/bridge-cron.log 2>&1

# 4. MAINTENANCE OPERATIONS
# Log bridge statistics daily at 8 AM
0 8 * * * curl -s -X GET "http://localhost/bridges/health" -H "api_key: $API_KEY" >> /var/log/bridge-stats.log 2>&1

# Log cancellation statistics daily at 8:30 AM  
30 8 * * * curl -s -X GET "http://localhost/bridges/sync-stats" -H "api_key: $API_KEY" >> /var/log/bridge-stats.log 2>&1

# Clean up old alerts weekly on Sunday at 2 AM
0 2 * * 0 curl -s -X DELETE "http://localhost/alerts/old?days=7" -H "api_key: $API_KEY" >> /var/log/bridge-cron.log 2>&1

# Cleanup old sync logs daily at 03:00 (keeps ${CLEANUP_DAYS} days)
0 3 * * * curl -s -X POST "http://localhost/maintenance/cleanup-logs?days=${CLEANUP_DAYS}" -H "api_key: $API_KEY" | sed 's/^/[cleanup] /' >> /var/log/bridge-cron.log 2>&1
# 5. RESOURCE MAPPING MAINTENANCE
# Validate resource mappings weekly on Monday at 1 AM
0 1 * * 1 curl -s -X GET "http://localhost/mappings/resources" -H "api_key: $API_KEY" >> /var/log/bridge-cron.log 2>&1

# Test job to verify cron is working (runs every minute)
* * * * * echo "$(date): Cron test job executed" >> /var/log/bridge-cron.log
EOF

# Install the crontab for www-data user
crontab -u www-data /tmp/crontab

# Remove the temporary file
rm /tmp/crontab

# Start cron service
service cron start

# Start Apache in foreground
exec apache2-foreground