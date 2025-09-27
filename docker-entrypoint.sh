#!/bin/bash

# Docker entrypoint script to run PHP-FPM, Apache and cron together

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

# Ensure storage directories exist and have proper permissions
mkdir -p /var/www/html/storage/sessions
chown -R www-data:www-data /var/www/html/storage
chmod -R 755 /var/www/html/storage
chmod -R 750 /var/www/html/storage/sessions

# Create the crontab file for www-data user with better logging
# Ensure API_KEY is available to cron jobs and configure cleanup days (default 30)
echo "API_KEY=${API_KEY}" > /tmp/crontab
CLEANUP_DAYS=${CLEANUP_DAYS:-30}
echo "CLEANUP_DAYS=${CLEANUP_DAYS}" >> /tmp/crontab
RENEW_MINUTES=${RENEW_MINUTES:-1440}
echo "RENEW_MINUTES=${RENEW_MINUTES}" >> /tmp/crontab
BRIDGE_URL=${BRIDGE_URL:-http://localhost}
echo "BRIDGE_URL=${BRIDGE_URL}" >> /tmp/crontab
# Set TENANT_MODE to 'multi' to process all tenants (uses admin /admin/tenants endpoint)
TENANT_MODE=${TENANT_MODE:-single}
echo "TENANT_MODE=${TENANT_MODE}" >> /tmp/crontab

cat >> /tmp/crontab << 'EOF'
# Generic Calendar Bridge Cron Jobs - Production Ready with sync_method tracking

# 1. BIDIRECTIONAL SYNC OPERATIONS (Updated with sync_method=cron)
# Sync from booking system to Outlook every 5 minutes
*/5 * * * * START_DATE=$(date +\%Y-\%m-\%d); END_DATE=$(date -d "+7 days" +\%Y-\%m-\%d); curl -s -X POST "http://localhost/bridges/sync/booking_system/outlook?sync_method=cron&start_date=$START_DATE&end_date=$END_DATE" -H "X-API-Key: $API_KEY" >> /var/log/bridge-cron.log 2>&1

# Sync from Outlook to booking system every 10 minutes with deletion handling
*/10 * * * * START_DATE=$(date +\%Y-\%m-\%d); END_DATE=$(date -d "+7 days" +\%Y-\%m-\%d); curl -s -X POST "http://localhost/bridges/sync/outlook/booking_system?sync_method=cron&handle_deletions=1&start_date=$START_DATE&end_date=$END_DATE" -H "X-API-Key: $API_KEY" >> /var/log/bridge-cron.log 2>&1

# 2. DELETION & CANCELLATION HANDLING (COORDINATED)
# Use centralized deletion processor instead of individual API calls (supports TENANT_MODE=single|multi)
*/5 * * * * if [ -f /var/www/html/scripts/enhanced_process_deletions.sh ]; then API_KEY="$API_KEY" BRIDGE_URL="$BRIDGE_URL" TENANT_MODE="$TENANT_MODE" /var/www/html/scripts/enhanced_process_deletions.sh >> /var/log/bridge-cron.log 2>&1; else echo "$(date): Script not found: /var/www/html/scripts/enhanced_process_deletions.sh" >> /var/log/bridge-cron.log; fi


# 2c. WEBHOOK QUEUE PROCESSING (Safety net for FastCGI immediate processing)
# Process webhook queue items (from bridge_queue table) every minute as backup
* * * * * curl -s -X POST "http://localhost/bridges/process-webhook-queue" -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" -d '{"batch_size":50}' | sed 's/^/[webhook-queue] /' >> /var/log/bridge-cron.log 2>&1

# Process pending sync operations (from bridge_mappings table) every 5 minutes  
*/5 * * * * curl -s -X POST "http://localhost/bridges/process-pending-syncs" -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" -d '{"batch_size":50}' | sed 's/^/[pending-syncs] /' >> /var/log/bridge-cron.log 2>&1

# Process deletion check queue every 5 minutes
*/5 * * * * curl -s -X POST "http://localhost/bridges/process-deletion-queue" -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" -d '{"batch_size":25}' | sed 's/^/[deletion-queue] /' >> /var/log/bridge-cron.log 2>&1

# 3. SYSTEM HEALTH & MONITORING
# Check bridge health every 10 minutes
*/10 * * * * curl -s -X GET "http://localhost/bridges/health" -H "X-API-Key: $API_KEY" >> /var/log/bridge-cron.log 2>&1

# Run system health checks every 15 minutes
*/15 * * * * curl -s -X GET "http://localhost/health/system" -H "X-API-Key: $API_KEY" >> /var/log/bridge-cron.log 2>&1

# Run alert checks every 15 minutes (this will now detect cron activity properly)
*/15 * * * * curl -s -X POST "http://localhost/alerts/check" -H "X-API-Key: $API_KEY" >> /var/log/bridge-cron.log 2>&1

# 4. MAINTENANCE OPERATIONS
# Log bridge statistics daily at 8 AM
0 8 * * * curl -s -X GET "http://localhost/bridges/health" -H "X-API-Key: $API_KEY" >> /var/log/bridge-stats.log 2>&1

# Log cancellation statistics daily at 8:30 AM  
30 8 * * * curl -s -X GET "http://localhost/bridges/sync-stats" -H "X-API-Key: $API_KEY" >> /var/log/bridge-stats.log 2>&1

# Clean up old alerts weekly on Sunday at 2 AM
0 2 * * 0 curl -s -X DELETE "http://localhost/alerts/old?days=7" -H "X-API-Key: $API_KEY" >> /var/log/bridge-cron.log 2>&1

# Cleanup old sync logs daily at 03:00 (keeps ${CLEANUP_DAYS} days)
0 3 * * * curl -s -X POST "http://localhost/maintenance/cleanup-logs?days=${CLEANUP_DAYS}" -H "X-API-Key: $API_KEY" | sed 's/^/[cleanup] /' >> /var/log/bridge-cron.log 2>&1
# Renew expiring webhook subscriptions hourly (renew anything expiring in next ${RENEW_MINUTES} minutes)
0 * * * * curl -s -X POST "http://localhost/maintenance/renew-subscriptions?bridge=outlook&renew_before_minutes=${RENEW_MINUTES}&limit=100" -H "X-API-Key: $API_KEY" | sed 's/^/[renew] /' >> /var/log/bridge-cron.log 2>&1
# 5. RESOURCE MAPPING MAINTENANCE
# Validate resource mappings weekly on Monday at 1 AM
0 1 * * 1 curl -s -X GET "http://localhost/mappings/resources" -H "X-API-Key: $API_KEY" >> /var/log/bridge-cron.log 2>&1

# Test job to verify cron is working (runs every minute)
* * * * * echo "$(date): Cron test job executed" >> /var/log/bridge-cron.log
EOF

# Install the crontab for www-data user
crontab -u www-data /tmp/crontab

# Remove the temporary file
rm /tmp/crontab

# Ensure helper scripts are executable
chmod +x /var/www/html/scripts/enhanced_process_deletions.sh 2>/dev/null || true
chmod +x /scripts/multi_tenant_sync.sh 2>/dev/null || true

# Start cron service
service cron start


# Check if composer dependencies need to be updated (development scenario with mounted volumes)
if [ -f /var/www/html/composer.json ]; then
    if [ ! -d /var/www/html/vendor ] || [ ! -f /var/www/html/vendor/autoload.php ] || [ /var/www/html/composer.json -nt /var/www/html/vendor/composer/installed.json ]; then
        echo "Updating Composer dependencies..."
        cd /var/www/html && composer install --no-dev --optimize-autoloader
    else
        echo "Composer dependencies are up to date"
    fi
fi


# Start PHP-FPM in background
php-fpm --daemonize

# Start Apache in foreground (using service command since apache2-foreground doesn't exist in FPM image)
exec apache2ctl -D FOREGROUND