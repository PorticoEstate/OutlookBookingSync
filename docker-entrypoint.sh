#!/bin/bash

# Docker entrypoint script to run Apache and cron together

# Create the cron log file
touch /var/log/cron.log

# Create the crontab file for www-data user
cat > /tmp/crontab << 'EOF'
# Outlook Calendar Sync Cron Jobs - Complete Bidirectional Sync

# 1. BOOKING SYSTEM → OUTLOOK SYNC
# Sync pending booking system items to Outlook every 5 minutes
*/5 * * * * curl -s -X POST "http://localhost/sync/to-outlook" > /dev/null 2>&1

# 2. OUTLOOK → BOOKING SYSTEM SYNC  
# Poll for Outlook changes every 15 minutes
*/15 * * * * curl -s -X POST "http://localhost/polling/poll-changes" > /dev/null 2>&1

# Import new Outlook events to mapping table every 10 minutes
*/10 * * * * curl -s -X POST "http://localhost/sync/from-outlook" > /dev/null 2>&1

# Process imported Outlook events into full booking entries every 10 minutes
*/10 * * * * curl -s -X POST "http://localhost/booking/process-imports" > /dev/null 2>&1

# 3. CANCELLATION & DELETION HANDLING
# Process booking system cancellations every 5 minutes
*/5 * * * * curl -s -X POST "http://localhost/cancel/detect-and-process" > /dev/null 2>&1

# Detect missing/deleted Outlook events every hour
0 * * * * curl -s -X POST "http://localhost/polling/detect-missing-events" > /dev/null 2>&1

# 4. MAINTENANCE OPERATIONS
# Clean up orphaned mappings every 6 hours
0 */6 * * * curl -s -X DELETE "http://localhost/sync/cleanup-orphaned" > /dev/null 2>&1

# 5. MONITORING & LOGGING
# Log sync statistics daily at 8 AM
0 8 * * * curl -s -X GET "http://localhost/sync/stats" >> /var/log/outlook-sync-stats.log 2>&1

# Log polling statistics daily at 8:30 AM  
30 8 * * * curl -s -X GET "http://localhost/polling/stats" >> /var/log/outlook-sync-stats.log 2>&1

# Run alert checks every 15 minutes
*/15 * * * * curl -s -X POST "http://localhost/alerts/check" > /dev/null 2>&1

# Clean up old alerts weekly on Sunday at 2 AM
0 2 * * 0 curl -s -X DELETE "http://localhost/alerts/old?days=7" > /dev/null 2>&1
EOF

# Install the crontab for www-data user
crontab -u www-data /tmp/crontab

# Remove the temporary file
rm /tmp/crontab

# Start cron service
service cron start

# Start Apache in foreground
exec apache2-foreground
