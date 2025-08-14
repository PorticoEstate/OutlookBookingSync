<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * ApiKeyMiddleware authenticates requests using per-tenant or global API keys.
 */
class ApiKeyMiddleware
{
	/**
	 * @param Request $request
	 * @param Handler $handler
	 * @return Response
	 */
	public function __invoke(Request $request, Handler $handler): Response
	{
		// Allow unauthenticated access for webhook validation/notifications
		$path = $request->getUri()->getPath();
		if (preg_match('#^/bridges/webhook/#', $path) || preg_match('#^/webhook/outlook-notifications$#', $path))
		{
			return $handler->handle($request);
		}

		$apiKey = $request->getHeaderLine('api_key');
		$tenantId = $request->getAttribute('tenant_id');

		// 1) Per-tenant API key map via env var (JSON: {"tenantA":"key1"})
		$tenantKeyValid = false;
		$mapJson = $_ENV['TENANT_API_KEYS_JSON'] ?? '';
		if ($tenantId && $mapJson) {
			$map = json_decode($mapJson, true);
			if (is_array($map) && isset($map[$tenantId])) {
				$tenantKeyValid = hash_equals((string)$map[$tenantId], (string)$apiKey);
			}
		}

		// 2) Fallback to global API key for backward compatibility
		$globalValid = false;
		$validKey = $_ENV['API_KEY'] ?? '';
		if ($validKey !== '') {
			$globalValid = hash_equals((string)$validKey, (string)$apiKey);
		}

		if (!($tenantKeyValid || $globalValid))
		{
			$response = new \Slim\Psr7\Response();
			$response->getBody()->write(json_encode(['error' => 'Unauthorized']));
			return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
		}

		return $handler->handle($request);
	}
}
