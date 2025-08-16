#!/usr/bin/env bash
set -euo pipefail

# Run EXPLAIN ANALYZE for common queries using psql, leveraging env vars
# Required env: DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
# Optional: TENANT_ID, HOURS_BACK, SOURCE_CAL, TARGET_CAL

: "${DB_HOST:?Set DB_HOST}"
: "${DB_PORT:=5432}"
: "${DB_NAME:?Set DB_NAME}"
: "${DB_USER:?Set DB_USER}"
: "${DB_PASS:?Set DB_PASS}"

TENANT_ID=${TENANT_ID:-}
HOURS_BACK=${HOURS_BACK:-24}
SOURCE_CAL=${SOURCE_CAL:-room1@company.com}
TARGET_CAL=${TARGET_CAL:-123}

export PGPASSWORD="$DB_PASS"

echo "Running EXPLAIN ANALYZE plans against $DB_HOST:$DB_PORT/$DB_NAME as $DB_USER"

psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
  -v TENANT_ID="$TENANT_ID" \
  -v HOURS_BACK="$HOURS_BACK" \
  -v SOURCE_CAL="$SOURCE_CAL" \
  -v TARGET_CAL="$TARGET_CAL" \
  -f scripts/explain_plans.sql
