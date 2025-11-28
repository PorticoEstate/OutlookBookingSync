<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\SyncOrchestrator;
use App\Services\BridgeManager;
use App\Repository\BridgeMappingRepository;
use App\Services\SyncLogService;
use Psr\Log\LoggerInterface;
use Mockery;

class SyncOrchestratorTest extends TestCase
{
    public function testSyncOrchestrationLogic()
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

        // Mock getting events
        $mockSourceBridge->shouldReceive('getEvents')->andReturn([
            ['id' => 'evt-1', 'subject' => 'Test Event', 'start' => '2025-01-01', 'end' => '2025-01-02']
        ]);
        
        // Mock mapping repository calls
        $mockMappingRepo->shouldReceive('findMappings')->andReturn([]);
        
        // Mock target bridge calls
        // Since there are no mappings, it should try to create the event
        $mockTargetBridge->shouldReceive('createEvent')->once()->andReturn('target-evt-1');
        
        // Mock mapping updates after creation
        $mockMappingRepo->shouldReceive('updateSourceTiming');
        $mockMappingRepo->shouldReceive('updateEventData');
        $mockMappingRepo->shouldReceive('updateSyncMethod');

        // Mock sync log write
        $mockSyncLog->shouldReceive('write')->once();

        $orchestrator = new SyncOrchestrator(
            $mockBridgeManager,
            $mockMappingRepo,
            $mockSyncLog,
            $mockLogger
        );

        // Act
        $result = $orchestrator->syncBetweenBridges(
            'booking_system',
            'outlook',
            'source-cal-1',
            'target-cal-1',
            '2025-01-01',
            '2025-01-30',
            ['tenant_id' => 'tenant-1']
        );
        
        // Assert
        $this->assertArrayHasKey('created', $result);
        $this->assertEquals(1, $result['created']);
    }
    
    protected function tearDown(): void
    {
        Mockery::close();
    }
}
