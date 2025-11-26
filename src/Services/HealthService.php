<?php

namespace App\Services;

use App\Repository\HealthRepository;
use Exception;

/**
 * Service for system health checks and monitoring.
 */
class HealthService
{
	private $healthRepo;
	private $logger;

	public function __construct(HealthRepository $healthRepo, $logger = null)
	{
		$this->healthRepo = $healthRepo;
		$this->logger = $logger;
	}

	/**
	 * Comprehensive system health check.
	 *
	 * @param string|null $tenantId
	 * @return array
	 */
	public function getSystemHealth(?string $tenantId = null): array
	{
		$health = [
			'timestamp' => date('Y-m-d H:i:s'),
			'status' => 'healthy',
			'uptime' => $this->getSystemUptime(),
			'checks' => [
				'database' => $this->checkDatabase($tenantId),
				'cron_jobs' => $this->checkCronJobs($tenantId),
				'disk_space' => $this->checkDiskSpace(),
				'memory_usage' => $this->checkMemoryUsage(),
				'sync_status' => $this->checkSyncStatus($tenantId),
				'recent_errors' => $this->checkRecentErrors($tenantId)
			]
		];

		// Determine overall health status
		$unhealthyChecks = array_filter($health['checks'], function($check) {
			return $check['status'] !== 'healthy';
		});

		if (!empty($unhealthyChecks)) {
			$health['status'] = 'degraded';
			$criticalChecks = array_filter($unhealthyChecks, function($check) {
				return $check['status'] === 'critical';
			});
			if (!empty($criticalChecks)) {
				$health['status'] = 'critical';
			}
		}

		return $health;
	}

	/**
	 * Quick health check for load balancers.
	 *
	 * @return bool
	 */
	public function getQuickHealth(): bool
	{
		return $this->healthRepo->checkDatabaseConnectivity();
	}

	/**
	 * Get system monitoring dashboard data.
	 *
	 * @param string|null $tenantId
	 * @return array
	 */
	public function getDashboardData(?string $tenantId = null): array
	{
		return [
			'timestamp' => date('Y-m-d H:i:s'),
			'system_overview' => $this->healthRepo->getSystemOverviewStats(),
			'sync_statistics' => $this->healthRepo->getAllSyncStatistics($tenantId),
			'recent_activity' => $this->healthRepo->getRecentActivity($tenantId),
			'performance_metrics' => $this->getPerformanceMetrics(),
			'error_summary' => $this->healthRepo->getErrorSummary($tenantId),
			'cron_status' => $this->getCronStatus()
		];
	}

	private function checkDatabase(?string $tenantId = null): array
	{
		try {
			$start = microtime(true);
			
			if (!$this->healthRepo->checkDatabaseConnectivity()) {
				return ['status' => 'critical', 'message' => 'Database connection failed'];
			}

			$totalMappings = $this->healthRepo->getMappingCount($tenantId);
			$responseTime = round((microtime(true) - $start) * 1000, 2);
			$activeQueries = $this->healthRepo->getActiveQueriesCount();

			$status = 'healthy';
			$warnings = [];

			if ($responseTime > 1000) {
				$status = 'warning';
				$warnings[] = 'Slow database response time';
			}

			if ($activeQueries > 5) {
				$status = 'warning';
				$warnings[] = 'High number of active queries';
			}

			return [
				'status' => $status,
				'response_time_ms' => $responseTime,
				'total_mappings' => $totalMappings,
				'active_queries' => $activeQueries,
				'warnings' => $warnings
			];

		} catch (Exception $e) {
			return [
				'status' => 'critical',
				'message' => 'Database check failed: ' . $e->getMessage()
			];
		}
	}

	private function checkCronJobs(?string $tenantId = null): array
	{
		try {
			$cronRunning = false;
			$cronOutput = shell_exec('ps aux | grep -v grep | grep cron');
			if ($cronOutput) {
				$cronRunning = true;
			}

			$stats = $this->healthRepo->getRecentAutomatedSyncs($tenantId);

			$status = 'healthy';
			$warnings = [];

			if (!$cronRunning) {
				$status = 'critical';
				$warnings[] = 'Cron daemon not running';
			}

			if ($stats['count'] == 0) {
				$status = 'warning';
				$warnings[] = 'No recent automated sync activity';
			}

			return [
				'status' => $status,
				'cron_daemon_running' => $cronRunning,
				'recent_automated_syncs' => $stats['count'],
				'last_automated_sync' => $stats['last_sync'],
				'warnings' => $warnings
			];

		} catch (Exception $e) {
			return [
				'status' => 'critical',
				'message' => 'Cron job check failed: ' . $e->getMessage()
			];
		}
	}

	private function checkDiskSpace(): array
	{
		try {
			$freeBytes = disk_free_space('/');
			$totalBytes = disk_total_space('/');
			$usedBytes = $totalBytes - $freeBytes;
			$usagePercent = round(($usedBytes / $totalBytes) * 100, 2);

			$status = 'healthy';
			if ($usagePercent > 90) {
				$status = 'critical';
			} elseif ($usagePercent > 80) {
				$status = 'warning';
			}

			return [
				'status' => $status,
				'usage_percent' => $usagePercent,
				'free_space_gb' => round($freeBytes / 1024 / 1024 / 1024, 2),
				'total_space_gb' => round($totalBytes / 1024 / 1024 / 1024, 2)
			];
		} catch (Exception $e) {
			return [
				'status' => 'warning',
				'message' => 'Disk space check failed: ' . $e->getMessage()
			];
		}
	}

	private function checkMemoryUsage(): array
	{
		try {
			$memInfo = file_get_contents('/proc/meminfo');
			preg_match('/MemTotal:\s+(\d+)\s+kB/', $memInfo, $totalMatches);
			preg_match('/MemAvailable:\s+(\d+)\s+kB/', $memInfo, $availableMatches);

			if (isset($totalMatches[1]) && isset($availableMatches[1])) {
				$totalMem = $totalMatches[1];
				$availableMem = $availableMatches[1];
				$usedMem = $totalMem - $availableMem;
				$usagePercent = round(($usedMem / $totalMem) * 100, 2);

				$status = 'healthy';
				if ($usagePercent > 90) {
					$status = 'critical';
				} elseif ($usagePercent > 80) {
					$status = 'warning';
				}

				return [
					'status' => $status,
					'usage_percent' => $usagePercent,
					'free_memory_mb' => round($availableMem / 1024, 2),
					'total_memory_mb' => round($totalMem / 1024, 2)
				];
			}

			return ['status' => 'unknown', 'message' => 'Could not determine memory usage'];
		} catch (Exception $e) {
			return [
				'status' => 'warning',
				'message' => 'Memory check failed: ' . $e->getMessage()
			];
		}
	}

	private function checkSyncStatus(?string $tenantId = null): array
	{
		try {
			$counts = $this->healthRepo->getSyncStatusCounts($tenantId);
			$errorCount = $counts['error'] ?? 0;
			$totalCount = array_sum($counts);

			$status = 'healthy';
			if ($totalCount > 0) {
				$errorRate = ($errorCount / $totalCount) * 100;
				if ($errorRate > 10) {
					$status = 'warning';
				}
				if ($errorRate > 25) {
					$status = 'critical';
				}
			}

			return [
				'status' => $status,
				'counts' => $counts
			];
		} catch (Exception $e) {
			return [
				'status' => 'warning',
				'message' => 'Sync status check failed: ' . $e->getMessage()
			];
		}
	}

	private function checkRecentErrors(?string $tenantId = null): array
	{
		try {
			$errorCount = $this->healthRepo->getRecentErrorCount($tenantId);

			$status = 'healthy';
			if ($errorCount > 50) {
				$status = 'critical';
			} elseif ($errorCount > 10) {
				$status = 'warning';
			}

			return [
				'status' => $status,
				'count_24h' => $errorCount
			];
		} catch (Exception $e) {
			return [
				'status' => 'warning',
				'message' => 'Error check failed: ' . $e->getMessage()
			];
		}
	}

	private function getSystemUptime(): string
	{
		try {
			$uptime = shell_exec('uptime -p');
			return trim($uptime ?: 'Unknown');
		} catch (Exception $e) {
			return 'Unknown';
		}
	}

	private function getPerformanceMetrics(): array
	{
		return [
			'memory_usage' => memory_get_usage(true),
			'peak_memory_usage' => memory_get_peak_usage(true),
			'cpu_load' => sys_getloadavg()
		];
	}

	private function getCronStatus(): array
	{
		$cronRunning = false;
		$cronOutput = shell_exec('ps aux | grep -v grep | grep cron');
		if ($cronOutput) {
			$cronRunning = true;
		}

		return [
			'running' => $cronRunning,
			'last_check' => date('Y-m-d H:i:s')
		];
	}
}
