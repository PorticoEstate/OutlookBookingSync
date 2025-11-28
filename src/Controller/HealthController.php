<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\HealthService;
use Exception;

/**
 * HealthController exposes endpoints and helpers for system health, metrics, and monitoring.
 */
class HealthController
{
	private $healthService;
	private $logger;

	/**
	 * @param HealthService $healthService Service for health checks
	 * @param mixed|null $logger PSR-3 logger (optional)
	 */
	public function __construct(HealthService $healthService, $logger = null)
	{
		$this->healthService = $healthService;
		$this->logger = $logger;
	}

	/**
	 * Comprehensive system health check.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args
	 * @return Response
	 */
	public function getSystemHealth(Request $request, Response $response, $args)
	{
		try {
			$tenantId = $request->getAttribute('tenant_id');
			$health = $this->healthService->getSystemHealth($tenantId);

			$response->getBody()->write(json_encode([
				'success' => true,
				'health' => $health
			], JSON_PRETTY_PRINT));

			return $response->withHeader('Content-Type', 'application/json');

		} catch (Exception $e) {
			$response->getBody()->write(json_encode([
				'success' => false,
				'error' => 'Health check failed: ' . $e->getMessage(),
				'status' => 'critical',
				'timestamp' => date('Y-m-d H:i:s')
			]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(503);
		}
	}

	/**
	 * Quick health check for load balancers.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args
	 * @return Response
	 */
	public function getQuickHealth(Request $request, Response $response, $args)
	{
		try {
			$dbHealthy = $this->healthService->getQuickHealth();

			if ($dbHealthy) {
				$response->getBody()->write(json_encode([
					'status' => 'healthy',
					'timestamp' => date('Y-m-d H:i:s')
				]));
				return $response->withHeader('Content-Type', 'application/json');
			} else {
				throw new Exception('Database connectivity failed');
			}

		} catch (Exception $e) {
			$response->getBody()->write(json_encode([
				'status' => 'unhealthy',
				'error' => $e->getMessage(),
				'timestamp' => date('Y-m-d H:i:s')
			]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(503);
		}
	}

	/**
	 * Get system monitoring dashboard data.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args
	 * @return Response
	 */
	public function getDashboardData(Request $request, Response $response, $args)
	{
		try {
			$tenantId = (string)($request->getAttribute('tenant_id') ?? '');
			$dashboard = $this->healthService->getDashboardData($tenantId !== '' ? $tenantId : null);

			$response->getBody()->write(json_encode([
				'success' => true,
				'dashboard' => $dashboard
			], JSON_PRETTY_PRINT));

			return $response->withHeader('Content-Type', 'application/json');

		} catch (Exception $e) {
			$response->getBody()->write(json_encode([
				'success' => false,
				'error' => 'Dashboard data retrieval failed: ' . $e->getMessage()
			]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * Get sync status statistics.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args
	 * @return Response
	 */
	public function getSyncStatus(Request $request, Response $response, $args)
	{
		try {
			$tenantId = (string)($request->getAttribute('tenant_id') ?? '');
			$status = $this->healthService->getSyncStatus($tenantId !== '' ? $tenantId : null);

			$response->getBody()->write(json_encode([
				'success' => true,
				'sync_status' => $status['sync_status']
			], JSON_PRETTY_PRINT));

			return $response->withHeader('Content-Type', 'application/json');

		} catch (Exception $e) {
			$response->getBody()->write(json_encode([
				'success' => false,
				'error' => 'Sync status retrieval failed: ' . $e->getMessage()
			]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * Get queue statistics.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args
	 * @return Response
	 */
	public function getQueueStats(Request $request, Response $response, $args)
	{
		try {
			$tenantId = (string)($request->getAttribute('tenant_id') ?? '');
			$stats = $this->healthService->getQueueStats($tenantId !== '' ? $tenantId : null);

			$response->getBody()->write(json_encode([
				'success' => true,
				'data' => $stats['data']
			], JSON_PRETTY_PRINT));

			return $response->withHeader('Content-Type', 'application/json');

		} catch (Exception $e) {
			$response->getBody()->write(json_encode([
				'success' => false,
				'error' => 'Queue stats retrieval failed: ' . $e->getMessage()
			]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}
}
