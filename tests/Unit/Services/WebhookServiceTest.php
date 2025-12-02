<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\WebhookService;
use App\Services\SyncOrchestrator;
use App\Services\BridgeManager;
use App\Repository\BridgeQueueRepository;
use App\Repository\BridgeResourceRepository;
use App\Repository\BridgeMappingRepository;
use App\Repository\BridgeSubscriptionRepository;
use Psr\Log\LoggerInterface;
use Mockery;

class WebhookServiceTest extends TestCase
{
    private $webhookService;
    private $mockQueueRepo;
    private $mockBridgeManager;
    private $mockResourceRepo;
    private $mockMappingRepo;
    private $mockSubRepo;
    private $mockOrchestrator;
    private $mockLogger;

    protected function setUp(): void
    {
        // Mock all dependencies
        $this->mockLogger = Mockery::mock(LoggerInterface::class);
        $this->mockLogger->shouldIgnoreMissing();

        $this->mockBridgeManager = Mockery::mock(BridgeManager::class);
        $this->mockQueueRepo = Mockery::mock(BridgeQueueRepository::class);
        $this->mockResourceRepo = Mockery::mock(BridgeResourceRepository::class);
        $this->mockMappingRepo = Mockery::mock(BridgeMappingRepository::class);
        $this->mockSubRepo = Mockery::mock(BridgeSubscriptionRepository::class);
        $this->mockOrchestrator = Mockery::mock(SyncOrchestrator::class);

        $this->webhookService = new WebhookService(
            $this->mockLogger,
            $this->mockBridgeManager,
            $this->mockQueueRepo,
            $this->mockResourceRepo,
            $this->mockMappingRepo,
            $this->mockSubRepo,
            $this->mockOrchestrator
        );
    }

    public function testHandleOutlookWebhookQueuesJob()
    {
        // Arrange
        $bridgeName = 'outlook';
        $tenantId = 'tenant-1';
        
        // Outlook webhook payload structure
        $payload = [
            'value' => [
                [
                    'subscriptionId' => 'sub-123',
                    'resourceData' => ['id' => 'evt-abc'],
                    'changeType' => 'created',
                    'resource' => 'Users/user@example.com/Events/evt-abc'
                ]
            ]
        ];

        // Mock Outlook bridge for user resolution
        $mockOutlookBridge = Mockery::mock(\App\Bridge\AbstractCalendarBridge::class);
        $mockOutlookBridge->shouldReceive('resolveUserGuidToEmail')
            ->with('user@example.com')
            ->andReturn('user@example.com');

        $this->mockBridgeManager->shouldReceive('getBridgeForTenant')
            ->with($tenantId, 'outlook')
            ->andReturn($mockOutlookBridge);

        // Expectation: The service should enqueue a job into the database
        $this->mockQueueRepo->shouldReceive('enqueueIfNotExists')
            ->once()
            ->with(
                'webhook',     // queue_type
                'outlook',     // source
                Mockery::any(), // target (determined by logic inside handleWebhook)
                Mockery::on(function ($jobPayload) {
                    return $jobPayload['event_id'] === 'evt-abc' 
                        && $jobPayload['change_type'] === 'created'
                        && $jobPayload['resource_id'] === 'user@example.com';
                }), 
                1,             // priority
                $tenantId      // tenant
            )
            ->andReturn(true);

        // Act
        $result = $this->webhookService->handleWebhook($bridgeName, $payload, [], $tenantId);

        // Assert
        $this->assertTrue($result['success']);
    }

    public function testProcessWebhookQueueBatch()
    {
        // Arrange
        $batchSize = 5;
        $tenantId = 'tenant-1';
        
        $queueItem = [
            'id' => 100,
            'payload' => json_encode([
                'resource_id' => 'res-1',
                'event_id' => 'evt-1',
                'change_type' => 'updated'
            ]),
            'source_bridge' => 'outlook',
            'target_bridge' => 'booking_system',
            'tenant_id' => $tenantId,
            'attempts' => 0
        ];

        // Mock finding pending items
        $this->mockQueueRepo->shouldReceive('findPendingItems')
            ->with('webhook', $batchSize, $tenantId)
            ->andReturn([$queueItem]);

        // Mock marking as processing
        $this->mockQueueRepo->shouldReceive('markProcessing')
            ->with(100)
            ->once();

        // Mock finding resource mapping
        $this->mockResourceRepo->shouldReceive('findMappingForWebhook')
            ->with('outlook', 'booking_system', 'res-1', $tenantId)
            ->andReturn([
                'mapping_id' => 1,
                'bridge_from' => 'outlook',
                'bridge_to' => 'booking_system',
                'source_calendar_id' => 'res-1',
                'target_calendar_id' => 'target-cal-1',
                'tenant_id' => $tenantId
            ]);

        // Mock bridge manager to get source bridge
        $mockSourceBridge = Mockery::mock(\App\Bridge\AbstractCalendarBridge::class);
        $this->mockBridgeManager->shouldReceive('getBridgeForTenant')
            ->with($tenantId, 'outlook')
            ->andReturn($mockSourceBridge);

        // Mock fetching event from source bridge (since payload doesn't have entity_data)
        $mockSourceBridge->shouldReceive('getEvent')
            ->with('res-1', 'evt-1')
            ->andReturn(['id' => 'evt-1', 'subject' => 'Test Event']);

        // Mock sync orchestrator
        $this->mockOrchestrator->shouldReceive('processSingleEventSync')
            ->with(
                'outlook',
                'booking_system',
                'res-1',
                'target-cal-1',
                ['id' => 'evt-1', 'subject' => 'Test Event'],
                Mockery::type('array')
            )
            ->andReturn([
                'success' => true,
                'created' => 1,
                'updated' => 0,
                'target_event_id' => 'target-evt-1'
            ]);

        // Mock updating mapping
        $this->mockMappingRepo->shouldReceive('createOrUpdateWithTargetEventId')
            ->once();

        // Mock updateStatusAndTargetEventId (called when updating existing mapping)
        $this->mockMappingRepo->shouldReceive('updateStatusAndTargetEventId')
            ->andReturn(1);

        // Mock marking as completed
        $this->mockQueueRepo->shouldReceive('markCompleted')
            ->with(100)
            ->once();

        // Mock updateStatus to capture error if any
        $this->mockQueueRepo->shouldReceive('updateStatus')
            ->with(100, 'failed', Mockery::any())
            ->andReturnUsing(function ($id, $status, $error) {
                var_dump($error);
            });

        // Act
        $result = $this->webhookService->processWebhookQueueBatch($batchSize, $tenantId);

        // Assert
        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(0, $result['errors']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
