<?php
namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ResponseInterface as Response;

class ApiKeyMiddleware
{
    public function __invoke(Request $request, Handler $handler): Response
    {
        // Allow unauthenticated access for webhook validation/notifications
        $path = $request->getUri()->getPath();
        if (preg_match('#^/bridges/webhook/#', $path) || preg_match('#^/webhook/outlook-notifications$#', $path)) {
            return $handler->handle($request);
        }

        $apiKey = $request->getHeaderLine('api_key');
        $validKey = $_ENV['API_KEY'] ?? '';

        if ($apiKey !== $validKey) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
