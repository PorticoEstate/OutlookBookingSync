<?php

namespace Tests\Integration;

use App\Services\BridgeManager;
use App\Repository\BridgeSubscriptionRepository;

class SubscriptionDeletionTest extends BaseTestCase
{
    protected $mockDatabase = false; // Use real DB

    public function testDeleteSubscriptionWithBridgeUnsubscribe()
    {
        // 1. Insert a test subscription into the real DB
        $db = $this->container->get('db');
        $subscriptionId = 'sub_' . uniqid();
        $tenantId = 'test_tenant';
        $bridgeType = 'outlook';
        
        $stmt = $db->prepare("INSERT INTO bridge_subscriptions (subscription_id, bridge_type, tenant_id, calendar_id, webhook_url, is_active, created_at) VALUES (?, ?, ?, 'cal1', 'http://example.com', true, NOW())");
        $stmt->execute([$subscriptionId, $bridgeType, $tenantId]);

        // 2. Mock BridgeManager and Bridge
        $mockBridge = $this->createMock(\App\Bridge\AbstractCalendarBridge::class);
        $mockBridge->method('unsubscribeFromChanges')->willReturnCallback(function($subId) use ($db, $subscriptionId) {
            // Simulate the bridge deleting the subscription from DB
            $stmt = $db->prepare("DELETE FROM bridge_subscriptions WHERE subscription_id = ?");
            $stmt->execute([$subscriptionId]);
            return true;
        });

        $mockBridgeManager = $this->createMock(BridgeManager::class);
        $mockBridgeManager->method('getBridgeForTenant')->willReturn($mockBridge);
        
        // Replace BridgeManager in container
        $this->container->set('bridgeManager', $mockBridgeManager);
        // Re-create controller with new dependency? 
        // The app uses the container to resolve the controller, so setting it in container should work if done before handling.
        // However, Slim might have already resolved it if not careful. 
        // BaseTestCase::createRequest creates a new app instance usually? 
        // Let's check BaseTestCase.
        
        // 3. Call the delete endpoint
        $request = $this->createRequest('DELETE', "/bridges/subscriptions/{$subscriptionId}");
        $response = $this->app->handle($request);

        // 4. Assert success
        $body = json_decode((string)$response->getBody(), true);
        
        if ($response->getStatusCode() !== 200) {
            var_dump($body);
        }

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertEquals('Subscription deleted successfully', $body['message']);

        // Verify it's gone from DB
        $repo = $this->container->get(BridgeSubscriptionRepository::class);
        $sub = $repo->findById($subscriptionId);
        $this->assertNull($sub);
    }
}
