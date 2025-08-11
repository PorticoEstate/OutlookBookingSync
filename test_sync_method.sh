#!/bin/bash

# Test script for sync_method implementation
echo "Testing sync_method implementation..."

BASE_URL="http://localhost:8082"

echo "1. Testing manual sync (default):"
curl -s -X POST "$BASE_URL/bridges/sync/outlook/booking_system?start_date=2025-08-01" \
  -H "Content-Type: application/json" | jq -r '.success, .summary.total_synced // "no data"'

echo -e "\n2. Testing polling sync:"
curl -s -X POST "$BASE_URL/bridges/sync/outlook/booking_system?sync_method=polling&start_date=2025-08-01" \
  -H "Content-Type: application/json" | jq -r '.success, .summary.total_synced // "no data"'

echo -e "\n3. Testing automated sync with deletions:"
curl -s -X POST "$BASE_URL/bridges/sync/outlook/booking_system?sync_method=automated&handle_deletions=1&start_date=2025-08-01" \
  -H "Content-Type: application/json" | jq -r '.success, .summary.total_synced // "no data"'

echo -e "\n4. Testing cron activity detection:"
curl -s -X POST "$BASE_URL/alerts/check" | jq -r '.success, .alerts_triggered'

echo -e "\nDone!"
