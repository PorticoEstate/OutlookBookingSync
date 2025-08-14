<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ResponseInterface as Response;

class TenantResolverMiddleware
{
    public function __invoke(Request $request, Handler $handler): Response
    {
        $route = $request->getAttribute('route');
        $tenantFromRoute = null;
        if ($route && method_exists($route, 'getArgument')) {
            try { $tenantFromRoute = $route->getArgument('tenantId'); } catch (\Throwable $e) { $tenantFromRoute = null; }
        }

        $header = $request->getHeaderLine('X-Tenant-Id') ?: $request->getHeaderLine('x-tenant-id');
        $tenantId = $tenantFromRoute ?: ($header ?: null);

        if (!$tenantId) {
            $tenantId = $_ENV['DEFAULT_TENANT_ID'] ?? 'default';
        }

        return $handler->handle($request->withAttribute('tenant_id', $tenantId));
    }
}
