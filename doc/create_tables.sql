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
    is_healthy BOOLEAN DEFAULT true
);