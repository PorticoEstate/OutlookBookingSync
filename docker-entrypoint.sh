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

# Create log files in accessible storage directory
touch /var/www/html/storage/logs/bridge-cron.log
touch /var/www/html/storage/logs/bridge-stats.log
touch /var/www/html/storage/logs/cron.log
chmod 666 /var/www/html/storage/logs/bridge-cron.log
chmod 666 /var/www/html/storage/logs/bridge-stats.log
chmod 666 /var/www/html/storage/logs/cron.log

# Ensure storage directories exist and have proper permissions
mkdir -p /var/www/html/storage/sessions /var/www/html/storage/logs
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

cat >> /tmp/crontab << 'EOF'
# Generic Calendar Bridge Cron Jobs - Production Ready with sync_method tracking

# 1. BIDIRECTIONAL SYNC OPERATIONS (Updated with sync_method=cron)
# Sync from booking system to Outlook every 5 minutes
*/5 * * * * START_DATE=$(date +\%Y-\%m-\%d); END_DATE=$(date -d "+30 days" +\%Y-\%m-\%d); curl -s -X POST "http://localhost/bridges/sync/booking_system/outlook?sync_method=cron&start_date=$START_DATE&end_date=$END_DATE&end_date=$END_DATE" -H "X-API-Key: $API_KEY" | sed 's/.*/[booking-to-outlook] &/' >> /var/www/html/storage/logs/bridge-cron.log 2>&1 && echo "" >> /var/www/html/storage/logs/bridge-cron.log

# Sync from Outlook to booking system every 5 minutes
*/5 * * * * START_DATE=$(date +\%Y-\%m-\%d); END_DATE=$(date -d "+30 days" +\%Y-\%m-\%d); curl -s -X POST "http://localhost/bridges/sync/outlook/booking_system?sync_method=cron&handle_deletions=1&start_date=$START_DATE&end_date=$END_DATE" -H "X-API-Key: $API_KEY" | sed 's/.*/[outlook-to-booking] &/' >> /var/www/html/storage/logs/bridge-cron.log 2>&1 && echo "" >> /var/www/html/storage/logs/bridge-cron.log

# 2. DELETION & CANCELLATION HANDLING
# 2a
*/5 * * * * curl -s -X POST "http://localhost/bridges/sync-deletions" -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" -d '{}' | sed 's/.*/[Detecting event cancellations] &/' >> /var/www/html/storage/logs/bridge-cron.log 2>&1 && echo "" >> /var/www/html/storage/logs/bridge-cron.log
# 2b
*/5 * * * * curl -s -X POST "http://localhost/bridges/process-pending-syncs" -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" -d '{"batch_size":50}' | sed 's/.*/[Processing pending syncs with deletions] &/' >> /var/www/html/storage/logs/bridge-cron.log 2>&1 && echo "" >> /var/www/html/storage/logs/bridge-cron.log

# 2c. WEBHOOK QUEUE PROCESSING (Safety net for FastCGI immediate processing)
# Process webhook queue items (from bridge_queue table) every minute as backup
* * * * * curl -s -X POST "http://localhost/bridges/process-webhook-queue" -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" -d '{"batch_size":50}' | sed 's/.*/[webhook-queue] &/' >> /var/www/html/storage/logs/bridge-cron.log 2>&1 && echo "" >> /var/www/html/storage/logs/bridge-cron.log

# Process deletion check queue every 5 minutes
*/5 * * * * curl -s -X POST "http://localhost/bridges/process-deletion-queue" -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" -d '{"batch_size":25}' | sed 's/.*/[deletion-queue] &/' >> /var/www/html/storage/logs/bridge-cron.log 2>&1 && echo "" >> /var/www/html/storage/logs/bridge-cron.log

# 3. SYSTEM HEALTH & MONITORING
# Check bridge health every 10 minutes
*/10 * * * * curl -s -X GET "http://localhost/bridges/health" -H "X-API-Key: $API_KEY" | sed 's/.*/[bridge-health] &/' >> /var/www/html/storage/logs/bridge-cron.log 2>&1 && echo "" >> /var/www/html/storage/logs/bridge-cron.log

# Run system health checks every 15 minutes
*/15 * * * * curl -s -X GET "http://localhost/health/system" -H "X-API-Key: $API_KEY" | sed 's/.*/[system-health] &/' >> /var/www/html/storage/logs/bridge-cron.log 2>&1 && echo "" >> /var/www/html/storage/logs/bridge-cron.log

# Run alert checks every 15 minutes (this will now detect cron activity properly)
*/15 * * * * curl -s -X POST "http://localhost/alerts/check" -H "X-API-Key: $API_KEY" | sed 's/.*/[alerts] &/' >> /var/www/html/storage/logs/bridge-cron.log 2>&1 && echo "" >> /var/www/html/storage/logs/bridge-cron.log

# Log cancellation statistics daily at 8:30 AM  
30 8 * * * curl -s -X GET "http://localhost/bridges/sync-stats" -H "X-API-Key: $API_KEY" | sed 's/.*/[stats] &/' >> /var/www/html/storage/logs/bridge-stats.log 2>&1 && echo "" >> /var/www/html/storage/logs/bridge-stats.log

# Clean up old alerts weekly on Sunday at 2 AM
0 2 * * 0 curl -s -X DELETE "http://localhost/alerts/old?days=7" -H "X-API-Key: $API_KEY" | sed 's/.*/[cleanup-alerts] &/' >> /var/www/html/storage/logs/bridge-cron.log 2>&1 && echo "" >> /var/www/html/storage/logs/bridge-cron.log

# Cleanup old sync logs daily at 03:00 (keeps ${CLEANUP_DAYS} days)
0 3 * * * curl -s -X POST "http://localhost/maintenance/cleanup-logs?days=${CLEANUP_DAYS}" -H "X-API-Key: $API_KEY" | sed 's/.*/[cleanup] &/' >> /var/www/html/storage/logs/bridge-cron.log 2>&1 && echo "" >> /var/www/html/storage/logs/bridge-cron.log
# Renew expiring webhook subscriptions hourly (renew anything expiring in next ${RENEW_MINUTES} minutes)
0 * * * * curl -s -X POST "http://localhost/maintenance/renew-subscriptions?bridge=outlook&renew_before_minutes=${RENEW_MINUTES}&limit=100" -H "X-API-Key: $API_KEY" | sed 's/.*/[renew] &/' >> /var/www/html/storage/logs/bridge-cron.log 2>&1 && echo "" >> /var/www/html/storage/logs/bridge-cron.log
# 5. RESOURCE MAPPING MAINTENANCE
# Validate resource mappings weekly on Monday at 1 AM
0 1 * * 1 curl -s -X GET "http://localhost/mappings/resources" -H "X-API-Key: $API_KEY" | sed 's/.*/[resource-mappings] &/' >> /var/www/html/storage/logs/bridge-cron.log 2>&1 && echo "" >> /var/www/html/storage/logs/bridge-cron.log

# Test job to verify cron is working (runs every minute)
# Test job to verify cron is working (runs every minute)
* * * * * echo "$(date): Cron test job executed" >> /var/www/html/storage/logs/cron.log && echo "" >> /var/www/html/storage/logs/cron.log
EOF

# Install the crontab for www-data user
crontab -u www-data /tmp/crontab

# Remove the temporary file
rm /tmp/crontab


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