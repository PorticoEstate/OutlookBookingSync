<?php

namespace App\Services;

use App\Repository\AlertRepository;
use Exception;

/**
 * AlertService handles creation, detection, persistence, and notification of system alerts.
 */
class AlertService
{
	private $alertRepo;
	private $logger;

	/**
	 * @param AlertRepository $alertRepo Repository for alert data
	 * @param mixed|null $logger Optional PSR-3 compatible logger
	 */
	public function __construct(AlertRepository $alertRepo, $logger = null)
	{
		$this->alertRepo = $alertRepo;
		$this->logger = $logger;
	}

	/**
	 * Check system health and trigger alerts if needed.
	 *
	 * @return array{success:bool,alerts_triggered?:int,alerts?:array,error?:string} Summary of alert checks
	 */
	public function checkAndAlert()
	{
		try
		{
			$alerts = [];

			// Check for high error rates
			$errorRateAlert = $this->checkErrorRate();
			if ($errorRateAlert)
			{
				$alerts[] = $errorRateAlert;
			}

			// Check for stalled sync operations
			$stalledSyncAlert = $this->checkStalledSyncs();
			if ($stalledSyncAlert)
			{
				$alerts[] = $stalledSyncAlert;
			}

			// Check for database connectivity issues
			$dbAlert = $this->checkDatabaseHealth();
			if ($dbAlert)
			{
				$alerts[] = $dbAlert;
			}

			// Check for missing cron job activity
			$cronAlert = $this->checkCronActivity();
			if ($cronAlert)
			{
				$alerts[] = $cronAlert;
			}

			// Process alerts
			foreach ($alerts as $alert)
			{
				$this->processAlert($alert);
			}

			return [
				'success' => true,
				'alerts_triggered' => count($alerts),
				'alerts' => $alerts
			];
		}
		catch (Exception $e)
		{
			if ($this->logger)
			{
				$this->logger->error('Alert service error', [
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString()
				]);
			}

			return [
				'success' => false,
				'error' => $e->getMessage()
			];
		}
	}

	/**
	 * Check error rate over the last hour.
	 *
	 * @return array|null Structured alert on elevated error rates, or null when healthy
	 */
	private function checkErrorRate()
	{
		try
		{
			$stats = $this->alertRepo->getErrorRate(1);
			$totalOps = $stats['total_operations'];
			$errorCount = $stats['error_count'];

			if ($totalOps > 0)
			{
				$errorRate = ($errorCount / $totalOps) * 100;

				if ($errorRate > 25)
				{
					return [
						'type' => 'high_error_rate',
						'severity' => 'critical',
						'message' => "High error rate detected: {$errorRate}% ({$errorCount}/{$totalOps}) in the last hour",
						'data' => [
							'error_rate' => $errorRate,
							'error_count' => $errorCount,
							'total_operations' => $totalOps
						]
					];
				}
				elseif ($errorRate > 10)
				{
					return [
						'type' => 'elevated_error_rate',
						'severity' => 'warning',
						'message' => "Elevated error rate: {$errorRate}% ({$errorCount}/{$totalOps}) in the last hour",
						'data' => [
							'error_rate' => $errorRate,
							'error_count' => $errorCount,
							'total_operations' => $totalOps
						]
					];
				}
			}

			return null;
		}
		catch (Exception $e)
		{
			throw new Exception("Error checking error rate: " . $e->getMessage());
		}
	}

	/**
	 * Check for stalled sync operations.
	 *
	 * @return array|null Structured alert when stalled operations exceed threshold, otherwise null
	 */
	private function checkStalledSyncs()
	{
		try
		{
			$stalledCount = $this->alertRepo->getStalledSyncsCount(2);

			if ($stalledCount > 10)
			{
				return [
					'type' => 'stalled_syncs',
					'severity' => 'warning',
					'message' => "Found {$stalledCount} sync operations pending for more than 2 hours",
					'data' => [
						'stalled_count' => $stalledCount
					]
				];
			}

			return null;
		}
		catch (Exception $e)
		{
			throw new Exception("Error checking stalled syncs: " . $e->getMessage());
		}
	}

	/**
	 * Check database health.
	 *
	 * @return array|null Structured alert when DB is slow/unavailable, otherwise null
	 */
	private function checkDatabaseHealth()
	{
		try
		{
			$responseTime = $this->alertRepo->checkDatabaseHealth();

			if ($responseTime > 5000)
			{ // 5 seconds
				return [
					'type' => 'slow_database',
					'severity' => 'critical',
					'message' => "Database response time is critically slow: {$responseTime}ms",
					'data' => [
						'response_time_ms' => $responseTime
					]
				];
			}
			elseif ($responseTime > 2000)
			{ // 2 seconds
				return [
					'type' => 'slow_database',
					'severity' => 'warning',
					'message' => "Database response time is slow: {$responseTime}ms",
					'data' => [
						'response_time_ms' => $responseTime
					]
				];
			}

			return null;
		}
		catch (Exception $e)
		{
			return [
				'type' => 'database_connectivity',
				'severity' => 'critical',
				'message' => "Database connectivity failed: " . $e->getMessage(),
				'data' => [
					'error' => $e->getMessage()
				]
			];
		}
	}

	/**
	 * Check cron job activity.
	 *
	 * @return array|null Structured alert when no automated activity is detected, otherwise null
	 */
	private function checkCronActivity()
	{
		try
		{
			$recentActivity = $this->alertRepo->getRecentAutomatedActivity(30);

			// If no automated activity in 30 minutes, something might be wrong
			if ($recentActivity == 0)
			{
				return [
					'type' => 'no_cron_activity',
					'severity' => 'warning',
					'message' => "No automated sync activity detected in the last 30 minutes",
					'data' => [
						'minutes_since_activity' => 30
					]
				];
			}

			return null;
		}
		catch (Exception $e)
		{
			throw new Exception("Error checking cron activity: " . $e->getMessage());
		}
	}

	/**
	 * Process an individual alert.
	 *
	 * @param array $alert The alert payload with type, severity, message, and optional data
	 * @return void
	 */
	private function processAlert($alert)
	{
		try
		{
			// Log the alert
			if ($this->logger)
			{
				$logLevel = $alert['severity'] === 'critical' ? 'critical' : 'warning';
				$this->logger->$logLevel($alert['message'], [
					'alert_type' => $alert['type'],
					'severity' => $alert['severity'],
					'data' => $alert['data'] ?? []
				]);
			}

			// Store alert in database for tracking
			$this->storeAlert($alert);

			// Send notifications based on severity
			if ($alert['severity'] === 'critical')
			{
				$this->sendCriticalAlert($alert);
			}
			elseif ($alert['severity'] === 'warning')
			{
				$this->sendWarningAlert($alert);
			}
		}
		catch (Exception $e)
		{
			if ($this->logger)
			{
				$this->logger->error('Failed to process alert', [
					'alert' => $alert,
					'error' => $e->getMessage()
				]);
			}
		}
	}

	/**
	 * Store alert in database.
	 *
	 * @param array $alert Alert payload to persist
	 * @return void
	 */
	private function storeAlert($alert)
	{
		try
		{
			$this->alertRepo->createAlert(
				$alert['type'],
				$alert['severity'],
				$alert['message'],
				$alert['data'] ?? []
			);
		}
		catch (Exception $e)
		{
			// Don't throw here to avoid alert processing loops
			if ($this->logger)
			{
				$this->logger->error('Failed to store alert in database', [
					'error' => $e->getMessage(),
					'alert' => $alert
				]);
			}
		}
	}

	/**
	 * Send critical alert notifications.
	 *
	 * @param array $alert Alert payload
	 * @return void
	 */
	private function sendCriticalAlert($alert)
	{
		// In a real implementation, you would send emails, Slack messages, etc.
		// For now, just log at critical level
		if ($this->logger)
		{
			$this->logger->critical("🚨 CRITICAL ALERT: " . $alert['message'], [
				'alert_type' => $alert['type'],
				'data' => $alert['data'] ?? []
			]);
		}

		// Example: Send to webhook endpoint
		$this->sendWebhookNotification($alert, 'critical');
	}

	/**
	 * Send warning alert notifications.
	 *
	 * @param array $alert Alert payload
	 * @return void
	 */
	private function sendWarningAlert($alert)
	{
		// In a real implementation, you would send less urgent notifications
		if ($this->logger)
		{
			$this->logger->warning("⚠️ WARNING: " . $alert['message'], [
				'alert_type' => $alert['type'],
				'data' => $alert['data'] ?? []
			]);
		}

		// Example: Send to webhook endpoint
		$this->sendWebhookNotification($alert, 'warning');
	}

	/**
	 * Send webhook notification.
	 *
	 * @param array $alert Alert payload
	 * @param string $urgency Notification urgency channel
	 * @return void
	 */
	private function sendWebhookNotification($alert, $urgency)
	{
		$webhookUrl = $_ENV['ALERT_WEBHOOK_URL'] ?? null;

		if (!$webhookUrl)
		{
			return; // No webhook configured
		}

		try
		{
			$payload = [
				'service' => 'OutlookBookingSync',
				'alert_type' => $alert['type'],
				'severity' => $alert['severity'],
				'urgency' => $urgency,
				'message' => $alert['message'],
				'timestamp' => date('Y-m-d H:i:s'),
				'data' => $alert['data'] ?? []
			];

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $webhookUrl);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				'Content-Type: application/json',
				'User-Agent: OutlookBookingSync-AlertService/1.0'
			]);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);

			$response = curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			if ($httpCode !== 200)
			{
				if ($this->logger)
				{
					$this->logger->warning('Webhook notification failed', [
						'url' => $webhookUrl,
						'http_code' => $httpCode,
						'response' => $response
					]);
				}
			}

			curl_close($ch);
		}
		catch (Exception $e)
		{
			if ($this->logger)
			{
				$this->logger->error('Webhook notification error', [
					'error' => $e->getMessage(),
					'alert' => $alert
				]);
			}
		}
	}

	/**
	 * Get recent alerts.
	 *
	 * @param int $hours Window in hours to look back
	 * @return array{success:bool,alerts?:array,error?:string}
	 */
	public function getRecentAlerts($hours = 24)
	{
		try
		{
			$alerts = $this->alertRepo->getRecentAlerts($hours);

			return [
				'success' => true,
				'alerts' => $alerts
			];
		}
		catch (Exception $e)
		{
			return [
				'success' => false,
				'error' => $e->getMessage()
			];
		}
	}

	/**
	 * Clear old alerts.
	 *
	 * @param int $days Days to retain alerts; older entries are deleted
	 * @return array{success:bool,deleted_count?:int,error?:string}
	 */
	public function clearOldAlerts($days = 7)
	{
		try
		{
			$deletedCount = $this->alertRepo->deleteOldAlerts($days);

			return [
				'success' => true,
				'deleted_count' => $deletedCount
			];
		}
		catch (Exception $e)
		{
			return [
				'success' => false,
				'error' => $e->getMessage()
			];
		}
	}

	/**
	 * Acknowledge an alert.
	 *
	 * @param int $alertId
	 * @param string $acknowledgedBy
	 * @return bool True if acknowledged, false if not found or already acknowledged
	 */
	public function acknowledgeAlert($alertId, $acknowledgedBy)
	{
		return $this->alertRepo->acknowledgeAlert($alertId, $acknowledgedBy);
	}

	/**
	 * Get alert statistics.
	 *
	 * @param int $hours
	 * @return array
	 */
	public function getAlertStats($hours = 24)
	{
		return $this->alertRepo->getAlertStats($hours);
	}
}
