# Monitoring and Health Check System

This document describes the monitoring dashboard and health check system for OutlookBookingSync.

## Overview

The monitoring system provides comprehensive health checks, alerting, and a real-time dashboard to monitor the sync service operations.

## Components

### 1. Health Check System
- **Quick Health Check**: `/health` - Basic connectivity test
- **Comprehensive Health**: `/health/system` - Full system status
- **Dashboard Data**: `/health/dashboard` - Aggregated monitoring data

### 2. Alert System
- **Alert Checks**: `/alerts/check` - Run health checks and trigger alerts
- **Alert History**: `/alerts` - View recent alerts
- **Alert Stats**: `/alerts/stats` - Alert statistics and summaries
- **Alert Management**: `/alerts/{id}/acknowledge` - Acknowledge alerts

### 3. Monitoring Dashboard
- **Web Dashboard**: `/dashboard` - HTML monitoring interface
- **Auto-refresh**: Updates every 30 seconds
- **Real-time metrics**: System health, sync status, performance

## Database Tables

### outlook_sync_alerts
Stores system alerts and notifications:
```sql
CREATE TABLE outlook_sync_alerts (
    id SERIAL PRIMARY KEY,
    alert_type VARCHAR(100) NOT NULL,
    severity VARCHAR(20) NOT NULL CHECK (severity IN ('info', 'warning', 'critical')),
    message TEXT NOT NULL,
    alert_data JSONB,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    acknowledged_at TIMESTAMP WITH TIME ZONE,
    acknowledged_by VARCHAR(255)
);
```

## Health Checks

The system monitors:

### Database Health
- Connection response time
- Active queries count
- Total mappings

### Outlook Connectivity
- Recent sync activity
- API credentials status
- Connectivity proxy

### Cron Jobs
- Daemon running status
- Recent automated sync activity
- Job execution logs

### System Resources
- Memory usage
- Disk space
- CPU utilization

### Sync Status
- Error rates
- Pending operations
- Recent sync statistics

## Alert Types

### Error Rate Alerts
- **high_error_rate**: >25% error rate (Critical)
- **elevated_error_rate**: >10% error rate (Warning)

### Sync Operation Alerts
- **stalled_syncs**: Operations pending >2 hours (Warning)
- **no_cron_activity**: No automated activity >30 minutes (Warning)

### Infrastructure Alerts
- **slow_database**: Response time >2s (Warning) or >5s (Critical)
- **database_connectivity**: Connection failures (Critical)

## Dashboard Features

### System Overview Cards
- Total mappings count
- Synced items count
- Pending operations
- Error count

### Health Status Cards
- Database health with response times
- Cron job status with recent activity
- System resources (memory/disk usage)
- Outlook connectivity status

### Activity Monitoring
- Recent sync operations
- Error summaries
- Performance metrics
- Throughput statistics

## Configuration

### Environment Variables
```env
# Optional: Alert webhook for notifications
ALERT_WEBHOOK_URL=https://your-webhook-endpoint.com/alerts

# Database configuration (required)
DB_HOST=localhost
DB_PORT=5432
DB_NAME=your_database
DB_USER=your_user
DB_PASS=your_password
```

### Alert Webhook Payload
When configured, alerts are sent to the webhook URL:
```json
{
    "service": "OutlookBookingSync",
    "alert_type": "high_error_rate",
    "severity": "critical",
    "urgency": "critical",
    "message": "High error rate detected: 26.5%",
    "timestamp": "2025-06-13 13:53:07",
    "data": {
        "error_rate": 26.5,
        "error_count": 15,
        "total_operations": 57
    }
}
```

## Setup Instructions

### 1. Create Database Tables
```bash
# Create the alerts table
cat database/outlook_sync_alerts.sql | docker exec -i portico_outlook psql -h $DB_HOST -U $DB_USER -d $DB_NAME
```

### 2. Access Dashboard
Navigate to: `http://localhost:8082/dashboard`

### 3. Monitor Health
- Quick check: `curl http://localhost:8082/health`
- Full status: `curl http://localhost:8082/health/system`

### 4. Set Up Alerting
- Configure webhook URL in environment
- Run periodic alert checks: `curl -X POST http://localhost:8082/alerts/check`

## Automated Monitoring

### Cron Job Integration
Add to existing cron jobs for automated monitoring:
```bash
# Check for alerts every 15 minutes
*/15 * * * * curl -s -X POST "http://localhost/alerts/check" > /dev/null 2>&1

# Clean up old alerts weekly
0 2 * * 0 curl -s -X DELETE "http://localhost/alerts/old?days=7" > /dev/null 2>&1
```

### Docker Health Checks
Add to docker-compose.yml:
```yaml
services:
  portico_outlook:
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/health"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 40s
```

## Troubleshooting

### Common Issues

#### Dashboard Not Loading
- Verify container is running: `docker ps -f name=portico_outlook`
- Check logs: `docker logs portico_outlook`
- Test health endpoint: `curl http://localhost:8082/health`

#### Alerts Not Triggering
- Verify table exists: Check `outlook_sync_alerts` table
- Check database connectivity in health status
- Review alert service logs

#### High Error Rates
- Check recent activity in dashboard
- Review error summaries
- Investigate specific error messages

### Log Locations
- **Application Logs**: `docker logs portico_outlook`
- **Alert Logs**: Stored in application logs with alert context
- **Cron Logs**: Container cron execution logs

## Performance Considerations

### Resource Usage
- Dashboard auto-refresh: 30-second intervals
- Health checks: Lightweight database queries
- Alert checks: Run on-demand or via cron

### Scalability
- Alert table cleanup: Automatic via API endpoint
- Database indexing: Optimized for time-based queries
- Webhook timeouts: 10-second limit

## Security Notes

### Access Control
- Dashboard: No built-in authentication (add reverse proxy)
- API endpoints: Protected by optional API key middleware
- Database: Uses application database credentials

### Data Retention
- Alerts: Configurable retention (default 7 days)
- Health data: Real-time only, not stored
- Dashboard: No persistent storage
