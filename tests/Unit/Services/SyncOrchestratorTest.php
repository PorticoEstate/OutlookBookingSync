<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\SyncOrchestrator;
use App\Services\BridgeManager;
use App\Repository\BridgeMappingRepository;
use App\Repository\BridgeResourceRepository;
use App\Repository\BridgeQueueRepository;
use App\Services\SyncLogService;
use Psr\Log\LoggerInterface;
use Mockery;

class SyncOrchestratorTest extends TestCase
{
    public function testProcessSingleEventSync()
    {
        // Arrange
        $mockBridgeManager = Mockery::mock(BridgeManager::class);
        $mockMappingRepo = Mockery::mock(BridgeMappingRepository::class);
        $mockSyncLog = Mockery::mock(SyncLogService::class);
        $mockLogger = Mockery::mock(LoggerInterface::class);
        $mockLogger->shouldIgnoreMissing();

        // Mock the source and target bridges
        $mockSourceBridge = Mockery::mock(\App\Bridge\AbstractCalendarBridge::class);
        $mockTargetBridge = Mockery::mock(\App\Bridge\AbstractCalendarBridge::class);
        
        // Mock getBridgeType
        $mockSourceBridge->shouldReceive('getBridgeType')->andReturn('booking_system');
        $mockTargetBridge->shouldReceive('getBridgeType')->andReturn('outlook');

        $mockBridgeManager->shouldReceive('getBridgeForTenant')
            ->with('tenant-1', 'booking_system')
            ->andReturn($mockSourceBridge);

        $mockBridgeManager->shouldReceive('getBridgeForTenant')
            ->with('tenant-1', 'outlook')
            ->andReturn($mockTargetBridge);

        // Mock finding no existing mapping (new event)
        $mockMappingRepo->shouldReceive('findMappingBySourceEventId')
            ->with('booking_system', 'outlook', 'source-cal-1', 'target-cal-1', 'evt-1', 'tenant-1')
            ->andReturn(null);

        // Mock mapping lookup for post-creation update
        $mockMappingRepo->shouldReceive('findMappings')->andReturn([
            [
                'id' => 1,
                'source_event_id' => 'evt-1',
                'target_event_id' => 'target-evt-1',
                'normalized_reversed' => false
            ]
        ]);
        
        // Mock target bridge creates the event
        $mockTargetBridge->shouldReceive('createEvent')->once()->andReturn('target-evt-1');
        
        // Mock mapping updates after creation
        $mockMappingRepo->shouldReceive('updateSourceTiming');
        $mockMappingRepo->shouldReceive('updateEventData');
        $mockMappingRepo->shouldReceive('updateSyncMethod');

        // Mock resource repository
        $mockResourceRepo = \Mockery::mock(BridgeResourceRepository::class);
        
        // Mock queue repository
        $mockQueueRepo = \Mockery::mock(BridgeQueueRepository::class);

        $orchestrator = new SyncOrchestrator(
            $mockBridgeManager,
            $mockMappingRepo,
            $mockResourceRepo,
            $mockQueueRepo,
            $mockSyncLog,
            $mockLogger
        );

        // Source event data
        $sourceEvent = [
            'id' => 'evt-1',
            'subject' => 'Test Event',
            'start' => '2025-01-01',
            'end' => '2025-01-02'
        ];

        // Act - use processSingleEventSync (queue-based flow)
        $result = $orchestrator->processSingleEventSync(
            'booking_system',
            'outlook',
            'source-cal-1',
            'target-cal-1',
            $sourceEvent,
            ['tenant_id' => 'tenant-1']
        );
        
        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals('created', $result['action']);
        $this->assertEquals('evt-1', $result['source_event_id']);
        $this->assertEquals('target-evt-1', $result['target_event_id']);
    }
    
    protected function tearDown(): void
    {
        Mockery::close();
    }
}
