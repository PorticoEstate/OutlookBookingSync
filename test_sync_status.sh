#!/bin/bash

# Test script for sync_status functionality
# This script verifies that the sync_status implementation is working correctly

echo "=== Testing sync_status functionality ==="

# 1. Test database schema
echo "1. Checking database schema..."
echo "SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'bridge_mappings' AND column_name IN ('sync_status', 'error_message', 'retry_count', 'updated_at');" | docker exec -i outlookbookingsync-db-1 psql -U postgres -d bridge_db

# 2. Test bridge initialization
echo -e "\n2. Testing bridge initialization..."
curl -s "http://localhost:8082/bridges" | jq '.'

# 3. Test sync status endpoint
echo -e "\n3. Testing sync status endpoint..."
curl -s "http://localhost:8082/health/sync-status" | jq '.'

# 4. Test sync statistics
echo -e "\n4. Testing sync statistics..."
curl -s "http://localhost:8082/bridges/sync-stats" | jq '.'

# 5. Test re-enable failed events
echo -e "\n5. Testing re-enable failed events..."
curl -s -X POST "http://localhost:8082/health/re-enable-failed" \
  -H "Content-Type: application/json" \
  -d '{"bridge_name": "outlook"}' | jq '.'

# 6. Test pending syncs processing
echo -e "\n6. Testing pending syncs processing..."
curl -s -X POST "http://localhost:8082/bridges/process-pending-syncs" \
  -H "Content-Type: application/json" \
  -d '{"batch_size": 10}' | jq '.'

echo -e "\n=== sync_status functionality test complete ==="
