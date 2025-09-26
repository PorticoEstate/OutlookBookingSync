<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use Exception;

/**
 * Maintenance controller for operational endpoints like log cleanup and subscription renewals.
 */
class MaintenanceController
{
	/** @var PDO */
	private $db;
	/** @var mixed */
	private $logger;
	/** @var mixed */
	private $bridgeManager;

	/**
	 * @param PDO $db Database connection
	 * @param mixed $logger PSR-3 compatible logger (optional)
	 * @param mixed $bridgeManager BridgeManager instance (optional)
	 */
	public function __construct(PDO $db, $logger = null, $bridgeManager = null)
	{
		$this->db = $db;
		$this->logger = $logger;
		$this->bridgeManager = $bridgeManager;
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
			if (!$this->db)
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => 'Database connection not available'
				]));
				return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
			}

			$queryParams = $request->getQueryParams();
			$days = isset($queryParams['days']) ? max(1, (int)$queryParams['days']) : 30;

			$stmt = $this->db->prepare("SELECT cleanup_old_bridge_logs(:days) AS deleted_count");
			$stmt->execute([':days' => $days]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['deleted_count' => 0];

			$payload = [
				'success' => true,
				'days_kept' => $days,
				'deleted' => (int)$row['deleted_count'],
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
			if (!$this->db)
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => 'Database connection not available'
				]));
				return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
			}

			$query = $request->getQueryParams();
			$bridge = $query['bridge'] ?? 'outlook';
			$minutes = isset($query['renew_before_minutes']) ? max(5, (int)$query['renew_before_minutes']) : 1440;
			$subscriptionId = (string)($query['subscription_id'] ?? '');
			$limit = isset($query['limit']) ? max(1, (int)$query['limit']) : 50;
			$tenantId = (string)($request->getAttribute('tenant_id') ?? '');

			// Select active subscriptions expiring before the threshold
		  $sql = "SELECT subscription_id, calendar_id, expires_at FROM bridge_subscriptions 
			  WHERE bridge_type = :bridge AND is_active = TRUE AND expires_at IS NOT NULL 
			  AND expires_at < (NOW() + (:minutes || ' minutes')::interval)";
		  
		  // Add tenant filter if specified
		  if ($tenantId !== '') {
			  $sql .= " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
		  }
		  
		  // Add specific subscription filter if provided
		  if ($subscriptionId !== '') {
			  $sql .= " AND subscription_id = :subscription_id";
		  }
		  
		  $sql .= " ORDER BY expires_at ASC LIMIT :limit";
		  
		  $stmt = $this->db->prepare($sql);
		  $stmt->bindValue(':bridge', $bridge, \PDO::PARAM_STR);
		  $stmt->bindValue(':minutes', (string)$minutes, \PDO::PARAM_STR);
		  $stmt->bindValue(':limit', (int)$limit, \PDO::PARAM_INT);
		  if ($tenantId !== '') { 
			  $stmt->bindValue(':tenant_id', (string)$tenantId, \PDO::PARAM_STR); 
		  }
		  if ($subscriptionId !== '') { 
			  $stmt->bindValue(':subscription_id', $subscriptionId, \PDO::PARAM_STR); 
		  }
			$stmt->execute();
			$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

			$renewed = [];
			$failed = [];

			if (!empty($rows))
			{
				if (!$this->bridgeManager)
				{
					throw new Exception('BridgeManager not available');
				}
				$bridgeInstance = $tenantId !== '' ? $this->bridgeManager->getBridgeForTenant($tenantId, $bridge) : $this->bridgeManager->getBridge($bridge);

				foreach ($rows as $row)
				{
					if (method_exists($bridgeInstance, 'renewSubscription'))
					{
						$result = $bridgeInstance->renewSubscription($row['subscription_id']);
						if (!empty($result['success']))
						{
							$renewed[] = $result;
						}
						else
						{
							$failed[] = $result;
						}
					}
					else
					{
						$failed[] = [
							'subscription_id' => $row['subscription_id'],
							'error' => 'Bridge does not support renewal'
						];
					}
				}
			}

			$payload = [
				'success' => true,
				'bridge' => $bridge,
				'checked' => count($rows),
				'renewed' => $renewed,
				'failed' => $failed,
				'timestamp' => date('c')
			];

			if ($this->logger)
			{
				$this->logger->info('renew_subscriptions executed', [
					'bridge' => $bridge,
					'checked' => count($rows),
					'renewed_count' => count($renewed),
					'failed_count' => count($failed),
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
	 * Download log files (bridge-cron.log, cron.log, etc.)
	 *
	 * @param Request $request
	 * @param Response $response  
	 * @param array $args
	 * @return Response
	 */
	public function downloadLogs(Request $request, Response $response, $args)
	{
		try
		{
			$queryParams = $request->getQueryParams();
			$logType = $queryParams['type'] ?? 'bridge-cron';
			
			// Define allowed log files for security
			$allowedLogs = [
				'bridge-cron' => '/var/log/bridge-cron.log',
				'cron' => '/var/log/cron.log',
				'bridge-stats' => '/var/log/bridge-stats.log'
			];

			if (!isset($allowedLogs[$logType]))
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => 'Invalid log type. Allowed: ' . implode(', ', array_keys($allowedLogs))
				]));
				return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
			}

			$logFile = $allowedLogs[$logType];

			// Check if file exists and is readable
			if (!file_exists($logFile) || !is_readable($logFile))
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => "Log file not found or not readable: {$logType}"
				]));
				return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
			}

			// Get file size and content
			$fileSize = filesize($logFile);
			$maxSize = 10 * 1024 * 1024; // 10MB limit for download safety

			if ($fileSize > $maxSize)
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => "Log file too large for download ({$fileSize} bytes). Maximum: {$maxSize} bytes"
				]));
				return $response->withStatus(413)->withHeader('Content-Type', 'application/json');
			}

			// Read file content
			$content = file_get_contents($logFile);
			if ($content === false)
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => "Failed to read log file: {$logType}"
				]));
				return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
			}

			// Generate filename with timestamp
			$timestamp = date('Y-m-d_H-i-s');
			$filename = "outlookbookingsync_{$logType}_{$timestamp}.log";

			if ($this->logger)
			{
				$this->logger->info('Log file downloaded', [
					'log_type' => $logType,
					'file_size' => $fileSize,
					'filename' => $filename
				]);
			}

			// Return file as download
			$response->getBody()->write($content);
			return $response
				->withHeader('Content-Type', 'text/plain; charset=utf-8')
				->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
				->withHeader('Content-Length', (string)$fileSize)
				->withHeader('Cache-Control', 'no-cache, must-revalidate')
				->withHeader('Pragma', 'no-cache');
		}
		catch (Exception $e)
		{
			if ($this->logger)
			{
				$this->logger->error('Failed to download logs', ['error' => $e->getMessage()]);
			}
			$response->getBody()->write(json_encode([
				'success' => false,
				'error' => 'Log download failed: ' . $e->getMessage()
			]));
			return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
		}
	}
}
