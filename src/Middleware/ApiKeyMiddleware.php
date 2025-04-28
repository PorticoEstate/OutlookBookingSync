<?php
namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ResponseInterface as Response;

class ApiKeyMiddleware
{
    public function __invoke(Request $request, Handler $handler): Response
    {
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
