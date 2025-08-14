<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * AdminRoleMiddleware ensures admin-only access to /admin endpoints.
 * - Requires valid global API key (header: api_key) for admin routes.
 * - Optional IP allowlist via env ADMIN_IP_ALLOWLIST (comma-separated CIDRs).
 */
class AdminRoleMiddleware
{
    public function __invoke(Request $request, Handler $handler): Response
    {
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, '/admin')) {
            return $handler->handle($request);
        }

        $apiKey = $request->getHeaderLine('api_key');
        $globalKey = $_ENV['API_KEY'] ?? '';
        $hasGlobal = ($globalKey !== '') && hash_equals((string)$globalKey, (string)$apiKey);

        // Optional IP allowlist
        $allowlist = trim((string)($_ENV['ADMIN_IP_ALLOWLIST'] ?? ''));
        if ($allowlist !== '') {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ok = false;
            foreach (array_filter(array_map('trim', explode(',', $allowlist))) as $cidr) {
                if (self::ipInCidr($ip, $cidr)) { $ok = true; break; }
            }
            if (!$ok) { $hasGlobal = false; }
        }

        if (!$hasGlobal) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode(['error' => 'Admin access required']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if ($ip === '' || $cidr === '') return false;
        if (strpos($cidr, '/') === false) { // exact IP match
            return $ip === $cidr;
        }
        [$subnet, $mask] = explode('/', $cidr, 2);
        $mask = (int)$mask;
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) return false;
        $maskBinary = -1 << (32 - $mask);
        return ($ipLong & $maskBinary) === ($subnetLong & $maskBinary);
    }
}
