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

## Composite ID System Monitoring

The monitoring system provides comprehensive tracking of the composite ID system and priority filtering operations.

### Composite ID Metrics

#### Database Schema Monitoring
The bridge system maintains detailed tracking of composite ID usage:

```sql
-- Bridge mappings with composite ID information
SELECT 
    source_bridge,
    target_bridge,
    COUNT(*) as total_mappings,
    COUNT(CASE WHEN source_id LIKE '%\_[0-9]%' THEN 1 END) as composite_id_mappings,
    COUNT(CASE WHEN source_id LIKE 'event\_%' THEN 1 END) as event_mappings,
    COUNT(CASE WHEN source_id LIKE 'booking\_%' THEN 1 END) as booking_mappings,
    COUNT(CASE WHEN source_id LIKE 'allocation\_%' THEN 1 END) as allocation_mappings
FROM bridge_mappings 
GROUP BY source_bridge, target_bridge;

-- Priority filtering statistics
SELECT 
    DATE(created_at) as sync_date,
    COUNT(*) as total_sync_operations,
    COUNT(CASE WHEN operation_data->>'priority_filtered' = 'true' THEN 1 END) as priority_filtered_operations,
    AVG((operation_data->>'conflicts_resolved')::int) as avg_conflicts_per_sync
FROM bridge_sync_logs 
WHERE operation = 'sync' 
AND created_at >= NOW() - INTERVAL '7 days'
GROUP BY DATE(created_at)
ORDER BY sync_date DESC;
```

#### Composite ID Health Endpoints

```bash
# Get composite ID system statistics
curl -X GET "http://your-bridge/health/composite-ids"

# Response includes detailed breakdown
{
  "success": true,
  "composite_id_stats": {
    "total_mappings": 1245,
    "composite_id_mappings": 1198,
    "breakdown_by_type": {
      "event": 789,
      "booking": 312,
      "allocation": 97,
      "meeting": 43,
      "appointment": 4
    },
    "health_status": "healthy",
    "malformed_ids": 0,
    "last_updated": "2025-06-18T14:30:00Z"
  }
}

# Get priority filtering statistics
curl -X GET "http://your-bridge/health/priority-filtering"

# Response includes filtering effectiveness
{
  "success": true,
  "priority_filtering_stats": {
    "total_sync_operations_24h": 48,
    "operations_with_conflicts": 12,
    "conflicts_resolved": 37,
    "filtering_effectiveness": "92.5%",
    "priority_breakdown": {
      "priority_1_selected": 25,
      "priority_2_selected": 8,
      "priority_3_selected": 3
    },
    "most_common_conflicts": [
      {
        "conflict_type": "event_vs_booking",
        "occurrences": 15,
        "resolution": "event_selected"
      },
      {
        "conflict_type": "booking_vs_allocation", 
        "occurrences": 8,
        "resolution": "booking_selected"
      }
    ]
  }
}
```

### Priority Filtering Monitoring

#### Real-time Conflict Tracking
The monitoring system tracks priority filtering operations in real-time:

```bash
# Get current priority conflicts
curl -X GET "http://your-bridge/monitoring/priority-conflicts"

# Response shows active conflicts
{
  "success": true,
  "active_conflicts": [
    {
      "resource_id": "room_123",
      "time_slot": "2025-06-18T14:00:00Z to 2025-06-18T15:00:00Z",
      "conflicting_events": [
        {
          "composite_id": "event_78269",
          "priority": 1,
          "status": "selected_for_sync"
        },
        {
          "composite_id": "booking_456",
          "priority": 2,
          "status": "filtered_out"
        }
      ],
      "resolution_time": "2025-06-18T13:45:22Z"
    }
  ],
  "conflict_summary": {
    "total_conflicts_today": 5,
    "resolved_conflicts": 5,
    "pending_conflicts": 0
  }
}

# Get priority filtering performance metrics
curl -X GET "http://your-bridge/monitoring/filtering-performance"

# Response includes performance data
{
  "success": true,
  "performance_metrics": {
    "avg_filtering_time_ms": 12.3,
    "max_filtering_time_ms": 45.6,
    "filtering_operations_per_hour": 127,
    "efficiency_rating": "excellent",
    "resource_usage": {
      "cpu_overhead": "0.2%",
      "memory_overhead": "1.1MB"
    }
  }
}
```

#### Alert System Integration
The monitoring system includes specialized alerts for composite ID and priority filtering issues:

##### Composite ID Alerts
- **malformed_composite_ids**: Detects invalid composite ID formats (Critical)
- **composite_id_mapping_failures**: ID resolution failures (Warning)
- **orphaned_composite_mappings**: Mappings without valid composite IDs (Warning)

##### Priority Filtering Alerts  
- **excessive_conflicts**: >50% of sync operations have conflicts (Warning)
- **priority_filtering_failures**: Filter logic errors (Critical)
- **unresolved_conflicts**: Conflicts pending >1 hour (Warning)

```bash
# Trigger composite ID health check
curl -X POST "http://your-bridge/alerts/check-composite-ids"

# Trigger priority filtering health check  
curl -X POST "http://your-bridge/alerts/check-priority-filtering"

# Response includes alert details
{
  "success": true,
  "alerts_generated": [
    {
      "alert_type": "excessive_conflicts",
      "severity": "warning", 
      "message": "High conflict rate detected: 65% of sync operations had priority conflicts",
      "data": {
        "conflict_rate": 65.2,
        "operations_checked": 46,
        "conflicts_found": 30
      }
    }
  ]
}
```

## Database Tables
