-- Database table for storing Microsoft Graph webhook subscriptions
-- This tracks active webhook subscriptions for each room calendar

CREATE TABLE IF NOT EXISTS outlook_webhook_subscriptions (
    id SERIAL PRIMARY KEY,
    subscription_id VARCHAR(255) UNIQUE NOT NULL,
    calendar_id VARCHAR(255) NOT NULL,
    resource_id INTEGER,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    
    -- Ensure one subscription per calendar
    UNIQUE(calendar_id)
);

-- Index for finding expiring subscriptions
CREATE INDEX IF NOT EXISTS idx_webhook_expires_at ON outlook_webhook_subscriptions(expires_at);

-- Index for looking up by calendar
CREATE INDEX IF NOT EXISTS idx_webhook_calendar_id ON outlook_webhook_subscriptions(calendar_id);

-- Index for looking up by subscription ID
CREATE INDEX IF NOT EXISTS idx_webhook_subscription_id ON outlook_webhook_subscriptions(subscription_id);

-- Optional: Add a comment
COMMENT ON TABLE outlook_webhook_subscriptions IS 'Stores Microsoft Graph webhook subscriptions for room calendars to detect real-time Outlook changes';
