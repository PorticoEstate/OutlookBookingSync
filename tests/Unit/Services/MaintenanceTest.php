<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\SyncLogService;
use App\Services\WebhookService;
use App\Services\BridgeManager;
use App\Repository\BridgeSubscriptionRepository;
use Psr\Log\LoggerInterface;
use Mockery;
use PDO;
use PDOStatement;

class MaintenanceTest extends TestCase
{
    private $mockLogger;
    private $mockBridgeManager;
    private $mockSubRepo;
    private $mockPdo;
    private $mockPdoStmt;

    protected function setUp(): void
    {
        $this->mockLogger = Mockery::mock(LoggerInterface::class);
        $this->mockLogger->shouldIgnoreMissing();

        $this->mockBridgeManager = Mockery::mock(BridgeManager::class);
        $this->mockSubRepo = Mockery::mock(BridgeSubscriptionRepository::class);
        $this->mockPdo = Mockery::mock(PDO::class);
        $this->mockPdoStmt = Mockery::mock(PDOStatement::class);
    }

    public function testCleanupOldLogs()
    {
        // Arrange
        $days = 30;
        $deletedCount = 100;

        $this->mockPdo->shouldReceive('prepare')
            ->with("SELECT cleanup_old_bridge_logs(:days) AS deleted_count")
            ->once()
            ->andReturn($this->mockPdoStmt);

        $this->mockPdoStmt->shouldReceive('execute')
            ->with([':days' => $days])
            ->once();

        $this->mockPdoStmt->shouldReceive('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->once()
            ->andReturn(['deleted_count' => $deletedCount]);

        $syncLogService = new SyncLogService($this->mockPdo);

        // Act
        $result = $syncLogService->cleanupOldLogs($days);

        // Assert
        $this->assertEquals($deletedCount, $result);
    }

    public function testRenewSubscriptions()
    {
        // Arrange
        // We need to mock WebhookService dependencies partially, but since we are testing WebhookService::renewSubscriptions,
        // we should instantiate WebhookService with mocks.
        // However, WebhookService has many dependencies.
        
        $mockQueueRepo = Mockery::mock(\App\Repository\BridgeQueueRepository::class);
        $mockResourceRepo = Mockery::mock(\App\Repository\BridgeResourceRepository::class);
        $mockMappingRepo = Mockery::mock(\App\Repository\BridgeMappingRepository::class);
        $mockOrchestrator = Mockery::mock(\App\Services\SyncOrchestrator::class);

        $webhookService = new WebhookService(
            $this->mockLogger,
            $this->mockBridgeManager,
            $mockQueueRepo,
            $mockResourceRepo,
            $mockMappingRepo,
            $this->mockSubRepo,
            $mockOrchestrator
        );

        $expiringSub = [
            'id' => 1,
            'subscription_id' => 'sub-123',
            'bridge_type' => 'outlook',
            'tenant_id' => 'tenant-1',
            'resource_url' => 'me/events',
            'expiration_datetime' => '2026-01-01T00:00:00Z',
            'expires_at' => '2026-01-01T00:00:00Z'
        ];

        $this->mockSubRepo->shouldReceive('findExpiring')
            ->with(1440, 50, null, null, null)
            ->andReturn([$expiringSub]);

        $mockBridge = Mockery::mock(\App\Bridge\AbstractCalendarBridge::class);
        $this->mockBridgeManager->shouldReceive('getBridgeForTenant')
            ->with('tenant-1', 'outlook')
            ->andReturn($mockBridge);

        // Expect renewal call on bridge
        $mockBridge->shouldReceive('renewSubscription')
            ->with('sub-123')
            ->andReturn([
                'success' => true,
                'subscription_id' => 'sub-123',
                'expirationDateTime' => '2025-01-02T00:00:00Z'
            ]);

        // Expect update in repo
        $this->mockSubRepo->shouldReceive('updateExpiration')
            ->with('sub-123', '2025-01-02T00:00:00Z')
            ->once();

        // Act
        $result = $webhookService->renewSubscriptions();

        // Assert
        $this->assertCount(1, $result['renewed']);
        $this->assertEquals('sub-123', $result['renewed'][0]['subscription_id']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
