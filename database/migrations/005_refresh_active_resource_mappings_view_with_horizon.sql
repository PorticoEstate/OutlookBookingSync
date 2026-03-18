-- Migration: Refresh v_active_resource_mappings with horizon column
-- Description: Recreate active resource mappings view to include horizon after schema changes
-- Version: 005
-- Date: 2026-03-18

CREATE OR REPLACE VIEW v_active_resource_mappings AS
SELECT
    brm.id,
    brm.bridge_from,
    brm.bridge_to,
    brm.source_calendar_id,
    brm.target_calendar_id,
    brm.source_calendar_name,
    brm.target_calendar_name,
    brm.sync_direction,
    brm.is_active,
    brm.sync_enabled,
    brm.last_synced_at,
    brm.tenant_id,
    brm.created_at,
    brm.updated_at,
    CASE
        WHEN brm.last_synced_at > NOW() - INTERVAL '1 hour' THEN 'recent'
        WHEN brm.last_synced_at > NOW() - INTERVAL '1 day' THEN 'daily'
        WHEN brm.last_synced_at > NOW() - INTERVAL '1 week' THEN 'weekly'
        ELSE 'stale'
    END AS sync_freshness,
    COUNT(bm.id) AS mapped_events,
    brm.horizon
FROM bridge_resource_mappings brm
LEFT JOIN bridge_mappings bm ON (
    (
        brm.source_calendar_id = bm.source_calendar_id AND brm.target_calendar_id = bm.target_calendar_id
    ) OR (
        brm.source_calendar_id = bm.target_calendar_id AND brm.target_calendar_id = bm.source_calendar_id
    )
) AND (bm.tenant_id IS NOT DISTINCT FROM brm.tenant_id)
WHERE brm.is_active = true
GROUP BY brm.id, brm.bridge_from, brm.bridge_to, brm.source_calendar_id, brm.target_calendar_id,
         brm.source_calendar_name, brm.target_calendar_name, brm.sync_direction,
         brm.is_active, brm.sync_enabled, brm.last_synced_at, brm.tenant_id, brm.created_at, brm.updated_at,
         brm.horizon;

-- Record migration completion
INSERT INTO schema_migrations (version, description, applied_at)
VALUES ('005', 'Refresh v_active_resource_mappings view with horizon', NOW())
ON CONFLICT (version) DO NOTHING;

SELECT 'Migration 005: Refreshed v_active_resource_mappings with horizon' AS result;
