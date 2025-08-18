-- Bridge Mappings and Configuration Tables
-- Generic calendar bridge database schema

-- Bridge mappings table - tracks sync relationships between any two bridges
CREATE TABLE IF NOT EXISTS bridge_mappings (
    id SERIAL PRIMARY KEY,
    source_bridge VARCHAR(50) NOT NULL,
    target_bridge VARCHAR(50) NOT NULL,
    source_calendar_id VARCHAR(255) NOT NULL,
    target_calendar_id VARCHAR(255) NOT NULL,
    source_event_id VARCHAR(255) NOT NULL,
    target_event_id VARCHAR(255) NOT NULL,
    source_event_start VARCHAR(64),
    source_event_end VARCHAR(64),
    sync_direction VARCHAR(20) DEFAULT 'bidirectional', -- 'source_to_target', 'target_to_source', 'bidirectional'
    sync_status VARCHAR(20) DEFAULT 'pending' NOT NULL, -- 'pending', 'synced', 'cancelled', 'error'
    sync_method VARCHAR(20) DEFAULT 'manual', -- 'manual', 'polling', 'automated', 'cron'
    event_data JSONB,
    event_hash VARCHAR(64),
    last_synced_at TIMESTAMP,
    error_message TEXT,
    retry_count INTEGER DEFAULT 0,
    tenant_id VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(source_bridge, target_bridge, source_calendar_id, target_calendar_id, source_event_id, tenant_id)
);

-- Bridge configurations table - stores bridge-specific settings
CREATE TABLE IF NOT EXISTS bridge_configs (
    id SERIAL PRIMARY KEY,
    bridge_name VARCHAR(50) NOT NULL,
    bridge_type VARCHAR(50) NOT NULL,
    config_data JSONB NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    tenant_id VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(bridge_name, tenant_id)
);

-- Bridge sync logs - audit trail for all sync operations
CREATE TABLE IF NOT EXISTS bridge_sync_logs (
    id SERIAL PRIMARY KEY,
    source_bridge VARCHAR(50) NOT NULL,
    target_bridge VARCHAR(50) NOT NULL,
    operation VARCHAR(20) NOT NULL, -- 'create', 'update', 'delete', 'sync'
    status VARCHAR(20) NOT NULL, -- 'success', 'error', 'pending'
    event_count INTEGER DEFAULT 0,
    details JSONB,
    error_message TEXT,
    duration_ms INTEGER,
    tenant_id VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Bridge subscriptions - webhook subscriptions for real-time sync
CREATE TABLE IF NOT EXISTS bridge_subscriptions (
    id SERIAL PRIMARY KEY,
    bridge_type VARCHAR(50) NOT NULL,
    subscription_id VARCHAR(255) NOT NULL UNIQUE,
    calendar_id VARCHAR(255) NOT NULL,
    webhook_url TEXT NOT NULL,
    subscription_data JSONB,
    is_active BOOLEAN DEFAULT TRUE,
    expires_at TIMESTAMP,
    tenant_id VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_renewed_at TIMESTAMP
);

-- Bridge queue for async processing
CREATE TABLE IF NOT EXISTS bridge_queue (
    id SERIAL PRIMARY KEY,
    queue_type VARCHAR(50) NOT NULL DEFAULT 'sync',
    source_bridge VARCHAR(50) NOT NULL,
    target_bridge VARCHAR(50),
    priority INTEGER DEFAULT 5, -- 1=high, 5=normal, 10=low
    payload JSONB NOT NULL,
    status VARCHAR(20) DEFAULT 'pending', -- 'pending', 'processing', 'completed', 'failed'
    attempts INTEGER DEFAULT 0,
    max_attempts INTEGER DEFAULT 3,
    scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP,
    error_message TEXT,
    tenant_id VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Bridge resource mappings table - maps calendars between bridge systems
CREATE TABLE IF NOT EXISTS bridge_resource_mappings (
    id SERIAL PRIMARY KEY,
    bridge_from VARCHAR(50) NOT NULL, -- source bridge type (e.g., 'booking_system', 'outlook')
    bridge_to VARCHAR(50) NOT NULL,   -- target bridge type (e.g., 'outlook', 'booking_system')
    source_calendar_id VARCHAR(255) NOT NULL, -- calendar ID in the source bridge system
    target_calendar_id VARCHAR(255) NOT NULL, -- calendar ID in the target bridge system
    source_calendar_name VARCHAR(255),        -- human-readable name of source calendar
    target_calendar_name VARCHAR(255),        -- human-readable name of target calendar
    sync_direction VARCHAR(20) DEFAULT 'bidirectional', -- 'source_to_target', 'target_to_source', 'bidirectional'
    is_active BOOLEAN DEFAULT TRUE,
    sync_enabled BOOLEAN DEFAULT TRUE,
    last_synced_at TIMESTAMP,
    tenant_id VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(bridge_from, bridge_to, source_calendar_id, target_calendar_id, tenant_id)
);

-- Indexes for better performance
-- Notes:
-- - All indexes use IF NOT EXISTS where supported to keep the script idempotent.
-- - Expression/partial unique indexes are guarded in DO $$ blocks because IF NOT EXISTS
--   is not available for CREATE UNIQUE INDEX on expressions in older Postgres versions.
-- - After deploying indexes, consider running ANALYZE and reviewing EXPLAIN plans.
CREATE INDEX IF NOT EXISTS idx_bridge_resource_mappings_source ON bridge_resource_mappings(bridge_from, source_calendar_id);
CREATE INDEX IF NOT EXISTS idx_bridge_resource_mappings_target ON bridge_resource_mappings(bridge_to, target_calendar_id);
CREATE INDEX IF NOT EXISTS idx_bridge_resource_mappings_active ON bridge_resource_mappings(is_active, sync_enabled);
CREATE INDEX IF NOT EXISTS idx_bridge_resource_mappings_tenant ON bridge_resource_mappings(tenant_id);
-- Optional: ensure uniqueness when tenant_id is NULL and non-NULL together
DO $$ BEGIN
    -- Enforce uniqueness for (bridge_name, tenant_id) treating NULL tenant as ''
    CREATE UNIQUE INDEX IF NOT EXISTS uniq_bridge_configs_name_tenant_expr
        ON bridge_configs (bridge_name, COALESCE(tenant_id, ''));
EXCEPTION WHEN others THEN
    -- Ignore errors if the index already exists or cannot be created due to version constraints
    NULL;
END $$;
DO $$ BEGIN
    -- Enforce uniqueness for resource mappings across nullable tenant
    CREATE UNIQUE INDEX IF NOT EXISTS uniq_brm_bridge_cal_tenant_expr
        ON bridge_resource_mappings (bridge_from, bridge_to, source_calendar_id, target_calendar_id, COALESCE(tenant_id, ''));
EXCEPTION WHEN others THEN
    NULL;
END $$;

CREATE INDEX IF NOT EXISTS idx_bridge_mappings_source ON bridge_mappings(source_bridge, source_calendar_id, source_event_id);
CREATE INDEX IF NOT EXISTS idx_bridge_mappings_target ON bridge_mappings(target_bridge, target_calendar_id, target_event_id);
CREATE INDEX IF NOT EXISTS idx_bridge_mappings_sync ON bridge_mappings(last_synced_at);
CREATE INDEX IF NOT EXISTS idx_bridge_mappings_event_hash ON bridge_mappings(event_hash);
CREATE INDEX IF NOT EXISTS idx_bridge_mappings_sync_status ON bridge_mappings(sync_status);
CREATE INDEX IF NOT EXISTS idx_bridge_mappings_retry ON bridge_mappings(retry_count) WHERE sync_status = 'error';
CREATE INDEX IF NOT EXISTS idx_bridge_mappings_tenant ON bridge_mappings(tenant_id);
-- Optimize frequent tenant-scoped status/time filters and updated_at lookups
CREATE INDEX IF NOT EXISTS idx_bridge_mappings_tenant_status_updated ON bridge_mappings(tenant_id, sync_status, updated_at);
CREATE INDEX IF NOT EXISTS idx_bridge_mappings_updated ON bridge_mappings(updated_at);
CREATE INDEX IF NOT EXISTS idx_bridge_mappings_tenant_last_synced ON bridge_mappings(tenant_id, last_synced_at);

-- Support joins/exists checks by calendar-id pairs (both directions) with tenant scoping
CREATE INDEX IF NOT EXISTS idx_bridge_mappings_src_cal_pair_tenant ON bridge_mappings(source_calendar_id, target_calendar_id, tenant_id);
CREATE INDEX IF NOT EXISTS idx_bridge_mappings_tgt_cal_pair_tenant ON bridge_mappings(target_calendar_id, source_calendar_id, tenant_id);

CREATE INDEX IF NOT EXISTS idx_bridge_sync_logs_created ON bridge_sync_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_bridge_sync_logs_status ON bridge_sync_logs(status);
CREATE INDEX IF NOT EXISTS idx_bridge_sync_logs_bridges ON bridge_sync_logs(source_bridge, target_bridge);
CREATE INDEX IF NOT EXISTS idx_bridge_sync_logs_tenant ON bridge_sync_logs(tenant_id);
-- Speed up common filters like status='error' in last 24h, optionally per-tenant
CREATE INDEX IF NOT EXISTS idx_bridge_sync_logs_status_created_tenant ON bridge_sync_logs(status, created_at, tenant_id);
-- Speed up tenant-scoped time-window aggregations
CREATE INDEX IF NOT EXISTS idx_bridge_sync_logs_tenant_created ON bridge_sync_logs(tenant_id, created_at);

CREATE INDEX IF NOT EXISTS idx_bridge_subscriptions_bridge ON bridge_subscriptions(bridge_type, calendar_id);
CREATE INDEX IF NOT EXISTS idx_bridge_subscriptions_expires ON bridge_subscriptions(expires_at) WHERE is_active = true;
CREATE INDEX IF NOT EXISTS idx_bridge_subscriptions_tenant ON bridge_subscriptions(tenant_id);

CREATE INDEX IF NOT EXISTS idx_bridge_queue_status ON bridge_queue(status, scheduled_at);
CREATE INDEX IF NOT EXISTS idx_bridge_queue_priority ON bridge_queue(priority, scheduled_at) WHERE status = 'pending';
CREATE INDEX IF NOT EXISTS idx_bridge_queue_tenant ON bridge_queue(tenant_id);
-- Optimize fetching pending work per-tenant in priority/scheduled order
CREATE INDEX IF NOT EXISTS idx_bridge_queue_pending_tenant_order ON bridge_queue(tenant_id, priority, scheduled_at) WHERE status = 'pending';

-- Views for easy querying

-- Active resource mappings view
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
    (
        brm.source_calendar_id = bm.source_calendar_id AND brm.target_calendar_id = bm.target_calendar_id
    ) OR (
        brm.source_calendar_id = bm.target_calendar_id AND brm.target_calendar_id = bm.source_calendar_id
    )
) AND (bm.tenant_id IS NOT DISTINCT FROM brm.tenant_id)
WHERE brm.is_active = true
GROUP BY brm.id, brm.bridge_from, brm.bridge_to, brm.source_calendar_id, brm.target_calendar_id, 
         brm.source_calendar_name, brm.target_calendar_name, brm.sync_direction, brm.sync_enabled, 
         brm.last_synced_at, brm.created_at, brm.updated_at;

-- Active bridge mappings view
CREATE OR REPLACE VIEW v_active_bridge_mappings AS
SELECT 
    bm.*,
    CASE 
        WHEN bm.last_synced_at > NOW() - INTERVAL '1 hour' THEN 'recent'
        WHEN bm.last_synced_at > NOW() - INTERVAL '1 day' THEN 'daily'
        WHEN bm.last_synced_at > NOW() - INTERVAL '1 week' THEN 'weekly'
        ELSE 'stale'
    END as sync_freshness
FROM bridge_mappings bm;

-- Bridge sync statistics view
CREATE OR REPLACE VIEW v_bridge_sync_stats AS
SELECT 
    source_bridge,
    target_bridge,
    COUNT(*) as total_operations,
    COUNT(*) FILTER (WHERE status = 'success') as successful_operations,
    COUNT(*) FILTER (WHERE status = 'error') as failed_operations,
    AVG(duration_ms) as avg_duration_ms,
    MAX(created_at) as last_sync_at,
    SUM(event_count) as total_events_processed
FROM bridge_sync_logs
WHERE created_at > NOW() - INTERVAL '30 days'
GROUP BY source_bridge, target_bridge;

-- Bridge health view
CREATE OR REPLACE VIEW v_bridge_health AS
SELECT 
    bc.bridge_name,
    bc.bridge_type,
    bc.is_active as bridge_active,
    COUNT(bs.id) as active_subscriptions,
    COUNT(bs.id) FILTER (WHERE bs.expires_at < NOW() + INTERVAL '1 day') as expiring_subscriptions,
    MAX(bsl.created_at) as last_sync_at,
    COUNT(bsl.id) FILTER (WHERE bsl.created_at > NOW() - INTERVAL '1 hour' AND bsl.status = 'success') as recent_successful_syncs,
    COUNT(bsl.id) FILTER (WHERE bsl.created_at > NOW() - INTERVAL '1 hour' AND bsl.status = 'error') as recent_failed_syncs
FROM bridge_configs bc
LEFT JOIN bridge_subscriptions bs ON bc.bridge_type = bs.bridge_type AND bs.is_active = true AND (bs.tenant_id IS NOT DISTINCT FROM bc.tenant_id)
LEFT JOIN bridge_sync_logs bsl ON bc.bridge_name IN (bsl.source_bridge, bsl.target_bridge) 
    AND bsl.created_at > NOW() - INTERVAL '24 hours' AND (bsl.tenant_id IS NOT DISTINCT FROM bc.tenant_id)
GROUP BY bc.bridge_name, bc.bridge_type, bc.is_active;

-- Functions for maintenance

-- Function to cleanup old sync logs
CREATE OR REPLACE FUNCTION cleanup_old_bridge_logs(days_to_keep INTEGER DEFAULT 30)
RETURNS INTEGER AS $$
DECLARE
    deleted_count INTEGER;
BEGIN
    DELETE FROM bridge_sync_logs 
    WHERE created_at < NOW() - (days_to_keep || ' days')::INTERVAL;
    
    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    
    RETURN deleted_count;
END;
$$ LANGUAGE plpgsql;

-- Function to find orphaned bridge mappings
CREATE OR REPLACE FUNCTION find_orphaned_bridge_mappings()
RETURNS TABLE(
    mapping_id INTEGER,
    source_bridge VARCHAR(50),
    target_bridge VARCHAR(50),
    source_event_id VARCHAR(255),
    target_event_id VARCHAR(255),
    reason TEXT
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        bm.id,
        bm.source_bridge,
        bm.target_bridge,
        bm.source_event_id,
        bm.target_event_id,
        'No recent sync activity' as reason
    FROM bridge_mappings bm
    WHERE bm.last_synced_at < NOW() - INTERVAL '7 days'
       OR bm.last_synced_at IS NULL;
END;
$$ LANGUAGE plpgsql;

-- Trigger to update bridge_configs updated_at
CREATE OR REPLACE FUNCTION update_bridge_config_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_update_bridge_config_timestamp
    BEFORE UPDATE ON bridge_configs
    FOR EACH ROW
    EXECUTE FUNCTION update_bridge_config_timestamp();

-- Insert default bridge configurations
INSERT INTO bridge_configs (bridge_name, bridge_type, config_data) VALUES
('outlook', 'outlook', '{"description": "Microsoft Outlook/Graph API Bridge", "capabilities": ["webhooks", "recurring", "attendees"]}'),
('booking_system', 'booking_system', '{"description": "Internal Booking System Bridge", "capabilities": ["direct_db", "rest_api"]}')
ON CONFLICT (bridge_name, tenant_id) DO NOTHING;

-- Multi-tenancy registry (optional but recommended)
CREATE TABLE IF NOT EXISTS tenants (
    id VARCHAR(64) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tenant_api_keys (
    tenant_id VARCHAR(64) NOT NULL,
    api_key_hash VARCHAR(128) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- Sample data for testing (commented out)
/*
INSERT INTO bridge_mappings (
    source_bridge, target_bridge, source_calendar_id, target_calendar_id,
    source_event_id, target_event_id, sync_direction, event_data
) VALUES (
    'outlook', 'booking_system', 'room1@company.com', '123',
    'outlook-event-1', '456', 'bidirectional', 
    '{"subject": "Test Meeting", "start": "2025-06-15T10:00:00Z", "end": "2025-06-15T11:00:00Z"}'
);
*/

CREATE TABLE IF NOT EXISTS outlook_sync_alerts (
    id SERIAL PRIMARY KEY,
    alert_type VARCHAR(100) NOT NULL,
    severity VARCHAR(20) NOT NULL CHECK (severity IN ('info', 'warning', 'critical')),
    message TEXT NOT NULL,
    alert_data JSONB,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    acknowledged_at TIMESTAMP WITH TIME ZONE,
    acknowledged_by VARCHAR(255)
);