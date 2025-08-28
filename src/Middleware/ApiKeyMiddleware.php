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
		// If routing info is available and the matched route is the catch-all 404, bypass auth
		try {
			$routeContext = \Slim\Routing\RouteContext::fromRequest($request);
			$route = $routeContext->getRoute();
			if ($route && $route->getName() === 'catch_all_404') {
				return $handler->handle($request);
			}
		} catch (\RuntimeException $e) {
			// Routing has not been completed; continue with path-based checks below
		}

		// Allow unauthenticated access for webhook validation/notifications
		$path = $request->getUri()->getPath();
		if (preg_match('#^/bridges/webhook/#', $path) || preg_match('#^/webhook/outlook-notifications$#', $path))
		{
			return $handler->handle($request);
		}

		$apiKey = $request->getHeaderLine('api_key');
		$tenantId = $request->getAttribute('tenant_id');
		/** @var \PDO|null $db */
		$db = $request->getAttribute('db');

		// 1) Per-tenant API key map via env var (JSON: {"tenantA":"key1"})
		$tenantKeyValid = false;
		$mapJson = $_ENV['TENANT_API_KEYS_JSON'] ?? '';
		if ($tenantId && $mapJson) {
			$map = json_decode($mapJson, true);
			if (is_array($map) && isset($map[$tenantId])) {
				$tenantKeyValid = hash_equals((string)$map[$tenantId], (string)$apiKey);
			}
		}

		// 1b) Check DB-stored hashed API key if available (tenant_api_keys)
		if (!$tenantKeyValid && $tenantId && $db instanceof \PDO && $apiKey !== '') {
			try {
				$stmt = $db->prepare('SELECT api_key_hash FROM tenant_api_keys WHERE tenant_id = :id');
				$stmt->execute([':id' => $tenantId]);
				$row = $stmt->fetch(\PDO::FETCH_ASSOC);
				if ($row && isset($row['api_key_hash'])) {
					$tenantKeyValid = password_verify((string)$apiKey, (string)$row['api_key_hash']);
				}
			} catch (\Throwable $e) {
				// On DB error, do not disclose details; fall back to other methods
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
