#!/bin/bash

# Generic Calendar Bridge Test Script
# Tests the basic functionality of the calendar bridge system

set -e

BASE_URL="http://localhost"
API_KEY=${API_KEY:-"your_api_key_here"}

echo "🧪 Testing Generic Calendar Bridge Implementation"
echo "=============================================="
echo ""

# Function to make API calls
api_call() {
    local method=$1
    local endpoint=$2
    local data=$3
    local description=$4
    
    echo "Testing: $description"
    echo "  $method $endpoint"
    
    if [ -n "$data" ]; then
        curl -s -X $method \
             -H "Content-Type: application/json" \
             -H "X-API-Key: $API_KEY" \
             -d "$data" \
             "$BASE_URL$endpoint" | jq '.' || echo "❌ Failed"
    else
        curl -s -X $method \
             -H "X-API-Key: $API_KEY" \
             "$BASE_URL$endpoint" | jq '.' || echo "❌ Failed"
    fi
    
    echo ""
}

# Test 1: List all available bridges
api_call "GET" "/bridges" "" "List all available bridges"

# Test 2: Check bridge health
api_call "GET" "/bridges/health" "" "Check bridge health status"

# Test 3: Get Outlook calendars (if bridge is working)
api_call "GET" "/bridges/outlook/calendars" "" "Get Outlook calendars"

# Test 4: Get booking system calendars (if bridge is working)
api_call "GET" "/bridges/booking_system/calendars" "" "Get booking system calendars"

# Test 5: Test dry run sync between bridges
SYNC_DATA='{
    "source_calendar_id": "test@example.com",
    "target_calendar_id": "123", 
    "start_date": "2025-06-14",
    "end_date": "2025-06-21",
    "dry_run": true
}'

api_call "POST" "/bridges/sync/outlook/booking_system" "$SYNC_DATA" "Test dry run sync (Outlook → Booking System)"

# Test 6: Reverse sync dry run
REVERSE_SYNC_DATA='{
    "source_calendar_id": "123",
    "target_calendar_id": "test@example.com",
    "start_date": "2025-06-14", 
    "end_date": "2025-06-21",
    "dry_run": true
}'

api_call "POST" "/bridges/sync/booking_system/outlook" "$REVERSE_SYNC_DATA" "Test dry run sync (Booking System → Outlook)"

echo "🎉 Generic Calendar Bridge testing completed!"
echo ""
echo "Key endpoints tested:"
echo "  ✅ Bridge listing and health checks"
echo "  ✅ Calendar discovery for both bridges"
echo "  ✅ Bidirectional sync capabilities (dry run)"
echo ""
echo "To perform actual sync operations, remove 'dry_run': true from the requests."
echo "Make sure to configure proper calendar IDs for your environment."
