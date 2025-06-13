-- Main mapping table to track sync relationships
CREATE TABLE IF NOT EXISTS outlook_calendar_mapping (
    id SERIAL PRIMARY KEY,
    
    -- Booking system identifiers
    reservation_type VARCHAR(20), -- 'allocation', 'booking', 'event'
    reservation_id INTEGER,
    resource_id INTEGER NOT NULL,
    
    -- Outlook identifiers
    outlook_item_id VARCHAR(255) NOT NULL, -- Room/resource calendar ID
    outlook_event_id VARCHAR(255), -- Outlook event ID (null if not synced yet)
    
    -- Sync metadata
    sync_status VARCHAR(20) DEFAULT 'pending', -- 'pending', 'synced', 'error', 'conflict'
    last_sync_at TIMESTAMP,
    last_modified_booking TIMESTAMP,
    last_modified_outlook TIMESTAMP,
    sync_direction VARCHAR(20), -- 'to_outlook', 'from_outlook', 'bidirectional'
    error_message TEXT,
    
    -- Conflict resolution
    priority_level INTEGER DEFAULT 3, -- 1=Event, 2=Booking, 3=Allocation
    
    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT unique_booking_resource UNIQUE (reservation_type, reservation_id, resource_id),
    CONSTRAINT unique_outlook_event UNIQUE (outlook_event_id)
);



-- Sync state tracking
CREATE TABLE IF NOT EXISTS outlook_sync_state (
    id SERIAL PRIMARY KEY,
    resource_id INTEGER REFERENCES bb_resource(id),
    last_full_sync TIMESTAMP,
    last_incremental_sync TIMESTAMP,
    outlook_subscription_id VARCHAR(255), -- Microsoft Graph webhook subscription ID
    subscription_expires_at TIMESTAMP,
    sync_errors_count INTEGER DEFAULT 0,
    is_healthy BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Webhook subscriptions table for real-time Outlook change notifications
CREATE TABLE IF NOT EXISTS outlook_webhook_subscriptions (
    id SERIAL PRIMARY KEY,
    subscription_id VARCHAR(255) UNIQUE NOT NULL, -- Microsoft Graph subscription ID
    calendar_id VARCHAR(255) NOT NULL, -- Outlook calendar/room ID
    resource_id INTEGER, -- Optional reference to bb_resource
    notification_url VARCHAR(500) NOT NULL, -- Webhook endpoint URL
    change_types VARCHAR(100) DEFAULT 'created,updated,deleted', -- Types of changes to monitor
    client_state VARCHAR(255), -- Validation state for security
    expires_at TIMESTAMP NOT NULL, -- When subscription expires
    is_active BOOLEAN DEFAULT true, -- Whether subscription is active
    last_notification_at TIMESTAMP, -- Last time we received a notification
    notifications_received INTEGER DEFAULT 0, -- Count of notifications received
    
    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Ensure one active subscription per calendar
    CONSTRAINT unique_active_calendar UNIQUE (calendar_id, is_active) DEFERRABLE INITIALLY DEFERRED
);

-- Webhook notification log for debugging and monitoring
CREATE TABLE IF NOT EXISTS outlook_webhook_notifications (
    id SERIAL PRIMARY KEY,
    subscription_id VARCHAR(255) NOT NULL,
    calendar_id VARCHAR(255),
    event_id VARCHAR(255),
    change_type VARCHAR(20), -- 'created', 'updated', 'deleted'
    resource_url TEXT, -- Full resource URL from notification
    notification_data JSONB, -- Full notification payload
    processing_status VARCHAR(20) DEFAULT 'pending', -- 'pending', 'processed', 'error'
    processing_result JSONB, -- Result of processing the notification
    error_message TEXT,
    processed_at TIMESTAMP,
    
    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Reference to webhook subscription
    FOREIGN KEY (subscription_id) REFERENCES outlook_webhook_subscriptions(subscription_id) ON DELETE CASCADE
);

-- Outlook event change detection for fallback polling
CREATE TABLE IF NOT EXISTS outlook_event_changes (
    id SERIAL PRIMARY KEY,
    calendar_id VARCHAR(255) NOT NULL,
    event_id VARCHAR(255) NOT NULL,
    change_type VARCHAR(20), -- 'created', 'updated', 'deleted'
    detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP,
    processing_status VARCHAR(20) DEFAULT 'pending', -- 'pending', 'processed', 'error', 'ignored'
    event_data JSONB, -- Snapshot of event data when change was detected
    error_message TEXT,
    
    -- For tracking changes over time
    previous_hash VARCHAR(64), -- Hash of previous event data for change detection
    current_hash VARCHAR(64), -- Hash of current event data
    
    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Prevent duplicate change records
    CONSTRAINT unique_event_change UNIQUE (calendar_id, event_id, change_type, detected_at)
);

-- Polling state for delta queries (alternative to webhooks)
CREATE TABLE IF NOT EXISTS outlook_polling_state (
    id SERIAL PRIMARY KEY,
    calendar_id VARCHAR(255) UNIQUE NOT NULL, -- Outlook calendar/room ID
    delta_token TEXT, -- Microsoft Graph delta token for efficient polling
    last_poll_at TIMESTAMP, -- When we last polled this calendar
    last_successful_poll_at TIMESTAMP, -- When we last successfully polled
    poll_errors_count INTEGER DEFAULT 0, -- Number of consecutive poll errors
    last_error_message TEXT, -- Last error encountered during polling
    is_healthy BOOLEAN DEFAULT true, -- Whether polling is working for this calendar
    
    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Indexes for performance

-- outlook_calendar_mapping indexes
CREATE INDEX IF NOT EXISTS idx_calendar_mapping_status ON outlook_calendar_mapping(sync_status);
CREATE INDEX IF NOT EXISTS idx_calendar_mapping_resource ON outlook_calendar_mapping(resource_id);
CREATE INDEX IF NOT EXISTS idx_calendar_mapping_outlook_item ON outlook_calendar_mapping(outlook_item_id);
CREATE INDEX IF NOT EXISTS idx_calendar_mapping_outlook_event ON outlook_calendar_mapping(outlook_event_id);
CREATE INDEX IF NOT EXISTS idx_calendar_mapping_reservation ON outlook_calendar_mapping(reservation_type, reservation_id);
CREATE INDEX IF NOT EXISTS idx_calendar_mapping_direction ON outlook_calendar_mapping(sync_direction);
CREATE INDEX IF NOT EXISTS idx_calendar_mapping_priority ON outlook_calendar_mapping(priority_level);
CREATE INDEX IF NOT EXISTS idx_calendar_mapping_updated ON outlook_calendar_mapping(updated_at);

-- outlook_sync_state indexes
CREATE INDEX IF NOT EXISTS idx_sync_state_resource ON outlook_sync_state(resource_id);
CREATE INDEX IF NOT EXISTS idx_sync_state_subscription ON outlook_sync_state(outlook_subscription_id);
CREATE INDEX IF NOT EXISTS idx_sync_state_expires ON outlook_sync_state(subscription_expires_at);
CREATE INDEX IF NOT EXISTS idx_sync_state_healthy ON outlook_sync_state(is_healthy);

-- outlook_webhook_subscriptions indexes
CREATE INDEX IF NOT EXISTS idx_webhook_subscriptions_calendar ON outlook_webhook_subscriptions(calendar_id);
CREATE INDEX IF NOT EXISTS idx_webhook_subscriptions_expires ON outlook_webhook_subscriptions(expires_at);
CREATE INDEX IF NOT EXISTS idx_webhook_subscriptions_active ON outlook_webhook_subscriptions(is_active);
CREATE INDEX IF NOT EXISTS idx_webhook_subscriptions_resource ON outlook_webhook_subscriptions(resource_id);

-- outlook_webhook_notifications indexes
CREATE INDEX IF NOT EXISTS idx_webhook_notifications_subscription ON outlook_webhook_notifications(subscription_id);
CREATE INDEX IF NOT EXISTS idx_webhook_notifications_calendar ON outlook_webhook_notifications(calendar_id);
CREATE INDEX IF NOT EXISTS idx_webhook_notifications_event ON outlook_webhook_notifications(event_id);
CREATE INDEX IF NOT EXISTS idx_webhook_notifications_status ON outlook_webhook_notifications(processing_status);
CREATE INDEX IF NOT EXISTS idx_webhook_notifications_created ON outlook_webhook_notifications(created_at);
CREATE INDEX IF NOT EXISTS idx_webhook_notifications_processed ON outlook_webhook_notifications(processed_at);

-- outlook_event_changes indexes
CREATE INDEX IF NOT EXISTS idx_event_changes_calendar ON outlook_event_changes(calendar_id);
CREATE INDEX IF NOT EXISTS idx_event_changes_event ON outlook_event_changes(event_id);
CREATE INDEX IF NOT EXISTS idx_event_changes_status ON outlook_event_changes(processing_status);
CREATE INDEX IF NOT EXISTS idx_event_changes_detected ON outlook_event_changes(detected_at);
CREATE INDEX IF NOT EXISTS idx_event_changes_processed ON outlook_event_changes(processed_at);

-- outlook_polling_state indexes
CREATE INDEX IF NOT EXISTS idx_polling_state_calendar ON outlook_polling_state(calendar_id);
CREATE INDEX IF NOT EXISTS idx_polling_state_last_poll ON outlook_polling_state(last_poll_at);
CREATE INDEX IF NOT EXISTS idx_polling_state_healthy ON outlook_polling_state(is_healthy);
CREATE INDEX IF NOT EXISTS idx_polling_state_errors ON outlook_polling_state(poll_errors_count);

-- Comments for documentation
COMMENT ON TABLE outlook_calendar_mapping IS 'Main mapping table tracking sync relationships between booking system and Outlook';
COMMENT ON TABLE outlook_sync_state IS 'Tracks sync state and health for each resource/calendar';
COMMENT ON TABLE outlook_webhook_subscriptions IS 'Stores Microsoft Graph webhook subscriptions for real-time change notifications';
COMMENT ON TABLE outlook_webhook_notifications IS 'Logs all webhook notifications received from Microsoft Graph for debugging';
COMMENT ON TABLE outlook_event_changes IS 'Tracks detected changes in Outlook events for fallback polling mechanism';