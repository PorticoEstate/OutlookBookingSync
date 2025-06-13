-- Outlook sync alerts table for monitoring and alerting
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

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_outlook_sync_alerts_created_at ON outlook_sync_alerts(created_at);
CREATE INDEX IF NOT EXISTS idx_outlook_sync_alerts_severity ON outlook_sync_alerts(severity);
CREATE INDEX IF NOT EXISTS idx_outlook_sync_alerts_type ON outlook_sync_alerts(alert_type);
CREATE INDEX IF NOT EXISTS idx_outlook_sync_alerts_acknowledged ON outlook_sync_alerts(acknowledged_at) WHERE acknowledged_at IS NULL;

-- Add comments
COMMENT ON TABLE outlook_sync_alerts IS 'Outlook sync monitoring alerts and notifications';
COMMENT ON COLUMN outlook_sync_alerts.alert_type IS 'Type of alert (e.g., high_error_rate, stalled_syncs, database_connectivity)';
COMMENT ON COLUMN outlook_sync_alerts.severity IS 'Alert severity level: info, warning, or critical';
COMMENT ON COLUMN outlook_sync_alerts.message IS 'Human-readable alert message';
COMMENT ON COLUMN outlook_sync_alerts.alert_data IS 'Additional alert data in JSON format';
COMMENT ON COLUMN outlook_sync_alerts.acknowledged_at IS 'When the alert was acknowledged by an administrator';
COMMENT ON COLUMN outlook_sync_alerts.acknowledged_by IS 'Who acknowledged the alert';
