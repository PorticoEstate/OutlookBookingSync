<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\DeletionSyncService;
use App\Services\BridgeManager;
use App\Repository\BridgeQueueRepository;
use App\Repository\BridgeMappingRepository;
use App\Services\SyncLogService;
use Psr\Log\LoggerInterface;
use Mockery;

class DeletionSyncServiceTest extends TestCase
{
    private $deletionService;
    private $mockLogger;
    private $mockBridgeManager;
    private $mockQueueRepo;
    private $mockMappingRepo;
    private $mockSyncLog;

    protected function setUp(): void
    {
        $this->mockLogger = Mockery::mock(LoggerInterface::class);
        $this->mockLogger->shouldIgnoreMissing();

        $this->mockBridgeManager = Mockery::mock(BridgeManager::class);
        $this->mockQueueRepo = Mockery::mock(BridgeQueueRepository::class);
        $this->mockMappingRepo = Mockery::mock(BridgeMappingRepository::class);
        $this->mockSyncLog = Mockery::mock(SyncLogService::class);

        $this->deletionService = new DeletionSyncService(
            $this->mockLogger,
            $this->mockBridgeManager,
            $this->mockQueueRepo,
            $this->mockMappingRepo,
            $this->mockSyncLog
        );
    }

    public function testProcessDeletionChecks()
    {
        // Arrange
        $tenantId = 'tenant-1';
        $checkId = 123;
        $payload = json_encode([
            'calendar_id' => 'cal-1',
            'event_id' => 'evt-1'
        ]);

        $queueItem = [
            'id' => $checkId,
            'payload' => $payload,
            'tenant_id' => $tenantId
        ];

        // Mock finding pending items
        $this->mockQueueRepo->shouldReceive('findPendingItems')
            ->with('deletion_check', 50, $tenantId)
            ->andReturn([$queueItem]);

        // Mock marking as processing
        $this->mockQueueRepo->shouldReceive('markProcessing')
            ->with($checkId)
            ->once();

        // Mock marking as completed
        $this->mockQueueRepo->shouldReceive('markCompleted')
            ->with($checkId)
            ->once();

        // Mock bridge manager and bridge
        $mockBridge = Mockery::mock(\App\Bridge\AbstractCalendarBridge::class);
        $this->mockBridgeManager->shouldReceive('getBridgeForTenant')
            ->with($tenantId, 'outlook')
            ->andReturn($mockBridge);

        // Mock getEvent to return event (so it's not deleted)
        $mockBridge->shouldReceive('getEvent')
            ->with('cal-1', 'evt-1')
            ->andReturn(['id' => 'evt-1']);

        // Act
        $result = $this->deletionService->processDeletionChecks($tenantId);
        
        // Assert
        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(0, $result['deletions_found']); // Because we mocked getEvent to return an event
    }
    
    protected function tearDown(): void
    {
        Mockery::close();
    }
}
