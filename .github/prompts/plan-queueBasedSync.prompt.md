# Queue-Based Sync Architecture Implementation Plan

## Overview
Refactor `BridgeController::syncBridges()` to use queue-based processing instead of synchronous event processing. This prevents race conditions and provides unified processing for webhooks, manual syncs, and cron jobs.

## Current State
- **Webhooks**: Use conditional processing based on PHP-FPM availability:
  - If `fastcgi_finish_request()` exists: Queue items then process immediately after response
  - If not available: Queue items for cron job processing
  - Controlled by `WEBHOOK_IMMEDIATE_PROCESSING` environment variable (default: 'true')
- **Manual Syncs**: Process events synchronously in `syncBridges()` method
- **Queue Infrastructure**: Fully functional with `bridge_queue` table and `BridgeQueueRepository`
- **Queue Types**: 'webhook' (existing), 'sync' (to be implemented)

## Goals
1. Prevent race conditions from concurrent sync operations
2. Unify webhook and manual sync processing through single queue processor
3. Prevent duplicate queue items for the same sync operation
4. Maintain backward compatibility with immediate sync option
5. Reuse existing queue infrastructure (no new tables needed)

## Implementation Checklist

### Core Queue Infrastructure
- [x] **Step 0**: Add Duplicate Prevention to Queue Repository
  - [x] Create `enqueueIfNotExists()` method in `BridgeQueueRepository`
  - [x] Implement PostgreSQL JSONB containment check (`@>`)
  - [x] Add uniqueness check for: tenant_id, queue_type, bridges, calendar_ids, event_ids
  - [x] Return boolean (true=queued, false=duplicate)
  
- [x] **Step 1**: Extend Queue Processors to Handle Multiple Queue Types
  - [x] Add `$queueType` parameter to `processWebhookQueueBatch()` (default: 'webhook')
  - [x] Add `$queueType` parameter to `processWebhookQueueImmediate()` (default: 'webhook')
  - [x] Update repository calls to pass `$queueType`
  - [x] Test with both 'webhook' and 'sync' queue types
  - [x] Update `queueSyncOperation()` to use `enqueueIfNotExists()`

- [x] **Step 1.5**: Add Auto-Retry and Failure Handling Logic ✅
  - [x] Modify `WebhookService` to check `attempts < 3` on failure
  - [x] Mark as 'pending' if attempts < 3 (auto-retry)
  - [x] Mark as 'failed' if attempts >= 3 (permanent failure)
  - [x] Add `retryFailedItem()` method to `BridgeQueueRepository`
  - [x] Add `deleteQueueItem()` method to `BridgeQueueRepository`
  - [x] Add `cleanupOldItems()` method to `BridgeQueueRepository`
  - [x] Add `getFailedItems()` method to `BridgeQueueRepository`
  - [x] Add logging for retry/failure scenarios
  - [x] Added integration tests: `testRetryFailedItem()`, `testDeleteQueueItem()`, `testGetFailedItems()`, `testCleanupOldItems()`, `testAutoRetryLogic()`

- [x] **Step 2**: Modify syncBridges() to Use Queue-Based Processing ✅
  - [x] Replace synchronous processing with `enqueueIfNotExists()` calls
  - [x] Add PHP-FPM detection (`function_exists('fastcgi_finish_request')`)
  - [x] Check `SYNC_IMMEDIATE_PROCESSING` environment variable
  - [x] If PHP-FPM available: send response, then call `processWebhookQueueImmediate()`
  - [x] If not available: log that cron will process
  - [x] Update response format (jobs_queued, jobs_skipped, processing status)
  - [x] Maintain backward compatibility for dry_run mode
  - [x] Added `processSyncOperation()` method to WebhookService
  - [x] Updated queue processors to handle both 'webhook' and 'sync' types
  - [x] Fixed `findPendingItems()` to include queue_type in SELECT
  - [x] Added integration tests: `testSyncQueueProcessing()`, `testMixedQueueTypes()`

- [x] **Step 3**: Create Unified Queue Processor Endpoint ✅
  - [x] Add `processQueue()` method to `BridgeController`
  - [x] Accept `queue_types` array parameter (default: ['webhook', 'sync'])
  - [x] Accept `batch_size` parameter (default: 50)
  - [x] Loop through queue types and process each sequentially
  - [x] Return combined statistics (jobs processed, failures, duration)
  - [x] Add route: `POST /bridges/process-queue`
  - [x] Validate queue_types array and individual queue type values
  - [x] Support 'webhook', 'sync', and 'deletion' queue types
  - [x] Include per-queue-type results with error details when applicable
  - [x] Added integration tests: `testUnifiedQueueProcessor()`, `testQueueProcessorBatchLimit()`

### API & Management Layer
- [x] **Step 4**: Add Queue Management API Endpoints
  - [x] Add `getFailedQueueItems()` method to `BridgeController`
  - [x] Add `retryFailedQueueItem()` method to `BridgeController`
  - [x] Add `deleteQueueItem()` method to `BridgeController`
  - [x] Add route: `GET /bridges/queue/failed`
  - [x] Add route: `POST /bridges/queue/{id}/retry`
  - [x] Add route: `DELETE /bridges/queue/{id}`
  - [x] Test all three endpoints with valid/invalid IDs

- [x] **Step 5**: Add Queue Cleanup to Maintenance Controller
  - [x] Add `cleanupOldQueueItems()` method to `MaintenanceController`
  - [x] Accept `days` query parameter (default: 30)
  - [x] Call `queueRepository->cleanupOldItems()`
  - [x] Return deleted count
  - [x] Add route: `POST /maintenance/cleanup-queue`
  - [x] Add dependency injection for `BridgeQueueRepository` in `MaintenanceController`

### Configuration & Deployment
- [x] **Step 6**: Update Configuration and Routes
  - [x] Add `SYNC_IMMEDIATE_PROCESSING=true` to `.env.example`
  - [x] Add `SYNC_IMMEDIATE_PROCESSING=true` to `.env`
  - [x] Add all new routes to `bootstrap.php`:
    - [x] `/bridges/process-queue`
    - [x] `/bridges/queue/failed`
    - [x] `/bridges/queue/{id}/retry`
    - [x] `/bridges/queue/{id}`
    - [x] `/maintenance/cleanup-queue`
  - [x] Mark `/bridges/process-webhook-queue` as deprecated in docs

- [x] **Step 7**: Update Cron Job Configuration
  - [x] Update cron to call `/bridges/process-queue` with both queue types
  - [x] Add daily cleanup cron at 2 AM
  - [x] Created comprehensive `doc/cron-examples.sh` with unified processor examples
  - [x] Document cron configuration in `doc/operations.md`

### Testing
- [x] **Unit Tests** (covered in integration tests)
  - [x] Test `enqueueIfNotExists()` duplicate detection
  - [x] Test `processWebhookQueueBatch()` with different queue types
  - [x] Test auto-retry logic (attempts < 3 vs >= 3)
  - [x] Test `retryFailedItem()`, `deleteQueueItem()`, `cleanupOldItems()`
  - [x] Test `syncBridges()` queue-based processing

- [x] **Integration Tests** (21 tests, 94 assertions)
  - [x] Test full sync flow: enqueue → process → verify synced
  - [x] Test mixed queue processing (webhook + sync)
  - [x] Test auto-retry flow (fail → retry → fail → permanent failure)
  - [x] Test duplicate prevention (concurrent requests)
  - [x] Test queue cleanup (old items removed)
  - Tests: `testEnqueueIfNotExists_DuplicatePrevention`, `testEnqueueIfNotExists_DifferentEventsAllowed`, `testFindPendingItems_QueueTypeFiltering`, `testRetryFailedItem`, `testDeleteQueueItem`, `testGetFailedItems`, `testCleanupOldItems`, `testAutoRetryLogic`, `testSyncQueueProcessing`, `testMixedQueueTypes`, `testUnifiedQueueProcessor`, `testQueueProcessorBatchLimit`

- [ ] **Manual Testing**
  - [ ] Trigger manual sync → verify jobs queued
  - [ ] Call `/bridges/process-queue` → verify processing
  - [ ] Simulate failure → verify auto-retry (3 attempts)
  - [ ] Check failed items via `GET /bridges/queue/failed`
  - [ ] Retry failed item via `POST /bridges/queue/{id}/retry`
  - [ ] Delete queue item via `DELETE /bridges/queue/{id}`
  - [ ] Trigger webhook → verify queued and processed
  - [ ] Call `/maintenance/cleanup-queue` → verify cleanup
  - [ ] Test in PHP-FPM environment (immediate processing)
  - [ ] Test in non-PHP-FPM environment (cron fallback)

### Documentation
- [x] Update `doc/api_endpoints.md` with new endpoints
- [x] Update `doc/operations.md` with cron job configuration
- [x] Created `doc/cron-examples.sh` with comprehensive examples
- [ ] Update `doc/architecture.md` with queue-based sync architecture
- [x] Document auto-retry behavior and failure handling
- [x] Document duplicate prevention logic
- [ ] Update `CHANGELOG.md` with breaking changes (if any)
- [ ] Add troubleshooting guide for queue issues

### Deployment Preparation
- [ ] Review all code changes
- [ ] Run full test suite
- [ ] Update `.env.example` with all new variables
- [ ] Prepare rollback plan documentation
- [ ] Monitor dashboard shows queue stats correctly
- [ ] Verify logs show queue processing activities
- [ ] Performance test with high queue volume
- [ ] Security review (API key validation on new endpoints)

## Implementation Steps

### Step 0: Add Duplicate Prevention to Queue Repository
**File**: `src/Repository/BridgeQueueRepository.php`
**New Method**: `enqueueIfNotExists()`

**Implementation**:
```php
public function enqueueIfNotExists(
    string $queueType,
    string $sourceBridge,
    ?string $targetBridge,
    array $payload,
    int $priority = 5,
    ?string $tenantId = null
): bool {
    // Build uniqueness criteria including payload fields
    $sql = "SELECT COUNT(*) FROM bridge_queue 
            WHERE queue_type = :queue_type 
            AND source_bridge = :source_bridge 
            AND target_bridge = :target_bridge
            AND status IN ('pending', 'processing')
            AND tenant_id = :tenant_id
            AND payload::jsonb @> :payload_filter";
    
    // Extract key identifiers from payload for uniqueness check
    $payloadFilter = [];
    if (isset($payload['source_calendar_id'])) {
        $payloadFilter['source_calendar_id'] = $payload['source_calendar_id'];
    }
    if (isset($payload['target_calendar_id'])) {
        $payloadFilter['target_calendar_id'] = $payload['target_calendar_id'];
    }
    if (isset($payload['event_id'])) {
        $payloadFilter['event_id'] = $payload['event_id'];
    }
    if (isset($payload['source_event_id'])) {
        $payloadFilter['source_event_id'] = $payload['source_event_id'];
    }
    
    $stmt = $this->db->prepare($sql);
    $stmt->execute([
        ':queue_type' => $queueType,
        ':source_bridge' => $sourceBridge,
        ':target_bridge' => $targetBridge,
        ':tenant_id' => $tenantId,
        ':payload_filter' => json_encode($payloadFilter)
    ]);
    
    if ($stmt->fetchColumn() > 0) {
        return false; // Duplicate exists, skip enqueue
    }
    
    // No duplicate found, proceed with enqueue
    $this->enqueue($queueType, $sourceBridge, $targetBridge, $payload, $priority, $tenantId);
    return true;
}
```

**Uniqueness Criteria**:
- Base: `queue_type` + `source_bridge` + `target_bridge` + `tenant_id`
- Payload: `source_calendar_id` + `target_calendar_id` + `event_id` (or `source_event_id`)
- Only check items with status 'pending' or 'processing'
- Uses PostgreSQL's `@>` operator to check if existing payload contains the key identifiers

**Why These Fields**:
- **Calendar IDs**: Identifies which specific calendars are being synced
- **Event IDs**: Identifies the specific event being processed (for webhook operations)
- **Tenant ID**: Multi-tenancy isolation
- **Bridge Pair**: Direction of sync operation

**Example Scenarios**:
1. **Webhook for event creation**: `event_id=ABC`, `source_calendar_id=cal1`, `target_calendar_id=cal2`
2. **Manual sync**: `source_calendar_id=cal1`, `target_calendar_id=cal2` (no event_id = full calendar sync)
3. **Event update webhook**: `event_id=ABC`, `source_calendar_id=cal1`, `target_calendar_id=cal2`

**Impact**: Prevents duplicate sync operations when multiple requests arrive simultaneously

### Step 1: Extend Queue Processors to Handle Multiple Queue Types
**File**: `src/Services/WebhookService.php`

**Changes to `processWebhookQueueBatch()`**:
- Add `$queueType` parameter (default: 'webhook' for backward compatibility)
- Update repository call: `$this->queueRepository->findPendingItems($queueType, $batchSize, $tenantId)`
- No other logic changes needed - existing code already handles all event types

**Changes to `processWebhookQueueImmediate()`**:
- Add `$queueType` parameter (default: 'webhook' for backward compatibility)
- Update repository call: `$this->queueRepository->findPendingItems($queueType, $batchSize, $tenantId)`
- This method is used by both webhook and sync immediate processing

**Impact**: Minimal - single parameter addition to both methods, existing functionality preserved

### Step 1.5: Add Auto-Retry and Failure Handling Logic
**File**: `src/Services/WebhookService.php`
**Methods**: `processWebhookQueueBatch()` and `processWebhookQueueImmediate()`

**Auto-Retry Logic**:
```php
// When processing fails:
if ($item['attempts'] < 3) {
    // Mark as pending for retry (attempts already incremented by markProcessing)
    $this->queueRepository->updateStatus($item['id'], 'pending', $errorMessage);
    $this->logger->warning('Queue item marked for retry', [
        'id' => $item['id'],
        'attempts' => $item['attempts'] + 1,
        'max_attempts' => 3
    ]);
} else {
    // Max attempts reached, mark as failed
    $this->queueRepository->updateStatus($item['id'], 'failed', $errorMessage);
    $this->logger->error('Queue item failed after max attempts', [
        'id' => $item['id'],
        'attempts' => $item['attempts'] + 1,
        'error' => $errorMessage
    ]);
}
```

**File**: `src/Repository/BridgeQueueRepository.php`
**New Methods**:

```php
// Retry a failed queue item (reset attempts)
public function retryFailedItem(int $id): bool
{
    $sql = "UPDATE bridge_queue 
            SET status = 'pending', attempts = 0, error_message = NULL 
            WHERE id = :id AND status = 'failed'";
    $stmt = $this->db->prepare($sql);
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}

// Delete a specific queue item
public function deleteQueueItem(int $id): bool
{
    $sql = "DELETE FROM bridge_queue WHERE id = :id";
    $stmt = $this->db->prepare($sql);
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}

// Cleanup old completed/failed items (older than X days)
public function cleanupOldItems(int $daysOld = 30): int
{
    $sql = "DELETE FROM bridge_queue 
            WHERE status IN ('completed', 'failed') 
            AND created_at < NOW() - INTERVAL ':days days'";
    $stmt = $this->db->prepare($sql);
    $stmt->execute([':days' => $daysOld]);
    return $stmt->rowCount();
}

// Get failed queue items for manual review
public function getFailedItems(?string $queueType = null, ?string $tenantId = null, int $limit = 100): array
{
    $sql = "SELECT id, queue_type, source_bridge, target_bridge, payload, 
                   attempts, error_message, created_at, tenant_id
            FROM bridge_queue 
            WHERE status = 'failed'";
    
    $params = [];
    if ($queueType) {
        $sql .= " AND queue_type = :queue_type";
        $params[':queue_type'] = $queueType;
    }
    if ($tenantId) {
        $sql .= " AND tenant_id = :tenant_id";
        $params[':tenant_id'] = $tenantId;
    }
    
    $sql .= " ORDER BY created_at DESC LIMIT :limit";
    
    $stmt = $this->db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

### Step 2: Modify syncBridges() to Use Queue-Based Processing with PHP-FPM Detection
**File**: `src/Controller/BridgeController.php`
**Method**: `syncBridges()`

**Changes - Mirror Webhook Logic**:
1. **Always enqueue jobs first** (consistent with webhook behavior)
2. **Check environment variable**: `SYNC_IMMEDIATE_PROCESSING` (default: 'true')
3. **If immediate processing enabled**:
   - Check if `fastcgi_finish_request()` exists (PHP-FPM detection)
   - If available: Send HTTP response, then process queue immediately via `processWebhookQueueImmediate($tenantId, $batchSize, 'sync')`
   - If not available: Return response noting cron job will process queue
4. **If immediate processing disabled**: Return response noting cron job will process queue

**Key Principle**: Use identical logic to webhook processing for consistency

**Queue Implementation with Duplicate Prevention**:
- For each resource mapping, call `$this->queueRepository->enqueueIfNotExists()`
- Queue type: 'sync'
- Payload: Include all sync context (source_bridge, target_bridge, resource IDs, date range, etc.)
- Track skipped duplicates in response for visibility

**Response Format**:
```json
{
  "success": true,
  "message": "Sync operations queued",
  "jobs_queued": 5,
  "jobs_skipped": 2,
  "processing": "immediate|cron",
  "note": "Processing after response sent" // if PHP-FPM available
}
```

**Note**: `jobs_skipped` indicates duplicate operations already in queue

**Backward Compatibility**: Remove old synchronous processing code - all syncs now use queue

### Step 3: Create Unified Queue Processor Endpoint
**File**: `src/Controller/BridgeController.php`
**New Method**: `processQueue()`

**Functionality**:
- Accept `queue_types` array parameter (default: `["webhook", "sync"]`)
- Accept `batch_size` parameter (default: 50)
- Call `$this->webhookService->processWebhookQueueBatch($batchSize, $tenantId, $queueType)` for each type
- Return combined statistics: jobs processed per queue type, failures, duration

**Route**: `POST /bridges/process-queue`
**Body**: `{"queue_types": ["webhook", "sync"], "batch_size": 50}`

### Step 4: Add Queue Management API Endpoints
**File**: `src/Controller/BridgeController.php`
**New Methods**:

```php
// Get failed queue items
public function getFailedQueueItems(Request $request, Response $response): Response
{
    $tenantId = $request->getAttribute('tenant_id');
    $queueType = $request->getQueryParams()['queue_type'] ?? null;
    $limit = (int)($request->getQueryParams()['limit'] ?? 100);
    
    $failedItems = $this->queueRepository->getFailedItems($queueType, $tenantId, $limit);
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'failed_items' => $failedItems,
        'count' => count($failedItems)
    ], JSON_PRETTY_PRINT));
    
    return $response->withHeader('Content-Type', 'application/json');
}

// Retry a specific failed queue item
public function retryFailedQueueItem(Request $request, Response $response, array $args): Response
{
    $itemId = (int)$args['id'];
    
    $success = $this->queueRepository->retryFailedItem($itemId);
    
    if ($success) {
        $this->logger->info('Queue item marked for retry', ['id' => $itemId]);
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Queue item marked for retry',
            'id' => $itemId
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    } else {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to retry queue item (not found or not in failed status)',
            'id' => $itemId
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
}

// Delete a specific queue item
public function deleteQueueItem(Request $request, Response $response, array $args): Response
{
    $itemId = (int)$args['id'];
    
    $success = $this->queueRepository->deleteQueueItem($itemId);
    
    if ($success) {
        $this->logger->info('Queue item deleted', ['id' => $itemId]);
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Queue item deleted',
            'id' => $itemId
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    } else {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to delete queue item (not found)',
            'id' => $itemId
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
}
```

**File**: `bootstrap.php`
**Add Routes**:
```php
// Queue management routes
$app->get('/bridges/queue/failed', [\App\Controller\BridgeController::class, 'getFailedQueueItems']);
$app->post('/bridges/queue/{id}/retry', [\App\Controller\BridgeController::class, 'retryFailedQueueItem']);
$app->delete('/bridges/queue/{id}', [\App\Controller\BridgeController::class, 'deleteQueueItem']);
```

### Step 5: Add Queue Cleanup to Maintenance Controller
**File**: `src/Controller/MaintenanceController.php`
**New Method**:

```php
public function cleanupOldQueueItems(Request $request, Response $response): Response
{
    $days = (int)($request->getQueryParams()['days'] ?? 30);
    
    $deletedCount = $this->queueRepository->cleanupOldItems($days);
    
    $this->logger->info('Old queue items cleaned up', [
        'deleted_count' => $deletedCount,
        'days_old' => $days
    ]);
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'message' => 'Old queue items cleaned up',
        'deleted_count' => $deletedCount,
        'days_old' => $days
    ], JSON_PRETTY_PRINT));
    
    return $response->withHeader('Content-Type', 'application/json');
}
```

**File**: `bootstrap.php`
**Add Route**:
```php
$app->post('/maintenance/cleanup-queue', [\App\Controller\MaintenanceController::class, 'cleanupOldQueueItems']);
```

### Step 6: Update Configuration and Routes

**File**: `.env.example`
**Add**:
```bash
# Sync Processing Configuration
SYNC_IMMEDIATE_PROCESSING=true
```

**File**: `.env`
**Add**:
```bash
SYNC_IMMEDIATE_PROCESSING=true
```

**File**: `bootstrap.php`
**Add New Routes**:
```php
$app->post('/bridges/process-queue', [\App\Controller\BridgeController::class, 'processQueue']);
$app->get('/bridges/queue/failed', [\App\Controller\BridgeController::class, 'getFailedQueueItems']);
$app->post('/bridges/queue/{id}/retry', [\App\Controller\BridgeController::class, 'retryFailedQueueItem']);
$app->delete('/bridges/queue/{id}', [\App\Controller\BridgeController::class, 'deleteQueueItem']);
$app->post('/maintenance/cleanup-queue', [\App\Controller\MaintenanceController::class, 'cleanupOldQueueItems']);
```

**Deprecation Note**: Keep existing `/bridges/process-webhook-queue` for backward compatibility, but mark as deprecated in API docs

### Step 7: Update Cron Job Configuration
**Current Cron**:
```bash
*/5 * * * * curl -X POST http://localhost:8082/bridges/process-webhook-queue
```

**New Unified Cron**:
```bash
# Process all queue types every 5 minutes
*/5 * * * * curl -X POST -H "Content-Type: application/json" \
  -d '{"queue_types": ["webhook", "sync"], "batch_size": 50}' \
  http://localhost:8082/bridges/process-queue

# Cleanup old queue items daily at 2 AM
0 2 * * * curl -X POST "http://localhost:8082/maintenance/cleanup-queue?days=30"
```

**Benefit**: Single cron job processes all queue types instead of separate jobs

## Queue Payload Structure

### Webhook Queue Item (Existing)
```json
{
  "queue_type": "webhook",
  "payload": {
    "bridge_name": "outlook",
    "resource_id": "calendar_123",
    "event_id": "event_456",
    "change_type": "created",
    "webhook_data": {...}
  }
}
```

### Sync Queue Item (New)
```json
{
  "queue_type": "sync",
  "payload": {
    "source_bridge": "outlook",
    "target_bridge": "booking_system",
    "source_calendar_id": "calendar_123",
    "target_calendar_id": "resource_456",
    "sync_direction": "source_to_target",
    "start_date": "2025-11-01",
    "end_date": "2025-11-30",
    "mapping_id": 789
  }
}
```

## Error Handling

### Queue Processing Failures
- Use existing `markFailed()` in `BridgeQueueRepository`
- Failed items remain in queue for retry or manual investigation
- Dashboard shows failed queue items via `/health/queue-stats`

### Immediate Sync Failures
- Keep existing error response format for backward compatibility
- Return 500 with error details as currently implemented

## New API Endpoints

### Queue Management
- `GET /bridges/queue/failed` - Get list of failed queue items
  - Query params: `queue_type` (webhook|sync), `limit` (default: 100)
  - Returns: Array of failed items with error details
  
- `POST /bridges/queue/{id}/retry` - Retry a specific failed queue item
  - Resets attempts to 0 and status to 'pending'
  - Returns: Success/failure response
  
- `DELETE /bridges/queue/{id}` - Delete a specific queue item
  - Removes item from queue permanently
  - Use for items that cannot be retried or are no longer needed
  
- `POST /maintenance/cleanup-queue` - Cleanup old queue items
  - Query params: `days` (default: 30)
  - Deletes completed/failed items older than specified days
  - Should be called via cron job daily

### Unified Queue Processor
- `POST /bridges/process-queue` - Process multiple queue types
  - Body: `{"queue_types": ["webhook", "sync"], "batch_size": 50}`
  - Returns: Combined statistics for all processed queue types

## Auto-Retry Behavior

**Retry Logic**:
1. Queue item processing fails
2. Check `attempts` field (incremented by `markProcessing()`)
3. If `attempts < 3`: Mark as 'pending' for automatic retry
4. If `attempts >= 3`: Mark as 'failed' permanently
5. Failed items remain in queue for manual review/retry

**Manual Recovery**:
- View failed items via `GET /bridges/queue/failed`
- Retry specific item via `POST /bridges/queue/{id}/retry` (resets attempts to 0)
- Delete unrecoverable items via `DELETE /bridges/queue/{id}`

**Automatic Cleanup**:
- Daily cron job removes completed/failed items older than 30 days
- Prevents queue table from growing indefinitely
- Failed items visible for 30 days for manual recovery

## Testing Strategy

### Unit Tests
- Test `processWebhookQueueBatch()` with different queue types
- Test `syncBridges()` with queue-based processing
- Test queue payload serialization/deserialization
- Test auto-retry logic (attempts < 3 vs attempts >= 3)
- Test `enqueueIfNotExists()` duplicate prevention
- Test `retryFailedItem()`, `deleteQueueItem()`, `cleanupOldItems()`

### Integration Tests
- Test full sync flow: enqueue → process queue → verify events synced
- Test mixed queue processing: webhook + sync items in same batch
- Test auto-retry flow: fail → retry → fail → retry → permanently failed
- Test duplicate prevention: concurrent requests don't create duplicate queue items
- Test queue cleanup: old items are removed after 30 days

### Manual Testing
1. Trigger manual sync with immediate processing → verify jobs queued and processed
2. Call `/bridges/process-queue` → verify jobs processed
3. Simulate processing failure → verify auto-retry (up to 3 attempts)
4. After 3 failed attempts → verify item marked as 'failed'
5. Call `GET /bridges/queue/failed` → verify failed items visible
6. Call `POST /bridges/queue/{id}/retry` → verify item retried
7. Call `DELETE /bridges/queue/{id}` → verify item deleted
8. Create webhook subscription → trigger webhook → verify queued and processed
9. Call `/maintenance/cleanup-queue` → verify old items removed

## Migration Path

### Phase 1: Deploy with Immediate Processing Enabled (Default)
- Deploy code changes with `SYNC_IMMEDIATE_PROCESSING='true'` (set in .env file)
- Behavior similar to current synchronous processing (but via queue)
- In PHP-FPM environments: Response sent immediately, then queue processed
- In non-PHP-FPM environments: Falls back to cron job processing
- Monitor for issues

### Phase 2: Production Validation
- Verify immediate processing works correctly in production PHP-FPM environment
- Monitor queue processing performance and timing
- Compare processing times vs old synchronous approach
- Validate no race conditions occur

### Phase 3: Optional - Disable Immediate Processing (Future)
- For high-volume environments, optionally set `SYNC_IMMEDIATE_PROCESSING='false'`
- All sync operations processed by cron job only
- Better load distribution for systems with many concurrent syncs
- Update API documentation to note processing behavior

## Benefits

### Operational
- **Race Condition Prevention**: Queue ensures sequential processing per resource
- **Better Monitoring**: All sync operations visible in queue stats dashboard
- **Retry Capability**: Failed syncs remain in queue for automatic retry
- **Load Management**: Batch size controls processing rate

### Architectural
- **Unified Processing**: Single code path for all sync operations
- **Simpler Cron Jobs**: One endpoint instead of multiple
- **Better Testability**: Queue-based processing easier to test
- **Scalability**: Queue can be processed by multiple workers (future)

## Rollback Plan
If issues arise:
1. Set `SYNC_IMMEDIATE_PROCESSING='false'` to disable immediate processing (rely on cron only)
2. If severe issues: Clear problematic queue items: `DELETE FROM bridge_queue WHERE queue_type = 'sync'`
3. Emergency rollback: Revert to previous git commit and redeploy
4. Cron job will continue processing webhooks independently

## Documentation Updates Required
- `doc/api_endpoints.md`: Document new `/bridges/process-queue` endpoint
- `doc/operations.md`: Update cron job configuration
- `doc/architecture.md`: Explain queue-based sync architecture
- `CHANGELOG.md`: Document breaking changes (if default switches to queue-based)

## Estimated Effort
- **Step 0**: 1-2 hours (duplicate prevention with payload checking)
- **Step 1**: 30 minutes (queue type parameters)
- **Step 1.5**: 2-3 hours (auto-retry logic and repository methods)
- **Step 2**: 1-2 hours (modify syncBridges)
- **Step 3**: 1 hour (unified queue processor)
- **Step 6**: 2 hours (queue management API endpoints)
- **Step 7**: 1 hour (cleanup controller and route)
- **Step 8-9**: 30 minutes (routes and cron config)
- **Testing**: 3-4 hours (unit + integration tests + manual testing)
- **Documentation**: 1-2 hours

**Total**: 12-16 hours of development work

## Environment Variables

### New Configuration
- `SYNC_IMMEDIATE_PROCESSING` (default: 'true')
  - Controls whether manual syncs are processed immediately after queueing
  - Set to 'false' to always defer to cron job processing
  - Mirrors `WEBHOOK_IMMEDIATE_PROCESSING` behavior
  - **Add to .env file**: `SYNC_IMMEDIATE_PROCESSING=true`
  - **Add to .env.example**: `SYNC_IMMEDIATE_PROCESSING=true`

### Existing Configuration (for reference)
- `WEBHOOK_IMMEDIATE_PROCESSING` (default: 'true')
  - Controls webhook immediate processing
  - Both webhook and sync processing now use same pattern

## PHP-FPM Detection Logic

### How It Works
```php
// 1. Queue the sync operations
$this->queueRepository->enqueue('sync', $payload, $tenantId);

// 2. Check if immediate processing is enabled
$immediateProcessing = $_ENV['SYNC_IMMEDIATE_PROCESSING'] ?? 'true';
if ($immediateProcessing === 'true')
{
    // 3. Check if PHP-FPM is available
    if (function_exists('fastcgi_finish_request'))
    {
        // Send HTTP response to client immediately
        fastcgi_finish_request();
        
        // Process queue in background (client already has response)
        $this->webhookService->processWebhookQueueImmediate($tenantId, $batchSize, 'sync');
    }
    else
    {
        // Non-PHP-FPM environment: note in response that cron will process
        $this->logger->info('Sync queued for cron processing - fastcgi_finish_request not available');
    }
}
```

### Benefits of This Approach
1. **Consistent Pattern**: Webhooks and manual syncs use identical logic
2. **Fast User Response**: Client gets immediate 202 Accepted response
3. **Background Processing**: Work happens after response sent (if PHP-FPM available)
4. **Graceful Degradation**: Falls back to cron job if PHP-FPM unavailable
5. **Configurable**: Can disable immediate processing via environment variable

## Queue Priority Structure

**No Priority Differentiation Needed**:
- All queue items use default priority (5)
- Cron jobs serve as **fallback mechanism** for failed immediate processing
- Processing order: FIFO (First In, First Out) by `created_at`
- Rationale:
  - In PHP-FPM environments: Both webhooks and syncs process immediately after queueing
  - In non-PHP-FPM environments: Cron job processes all pending items in order
  - If immediate processing fails: Item remains in queue for cron job pickup
  - Priority levels add complexity without operational benefit in this architecture

**Queue Processing Flow**:
1. Request arrives (webhook or manual sync)
2. Duplicate check performed
3. Item enqueued (if not duplicate)
4. If `IMMEDIATE_PROCESSING='true'` and PHP-FPM available:
   - Response sent immediately
   - Queue processed in background
   - If processing fails: Item remains for cron job
5. Cron job runs periodically:
   - Processes any pending items (failed immediate processing or non-PHP-FPM queued items)
   - Acts as safety net and fallback

## Questions for User
1. What batch size makes sense for production? (Current webhook processing uses 50)
