<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Repository\BridgeQueueRepository;
use PDO;

class BridgeQueueRepositoryTest extends TestCase
{
    private $pdo;
    private $repository;

    protected function setUp(): void
    {
        // Use test database connection
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $port = $_ENV['DB_PORT'] ?? '5432';
        $dbname = $_ENV['DB_NAME'] ?? 'calendar_bridge';
        $username = $_ENV['DB_USER'] ?? 'bridge_user';
        $password = $_ENV['DB_PASS'] ?? 'bridge_password';

        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        $this->repository = new BridgeQueueRepository($this->pdo);

        // Clean up test data
        $this->pdo->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-tenant'");
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $this->pdo->exec("DELETE FROM bridge_queue WHERE tenant_id = 'test-tenant'");
    }

    public function testEnqueueIfNotExists_NewItem_ShouldEnqueue()
    {
        $payload = [
            'source_calendar_id' => 'cal-123',
            'target_calendar_id' => 'cal-456',
            'event_id' => 'evt-789'
        ];

        $result = $this->repository->enqueueIfNotExists(
            'webhook',
            'outlook',
            'booking_system',
            $payload,
            5,
            'test-tenant'
        );

        $this->assertTrue($result, 'Should enqueue new item');

        // Verify item was queued
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM bridge_queue WHERE tenant_id = 'test-tenant'");
        $stmt->execute();
        $count = $stmt->fetchColumn();

        $this->assertEquals(1, $count, 'One item should be in queue');
    }

    public function testEnqueueIfNotExists_DuplicateItem_ShouldSkip()
    {
        $payload = [
            'source_calendar_id' => 'cal-123',
            'target_calendar_id' => 'cal-456',
            'event_id' => 'evt-789'
        ];

        // First enqueue
        $result1 = $this->repository->enqueueIfNotExists(
            'webhook',
            'outlook',
            'booking_system',
            $payload,
            5,
            'test-tenant'
        );
        $this->assertTrue($result1, 'First enqueue should succeed');

        // Second enqueue (duplicate)
        $result2 = $this->repository->enqueueIfNotExists(
            'webhook',
            'outlook',
            'booking_system',
            $payload,
            5,
            'test-tenant'
        );
        $this->assertFalse($result2, 'Duplicate should be skipped');

        // Verify only one item in queue
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM bridge_queue WHERE tenant_id = 'test-tenant'");
        $stmt->execute();
        $count = $stmt->fetchColumn();

        $this->assertEquals(1, $count, 'Only one item should be in queue');
    }

    public function testEnqueueIfNotExists_DifferentEventId_ShouldEnqueue()
    {
        $payload1 = [
            'source_calendar_id' => 'cal-123',
            'target_calendar_id' => 'cal-456',
            'event_id' => 'evt-789'
        ];

        $payload2 = [
            'source_calendar_id' => 'cal-123',
            'target_calendar_id' => 'cal-456',
            'event_id' => 'evt-999' // Different event
        ];

        // First enqueue
        $result1 = $this->repository->enqueueIfNotExists(
            'webhook',
            'outlook',
            'booking_system',
            $payload1,
            5,
            'test-tenant'
        );
        $this->assertTrue($result1);

        // Second enqueue with different event
        $result2 = $this->repository->enqueueIfNotExists(
            'webhook',
            'outlook',
            'booking_system',
            $payload2,
            5,
            'test-tenant'
        );
        $this->assertTrue($result2, 'Different event should be enqueued');

        // Verify two items in queue
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM bridge_queue WHERE tenant_id = 'test-tenant'");
        $stmt->execute();
        $count = $stmt->fetchColumn();

        $this->assertEquals(2, $count, 'Two different events should be in queue');
    }

    public function testEnqueueIfNotExists_CompletedItemExists_ShouldEnqueue()
    {
        $payload = [
            'source_calendar_id' => 'cal-123',
            'target_calendar_id' => 'cal-456',
            'event_id' => 'evt-789'
        ];

        // First enqueue
        $this->repository->enqueueIfNotExists(
            'webhook',
            'outlook',
            'booking_system',
            $payload,
            5,
            'test-tenant'
        );

        // Mark as completed
        $stmt = $this->pdo->prepare("UPDATE bridge_queue SET status = 'completed' WHERE tenant_id = 'test-tenant'");
        $stmt->execute();

        // Second enqueue (should succeed because first is completed)
        $result = $this->repository->enqueueIfNotExists(
            'webhook',
            'outlook',
            'booking_system',
            $payload,
            5,
            'test-tenant'
        );

        $this->assertTrue($result, 'Should enqueue when existing item is completed');

        // Verify two items in queue (one completed, one pending)
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM bridge_queue WHERE tenant_id = 'test-tenant'");
        $stmt->execute();
        $count = $stmt->fetchColumn();

        $this->assertEquals(2, $count, 'Should have both completed and new pending item');
    }

    public function testFindPendingItems_WithQueueType_ShouldFilterCorrectly()
    {
        // Enqueue webhook item
        $this->repository->enqueue('webhook', 'outlook', 'booking_system', ['test' => 'webhook'], 5, 'test-tenant');
        
        // Enqueue sync item
        $this->repository->enqueue('sync', 'outlook', 'booking_system', ['test' => 'sync'], 5, 'test-tenant');

        // Find webhook items
        $webhookItems = $this->repository->findPendingItems('webhook', 10, 'test-tenant');
        $this->assertCount(1, $webhookItems, 'Should find only webhook items');

        // Find sync items
        $syncItems = $this->repository->findPendingItems('sync', 10, 'test-tenant');
        $this->assertCount(1, $syncItems, 'Should find only sync items');
    }
}
