<?php

namespace App\Repository;

use PDO;
use Exception;

/**
 * Repository for health check and monitoring database operations.
 */
class HealthRepository
{
	private $db;

	public function __construct(PDO $db)
	{
		$this->db = $db;
	}

	/**
	 * Check database connectivity.
	 *
	 * @return bool
	 */
	public function checkDatabaseConnectivity(): bool
	{
		return $this->db->query('SELECT 1') !== false;
	}

	/**
	 * Get total mapping count.
	 *
	 * @param string|null $tenantId
	 * @return int
	 */
	public function getMappingCount(?string $tenantId = null): int
	{
		$sql = 'SELECT COUNT(*) as count FROM bridge_mappings' . ($tenantId ? ' WHERE (tenant_id IS NOT DISTINCT FROM :tenant_id)' : '');
		$stmt = $this->db->prepare($sql);
		if ($tenantId) {
			$stmt->bindValue(':tenant_id', $tenantId);
		}
		$stmt->execute();
		$result = $stmt->fetch(PDO::FETCH_ASSOC);
		return (int)($result['count'] ?? 0);
	}

	/**
	 * Get active long-running queries count.
	 *
	 * @return int
	 */
	public function getActiveQueriesCount(): int
	{
		$stmt = $this->db->query("
			SELECT COUNT(*) as active_queries 
			FROM pg_stat_activity 
			WHERE state = 'active' AND query_start < NOW() - INTERVAL '30 seconds'
		");
		$result = $stmt->fetch(PDO::FETCH_ASSOC);
		return (int)($result['active_queries'] ?? 0);
	}

	/**
	 * Get recent automated sync stats.
	 *
	 * @param string|null $tenantId
	 * @return array{count: int, last_sync: ?string}
	 */
	public function getRecentAutomatedSyncs(?string $tenantId = null): array
	{
		$sql = "SELECT COUNT(*) as recent_automated_syncs, MAX(created_at) as last_automated_sync FROM bridge_sync_logs WHERE created_at > NOW() - INTERVAL '1 hour' AND operation IN ('sync', 'update')" . ($tenantId ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "");
		$stmt = $this->db->prepare($sql);
		if ($tenantId) {
			$stmt->bindValue(':tenant_id', $tenantId);
		}
		$stmt->execute();
		$result = $stmt->fetch(PDO::FETCH_ASSOC);

		return [
			'count' => (int)($result['recent_automated_syncs'] ?? 0),
			'last_sync' => $result['last_automated_sync']
		];
	}

	/**
	 * Get sync status counts.
	 *
	 * @param string|null $tenantId
	 * @return array
	 */
	public function getSyncStatusCounts(?string $tenantId = null): array
	{
		$sql = "SELECT sync_status, COUNT(*) as count FROM bridge_mappings" . ($tenantId ? " WHERE (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "") . " GROUP BY sync_status";
		$stmt = $this->db->prepare($sql);
		if ($tenantId) {
			$stmt->bindValue(':tenant_id', $tenantId);
		}
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
	}

	/**
	 * Get recent error count.
	 *
	 * @param string|null $tenantId
	 * @return int
	 */
	public function getRecentErrorCount(?string $tenantId = null): int
	{
		$sql = "SELECT COUNT(*) as error_count FROM bridge_sync_logs WHERE status = 'error' AND created_at > NOW() - INTERVAL '24 hours'" . ($tenantId ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "");
		$stmt = $this->db->prepare($sql);
		if ($tenantId) {
			$stmt->bindValue(':tenant_id', $tenantId);
		}
		$stmt->execute();
		$result = $stmt->fetch(PDO::FETCH_ASSOC);
		return (int)($result['error_count'] ?? 0);
	}

	/**
	 * Get system overview stats.
	 *
	 * @return array
	 */
	public function getSystemOverviewStats(): array
	{
		$stats = [];
		
		// Total tenants
		$stmt = $this->db->query("SELECT COUNT(*) as count FROM tenants");
		$stats['total_tenants'] = (int)$stmt->fetchColumn();

		// Total mappings
		$stmt = $this->db->query("SELECT COUNT(*) as count FROM bridge_mappings");
		$stats['total_mappings'] = (int)$stmt->fetchColumn();

		// Total active subscriptions
		$stmt = $this->db->query("SELECT COUNT(*) as count FROM bridge_subscriptions WHERE is_active = TRUE");
		$stats['active_subscriptions'] = (int)$stmt->fetchColumn();

		return $stats;
	}

	/**
	 * Get all sync statistics.
	 *
	 * @param string|null $tenantId
	 * @return array
	 */
	public function getAllSyncStatistics(?string $tenantId = null): array
	{
		$sql = "
			SELECT 
				COUNT(*) as total_syncs,
				COUNT(CASE WHEN status = 'success' THEN 1 END) as successful_syncs,
				COUNT(CASE WHEN status = 'error' THEN 1 END) as failed_syncs,
				AVG(CASE WHEN status = 'success' THEN execution_time_ms END) as avg_execution_time
			FROM bridge_sync_logs 
			WHERE created_at > NOW() - INTERVAL '24 hours'
		" . ($tenantId ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "");

		$stmt = $this->db->prepare($sql);
		if ($tenantId) {
			$stmt->bindValue(':tenant_id', $tenantId);
		}
		$stmt->execute();
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	/**
	 * Get recent activity.
	 *
	 * @param string|null $tenantId
	 * @param int $limit
	 * @return array
	 */
	public function getRecentActivity(?string $tenantId = null, int $limit = 10): array
	{
		$sql = "
			SELECT 
				id,
				operation,
				status,
				message,
				created_at,
				tenant_id
			FROM bridge_sync_logs 
			WHERE 1=1
		" . ($tenantId ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "") . "
			ORDER BY created_at DESC 
			LIMIT :limit
		";

		$stmt = $this->db->prepare($sql);
		if ($tenantId) {
			$stmt->bindValue(':tenant_id', $tenantId);
		}
		$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Get error summary.
	 *
	 * @param string|null $tenantId
	 * @return array
	 */
	public function getErrorSummary(?string $tenantId = null): array
	{
		$sql = "
			SELECT 
				message,
				COUNT(*) as count,
				MAX(created_at) as last_occurrence
			FROM bridge_sync_logs 
			WHERE status = 'error' 
			AND created_at > NOW() - INTERVAL '24 hours'
		" . ($tenantId ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "") . "
			GROUP BY message 
			ORDER BY count DESC 
			LIMIT 5
		";

		$stmt = $this->db->prepare($sql);
		if ($tenantId) {
			$stmt->bindValue(':tenant_id', $tenantId);
		}
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}
}
