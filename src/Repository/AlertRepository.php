<?php

namespace App\Repository;

use PDO;
use Exception;

/**
 * Repository for alert-related database operations.
 */
class AlertRepository
{
	private $db;

	public function __construct(PDO $db)
	{
		$this->db = $db;
	}

	/**
	 * Get error rate statistics for the specified interval.
	 *
	 * @param int $hours
	 * @return array{total_operations: int, error_count: int}
	 */
	public function getErrorRate(int $hours = 1): array
	{
		$stmt = $this->db->prepare("
			SELECT 
				COUNT(*) as total_operations,
				COUNT(CASE WHEN sync_status = 'error' THEN 1 END) as error_count
			FROM bridge_mappings 
			WHERE updated_at > NOW() - (:hours || ' hours')::interval
		");
		$stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
		$stmt->execute();
		$result = $stmt->fetch(PDO::FETCH_ASSOC);

		return [
			'total_operations' => (int)($result['total_operations'] ?? 0),
			'error_count' => (int)($result['error_count'] ?? 0)
		];
	}

	/**
	 * Get count of stalled sync operations.
	 *
	 * @param int $hours
	 * @return int
	 */
	public function getStalledSyncsCount(int $hours = 2): int
	{
		$stmt = $this->db->prepare("
			SELECT COUNT(*) as stalled_count
			FROM bridge_mappings 
			WHERE sync_status = 'pending' 
			AND created_at < NOW() - (:hours || ' hours')::interval
		");
		$stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
		$stmt->execute();
		$result = $stmt->fetch(PDO::FETCH_ASSOC);

		return (int)($result['stalled_count'] ?? 0);
	}

	/**
	 * Check database connectivity and response time.
	 *
	 * @return float Response time in milliseconds
	 * @throws Exception If database query fails
	 */
	public function checkDatabaseHealth(): float
	{
		$start = microtime(true);
		$this->db->query('SELECT 1');
		return (microtime(true) - $start) * 1000;
	}

	/**
	 * Get count of recent automated sync activities.
	 *
	 * @param int $minutes
	 * @return int
	 */
	public function getRecentAutomatedActivity(int $minutes = 30): int
	{
		$stmt = $this->db->prepare("
			SELECT COUNT(*) as recent_activity
			FROM bridge_mappings 
			WHERE updated_at > NOW() - (:minutes || ' minutes')::interval
			AND sync_method IN ('polling', 'automated', 'cron')
		");
		$stmt->bindValue(':minutes', $minutes, PDO::PARAM_INT);
		$stmt->execute();
		$result = $stmt->fetch(PDO::FETCH_ASSOC);

		return (int)($result['recent_activity'] ?? 0);
	}

	/**
	 * Create a new alert record.
	 *
	 * @param string $type
	 * @param string $severity
	 * @param string $message
	 * @param array $data
	 * @return void
	 */
	public function createAlert(string $type, string $severity, string $message, array $data = []): void
	{
		$stmt = $this->db->prepare("
			INSERT INTO outlook_sync_alerts (
				alert_type, 
				severity, 
				message, 
				alert_data, 
				created_at
			) VALUES (?, ?, ?, ?, NOW())
		");

		$stmt->execute([
			$type,
			$severity,
			$message,
			json_encode($data)
		]);
	}

	/**
	 * Get recent alerts.
	 *
	 * @param int $hours
	 * @param int $limit
	 * @return array
	 */
	public function getRecentAlerts(int $hours = 24, int $limit = 50): array
	{
		$stmt = $this->db->prepare("
			SELECT 
				id,
				alert_type,
				severity,
				message,
				alert_data,
				created_at,
				acknowledged_at,
				acknowledged_by
			FROM outlook_sync_alerts 
			WHERE created_at > NOW() - (:hours || ' hours')::interval
			ORDER BY created_at DESC
			LIMIT :limit
		");
		$stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
		$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
		$stmt->execute();

		$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

		// Decode JSON data
		foreach ($alerts as &$alert)
		{
			$alert['alert_data'] = json_decode($alert['alert_data'], true);
		}

		return $alerts;
	}

	/**
	 * Delete old alerts.
	 *
	 * @param int $days
	 * @return int Number of deleted records
	 */
	public function deleteOldAlerts(int $days = 7): int
	{
		$stmt = $this->db->prepare("
			DELETE FROM outlook_sync_alerts 
			WHERE created_at < NOW() - (:days || ' days')::interval
		");
		$stmt->bindValue(':days', $days, PDO::PARAM_INT);
		$stmt->execute();

		return $stmt->rowCount();
	}

	/**
	 * Acknowledge an alert.
	 *
	 * @param int $alertId
	 * @param string $acknowledgedBy
	 * @return bool True if acknowledged, false if not found or already acknowledged
	 */
	public function acknowledgeAlert(int $alertId, string $acknowledgedBy): bool
	{
		$stmt = $this->db->prepare("
			UPDATE outlook_sync_alerts 
			SET acknowledged_at = NOW(), acknowledged_by = :by
			WHERE id = :id AND acknowledged_at IS NULL
		");
		$stmt->execute([
			':by' => $acknowledgedBy,
			':id' => $alertId
		]);

		return $stmt->rowCount() > 0;
	}

	/**
	 * Get alert statistics.
	 *
	 * @param int $hours
	 * @return array
	 */
	public function getAlertStats(int $hours = 24): array
	{
		$stmt = $this->db->prepare("
			SELECT 
				severity,
				alert_type,
				COUNT(*) as count,
				MAX(created_at) as latest_occurrence
			FROM outlook_sync_alerts 
			WHERE created_at > NOW() - (:hours || ' hours')::interval
			GROUP BY severity, alert_type
			ORDER BY severity DESC, count DESC
		");
		$stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
		$stmt->execute();
		$breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

		// Get summary stats
		$summaryStmt = $this->db->prepare("
			SELECT 
				COUNT(*) as total_alerts,
				COUNT(CASE WHEN severity = 'critical' THEN 1 END) as critical_alerts,
				COUNT(CASE WHEN severity = 'warning' THEN 1 END) as warning_alerts,
				COUNT(CASE WHEN acknowledged_at IS NOT NULL THEN 1 END) as acknowledged_alerts
			FROM outlook_sync_alerts 
			WHERE created_at > NOW() - (:hours || ' hours')::interval
		");
		$summaryStmt->bindValue(':hours', $hours, PDO::PARAM_INT);
		$summaryStmt->execute();
		$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

		return [
			'hours' => $hours,
			'summary' => $summary,
			'breakdown' => $breakdown
		];
	}
}
