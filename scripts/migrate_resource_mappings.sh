#!/bin/bash

# Bridge Resource Mappings Migration Script
# This script applies the column rename migration to bridge_resource_mappings table

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}Starting Bridge Resource Mappings Migration...${NC}"

# Check if migration file exists
MIGRATION_FILE="/opt/OutlookBookingSync/database/migrations/001_rename_resource_mapping_columns.sql"
if [ ! -f "$MIGRATION_FILE" ]; then
    echo -e "${RED}Error: Migration file not found at $MIGRATION_FILE${NC}"
    exit 1
fi

# Load database configuration from environment
if [ -f "/opt/OutlookBookingSync/.env" ]; then
    source /opt/OutlookBookingSync/.env
else
    echo -e "${YELLOW}Warning: .env file not found, using default database connection${NC}"
    DB_HOST=${DB_HOST:-localhost}
    DB_PORT=${DB_PORT:-5432}
    DB_NAME=${DB_NAME:-outlook_booking_sync}
    DB_USER=${DB_USER:-postgres}
fi

# Construct database connection string
DB_CONNECTION="postgresql://${DB_USER}:${DB_PASS}@${DB_HOST}:${DB_PORT}/${DB_NAME}"

echo -e "${YELLOW}Database: ${DB_HOST}:${DB_PORT}/${DB_NAME}${NC}"

# Backup existing data
echo -e "${YELLOW}Creating backup of bridge_resource_mappings table...${NC}"
BACKUP_FILE="/tmp/bridge_resource_mappings_backup_$(date +%Y%m%d_%H%M%S).sql"

psql "$DB_CONNECTION" -c "
COPY bridge_resource_mappings TO '$BACKUP_FILE' WITH (FORMAT CSV, HEADER);
" || {
    echo -e "${RED}Error: Failed to create backup${NC}"
    exit 1
}

echo -e "${GREEN}Backup created: $BACKUP_FILE${NC}"

# Show current table structure
echo -e "${YELLOW}Current table structure:${NC}"
psql "$DB_CONNECTION" -c "\d bridge_resource_mappings"

# Show current data sample
echo -e "${YELLOW}Current data sample (first 3 rows):${NC}"
psql "$DB_CONNECTION" -c "SELECT * FROM bridge_resource_mappings LIMIT 3;"

# Confirm migration
echo -e "${YELLOW}Ready to apply migration. This will:${NC}"
echo "1. Add new semantic columns (source_calendar_id, target_calendar_id, etc.)"
echo "2. Migrate data from old columns to new columns"
echo "3. Update indexes and constraints"
echo "4. Keep old columns for safety (commented out drop statements)"
echo ""
read -p "Continue with migration? (y/N): " -n 1 -r
echo ""

if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${YELLOW}Migration cancelled.${NC}"
    exit 0
fi

# Apply migration
echo -e "${GREEN}Applying migration...${NC}"
psql "$DB_CONNECTION" -f "$MIGRATION_FILE" || {
    echo -e "${RED}Error: Migration failed${NC}"
    echo -e "${YELLOW}You can restore from backup: $BACKUP_FILE${NC}"
    exit 1
}

# Verify migration
echo -e "${YELLOW}Verifying migration results...${NC}"

# Show new table structure
echo -e "${YELLOW}New table structure:${NC}"
psql "$DB_CONNECTION" -c "\d bridge_resource_mappings"

# Show migrated data sample
echo -e "${YELLOW}Migrated data sample:${NC}"
psql "$DB_CONNECTION" -c "
SELECT 
    bridge_from, bridge_to, 
    source_calendar_id, target_calendar_id,
    source_calendar_name, target_calendar_name,
    sync_direction
FROM bridge_resource_mappings 
LIMIT 3;
"

# Verify data integrity
echo -e "${YELLOW}Data integrity check:${NC}"
psql "$DB_CONNECTION" -c "
SELECT 
    'Total records' as check_type, 
    COUNT(*) as count 
FROM bridge_resource_mappings
UNION ALL
SELECT 
    'Records with source_calendar_id' as check_type, 
    COUNT(*) as count 
FROM bridge_resource_mappings 
WHERE source_calendar_id IS NOT NULL
UNION ALL
SELECT 
    'Records with target_calendar_id' as check_type, 
    COUNT(*) as count 
FROM bridge_resource_mappings 
WHERE target_calendar_id IS NOT NULL;
"

echo -e "${GREEN}Migration completed successfully!${NC}"
echo -e "${YELLOW}Next steps:${NC}"
echo "1. Update application code to use new column names"
echo "2. Test the application thoroughly"
echo "3. After verification, uncomment the DROP COLUMN statements in the migration file"
echo "4. Keep backup file: $BACKUP_FILE"

echo -e "${GREEN}Bridge Resource Mappings Migration Complete!${NC}"
