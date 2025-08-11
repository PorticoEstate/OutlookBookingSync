-- Add sync_method column to track how the sync was initiated
-- This separates sync method (manual/polling/automated/cron) from sync direction (bidirectional/source_to_target)

ALTER TABLE bridge_mappings 
ADD COLUMN IF NOT EXISTS sync_method VARCHAR(20) DEFAULT 'manual';

-- Create index for efficient cron activity queries
CREATE INDEX IF NOT EXISTS idx_bridge_mappings_sync_method_updated 
ON bridge_mappings(sync_method, updated_at);

-- Update existing rows to have a default sync_method
UPDATE bridge_mappings 
SET sync_method = 'manual' 
WHERE sync_method IS NULL;
