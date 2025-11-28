<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Uri;
use DI\Container;

class BaseTestCase extends TestCase
{
    /** @var App */
    protected $app;

    /** @var Container */
    protected $container;

    /** @var bool */
    protected $mockDatabase = true;

    protected function setUp(): void
    {
        // Set environment variables for testing
        $_ENV['API_KEY'] = 'test-api-key';
        $_ENV['APP_ENV'] = 'testing';

        // Create a fresh app for each test
        $this->app = require __DIR__ . '/../../bootstrap.php';
        $this->container = $this->app->getContainer();

        // Mock the logger to avoid permission issues and disk I/O
        $mockLogger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->container->set('logger', $mockLogger);

        if ($this->mockDatabase) {
            // Mock the database connection to prevent real connection attempts
            // This avoids "Database connection failed" errors in test output
            $mockPdo = $this->createMock(\PDO::class);
            $this->container->set('db', $mockPdo);
        }
    }

    protected function createRequest(
        string $method,
        string $path,
        array $headers = ['HTTP_ACCEPT' => 'application/json'],
        array $cookies = [],
        array $serverParams = []
    ): ServerRequestInterface {
        $uri = new Uri('', '', 80, $path);
        $handle = fopen('php://temp', 'w+');
        $stream = (new StreamFactory())->createStreamFromResource($handle);

        $h = new Headers();
        // Add default auth headers
        $h->addHeader('X-API-Key', 'test-api-key');
        $h->addHeader('X-Tenant-Id', 'default');
        
        foreach ($headers as $name => $value) {
            $h->addHeader($name, $value);
        }

        return new Request($method, $uri, $h, $cookies, $serverParams, $stream);
    }
}
