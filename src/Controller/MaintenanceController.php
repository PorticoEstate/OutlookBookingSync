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
			$bridge = $query['bridge'] ?? '';
			$minutes = isset($query['renew_before_minutes']) ? max(5, (int)$query['renew_before_minutes']) : 1440;
			$subscriptionId = (string)($query['subscription_id'] ?? '');
			$limit = isset($query['limit']) ? max(1, (int)$query['limit']) : 50;
			$tenantId = (string)($request->getAttribute('tenant_id') ?? '');

			// Select active subscriptions expiring before the threshold
		  $sql = "SELECT tenant_id, bridge_type, subscription_id, calendar_id, expires_at FROM bridge_subscriptions 
			  WHERE is_active = TRUE AND expires_at IS NOT NULL 
			  AND expires_at < (NOW() + (:minutes || ' minutes')::interval)";
		  
		  // Add tenant filter if specified
		  if ($tenantId !== '') {
			  $sql .= " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
		  }
		  
		  // Add specific subscription filter if provided
		  if ($subscriptionId !== '') {
			  $sql .= " AND subscription_id = :subscription_id";
		  }

		  if ($bridge !== '') {
			  $sql .= " AND bridge_type = :bridge";
		  } 
		  
		  $sql .= " ORDER BY tenant_id, expires_at ASC LIMIT :limit";
		  
		  $stmt = $this->db->prepare($sql);
		  if ($bridge !== '') {
			  $stmt->bindValue(':bridge', $bridge, \PDO::PARAM_STR);
		  }
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
			$recreated = [];

			if (!empty($rows))
			{
				if (!$this->bridgeManager)
				{
					throw new Exception('BridgeManager not available');
				}
				// Get bridge instance (optionally scoped to tenant)
				$bridgeInstance = $tenantId !== '' ? $this->bridgeManager->getBridgeForTenant($tenantId, $bridge) : null;

				$prevSubscriptionTenantId = null;
				foreach ($rows as $row)
				{
					$subscriptionTenantId = $row['tenant_id'];
					$subscriptionBridge = $row['bridge_type'];
					if(!$tenantId && $subscriptionTenantId !== $prevSubscriptionTenantId)
					{
						$bridgeInstance = $this->bridgeManager->getBridgeForTenant($subscriptionTenantId, $subscriptionBridge);
					}
					$prevSubscriptionTenantId = $row['tenant_id'];
					
					// Check if subscription is already expired
					$expiresAt = $row['expires_at'];
					$isExpired = strtotime($expiresAt) < time();
					
					if ($isExpired)
					{
						// Subscription is expired - need to recreate it
						if ($this->logger)
						{
							$this->logger->info('Subscription expired, recreating', [
								'subscription_id' => $row['subscription_id'],
								'calendar_id' => $row['calendar_id'],
								'expired_at' => $expiresAt,
								'tenant_id' => $subscriptionTenantId
							]);
						}
						
						// Mark old subscription as inactive
						$updateSql = "UPDATE bridge_subscriptions 
									  SET is_active = FALSE 
									  WHERE subscription_id = :sub_id 
									  AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
						$updateStmt = $this->db->prepare($updateSql);
						$updateStmt->execute([
							':sub_id' => $row['subscription_id'],
							':tenant_id' => $subscriptionTenantId
						]);
						
						// Recreate subscription using subscribeToChanges
						if (method_exists($bridgeInstance, 'subscribeToChanges'))
						{
							try
							{
								// Get webhook URL from bridge_subscriptions or construct default
								$webhookUrlSql = "SELECT webhook_url FROM bridge_subscriptions 
												  WHERE subscription_id = :sub_id 
												  AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
								$webhookStmt = $this->db->prepare($webhookUrlSql);
								$webhookStmt->execute([
									':sub_id' => $row['subscription_id'],
									':tenant_id' => $subscriptionTenantId
								]);
								$webhookRow = $webhookStmt->fetch(PDO::FETCH_ASSOC);
								$webhookUrl = $webhookRow['webhook_url'] ?? null;
								
								if (!$webhookUrl)
								{
									$baseUrl = $_ENV['APP_BASE_URL'] ?? 'http://localhost';
									$webhookUrl = "{$baseUrl}/bridges/webhook/{$subscriptionBridge}?tenant_id={$subscriptionTenantId}";
								}
								
								$newSubscriptionId = $bridgeInstance->subscribeToChanges(
									$row['calendar_id'],
									$webhookUrl
								);
								
								$recreated[] = [
									'success' => true,
									'old_subscription_id' => $row['subscription_id'],
									'new_subscription_id' => $newSubscriptionId,
									'calendar_id' => $row['calendar_id'],
									'reason' => 'expired',
									'expired_at' => $expiresAt
								];
								
								if ($this->logger)
								{
									$this->logger->info('Subscription recreated successfully', [
										'old_subscription_id' => $row['subscription_id'],
										'new_subscription_id' => $newSubscriptionId,
										'calendar_id' => $row['calendar_id'],
										'tenant_id' => $subscriptionTenantId
									]);
								}
							}
							catch (Exception $e)
							{
								$failed[] = [
									'subscription_id' => $row['subscription_id'],
									'calendar_id' => $row['calendar_id'],
									'error' => 'Failed to recreate expired subscription: ' . $e->getMessage(),
									'reason' => 'expired',
									'expired_at' => $expiresAt
								];
								
								if ($this->logger)
								{
									$this->logger->error('Failed to recreate expired subscription', [
										'subscription_id' => $row['subscription_id'],
										'calendar_id' => $row['calendar_id'],
										'error' => $e->getMessage(),
										'tenant_id' => $subscriptionTenantId
									]);
								}
							}
						}
						else
						{
							$failed[] = [
								'subscription_id' => $row['subscription_id'],
								'calendar_id' => $row['calendar_id'],
								'error' => 'Bridge does not support subscription creation',
								'reason' => 'expired'
							];
						}
					}
					else
					{
						// Subscription not yet expired - try to renew it
						if (method_exists($bridgeInstance, 'renewSubscription'))
						{
							$result = $bridgeInstance->renewSubscription($row['subscription_id']);
							if (!empty($result['success']))
							{
								$renewed[] = $result;
							}
							else
							{
								// Renewal failed - check if it failed because subscription is expired
								$errorMessage = $result['error'] ?? '';
								if (stripos($errorMessage, '404') !== false || 
									stripos($errorMessage, 'not found') !== false ||
									stripos($errorMessage, 'subscription') !== false && stripos($errorMessage, 'does not exist') !== false)
								{
									// Subscription was deleted by Microsoft - recreate it
									if ($this->logger)
									{
										$this->logger->info('Subscription not found on Microsoft side, recreating', [
											'subscription_id' => $row['subscription_id'],
											'calendar_id' => $row['calendar_id'],
											'error' => $errorMessage,
											'tenant_id' => $subscriptionTenantId
										]);
									}
									
									// Mark old subscription as inactive
									$updateSql = "UPDATE bridge_subscriptions 
												  SET is_active = FALSE 
												  WHERE subscription_id = :sub_id 
												  AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
									$updateStmt = $this->db->prepare($updateSql);
									$updateStmt->execute([
										':sub_id' => $row['subscription_id'],
										':tenant_id' => $subscriptionTenantId
									]);
									
									// Recreate subscription
									if (method_exists($bridgeInstance, 'subscribeToChanges'))
									{
										try
										{
											$webhookUrlSql = "SELECT webhook_url FROM bridge_subscriptions 
															  WHERE subscription_id = :sub_id 
															  AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
											$webhookStmt = $this->db->prepare($webhookUrlSql);
											$webhookStmt->execute([
												':sub_id' => $row['subscription_id'],
												':tenant_id' => $subscriptionTenantId
											]);
											$webhookRow = $webhookStmt->fetch(PDO::FETCH_ASSOC);
											$webhookUrl = $webhookRow['webhook_url'] ?? null;
											
											if (!$webhookUrl)
											{
												$baseUrl = $_ENV['APP_BASE_URL'] ?? 'http://localhost';
												$webhookUrl = "{$baseUrl}/bridges/webhook/{$subscriptionBridge}?tenant_id={$subscriptionTenantId}";
											}
											
											$newSubscriptionId = $bridgeInstance->subscribeToChanges(
												$row['calendar_id'],
												$webhookUrl
											);
											
											$recreated[] = [
												'success' => true,
												'old_subscription_id' => $row['subscription_id'],
												'new_subscription_id' => $newSubscriptionId,
												'calendar_id' => $row['calendar_id'],
												'reason' => 'not_found_on_microsoft',
												'original_error' => $errorMessage
											];
										}
										catch (Exception $e)
										{
											$failed[] = [
												'subscription_id' => $row['subscription_id'],
												'calendar_id' => $row['calendar_id'],
												'error' => 'Failed to recreate missing subscription: ' . $e->getMessage(),
												'reason' => 'not_found_on_microsoft'
											];
										}
									}
									else
									{
										$failed[] = $result;
									}
								}
								else
								{
									// Other renewal error
									$failed[] = $result;
								}
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
			}

			$payload = [
				'success' => true,
				'bridge' => $bridge,
				'checked' => count($rows),
				'renewed' => $renewed,
				'recreated' => $recreated,
				'failed' => $failed,
				'summary' => [
					'total_checked' => count($rows),
					'renewed_count' => count($renewed),
					'recreated_count' => count($recreated),
					'failed_count' => count($failed),
					'success_count' => count($renewed) + count($recreated)
				],
				'timestamp' => date('c')
			];

			if ($this->logger)
			{
				$this->logger->info('renew_subscriptions executed', [
					'bridge' => $bridge,
					'checked' => count($rows),
					'renewed_count' => count($renewed),
					'recreated_count' => count($recreated),
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
}
