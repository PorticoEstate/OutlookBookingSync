-- Migration: Rename bridge_resource_mappings columns for semantic clarity
-- This migration makes both booking_system and outlook able to be source or target

-- Step 1: Add new columns with semantic names
ALTER TABLE bridge_resource_mappings 
ADD COLUMN source_calendar_id VARCHAR(255),
ADD COLUMN target_calendar_id VARCHAR(255),
ADD COLUMN source_calendar_name VARCHAR(255),
ADD COLUMN target_calendar_name VARCHAR(255);

-- Step 2: Migrate data based on bridge_from/bridge_to direction
-- When bridge_from = 'booking_system' and bridge_to = 'outlook':
--   resource_id -> source_calendar_id (booking system resource)
--   calendar_id -> target_calendar_id (outlook calendar)
--   calendar_name -> target_calendar_name (outlook calendar name)
UPDATE bridge_resource_mappings 
SET 
    source_calendar_id = resource_id,
    target_calendar_id = calendar_id,
    target_calendar_name = calendar_name
WHERE bridge_from = 'booking_system' AND bridge_to = 'outlook';

-- When bridge_from = 'outlook' and bridge_to = 'booking_system':
--   calendar_id -> source_calendar_id (outlook calendar)
--   resource_id -> target_calendar_id (booking system resource)
--   calendar_name -> source_calendar_name (outlook calendar name)
UPDATE bridge_resource_mappings 
SET 
    source_calendar_id = calendar_id,
    target_calendar_id = resource_id,
    source_calendar_name = calendar_name
WHERE bridge_from = 'outlook' AND bridge_to = 'booking_system';

-- Step 3: Handle any other bridge combinations (future extensibility)
-- For any remaining unmigrated rows, use the original logic
UPDATE bridge_resource_mappings 
SET 
    source_calendar_id = COALESCE(source_calendar_id, resource_id),
    target_calendar_id = COALESCE(target_calendar_id, calendar_id),
    target_calendar_name = COALESCE(target_calendar_name, calendar_name)
WHERE source_calendar_id IS NULL OR target_calendar_id IS NULL;

-- Step 4: Make new columns NOT NULL after data migration
ALTER TABLE bridge_resource_mappings 
ALTER COLUMN source_calendar_id SET NOT NULL,
ALTER COLUMN target_calendar_id SET NOT NULL;

-- Step 5: Update the unique constraint to use new column names
ALTER TABLE bridge_resource_mappings 
DROP CONSTRAINT IF EXISTS bridge_resource_mappings_bridge_from_bridge_to_resource_id_calendar_key;

ALTER TABLE bridge_resource_mappings 
ADD CONSTRAINT bridge_resource_mappings_bridge_from_bridge_to_source_target_key 
UNIQUE(bridge_from, bridge_to, source_calendar_id, target_calendar_id);

-- Step 6: Update indexes
DROP INDEX IF EXISTS idx_bridge_resource_mappings_from;
DROP INDEX IF EXISTS idx_bridge_resource_mappings_to;

CREATE INDEX idx_bridge_resource_mappings_source ON bridge_resource_mappings(bridge_from, source_calendar_id);
CREATE INDEX idx_bridge_resource_mappings_target ON bridge_resource_mappings(bridge_to, target_calendar_id);

-- Step 7: Remove old columns (commented out for safety - uncomment after verification)
-- ALTER TABLE bridge_resource_mappings DROP COLUMN resource_id;
-- ALTER TABLE bridge_resource_mappings DROP COLUMN calendar_id;
-- ALTER TABLE bridge_resource_mappings DROP COLUMN calendar_name;

-- Step 8: Update the view to use new column names
DROP VIEW IF EXISTS v_active_resource_mappings;

CREATE OR REPLACE VIEW v_active_resource_mappings AS
SELECT 
    brm.*,
    CASE 
        WHEN brm.last_synced_at > NOW() - INTERVAL '1 hour' THEN 'recent'
        WHEN brm.last_synced_at > NOW() - INTERVAL '1 day' THEN 'daily'
        WHEN brm.last_synced_at > NOW() - INTERVAL '1 week' THEN 'weekly'
        ELSE 'stale'
    END as sync_freshness,
    COUNT(bm.id) as mapped_events
FROM bridge_resource_mappings brm
LEFT JOIN bridge_mappings bm ON (
    brm.source_calendar_id = bm.source_calendar_id AND brm.target_calendar_id = bm.target_calendar_id
    OR brm.source_calendar_id = bm.target_calendar_id AND brm.target_calendar_id = bm.source_calendar_id
)
WHERE brm.is_active = true
GROUP BY brm.id, brm.bridge_from, brm.bridge_to, brm.source_calendar_id, brm.target_calendar_id, 
         brm.source_calendar_name, brm.target_calendar_name, brm.sync_direction, brm.sync_enabled, 
         brm.last_synced_at, brm.created_at, brm.updated_at;

-- Verification queries (run after migration)
-- SELECT 'Before migration - old columns' as phase, COUNT(*) as total_records, 
--        COUNT(resource_id) as has_resource_id, COUNT(calendar_id) as has_calendar_id 
-- FROM bridge_resource_mappings;

-- SELECT 'After migration - new columns' as phase, COUNT(*) as total_records,
--        COUNT(source_calendar_id) as has_source_calendar_id, 
--        COUNT(target_calendar_id) as has_target_calendar_id
-- FROM bridge_resource_mappings;

-- SELECT 'Sample migrated data' as phase, bridge_from, bridge_to, 
--        source_calendar_id, target_calendar_id, source_calendar_name, target_calendar_name
-- FROM bridge_resource_mappings LIMIT 5;
