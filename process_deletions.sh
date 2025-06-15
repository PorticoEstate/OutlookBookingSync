#!/bin/bash

# LEGACY: Deletion Sync Processor (Original Version)
# 
# ⚠️  RELATIONSHIP TO CRON JOBS:
# This script was originally designed to be called manually or by external cron jobs.
# However, the docker-entrypoint.sh ALSO contains individual cron jobs that call
# the same API endpoints, creating redundancy:
#
# REDUNDANT SETUP (CURRENT):
# 1. docker-entrypoint.sh cron: */5 * * * * curl /bridges/process-deletion-queue
# 2. docker-entrypoint.sh cron: */5 * * * * curl /bridges/sync-deletions  
# 3. docker-entrypoint.sh cron: */30 * * * * curl /bridges/sync-deletions
# 4. This script: Calls the same endpoints when run manually
#
# RECOMMENDED SETUP:
# - Use EITHER this script OR the individual cron jobs, not both
# - For better coordination, use /scripts/enhanced_process_deletions.sh
# - Or call this script from cron instead of individual API calls
#
# MIGRATION PATH:
# 1. Replace individual cron jobs with: */5 * * * * /path/to/process_deletions.sh
# 2. Or migrate to enhanced_process_deletions.sh for multi-tenant support

set -e

BRIDGE_URL="${BRIDGE_URL:-http://localhost:8080}"
LOG_FILE="${LOG_FILE:-/var/log/bridge-deletion-sync.log}"

echo "$(date): Starting deletion sync processing..." >> "$LOG_FILE"
echo "⚠️  Note: This is the legacy script. Consider migrating to enhanced_process_deletions.sh" >> "$LOG_FILE"

# Function to log with timestamp
log() {
    echo "$(date): $1" >> "$LOG_FILE"
}

# Function to make API call and log result
api_call() {
    local endpoint=$1
    local description=$2
    
    log "Processing: $description"
    
    response=$(curl -s -X POST "$BRIDGE_URL$endpoint" -H "Content-Type: application/json")
    
    if echo "$response" | jq -e '.success' > /dev/null 2>&1; then
        log "✅ SUCCESS: $description"
        echo "$response" | jq '.results' >> "$LOG_FILE"
    else
        log "❌ FAILED: $description"
        echo "$response" >> "$LOG_FILE"
    fi
}

# Process deletion queue (from webhooks)
api_call "/bridges/process-deletion-queue" "Processing webhook deletion queue"

# Run manual deletion sync (check recent events)
api_call "/bridges/sync-deletions" "Manual deletion sync check"

log "Deletion sync processing completed"
echo "" >> "$LOG_FILE"
