# Bridge Status Tracking Architecture

## How Status is Determined in the Bridge System

The bridge system uses two main tables to track sync status:

### 1. `bridge_mappings` Table
- Stores permanent mapping relationships between calendar events
- `last_synced_at`: Timestamp of last successful sync
- No direct status column (this is the key difference from old system)

### 2. `bridge_sync_logs` Table  
- Tracks all sync operations with status
- `status` values: 'success', 'error', 'pending'
- `operation` values: 'create', 'update', 'delete', 'sync'

## Status Logic

### 🟢 **Synced**
- Mapping has `last_synced_at` timestamp
- AND has recent 'success' status in sync logs (last 24h)

### 🟡 **Pending** 
- Mapping has no `last_synced_at` timestamp (never synced)
- OR has recent 'pending' status in sync logs (last 1h)
- AND no recent errors

### 🔴 **Error**
- Mapping has recent 'error' status in sync logs (last 24h)
- AND no more recent 'success' status

## Benefits of This Architecture

1. **Audit Trail**: Complete history of all sync operations
2. **Transient States**: Can track temporary 'pending' states
3. **Error Recovery**: Can see when errors are resolved
4. **Performance**: Can track sync throughput and timing
5. **Debugging**: Full context for troubleshooting

## Query Examples

```sql
-- Get synced mappings
SELECT bm.* FROM bridge_mappings bm
WHERE bm.last_synced_at IS NOT NULL
AND EXISTS (
    SELECT 1 FROM bridge_sync_logs bsl 
    WHERE (bsl.source_bridge = bm.source_bridge AND bsl.target_bridge = bm.target_bridge)
    AND bsl.status = 'success' 
    AND bsl.created_at > NOW() - INTERVAL '24 hours'
);

-- Get error mappings  
SELECT bm.* FROM bridge_mappings bm
WHERE EXISTS (
    SELECT 1 FROM bridge_sync_logs bsl 
    WHERE (bsl.source_bridge = bm.source_bridge AND bsl.target_bridge = bm.target_bridge)
    AND bsl.status = 'error' 
    AND bsl.created_at > NOW() - INTERVAL '24 hours'
    AND NOT EXISTS (
        SELECT 1 FROM bridge_sync_logs bsl2 
        WHERE (bsl2.source_bridge = bm.source_bridge AND bsl2.target_bridge = bm.target_bridge)
        AND bsl2.status = 'success' 
        AND bsl2.created_at > bsl.created_at
    )
);
```

This approach provides much more granular and accurate status tracking than a simple status column.
