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
    sync_direction VARCHAR(20) DEFAULT 'bidirectional', -- 'source_to_target', 'target_to_source', 'bidirectional'
    event_data JSONB,
    last_synced_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(source_bridge, target_bridge, source_calendar_id, target_calendar_id, source_event_id)
);

-- Bridge configurations table - stores bridge-specific settings
CREATE TABLE IF NOT EXISTS bridge_configs (
    id SERIAL PRIMARY KEY,
    bridge_name VARCHAR(50) NOT NULL UNIQUE,
    bridge_type VARCHAR(50) NOT NULL,
    config_data JSONB NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Indexes for better performance
CREATE INDEX IF NOT EXISTS idx_bridge_mappings_source ON bridge_mappings(source_bridge, source_calendar_id, source_event_id);
CREATE INDEX IF NOT EXISTS idx_bridge_mappings_target ON bridge_mappings(target_bridge, target_calendar_id, target_event_id);
CREATE INDEX IF NOT EXISTS idx_bridge_mappings_sync ON bridge_mappings(last_synced_at);

CREATE INDEX IF NOT EXISTS idx_bridge_sync_logs_created ON bridge_sync_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_bridge_sync_logs_status ON bridge_sync_logs(status);
CREATE INDEX IF NOT EXISTS idx_bridge_sync_logs_bridges ON bridge_sync_logs(source_bridge, target_bridge);

CREATE INDEX IF NOT EXISTS idx_bridge_subscriptions_bridge ON bridge_subscriptions(bridge_type, calendar_id);
CREATE INDEX IF NOT EXISTS idx_bridge_subscriptions_expires ON bridge_subscriptions(expires_at) WHERE is_active = true;

CREATE INDEX IF NOT EXISTS idx_bridge_queue_status ON bridge_queue(status, scheduled_at);
CREATE INDEX IF NOT EXISTS idx_bridge_queue_priority ON bridge_queue(priority, scheduled_at) WHERE status = 'pending';

-- Views for easy querying

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
LEFT JOIN bridge_subscriptions bs ON bc.bridge_type = bs.bridge_type AND bs.is_active = true
LEFT JOIN bridge_sync_logs bsl ON bc.bridge_name IN (bsl.source_bridge, bsl.target_bridge) 
    AND bsl.created_at > NOW() - INTERVAL '24 hours'
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
ON CONFLICT (bridge_name) DO NOTHING;

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
