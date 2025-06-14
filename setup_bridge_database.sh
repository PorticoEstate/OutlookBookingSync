#!/bin/bash

# Bridge Database Migration Script
# This script sets up the generic bridge database schema

set -e

# Database connection parameters from environment
DB_HOST=${DB_HOST:-localhost}
DB_PORT=${DB_PORT:-5432}
DB_NAME=${DB_NAME:-outlook_sync}
DB_USER=${DB_USER:-postgres}

echo "Setting up Generic Calendar Bridge Database Schema..."
echo "Host: $DB_HOST:$DB_PORT"
echo "Database: $DB_NAME"
echo "User: $DB_USER"

# Check if psql is available
if ! command -v psql &> /dev/null; then
    echo "Error: psql command not found. Please install PostgreSQL client."
    exit 1
fi

# Function to execute SQL file
execute_sql() {
    local sql_file=$1
    local description=$2
    
    echo "Executing: $description..."
    
    if [ -f "$sql_file" ]; then
        PGPASSWORD=$DB_PASS psql -h $DB_HOST -p $DB_PORT -U $DB_USER -d $DB_NAME -f "$sql_file"
        echo "✅ $description completed successfully"
    else
        echo "❌ Error: SQL file $sql_file not found"
        exit 1
    fi
}

# Create bridge schema
execute_sql "database/bridge_schema.sql" "Bridge database schema setup"

echo ""
echo "🎉 Generic Calendar Bridge database setup completed successfully!"
echo ""
echo "Bridge tables created:"
echo "  - bridge_mappings (sync relationships)"
echo "  - bridge_configs (bridge configurations)"  
echo "  - bridge_sync_logs (audit trail)"
echo "  - bridge_subscriptions (webhook subscriptions)"
echo "  - bridge_queue (async processing queue)"
echo ""
echo "Views created:"
echo "  - v_active_bridge_mappings"
echo "  - v_bridge_sync_stats"
echo "  - v_bridge_health"
echo ""
echo "You can now use the generic bridge API endpoints:"
echo "  GET  /bridges - List all bridges"
echo "  GET  /bridges/{name}/calendars - Get calendars for a bridge"
echo "  POST /bridges/sync/{source}/{target} - Sync between bridges"
echo "  GET  /bridges/health - Check bridge health"
echo ""
