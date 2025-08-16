-- EXPLAIN ANALYZE plans for common queries used by the bridge
-- Usage with psql variables (can be overridden via -v):
--   psql ... -v TENANT_ID='acme' -v HOURS_BACK='24' -v SOURCE_CAL='room1@company.com' -v TARGET_CAL='123' -f scripts/explain_plans.sql

\timing on

-- Defaults (override via -v ...)
\set TENANT_ID ''
\set HOURS_BACK '24'
\set SOURCE_CAL 'room1@company.com'
\set TARGET_CAL '123'

\echo
\echo ==== bridge_queue: pending per-tenant in priority/scheduled order ====
EXPLAIN (ANALYZE, BUFFERS, VERBOSE)
SELECT id, queue_type, priority, scheduled_at
FROM bridge_queue
WHERE status = 'pending'
  AND (tenant_id IS NOT DISTINCT FROM :'TENANT_ID')
ORDER BY priority ASC, scheduled_at ASC
LIMIT 50;

\echo
\echo ==== bridge_mappings: status counts in recent window (tenant-scoped) ====
EXPLAIN (ANALYZE, BUFFERS, VERBOSE)
SELECT sync_status, COUNT(*)
FROM bridge_mappings
WHERE (tenant_id IS NOT DISTINCT FROM :'TENANT_ID')
  AND updated_at > NOW() - (:'HOURS_BACK' || ' hours')::interval
GROUP BY sync_status;

\echo
\echo ==== bridge_mappings: calendar pair lookup (forward) ====
EXPLAIN (ANALYZE, BUFFERS, VERBOSE)
SELECT id
FROM bridge_mappings
WHERE source_calendar_id = :'SOURCE_CAL'
  AND target_calendar_id = :'TARGET_CAL'
  AND (tenant_id IS NOT DISTINCT FROM :'TENANT_ID')
LIMIT 1;

\echo
\echo ==== bridge_mappings: calendar pair lookup (reverse) ====
EXPLAIN (ANALYZE, BUFFERS, VERBOSE)
SELECT id
FROM bridge_mappings
WHERE target_calendar_id = :'SOURCE_CAL'
  AND source_calendar_id = :'TARGET_CAL'
  AND (tenant_id IS NOT DISTINCT FROM :'TENANT_ID')
LIMIT 1;

\echo
\echo ==== bridge_sync_logs: recent errors count (tenant-scoped) ====
EXPLAIN (ANALYZE, BUFFERS, VERBOSE)
SELECT COUNT(*)
FROM bridge_sync_logs
WHERE status = 'error'
  AND created_at > NOW() - (:'HOURS_BACK' || ' hours')::interval
  AND (tenant_id IS NOT DISTINCT FROM :'TENANT_ID');

\echo
\echo ==== bridge_resource_mappings: active, tenant-scoped list (top 50 by updated_at) ====
EXPLAIN (ANALYZE, BUFFERS, VERBOSE)
SELECT id, bridge_from, bridge_to, source_calendar_id, target_calendar_id, updated_at
FROM bridge_resource_mappings
WHERE is_active = true
  AND sync_enabled = true
  AND (tenant_id IS NOT DISTINCT FROM :'TENANT_ID')
ORDER BY updated_at DESC
LIMIT 50;
