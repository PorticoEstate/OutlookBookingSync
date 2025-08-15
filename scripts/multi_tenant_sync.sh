#!/bin/bash

set -e

# Multi-tenant sync runner: discovers tenants and triggers sync in both directions for each

BRIDGE_URL="${BRIDGE_URL:-http://localhost}"
API_KEY_HEADER="${API_KEY:+-H \"api_key: ${API_KEY}\"}"
SYNC_WINDOW_DAYS="${SYNC_WINDOW_DAYS:-7}"
LOG_FILE="${LOG_FILE:-/var/log/bridge-sync.log}"

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $1" | tee -a "$LOG_FILE"; }

get_active_tenants() {
  local resp
  resp=$(curl -s -X GET "$BRIDGE_URL/admin/tenants" -H "Content-Type: application/json" $API_KEY_HEADER)
  echo "$resp" | jq -r '.tenants[] | select(.active==true) | .id // empty'
}

has_active_mappings() {
  local tenant_id=$1
  local TENANT_HEADER="-H X-Tenant-Id: $tenant_id"
  local resp
  resp=$(curl -s -X GET "$BRIDGE_URL/mappings/resources?active_only=true" -H "Content-Type: application/json" $API_KEY_HEADER $TENANT_HEADER)
  echo "$resp" | jq -e '.count and .count > 0' > /dev/null 2>&1
}

post_json() {
  local url=$1; shift
  local desc=$1; shift
  local headers=("-H" "Content-Type: application/json" "-H" "User-Agent: BridgeCron/1.0")
  log "🔄 $desc"
  local resp
  set +e
  resp=$(timeout 180 curl -s -X POST "$url" ${headers[@]} $API_KEY_HEADER "$@")
  local rc=$?
  set -e
  if [ $rc -ne 0 ]; then
    log "❌ Timeout or network error for: $desc"
    return 1
  fi
  if echo "$resp" | jq -e '.success == true' > /dev/null 2>&1; then
    log "✅ OK: $desc"
    return 0
  else
    local err
    err=$(echo "$resp" | jq -r '.error // .message // "unknown error"' 2>/dev/null || echo "unknown error")
    log "⚠️  Failed: $desc — $err"
    return 1
  fi
}

main() {
  local start_date end_date
  start_date=$(date +%F)
  end_date=$(date -d "+${SYNC_WINDOW_DAYS} days" +%F)

  local tenants
  tenants=$(get_active_tenants)
  if [ -z "$tenants" ]; then
    log "ℹ️  No active tenants found"
    exit 0
  fi

  for t in $tenants; do
    local TH=("-H" "X-Tenant-Id: $t")
    if ! has_active_mappings "$t"; then
      log "⏭️  Skipping tenant $t (no active mappings)"
      continue
    fi

    # Forward: booking_system → outlook
    post_json \
      "$BRIDGE_URL/bridges/sync/booking_system/outlook?sync_method=cron&start_date=$start_date&end_date=$end_date" \
      "Sync booking_system→outlook for tenant $t ($start_date..$end_date)" \
      "${TH[@]}" || true

    # Reverse: outlook → booking_system (include deletions)
    post_json \
      "$BRIDGE_URL/bridges/sync/outlook/booking_system?sync_method=cron&handle_deletions=1&start_date=$start_date&end_date=$end_date" \
      "Sync outlook→booking_system for tenant $t ($start_date..$end_date)" \
      "${TH[@]}" || true

    # Small gap
    sleep 1
  done
}

main "$@"
