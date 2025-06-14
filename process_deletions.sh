#!/bin/bash

# Automated Deletion Sync Processor
# This script should be run periodically (e.g., every 5-15 minutes) via cron
# to process deletion checks and maintain data consistency

set -e

BRIDGE_URL="${BRIDGE_URL:-http://localhost:8080}"
LOG_FILE="${LOG_FILE:-/var/log/bridge-deletion-sync.log}"

echo "$(date): Starting deletion sync processing..." >> "$LOG_FILE"

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
