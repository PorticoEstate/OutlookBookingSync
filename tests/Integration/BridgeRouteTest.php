<?php

namespace Tests\Integration;

use App\Services\BridgeManager;
use App\Services\SyncOrchestrator;
use App\Services\WebhookService;
use App\Services\SyncLogService;
use App\Repository\BridgeResourceRepository;
use App\Repository\BridgeMappingRepository;
use App\Repository\BridgeQueueRepository;
use App\Repository\BridgeSubscriptionRepository;

class BridgeRouteTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock all dependencies of BridgeController
        $this->mockDependencies();
    }

    private function mockDependencies()
    {
        // Mock Logger to avoid file permission issues
        $mockLogger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->container->set('logger', $mockLogger);

        $mockBridgeManager = $this->createMock(BridgeManager::class);
        $this->container->set('bridgeManager', $mockBridgeManager);

        $mockResourceRepo = $this->createMock(BridgeResourceRepository::class);
        $this->container->set(BridgeResourceRepository::class, $mockResourceRepo);

        $mockMappingRepo = $this->createMock(BridgeMappingRepository::class);
        $this->container->set(BridgeMappingRepository::class, $mockMappingRepo);

        $mockQueueRepo = $this->createMock(BridgeQueueRepository::class);
        $this->container->set(BridgeQueueRepository::class, $mockQueueRepo);

        $mockSubscriptionRepo = $this->createMock(BridgeSubscriptionRepository::class);
        $this->container->set(BridgeSubscriptionRepository::class, $mockSubscriptionRepo);

        $mockSyncOrchestrator = $this->createMock(SyncOrchestrator::class);
        $this->container->set(SyncOrchestrator::class, $mockSyncOrchestrator);

        $mockWebhookService = $this->createMock(WebhookService::class);
        $this->container->set(WebhookService::class, $mockWebhookService);

        $mockSyncLog = $this->createMock(SyncLogService::class);
        $this->container->set('syncLog', $mockSyncLog);
    }

    public function testListBridges()
    {
        $mockBridgeManager = $this->container->get('bridgeManager');
        $mockBridgeManager->method('getAllBridgesInfo')->willReturn([
            'outlook' => ['class' => 'OutlookBridge'],
            'booking_system' => ['class' => 'BookingSystemBridge']
        ]);

        $request = $this->createRequest('GET', '/bridges');
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        $this->assertArrayHasKey('bridges', $body);
        $this->assertCount(2, $body['bridges']);
        $this->assertEquals('OutlookBridge', $body['bridges']['outlook']['class']);
    }

    public function testSyncBridges()
    {
        // Mock active mappings
        $mockResourceRepo = $this->container->get(BridgeResourceRepository::class);
        $mockResourceRepo->method('findActiveMappings')->willReturn([
            [
                'id' => 1,
                'tenant_id' => 'default',
                'bridge_from' => 'outlook',
                'bridge_to' => 'booking_system',
                'source_calendar_id' => 'cal1',
                'target_calendar_id' => 'res1'
            ]
        ]);

        // Mock BridgeManager to return a mock bridge
        $mockBridge = $this->createMock(\App\Bridge\AbstractCalendarBridge::class);
        $mockBridge->method('getEvents')->willReturn([
            ['id' => 'evt1', 'title' => 'Test Event']
        ]);

        $mockBridgeManager = $this->container->get('bridgeManager');
        $mockBridgeManager->method('getBridgeForTenant')->willReturn($mockBridge);

        $request = $this->createRequest('POST', '/bridges/sync/outlook/booking_system');
        // Add body params for dry run
        $request->getBody()->write(json_encode(['dry_run' => true]));
        $request->getBody()->rewind();
        $request = $request->withHeader('Content-Type', 'application/json');

        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertEquals(1, $body['mappings_processed']);
        $this->assertNotEmpty($body['sync_results']);
        $this->assertEquals(1, $body['summary']['total_source_events']);
    }

    public function testHandleWebhook()
    {
        $mockWebhookService = $this->container->get(WebhookService::class);
        $mockWebhookService->expects($this->once())
            ->method('handleWebhook')
            ->with('booking_system', $this->anything())
            ->willReturn(['status' => 'processed']);

        $request = $this->createRequest('POST', '/bridges/webhook/booking_system');
        $request->getBody()->write(json_encode(['event' => 'test']));
        $request->getBody()->rewind();
        $request = $request->withHeader('Content-Type', 'application/json');

        $response = $this->app->handle($request);

        $this->assertEquals(202, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        $this->assertTrue($body['success']);
    }
}
