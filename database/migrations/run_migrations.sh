#!/bin/bash

# Database Migration Runner
# Runs pending SQL migrations in order

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MIGRATION_DIR="$SCRIPT_DIR"
LOG_FILE="$SCRIPT_DIR/migration.log"

# Database configuration from .env or environment
if [ -f "$SCRIPT_DIR/../../.env" ]; then
    source "$SCRIPT_DIR/../../.env"
fi

DB_HOST=${DB_HOST:-localhost}
DB_PORT=${DB_PORT:-5432}
DB_NAME=${DB_NAME:-bridge_db}
DB_USER=${DB_USER:-bridge_user}
DB_PASSWORD=${DB_PASSWORD}

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to log messages
log() {
    echo -e "$1" | tee -a "$LOG_FILE"
}

# Function to execute SQL
execute_sql() {
    local sql_file="$1"
    local description="$2"
    
    log "${BLUE}[INFO]${NC} Executing: $description"
    
    if [ -n "$DB_PASSWORD" ]; then
        PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -f "$sql_file" >> "$LOG_FILE" 2>&1
    else
        psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -f "$sql_file" >> "$LOG_FILE" 2>&1
    fi
    
    if [ $? -eq 0 ]; then
        log "${GREEN}[SUCCESS]${NC} Migration completed: $description"
        return 0
    else
        log "${RED}[ERROR]${NC} Migration failed: $description"
        return 1
    fi
}

# Function to check if migration is already applied
is_migration_applied() {
    local version="$1"
    
    if [ -n "$DB_PASSWORD" ]; then
        local count=$(PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -t -c "SELECT COUNT(*) FROM schema_migrations WHERE version = '$version';" 2>/dev/null | tr -d ' ')
    else
        local count=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -t -c "SELECT COUNT(*) FROM schema_migrations WHERE version = '$version';" 2>/dev/null | tr -d ' ')
    fi
    
    [ "$count" = "1" ]
}

# Function to test database connection
test_connection() {
    log "${BLUE}[INFO]${NC} Testing database connection..."
    
    if [ -n "$DB_PASSWORD" ]; then
        PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "SELECT 1;" > /dev/null 2>&1
    else
        psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "SELECT 1;" > /dev/null 2>&1
    fi
    
    if [ $? -eq 0 ]; then
        log "${GREEN}[SUCCESS]${NC} Database connection successful"
        return 0
    else
        log "${RED}[ERROR]${NC} Cannot connect to database"
        log "${RED}[ERROR]${NC} Host: $DB_HOST, Port: $DB_PORT, Database: $DB_NAME, User: $DB_USER"
        return 1
    fi
}

# Function to show usage
usage() {
    cat << EOF
Usage: $0 [OPTIONS]

Options:
    --dry-run           Show what migrations would be executed without running them
    --force             Force execution even if migrations are already applied
    --version VERSION   Run specific migration version only
    --help              Show this help message

Examples:
    $0                  Run all pending migrations
    $0 --dry-run        Preview migrations without executing
    $0 --version 002    Run only migration 002
    $0 --force          Force re-run all migrations

Database Configuration:
    Set via .env file or environment variables:
    - DB_HOST (default: localhost)
    - DB_PORT (default: 5432)
    - DB_NAME (default: bridge_db)
    - DB_USER (default: bridge_user)
    - DB_PASSWORD (required for authentication)

EOF
}

# Parse command line arguments
DRY_RUN=false
FORCE=false
SPECIFIC_VERSION=""

while [[ $# -gt 0 ]]; do
    case $1 in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --force)
            FORCE=true
            shift
            ;;
        --version)
            SPECIFIC_VERSION="$2"
            shift 2
            ;;
        --help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            usage
            exit 1
            ;;
    esac
done

# Main execution
main() {
    log "${BLUE}[INFO]${NC} Starting database migration process..."
    log "${BLUE}[INFO]${NC} Migration directory: $MIGRATION_DIR"
    log "${BLUE}[INFO]${NC} Log file: $LOG_FILE"
    
    # Test database connection
    if ! test_connection; then
        exit 1
    fi
    
    # Get list of migration files
    migration_files=($(ls -1 "$MIGRATION_DIR"/*.sql 2>/dev/null | sort))
    
    if [ ${#migration_files[@]} -eq 0 ]; then
        log "${YELLOW}[WARNING]${NC} No migration files found in $MIGRATION_DIR"
        exit 0
    fi
    
    log "${BLUE}[INFO]${NC} Found ${#migration_files[@]} migration file(s)"
    
    executed_count=0
    skipped_count=0
    
    # Process each migration file
    for file in "${migration_files[@]}"; do
        filename=$(basename "$file")
        
        # Extract version number from filename (assumes format: XXX_description.sql)
        if [[ $filename =~ ^([0-9]+)_ ]]; then
            version="${BASH_REMATCH[1]}"
        else
            log "${YELLOW}[WARNING]${NC} Skipping file with invalid format: $filename"
            continue
        fi
        
        # If specific version requested, skip others
        if [ -n "$SPECIFIC_VERSION" ] && [ "$version" != "$SPECIFIC_VERSION" ]; then
            continue
        fi
        
        # Check if migration is already applied (unless forced)
        if [ "$FORCE" = false ] && is_migration_applied "$version"; then
            log "${YELLOW}[SKIP]${NC} Migration $version already applied: $filename"
            ((skipped_count++))
            continue
        fi
        
        # Extract description from file
        description=$(grep -m1 "^-- Description:" "$file" 2>/dev/null | sed 's/^-- Description: //' || echo "No description")
        
        if [ "$DRY_RUN" = true ]; then
            log "${BLUE}[DRY-RUN]${NC} Would execute migration $version: $description"
            log "${BLUE}[DRY-RUN]${NC} File: $filename"
        else
            # Execute the migration
            if execute_sql "$file" "$description"; then
                ((executed_count++))
            else
                log "${RED}[ERROR]${NC} Migration execution stopped due to failure"
                exit 1
            fi
        fi
    done
    
    # Summary
    if [ "$DRY_RUN" = true ]; then
        log "${BLUE}[INFO]${NC} Dry run completed - no changes made"
    else
        log "${GREEN}[SUCCESS]${NC} Migration process completed"
        log "${GREEN}[SUCCESS]${NC} Executed: $executed_count, Skipped: $skipped_count"
    fi
    
    if [ -n "$SPECIFIC_VERSION" ] && [ "$executed_count" -eq 0 ] && [ "$skipped_count" -eq 0 ]; then
        log "${YELLOW}[WARNING]${NC} No migration found for version: $SPECIFIC_VERSION"
    fi
}

# Run main function
main "$@"
