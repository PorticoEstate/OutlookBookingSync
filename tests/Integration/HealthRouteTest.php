<?php

namespace Tests\Integration;

use App\Services\HealthService;

class HealthRouteTest extends BaseTestCase
{
    public function testGetHealth()
    {
        // Mock HealthService
        $mockHealthService = $this->createMock(HealthService::class);
        $mockHealthService->method('getQuickHealth')->willReturn(true);
        $this->container->set(HealthService::class, $mockHealthService);

        $request = $this->createRequest('GET', '/health');
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertJson((string)$response->getBody());
    }

    public function testGetSystemHealth()
    {
        // Mock HealthService to avoid DB calls
        $mockHealthService = $this->createMock(HealthService::class);
        $mockHealthService->method('getSystemHealth')->willReturn([
            'status' => 'healthy',
            'database' => 'connected',
            'timestamp' => date('c')
        ]);

        // Overwrite the service in the container
        $this->container->set(HealthService::class, $mockHealthService);

        $request = $this->createRequest('GET', '/health/system');
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertEquals('healthy', $body['health']['status']);
    }
}
