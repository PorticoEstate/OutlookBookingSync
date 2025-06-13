#!/bin/bash

# Docker entrypoint script to run Apache and cron together

# Create the cron log file
touch /var/log/cron.log

# Create the crontab file for www-data user
cat > /tmp/crontab << 'EOF'
# Outlook Calendar Sync Cron Jobs
# Poll for Outlook changes every 15 minutes
*/15 * * * * curl -s -X POST "http://localhost/polling/poll-changes" > /dev/null 2>&1

# Detect missing events (backup deletion detection) every hour
0 * * * * curl -s -X POST "http://localhost/polling/detect-missing-events" > /dev/null 2>&1

# Process booking system cancellations every 10 minutes
*/10 * * * * curl -s -X POST "http://localhost/cancel/detect-and-process" > /dev/null 2>&1

# Log polling statistics daily at 8 AM
0 8 * * * curl -s -X GET "http://localhost/polling/stats" >> /var/log/outlook-sync-stats.log 2>&1
EOF

# Install the crontab for www-data user
crontab -u www-data /tmp/crontab

# Remove the temporary file
rm /tmp/crontab

# Start cron service
service cron start

# Start Apache in foreground
exec apache2-foreground
