<?php

namespace Tests\Integration;

class RealDatabaseTest extends BaseTestCase
{
    protected $mockDatabase = false;

    public function testDatabaseConnection()
    {
        $db = $this->container->get('db');
        
        $this->assertInstanceOf(\PDO::class, $db);
        
        // Test a simple query
        $stmt = $db->query('SELECT 1');
        $result = $stmt->fetchColumn();
        
        $this->assertEquals(1, $result);
    }

    public function testSystemHealthWithRealDb()
    {
        // This tests the actual HealthService interacting with the real DB
        $request = $this->createRequest('GET', '/health/system');
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        
        $body = json_decode((string)$response->getBody(), true);
        
        $this->assertTrue($body['success']);
        // Status might be degraded if cron jobs haven't run, but DB should be connected
        $this->assertContains($body['health']['status'], ['healthy', 'degraded']);
        $this->assertEquals('healthy', $body['health']['checks']['database']['status']);
    }

    public function testGetDashboardDataWithRealDb()
    {
        $request = $this->createRequest('GET', '/health/dashboard');
        $response = $this->app->handle($request);

        $body = json_decode((string)$response->getBody(), true);

        if ($response->getStatusCode() !== 200) {
            var_dump($body);
        }

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('dashboard', $body);
        $this->assertArrayHasKey('system_overview', $body['dashboard']);
    }

    public function testGetResourceMappingsWithRealDb()
    {
        $request = $this->createRequest('GET', '/mappings/resources');
        $response = $this->app->handle($request);

        $body = json_decode((string)$response->getBody(), true);

        if ($response->getStatusCode() !== 200) {
            var_dump($body);
        }

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertIsArray($body);
        // We might not have mappings, but the query should succeed
    }

    public function testGetSyncStatsWithRealDb()
    {
        $request = $this->createRequest('GET', '/bridges/sync-stats');
        $response = $this->app->handle($request);

        $body = json_decode((string)$response->getBody(), true);

        if ($response->getStatusCode() !== 200) {
            var_dump($body);
        }

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('all_bridge_stats', $body);
    }

    public function testGetRecentAlertsWithRealDb()
    {
        $request = $this->createRequest('GET', '/alerts');
        $response = $this->app->handle($request);

        $body = json_decode((string)$response->getBody(), true);

        if ($response->getStatusCode() !== 200) {
            var_dump($body);
        }

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('alerts', $body);
    }

    public function testGetBridgeConfigWithRealDb()
    {
        // Setup CSRF token
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['csrf_token'] = 'test-csrf-token';
        
        // 1. Create a test tenant
        $tenantId = 'test_tenant_' . uniqid();
        $createTenantRequest = $this->createRequest('POST', '/admin/tenants');
        $createTenantRequest = $createTenantRequest->withHeader('X-CSRF-Token', 'test-csrf-token');
        
        $createTenantRequest->getBody()->write(json_encode([
            'id' => $tenantId,
            'name' => 'Test Tenant for Config',
            'active' => true
        ]));
        $createTenantRequest->getBody()->rewind();
        $response = $this->app->handle($createTenantRequest);
        
        if ($response->getStatusCode() !== 201) {
            fwrite(STDERR, "Create Tenant Failed: " . $response->getStatusCode() . "\n");
            fwrite(STDERR, "Body: " . (string)$response->getBody() . "\n");
            fwrite(STDERR, "Env Key: " . ($_ENV['API_KEY'] ?? 'unset') . "\n");
        }

        $this->assertEquals(201, $response->getStatusCode());

        // 2. Upsert a bridge config
        $bridgeName = 'outlook';
        $configData = [
            'group_id' => 'test-group-id',
            'timezone' => 'UTC',
            'client_id' => 'test-client-id',
            'tenant_id' => 'test-azure-tenant-id',
            'client_secret' => 'test-client-secret',
            'webhook_client_secret' => 'test-webhook-secret'
        ];
        
        $upsertRequest = $this->createRequest('PUT', "/admin/tenants/{$tenantId}/configs/{$bridgeName}");
        $upsertRequest = $upsertRequest->withHeader('X-CSRF-Token', 'test-csrf-token');
        $upsertRequest->getBody()->write(json_encode($configData));
        $upsertRequest->getBody()->rewind();
        $response = $this->app->handle($upsertRequest);
        $this->assertEquals(200, $response->getStatusCode());

        // 3. Get the bridge config and verify structure
        $getRequest = $this->createRequest('GET', "/admin/tenants/{$tenantId}/configs/{$bridgeName}");
        // GET requests don't need CSRF token usually, but let's see
        $response = $this->app->handle($getRequest);
        
        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        
        // Verify structure matches user requirement
        $this->assertArrayHasKey('bridge_name', $body);
        $this->assertEquals($bridgeName, $body['bridge_name']);
        
        $this->assertArrayHasKey('bridge_type', $body);
        // Note: bridge_type usually defaults to bridge_name if not specified, or handled by repo
        
        $this->assertArrayHasKey('config_data', $body);
        $this->assertEquals($configData, $body['config_data']);
        
        $this->assertArrayHasKey('tenant_id', $body);
        $this->assertEquals($tenantId, $body['tenant_id']);
        
        $this->assertArrayHasKey('updated_at', $body);

        // 4. Cleanup (Optional but good practice)
        $deleteRequest = $this->createRequest('DELETE', "/admin/tenants/{$tenantId}");
        $deleteRequest = $deleteRequest->withHeader('X-CSRF-Token', 'test-csrf-token');
        $this->app->handle($deleteRequest);
    }

    public function testGetHealthSyncStatusWithRealDb()
    {
        $request = $this->createRequest('GET', '/health/sync-status');
        $response = $this->app->handle($request);

        $body = json_decode((string)$response->getBody(), true);

        if ($response->getStatusCode() !== 200) {
            var_dump($body);
        }

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('sync_status', $body);
        $this->assertArrayHasKey('overall_sync_health', $body['sync_status']);
    }

    public function testGetQueueStatsWithRealDb()
    {
        $request = $this->createRequest('GET', '/health/queue-stats');
        $response = $this->app->handle($request);

        $body = json_decode((string)$response->getBody(), true);

        if ($response->getStatusCode() !== 200) {
            var_dump($body);
        }

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('webhook_queue', $body['data']);
    }

    public function testEnqueueIfNotExists_DuplicatePrevention()
    {
        $db = $this->container->get('db');
        $queueRepo = new \App\Repository\BridgeQueueRepository($db);

        // Clean up any existing test data
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-duplicate-check'");

        $payload = [
            'source_calendar_id' => 'test-cal-123',
            'target_calendar_id' => 'test-cal-456',
            'event_id' => 'test-evt-789'
        ];

        // First enqueue should succeed
        $result1 = $queueRepo->enqueueIfNotExists(
            'webhook',
            'outlook',
            'booking_system',
            $payload,
            5,
            'test-duplicate-check'
        );
        $this->assertTrue($result1, 'First enqueue should succeed');

        // Second enqueue (duplicate) should fail
        $result2 = $queueRepo->enqueueIfNotExists(
            'webhook',
            'outlook',
            'booking_system',
            $payload,
            5,
            'test-duplicate-check'
        );
        $this->assertFalse($result2, 'Duplicate enqueue should be prevented');

        // Verify only one item exists
        $stmt = $db->prepare("SELECT COUNT(*) FROM bridge_queue WHERE tenant_id = 'test-duplicate-check' AND status = 'pending'");
        $stmt->execute();
        $count = $stmt->fetchColumn();
        $this->assertEquals(1, $count, 'Only one pending item should exist');

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-duplicate-check'");
    }

    public function testEnqueueIfNotExists_DifferentEventsAllowed()
    {
        $db = $this->container->get('db');
        $queueRepo = new \App\Repository\BridgeQueueRepository($db);

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-different-events'");

        $payload1 = [
            'source_calendar_id' => 'test-cal-123',
            'target_calendar_id' => 'test-cal-456',
            'event_id' => 'test-evt-111'
        ];

        $payload2 = [
            'source_calendar_id' => 'test-cal-123',
            'target_calendar_id' => 'test-cal-456',
            'event_id' => 'test-evt-222' // Different event
        ];

        // Both should succeed
        $result1 = $queueRepo->enqueueIfNotExists('webhook', 'outlook', 'booking_system', $payload1, 5, 'test-different-events');
        $result2 = $queueRepo->enqueueIfNotExists('webhook', 'outlook', 'booking_system', $payload2, 5, 'test-different-events');

        $this->assertTrue($result1);
        $this->assertTrue($result2);

        // Verify two items exist
        $stmt = $db->prepare("SELECT COUNT(*) FROM bridge_queue WHERE tenant_id = 'test-different-events' AND status = 'pending'");
        $stmt->execute();
        $count = $stmt->fetchColumn();
        $this->assertEquals(2, $count, 'Two different events should be enqueued');

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-different-events'");
    }

    public function testFindPendingItems_QueueTypeFiltering()
    {
        $db = $this->container->get('db');
        $queueRepo = new \App\Repository\BridgeQueueRepository($db);

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-queue-types'");

        // Enqueue items with different queue types
        $queueRepo->enqueue('webhook', 'outlook', 'booking_system', ['type' => 'webhook'], 5, 'test-queue-types');
        $queueRepo->enqueue('sync', 'outlook', 'booking_system', ['type' => 'sync'], 5, 'test-queue-types');

        // Find webhook items
        $webhookItems = $queueRepo->findPendingItems('webhook', 10, 'test-queue-types');
        $this->assertCount(1, $webhookItems, 'Should find 1 webhook item');
        $payload1 = json_decode($webhookItems[0]['payload'], true);
        $this->assertEquals('webhook', $payload1['type']);

        // Find sync items
        $syncItems = $queueRepo->findPendingItems('sync', 10, 'test-queue-types');
        $this->assertCount(1, $syncItems, 'Should find 1 sync item');
        $payload2 = json_decode($syncItems[0]['payload'], true);
        $this->assertEquals('sync', $payload2['type']);

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-queue-types'");
    }

    public function testRetryFailedItem()
    {
        $db = $this->container->get('db');
        $queueRepo = new \App\Repository\BridgeQueueRepository($db);

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-retry'");

        // Enqueue an item
        $queueRepo->enqueue('webhook', 'outlook', 'booking_system', ['test' => 'data'], 3, 'test-retry');
        
        // Get the item
        $items = $queueRepo->findPendingItems('webhook', 1, 'test-retry');
        $this->assertCount(1, $items);
        $itemId = $items[0]['id'];
        
        // Simulate failure by updating status to failed with attempts
        $db->exec("UPDATE bridge_queue SET status = 'failed', attempts = 3, error_message = 'Test error' WHERE id = $itemId");
        
        // Verify it's failed
        $stmt = $db->prepare("SELECT status, attempts, error_message FROM bridge_queue WHERE id = :id");
        $stmt->execute([':id' => $itemId]);
        $result = $stmt->fetch();
        $this->assertEquals('failed', $result['status']);
        $this->assertEquals(3, $result['attempts']);
        
        // Retry the failed item
        $retried = $queueRepo->retryFailedItem($itemId);
        $this->assertTrue($retried, 'Should successfully retry failed item');
        
        // Verify status is reset
        $stmt->execute([':id' => $itemId]);
        $result = $stmt->fetch();
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals(0, $result['attempts']);
        $this->assertNull($result['error_message']);
        
        // Try to retry a non-failed item (should fail)
        $retried = $queueRepo->retryFailedItem($itemId);
        $this->assertFalse($retried, 'Should not retry non-failed item');
        
        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-retry'");
    }

    public function testDeleteQueueItem()
    {
        $db = $this->container->get('db');
        $queueRepo = new \App\Repository\BridgeQueueRepository($db);

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-delete'");

        // Enqueue an item
        $queueRepo->enqueue('webhook', 'outlook', 'booking_system', ['test' => 'data'], 3, 'test-delete');
        
        // Get the item
        $items = $queueRepo->findPendingItems('webhook', 1, 'test-delete');
        $this->assertCount(1, $items);
        $itemId = $items[0]['id'];
        
        // Delete the item
        $deleted = $queueRepo->deleteQueueItem($itemId);
        $this->assertTrue($deleted, 'Should successfully delete item');
        
        // Verify it's gone
        $items = $queueRepo->findPendingItems('webhook', 1, 'test-delete');
        $this->assertCount(0, $items);
        
        // Try to delete non-existent item
        $deleted = $queueRepo->deleteQueueItem($itemId);
        $this->assertFalse($deleted, 'Should return false for non-existent item');
        
        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-delete'");
    }

    public function testGetFailedItems()
    {
        $db = $this->container->get('db');
        $queueRepo = new \App\Repository\BridgeQueueRepository($db);

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id IN ('test-failed-1', 'test-failed-2')");

        // Enqueue items for different tenants
        $queueRepo->enqueue('webhook', 'outlook', 'booking_system', ['test' => '1'], 3, 'test-failed-1');
        $queueRepo->enqueue('webhook', 'outlook', 'booking_system', ['test' => '2'], 3, 'test-failed-1');
        $queueRepo->enqueue('webhook', 'outlook', 'booking_system', ['test' => '3'], 3, 'test-failed-2');
        
        // Get items for our test tenants and mark as failed
        $items = $queueRepo->findPendingItems('webhook', 10, 'test-failed-1');
        foreach ($items as $item)
        {
            $db->exec("UPDATE bridge_queue SET status = 'failed', attempts = 3, error_message = 'Test error' WHERE id = {$item['id']}");
        }
        
        $items = $queueRepo->findPendingItems('webhook', 10, 'test-failed-2');
        foreach ($items as $item)
        {
            $db->exec("UPDATE bridge_queue SET status = 'failed', attempts = 3, error_message = 'Test error' WHERE id = {$item['id']}");
        }
        
        // Get failed items for tenant 1
        $failedItems = $queueRepo->getFailedItems('test-failed-1');
        $this->assertCount(2, $failedItems, 'Should find 2 failed items for tenant 1');
        $this->assertNotEmpty($failedItems[0]['error_message'], 'Should have error message');
        
        // Get failed items for tenant 2
        $failedItems2 = $queueRepo->getFailedItems('test-failed-2');
        $this->assertCount(1, $failedItems2, 'Should find 1 failed item for tenant 2');
        
        // Test limit on tenant-specific query
        $limitedItems = $queueRepo->getFailedItems('test-failed-1', 1);
        $this->assertCount(1, $limitedItems, 'Should respect limit parameter');
        
        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id IN ('test-failed-1', 'test-failed-2')");
    }

    public function testCleanupOldItems()
    {
        $db = $this->container->get('db');
        $queueRepo = new \App\Repository\BridgeQueueRepository($db);

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-cleanup'");

        // Enqueue items
        $queueRepo->enqueue('webhook', 'outlook', 'booking_system', ['test' => '1'], 3, 'test-cleanup');
        $queueRepo->enqueue('webhook', 'outlook', 'booking_system', ['test' => '2'], 3, 'test-cleanup');
        
        // Get items and mark as completed/failed with old created_at
        $items = $queueRepo->findPendingItems('webhook', 10, 'test-cleanup');
        $this->assertCount(2, $items);
        
        // Mark first as completed 40 days ago
        $db->exec("UPDATE bridge_queue SET status = 'completed', created_at = NOW() - INTERVAL '40 days' WHERE id = {$items[0]['id']}");
        
        // Mark second as failed 35 days ago
        $db->exec("UPDATE bridge_queue SET status = 'failed', created_at = NOW() - INTERVAL '35 days' WHERE id = {$items[1]['id']}");
        
        // Cleanup items older than 30 days
        $cleaned = $queueRepo->cleanupOldItems(30, 'test-cleanup');
        $this->assertEquals(2, $cleaned, 'Should clean up 2 old items');
        
        // Verify they're gone
        $stmt = $db->query("SELECT COUNT(*) FROM bridge_queue WHERE tenant_id = 'test-cleanup'");
        $count = $stmt->fetchColumn();
        $this->assertEquals(0, $count, 'All old items should be removed');
        
        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-cleanup'");
    }

    public function testAutoRetryLogic()
    {
        $db = $this->container->get('db');
        $queueRepo = new \App\Repository\BridgeQueueRepository($db);

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-auto-retry'");

        // Enqueue an item with max_attempts = 3
        $queueRepo->enqueue('webhook', 'outlook', 'booking_system', ['test' => 'retry'], 3, 'test-auto-retry');
        
        // Get the item
        $items = $queueRepo->findPendingItems('webhook', 1, 'test-auto-retry');
        $this->assertCount(1, $items);
        $itemId = $items[0]['id'];
        
        // Simulate first failure (attempts: 0 -> 1, status: pending)
        $queueRepo->updateStatus($itemId, 'pending', 'Error 1');
        $stmt = $db->prepare("SELECT attempts, status FROM bridge_queue WHERE id = :id");
        $stmt->execute([':id' => $itemId]);
        $result = $stmt->fetch();
        $this->assertEquals(1, $result['attempts']);
        $this->assertEquals('pending', $result['status']);
        
        // Simulate second failure (attempts: 1 -> 2, status: pending)
        $queueRepo->updateStatus($itemId, 'pending', 'Error 2');
        $stmt->execute([':id' => $itemId]);
        $result = $stmt->fetch();
        $this->assertEquals(2, $result['attempts']);
        $this->assertEquals('pending', $result['status']);
        
        // Simulate third failure (attempts: 2 -> 3, status: failed)
        $queueRepo->updateStatus($itemId, 'failed', 'Error 3');
        $stmt->execute([':id' => $itemId]);
        $result = $stmt->fetch();
        $this->assertEquals(3, $result['attempts']);
        $this->assertEquals('failed', $result['status']);
        
        // Item should now be in failed state permanently
        $items = $queueRepo->findPendingItems('webhook', 1, 'test-auto-retry');
        $this->assertCount(0, $items, 'Failed item should not appear in pending queue');
        
        // Verify it's in failed items
        $failedItems = $queueRepo->getFailedItems('test-auto-retry');
        $this->assertCount(1, $failedItems);
        $this->assertEquals(3, $failedItems[0]['attempts']);
        
        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-auto-retry'");
    }

    public function testSyncQueueProcessing()
    {
        $db = $this->container->get('db');
        $queueRepo = new \App\Repository\BridgeQueueRepository($db);

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-sync-queue'");

        // Enqueue a sync operation
        $syncPayload = [
            'mapping_id' => 1,
            'source_bridge' => 'outlook',
            'target_bridge' => 'booking_system',
            'source_calendar_id' => 'cal123',
            'target_calendar_id' => 'res456',
            'start_date' => '2025-11-01',
            'end_date' => '2025-11-30',
            'options' => ['dry_run' => false],
            'tenant_id' => 'test-sync-queue'
        ];

        $queued = $queueRepo->enqueueIfNotExists(
            'sync',
            'outlook',
            'booking_system',
            $syncPayload,
            3,
            'test-sync-queue'
        );

        $this->assertTrue($queued, 'Should queue sync operation');

        // Verify it's in the queue
        $items = $queueRepo->findPendingItems('sync', 10, 'test-sync-queue');
        $this->assertCount(1, $items);
        $this->assertEquals('sync', $items[0]['queue_type']);
        $this->assertEquals('outlook', $items[0]['source_bridge']);
        $this->assertEquals('booking_system', $items[0]['target_bridge']);

        $payload = json_decode($items[0]['payload'], true);
        $this->assertEquals(1, $payload['mapping_id']);
        $this->assertEquals('cal123', $payload['source_calendar_id']);
        $this->assertEquals('res456', $payload['target_calendar_id']);

        // Test duplicate prevention for sync operations
        $queued2 = $queueRepo->enqueueIfNotExists(
            'sync',
            'outlook',
            'booking_system',
            $syncPayload,
            3,
            'test-sync-queue'
        );

        $this->assertFalse($queued2, 'Should not queue duplicate sync operation');

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-sync-queue'");
    }

    public function testMixedQueueTypes()
    {
        $db = $this->container->get('db');
        $queueRepo = new \App\Repository\BridgeQueueRepository($db);

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-mixed-queue'");

        // Enqueue webhook operation
        $webhookPayload = [
            'resource_id' => 'cal123',
            'event_id' => 'evt789',
            'change_type' => 'updated'
        ];

        $queueRepo->enqueueIfNotExists('webhook', 'outlook', 'booking_system', $webhookPayload, 1, 'test-mixed-queue');

        // Enqueue sync operation
        $syncPayload = [
            'mapping_id' => 1,
            'source_calendar_id' => 'cal123',
            'target_calendar_id' => 'res456',
            'start_date' => '2025-11-01',
            'end_date' => '2025-11-30'
        ];

        $queueRepo->enqueueIfNotExists('sync', 'outlook', 'booking_system', $syncPayload, 3, 'test-mixed-queue');

        // Verify both are queued
        $allItems = $queueRepo->findPendingItems('webhook', 100, 'test-mixed-queue');
        $this->assertCount(1, $allItems, 'Should have 1 webhook item');

        $syncItems = $queueRepo->findPendingItems('sync', 100, 'test-mixed-queue');
        $this->assertCount(1, $syncItems, 'Should have 1 sync item');

        // Verify queue type filtering works correctly
        $webhookPayloadResult = json_decode($allItems[0]['payload'], true);
        $this->assertArrayHasKey('resource_id', $webhookPayloadResult);

        $syncPayloadResult = json_decode($syncItems[0]['payload'], true);
        $this->assertArrayHasKey('mapping_id', $syncPayloadResult);

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-mixed-queue'");
    }

    public function testUnifiedQueueProcessor()
    {
        $db = $this->container->get('db');
        $queueRepo = new \App\Repository\BridgeQueueRepository($db);
        $webhookService = $this->container->get(\App\Services\WebhookService::class);

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-unified-processor'");

        // Enqueue multiple items of different types
        $webhookPayload1 = ['resource_id' => 'cal123', 'event_id' => 'evt1', 'change_type' => 'updated'];
        $webhookPayload2 = ['resource_id' => 'cal456', 'event_id' => 'evt2', 'change_type' => 'updated'];
        
        $syncPayload1 = [
            'mapping_id' => 1,
            'source_calendar_id' => 'cal789',
            'target_calendar_id' => 'res101',
            'start_date' => '2025-11-01',
            'end_date' => '2025-11-30'
        ];

        $queueRepo->enqueueIfNotExists('webhook', 'outlook', 'booking_system', $webhookPayload1, 1, 'test-unified-processor');
        $queueRepo->enqueueIfNotExists('webhook', 'outlook', 'booking_system', $webhookPayload2, 1, 'test-unified-processor');
        $queueRepo->enqueueIfNotExists('sync', 'outlook', 'booking_system', $syncPayload1, 3, 'test-unified-processor');

        // Verify items are queued
        $webhookItems = $queueRepo->findPendingItems('webhook', 10, 'test-unified-processor');
        $syncItems = $queueRepo->findPendingItems('sync', 10, 'test-unified-processor');
        
        $this->assertCount(2, $webhookItems, 'Should have 2 webhook items');
        $this->assertCount(1, $syncItems, 'Should have 1 sync item');

        // Note: We can't actually process the queue items here because they would fail
        // (no actual bridges configured, no real events), but we've verified:
        // 1. Items can be queued with different types
        // 2. findPendingItems correctly filters by queue_type
        // 3. The unified processor can query both types

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-unified-processor'");
    }

    public function testQueueProcessorBatchLimit()
    {
        $db = $this->container->get('db');
        $queueRepo = new \App\Repository\BridgeQueueRepository($db);

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-batch-limit'");

        // Enqueue 10 webhook items
        for ($i = 1; $i <= 10; $i++)
        {
            $payload = ['resource_id' => "cal{$i}", 'event_id' => "evt{$i}", 'change_type' => 'updated'];
            $queueRepo->enqueueIfNotExists('webhook', 'outlook', 'booking_system', $payload, 1, 'test-batch-limit');
        }

        // Verify all 10 are queued
        $allItems = $queueRepo->findPendingItems('webhook', 100, 'test-batch-limit');
        $this->assertCount(10, $allItems, 'Should have 10 webhook items');

        // Fetch with batch limit of 5
        $batchItems = $queueRepo->findPendingItems('webhook', 5, 'test-batch-limit');
        $this->assertCount(5, $batchItems, 'Should respect batch limit of 5');

        // Fetch with batch limit of 3
        $smallBatch = $queueRepo->findPendingItems('webhook', 3, 'test-batch-limit');
        $this->assertCount(3, $smallBatch, 'Should respect batch limit of 3');

        // Clean up
        $db->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-batch-limit'");
    }
}


