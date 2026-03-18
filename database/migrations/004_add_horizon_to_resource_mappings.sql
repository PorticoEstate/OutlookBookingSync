-- Migration: Add horizon to bridge_resource_mappings
-- Description: Adds per-mapping sync horizon (days) to override default sync end date window
-- Version: 004
-- Date: 2026-03-18

ALTER TABLE bridge_resource_mappings
    ADD COLUMN IF NOT EXISTS horizon INTEGER;

ALTER TABLE bridge_resource_mappings
    DROP CONSTRAINT IF EXISTS chk_bridge_resource_mappings_horizon_non_negative;

ALTER TABLE bridge_resource_mappings
    ADD CONSTRAINT chk_bridge_resource_mappings_horizon_non_negative
    CHECK (horizon IS NULL OR horizon >= 0);

-- Record migration completion
INSERT INTO schema_migrations (version, description, applied_at)
VALUES ('004', 'Add horizon to bridge_resource_mappings', NOW())
ON CONFLICT (version) DO NOTHING;

SELECT 'Migration 004: Added horizon to bridge_resource_mappings' AS result;
