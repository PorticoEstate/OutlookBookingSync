-- Migration: Add bridge_resources table
-- Description: Adds the bridge_resources table for caching resources from bridge APIs
-- Version: 002
-- Date: 2025-09-08

-- Bridge resources table - stores available resources per bridge and tenant
-- Used when bridge APIs don't provide direct resource listing (e.g., restricted Outlook Graph access)
CREATE TABLE IF NOT EXISTS bridge_resources (
    id SERIAL PRIMARY KEY,
    bridge_name VARCHAR(50) NOT NULL,
    bridge_type VARCHAR(50) NOT NULL,
    resource_id VARCHAR(255) NOT NULL,
    resource_email VARCHAR(255),
    resource_name VARCHAR(500),
    resource_type VARCHAR(50) DEFAULT 'room', -- 'room', 'equipment', 'user', etc.
    capacity INTEGER,
    location VARCHAR(255),
    description TEXT,
    resource_data JSONB, -- Additional bridge-specific metadata
    is_active BOOLEAN DEFAULT TRUE,
    tenant_id VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(bridge_name, resource_id, tenant_id)
);

-- Indexes for bridge resources
CREATE INDEX IF NOT EXISTS idx_bridge_resources_bridge_tenant ON bridge_resources(bridge_name, tenant_id);
CREATE INDEX IF NOT EXISTS idx_bridge_resources_type_tenant ON bridge_resources(bridge_type, tenant_id);
CREATE INDEX IF NOT EXISTS idx_bridge_resources_active_tenant ON bridge_resources(is_active, tenant_id);
CREATE INDEX IF NOT EXISTS idx_bridge_resources_email_tenant ON bridge_resources(resource_email, tenant_id);
CREATE INDEX IF NOT EXISTS idx_bridge_resources_name_search ON bridge_resources USING gin(to_tsvector('english', resource_name));

-- Trigger to update bridge_resources updated_at
CREATE OR REPLACE FUNCTION update_bridge_resources_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_update_bridge_resources_timestamp
    BEFORE UPDATE ON bridge_resources
    FOR EACH ROW
    EXECUTE FUNCTION update_bridge_resources_timestamp();



-- Record migration completion
INSERT INTO schema_migrations (version, description, applied_at) 
VALUES ('002', 'Add bridge_resources table', NOW())
ON CONFLICT (version) DO NOTHING;

-- Display completion message
SELECT 'Migration 002: bridge_resources table added successfully' AS result;
