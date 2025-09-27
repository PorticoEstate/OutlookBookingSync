#!/bin/bash

# Enhanced Deletion Sync Processor 
# Centralized deletion and cancellation processing for calendar bridge
# This replaces multiple individual cron jobs with a coordinated approach

set -e

BRIDGE_URL="${BRIDGE_URL:-http://localhost}"
API_KEY_HEADER="${API_KEY:+-H \"X-API-Key: ${API_KEY}\"}"
LOG_FILE="${LOG_FILE:-/var/log/bridge-deletion-sync.log}"
TENANT_MODE="${TENANT_MODE:-single}"
SPECIFIC_TENANT="${1:-}"

# Function to log with timestamp
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S'): $1" | tee -a "$LOG_FILE"
}

# Function to make API call with proper error handling
api_call() {
    local endpoint=$1
    local description=$2
    local expected_time=${3:-30}  # Expected max time in seconds
    local tenant_header=${4:-}    # Optional: "-H X-Tenant-Id: <id>"
    local body_data=${5:-'{"batch_size":25}'}  # Optional: JSON body data

    log "🔄 Starting: $description"

    # Use timeout to prevent hanging
    response=$(timeout "$expected_time" curl -s -X POST "$BRIDGE_URL$endpoint" \
        -H "Content-Type: application/json" \
        -H "User-Agent: BridgeDeletionProcessor/1.0" \
        -d "$body_data" \
        $API_KEY_HEADER $tenant_header) || {
        log "❌ TIMEOUT: $description (exceeded ${expected_time}s)"
        return 1
    }

    # Check if response is valid JSON
    if echo "$response" | jq -e '.success' > /dev/null 2>&1; then
        local success
        success=$(echo "$response" | jq -r '.success')
        if [ "$success" = "true" ]; then
            log "✅ SUCCESS: $description"
            # Log results if available
            local results
            results=$(echo "$response" | jq -r '.results // "No detailed results"')
            log "   📊 Results: $results"
        else
            local error
            error=$(echo "$response" | jq -r '.error // "Unknown error"')
            log "❌ API ERROR: $description - $error"
            return 1
        fi
    else
        log "❌ INVALID RESPONSE: $description"
        log "   📄 Raw response: $response"
        return 1
    fi
}

# Detect whether the bridge exposes multi-tenant routes
# Fetch tenant list from admin endpoint
get_all_tenants() {
    local resp
    resp=$(curl -s -X GET "$BRIDGE_URL/admin/tenants" -H "Content-Type: application/json" $API_KEY_HEADER)
    echo "$resp" | jq -r '.tenants[].id // empty'
}

# Main deletion processing workflow
main() {
    log "🚀 Starting deletion sync processing (Mode: $TENANT_MODE)"
    
    local errors=0

    if [[ "$TENANT_MODE" == "multi" ]]; then
        if [[ -n "$SPECIFIC_TENANT" ]]; then
            # Process specific tenant
            log "🏢 Processing tenant: $SPECIFIC_TENANT"
            process_tenant_deletions "$SPECIFIC_TENANT" || ((errors++))
        else
            # Process all tenants
            log "🌍 Processing all tenants"
            process_all_tenants || ((errors++))
        fi
    else
        # Single tenant mode (backward compatibility)
        log "🏠 Processing single tenant"
        process_single_tenant_deletions || ((errors++))
    fi
    
    # Final status
    if [ $errors -eq 0 ]; then
        log "✅ Deletion sync processing completed successfully"
    else
        log "⚠️  Deletion sync completed with $errors errors"
        exit 1
    fi
}

# Process deletions for single tenant (current behavior)
process_single_tenant_deletions() {
    local errors=0
    
    # Step 1: Process deletion queue (from webhooks) - High priority
    api_call "/bridges/process-deletion-queue" "Processing webhook deletion queue" 60 "" '{"batch_size":25}' || ((errors++))
    
    # Step 2: Detect cancellations (inactive events) - Medium priority  
    api_call "/bridges/sync-deletions" "Detecting event cancellations" 120 "" '{}' || ((errors++))
    
    # Step 3: Process pending syncs that may have deletion status
    api_call "/bridges/process-pending-syncs" "Processing pending syncs with deletions" 180 "" '{"batch_size":50}' || ((errors++))
   
    return $errors
}

# Process deletions for specific tenant
process_tenant_deletions() {
    local tenant_id=$1
    local errors=0
    
    log "🔄 Processing tenant: $tenant_id"

    local TENANT_HEADER="-H X-Tenant-Id: $tenant_id"
    # Tenant-specific deletion processing using header-based scoping
 #   api_call "/bridges/process-deletion-queue" "Processing $tenant_id webhook deletions" 60 "$TENANT_HEADER" '{"batch_size":25}' || ((errors++))
 #   api_call "/bridges/sync-deletions" "Detecting $tenant_id cancellations" 120 "$TENANT_HEADER" '{}' || ((errors++))
 #   api_call "/bridges/process-pending-syncs" "Processing $tenant_id pending syncs" 180 "$TENANT_HEADER" '{"batch_size":50}' || ((errors++))
    
    return $errors
}

# Process deletions for all tenants
process_all_tenants() {
    local errors=0
    
    # Get list of active tenants via admin endpoint
    local tenants
    tenants=$(get_all_tenants)
    
    if [ -z "$tenants" ]; then
        log "⚠️  No active tenants found"
        return 0
    fi
    
    # Process each tenant
    for tenant in $tenants; do
        log "🏢 Processing tenant: $tenant"
        process_tenant_deletions "$tenant" || ((errors++))
        
        # Small delay between tenants to prevent resource conflicts
        sleep 2
    done
    
    # Also run global cleanup operations
    log "🌍 Running global cleanup operations"
    api_call "/bridges/process-deletion-queue" "Global deletion queue cleanup" 300 "" '{"batch_size":50}' || ((errors++))
    
    return $errors
}

# Health check before processing
health_check() {
    log "🏥 Performing health check"
    
    local health_response
    health_response=$(curl -s -X GET "$BRIDGE_URL/health" -H "Content-Type: application/json" $API_KEY_HEADER)
    
    if echo "$health_response" | jq -e '.status' > /dev/null 2>&1; then
        local status=$(echo "$health_response" | jq -r '.status')
        if [ "$status" != "healthy" ]; then
            log "⚠️  System health check failed: $status"
            log "   Continuing with deletion processing anyway..."
        else
            log "✅ System health check passed"
        fi
    else
        log "⚠️  Could not determine system health, continuing anyway"
    fi
}

# Signal handlers for graceful shutdown
cleanup() {
    log "🛑 Received interrupt signal, cleaning up..."
    exit 1
}

trap cleanup SIGINT SIGTERM

# Main execution
log "=================================================="
log "🔄 Bridge Deletion Sync Processor Starting"
log "   Mode: $TENANT_MODE"
log "   URL: $BRIDGE_URL" 
log "   Tenant: ${SPECIFIC_TENANT:-'all'}"
log "=================================================="

# Perform health check
health_check

# Run main processing
main

log "=================================================="
log "🏁 Bridge Deletion Sync Processor Finished"
log "=================================================="
