<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\SyncLogService;
use App\Services\WebhookService;
use App\Repository\BridgeQueueRepository;
use Exception;

/**
 * Maintenance controller for operational endpoints like log cleanup and subscription renewals.
 */
class MaintenanceController
{
	private SyncLogService $syncLogService;
	private WebhookService $webhookService;
	private BridgeQueueRepository $queueRepository;
	private $logger;

	public function __construct(
		SyncLogService $syncLogService, 
		WebhookService $webhookService, 
		BridgeQueueRepository $queueRepository,
		$logger = null
	)
	{
		$this->syncLogService = $syncLogService;
		$this->webhookService = $webhookService;
		$this->queueRepository = $queueRepository;
		$this->logger = $logger;
	}

	/**
	 * Cleanup old bridge sync logs using DB function cleanup_old_bridge_logs(days)
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args
	 * @return Response
	 */
	public function cleanupLogs(Request $request, Response $response, $args)
	{
		try
		{
			$queryParams = $request->getQueryParams();
			$days = isset($queryParams['days']) ? max(1, (int)$queryParams['days']) : 30;

			$deletedCount = $this->syncLogService->cleanupOldLogs($days);

			$payload = [
				'success' => true,
				'days_kept' => $days,
				'deleted' => $deletedCount,
				'timestamp' => date('c')
			];

			if ($this->logger)
			{
				$this->logger->info('cleanup_old_bridge_logs executed', $payload);
			}

			$response->getBody()->write(json_encode($payload));
			return $response->withHeader('Content-Type', 'application/json');
		}
		catch (Exception $e)
		{
			if ($this->logger)
			{
				$this->logger->error('Failed to cleanup logs', ['error' => $e->getMessage()]);
			}
			$response->getBody()->write(json_encode([
				'success' => false,
				'error' => 'Cleanup failed: ' . $e->getMessage()
			]));
			return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
		}
	}

	/**
	 * Renew expiring webhook subscriptions
	 * Query params:
	 *  - bridge (optional, default 'outlook')
	 *  - renew_before_minutes (optional, default 1440 = 24h)
	 *  - limit (optional, default 50)
	*
	* @param Request $request
	* @param Response $response
	* @param array $args
	* @return Response
	 */
	public function renewSubscriptions(Request $request, Response $response, $args)
	{
		try
		{
			$query = $request->getQueryParams();
			$bridge = $query['bridge'] ?? '';
			$minutes = isset($query['renew_before_minutes']) ? max(5, (int)$query['renew_before_minutes']) : 1440;
			$subscriptionId = (string)($query['subscription_id'] ?? '');
			$limit = isset($query['limit']) ? max(1, (int)$query['limit']) : 50;
			$tenantId = (string)($request->getAttribute('tenant_id') ?? '');

			$payload = $this->webhookService->renewSubscriptions($bridge, $minutes, $limit, $tenantId, $subscriptionId);
			$payload['success'] = true;
			$payload['timestamp'] = date('c');

			if ($this->logger)
			{
				$this->logger->info('renew_subscriptions executed', [
					'bridge' => $bridge,
					'checked' => $payload['checked'],
					'renewed_count' => count($payload['renewed']),
					'recreated_count' => count($payload['recreated']),
					'failed_count' => count($payload['failed']),
				]);
			}

			$response->getBody()->write(json_encode($payload));
			return $response->withHeader('Content-Type', 'application/json');
		}
		catch (Exception $e)
		{
			if ($this->logger)
			{
				$this->logger->error('Failed to renew subscriptions', ['error' => $e->getMessage()]);
			}
			$response->getBody()->write(json_encode([
				'success' => false,
				'error' => 'Renewal failed: ' . $e->getMessage()
			]));
			return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
		}
	}

	/**
	 * Cleanup old completed or failed queue items
	 * Query params:
	 *  - days (optional, default 30): items older than this will be deleted
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args
	 * @return Response
	 */
	public function cleanupOldQueueItems(Request $request, Response $response, $args)
	{
		try
		{
			$queryParams = $request->getQueryParams();
			$days = isset($queryParams['days']) ? max(1, (int)$queryParams['days']) : 30;
			$tenantId = $request->getAttribute('tenant_id');

			$deletedCount = $this->queueRepository->cleanupOldItems($days, $tenantId);

			$payload = [
				'success' => true,
				'days_threshold' => $days,
				'deleted' => $deletedCount,
				'tenant_id' => $tenantId,
				'timestamp' => date('c')
			];

			if ($this->logger)
			{
				$this->logger->info('cleanup_old_queue_items executed', $payload);
			}

			$response->getBody()->write(json_encode($payload));
			return $response->withHeader('Content-Type', 'application/json');
		}
		catch (Exception $e)
		{
			if ($this->logger)
			{
				$this->logger->error('Failed to cleanup old queue items', ['error' => $e->getMessage()]);
			}
			$response->getBody()->write(json_encode([
				'success' => false,
				'error' => 'Queue cleanup failed: ' . $e->getMessage()
			]));
			return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
		}
	}

	/**
	 * Reset stuck queue items that have been in 'processing' status too long.
	 * Query params:
	 *  - minutes (optional, default 10): items processing longer than this are reset
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args
	 * @return Response
	 */
	public function resetStuckQueue(Request $request, Response $response, $args)
	{
		try
		{
			$queryParams = $request->getQueryParams();
			$minutes = isset($queryParams['minutes']) ? max(1, (int)$queryParams['minutes']) : 10;
			$tenantId = $request->getAttribute('tenant_id');

			$result = $this->queueRepository->resetStuckProcessing($minutes, $tenantId);

			$payload = [
				'success' => true,
				'threshold_minutes' => $minutes,
				'reset_to_pending' => $result['reset_to_pending'],
				'marked_as_failed' => $result['marked_as_failed'],
				'total_reset' => $result['total_reset'],
				'tenant_id' => $tenantId,
				'timestamp' => date('c')
			];

			if ($this->logger)
			{
				$this->logger->info('reset_stuck_queue executed', $payload);
			}

			$response->getBody()->write(json_encode($payload));
			return $response->withHeader('Content-Type', 'application/json');
		}
		catch (Exception $e)
		{
			if ($this->logger)
			{
				$this->logger->error('Failed to reset stuck queue items', ['error' => $e->getMessage()]);
			}
			$response->getBody()->write(json_encode([
				'success' => false,
				'error' => 'Reset stuck queue failed: ' . $e->getMessage()
			]));
			return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
		}
	}
}
