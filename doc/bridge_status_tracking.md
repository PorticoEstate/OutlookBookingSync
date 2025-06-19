# Bridge Status Tracking Architecture

## How Status is Determined in the Bridge System

The bridge system uses a **hybrid approach** combining database columns and sync logs to provide comprehensive status tracking:

### 1. `bridge_mappings` Table (Updated Schema)
- Stores permanent mapping relationships between calendar events
- `sync_status`: Direct status column with values: 'synced', 'pending', 'error', 'cancelled'
- `error_message`: Detailed error information for failed syncs
- `retry_count`: Number of sync retry attempts
- `last_synced_at`: Timestamp of last successful sync
- `updated_at`: Timestamp of last status update

### 2. `bridge_sync_logs` Table  
- Tracks all sync operations with detailed audit trail
- `status` values: 'success', 'error', 'pending'
- `operation` values: 'create', 'update', 'delete', 'sync'
- Provides historical context and debugging information

## Current Status Logic (Enhanced)

### 🟢 **Synced**
- `sync_status = 'synced'` in bridge_mappings
- AND has `last_synced_at` timestamp
- AND no recent errors or pending operations

### 🟡 **Pending** 
- `sync_status = 'pending'` in bridge_mappings
- OR has no `last_synced_at` timestamp (never synced)
- AND no recent critical errors

### 🔴 **Error**
- `sync_status = 'error'` in bridge_mappings
- WITH `error_message` containing failure details
- AND `retry_count` tracking attempt numbers

### ❌ **Cancelled**
- `sync_status = 'cancelled'` in bridge_mappings
- Event deleted/cancelled in source system
- Preserves mapping for audit purposes

## Benefits of This Enhanced Architecture

1. **Direct Status Access**: Immediate status without complex queries
2. **Audit Trail**: Complete history via sync logs
3. **Error Tracking**: Detailed error messages and retry counts
4. **Performance**: Optimized queries with indexed status columns
5. **Debugging**: Full context for troubleshooting
6. **Recovery**: Built-in retry mechanisms and re-enable workflows

## Status Management Methods

The bridge system provides comprehensive status management through AbstractCalendarBridge:

```php
// Update sync status
public function updateSyncStatus($eventId, $status, $errorMessage = null): bool

// Create event mapping with initial status
public function createEventMapping($sourceId, $targetId, $direction): bool

// Mark event as cancelled
public function markEventCancelled($eventId): bool

// Mark event as pending for retry
public function markEventPending($eventId): bool

// Get events by status
public function getEventsToSync($limit = 100): array
public function getCancelledEvents($bridgeName = null, $limit = 100): array

// Get comprehensive statistics
public function getSyncStats(): array
```

## API Endpoints for Status Management

### Status Monitoring
```bash
GET /health/sync-status              # Comprehensive sync status overview
GET /bridges/sync-stats              # Detailed statistics for all bridges
GET /bridges/sync-stats/{bridge}     # Statistics for specific bridge
GET /bridges/cancelled-events        # All cancelled events
GET /bridges/{bridge}/pending-events # Pending events for bridge
```

### Status Management
```bash
POST /bridges/process-pending-syncs           # Process all pending syncs
POST /bridges/process-pending-syncs/{bridge}  # Process pending for specific bridge
POST /bridges/re-enable-failed               # Re-enable all failed events
POST /bridges/re-enable-failed/{bridge}      # Re-enable failed for specific bridge
```

## Enhanced Query Examples

```sql
-- Get synced mappings (simplified with direct status)
SELECT * FROM bridge_mappings 
WHERE sync_status = 'synced';

-- Get error mappings with details
SELECT bm.*, bm.error_message, bm.retry_count
FROM bridge_mappings bm
WHERE bm.sync_status = 'error';

-- Get pending events for processing
SELECT * FROM bridge_mappings 
WHERE sync_status = 'pending'
ORDER BY updated_at ASC
LIMIT 100;

-- Get cancelled events with audit trail
SELECT bm.*, bsl.created_at as cancelled_at
FROM bridge_mappings bm
LEFT JOIN bridge_sync_logs bsl ON (
    bsl.source_bridge = bm.source_bridge 
    AND bsl.target_bridge = bm.target_bridge
    AND bsl.operation = 'delete'
    AND bsl.created_at = (
        SELECT MAX(created_at) FROM bridge_sync_logs bsl2
        WHERE bsl2.source_bridge = bm.source_bridge
        AND bsl2.target_bridge = bm.target_bridge
    )
)
WHERE bm.sync_status = 'cancelled';

-- Get comprehensive sync health overview
SELECT 
    sync_status,
    COUNT(*) as count,
    AVG(retry_count) as avg_retries,
    MAX(retry_count) as max_retries,
    MIN(updated_at) as oldest_update,
    MAX(updated_at) as newest_update
FROM bridge_mappings 
GROUP BY sync_status;
```

This enhanced approach provides both the simplicity of direct status access and the comprehensive audit capabilities needed for enterprise-grade sync management.
