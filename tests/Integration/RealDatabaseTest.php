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
}
