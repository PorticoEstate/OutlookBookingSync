<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * CsrfMiddleware enforces a session-based CSRF token for unsafe methods
 * under /admin endpoints. Token is expected in header 'X-CSRF-Token'.
 */
class CsrfMiddleware
{
    /**
     * @param Request $request
     * @param Handler $handler
     * @return Response
     */
    public function __invoke(Request $request, Handler $handler): Response
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        // Only enforce for /admin and unsafe methods; allow GET /admin/csrf without token
        $isAdminPath = str_starts_with($path, '/admin');
        $isUnsafe = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        if ($isAdminPath && $isUnsafe) {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            $expected = $_SESSION['csrf_token'] ?? '';
            $provided = $request->getHeaderLine('X-CSRF-Token');
            if (!($expected && is_string($provided) && hash_equals($expected, $provided))) {
                $response = new \Slim\Psr7\Response();
                $response->getBody()->write(json_encode(['error' => 'CSRF validation failed']));
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
            }
        }

        return $handler->handle($request);
    }
}
