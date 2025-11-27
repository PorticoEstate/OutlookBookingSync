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
}
