<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use Exception;

/**
 * HealthController exposes endpoints and helpers for system health, metrics, and monitoring.
 */
class HealthController
{
    private $db;
    private $logger;

    /**
     * @param PDO $db Database connection
     * @param mixed|null $logger PSR-3 logger (optional)
     */
    public function __construct(PDO $db, $logger = null)
    {
        $this->db = $db;
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
        $health = [
                'timestamp' => date('Y-m-d H:i:s'),
                'status' => 'healthy',
                'uptime' => $this->getSystemUptime(),
                'checks' => [
            'database' => $this->checkDatabase($request->getAttribute('tenant_id')),
            'cron_jobs' => $this->checkCronJobs($request->getAttribute('tenant_id')),
            'disk_space' => $this->checkDiskSpace(),
            'memory_usage' => $this->checkMemoryUsage(),
            'sync_status' => $this->checkSyncStatus($request->getAttribute('tenant_id')),
            'recent_errors' => $this->checkRecentErrors($request->getAttribute('tenant_id'))
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
            // Quick database ping
            $stmt = $this->db->query('SELECT 1');
            $dbHealthy = $stmt !== false;

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
                $dashboard = [
                'timestamp' => date('Y-m-d H:i:s'),
                'system_overview' => $this->getSystemOverview(),
                    'sync_statistics' => $this->getAllSyncStatistics($tenantId !== '' ? $tenantId : null),
                    'recent_activity' => $this->getRecentActivity($tenantId !== '' ? $tenantId : null),
                'performance_metrics' => $this->getPerformanceMetrics(),
                    'error_summary' => $this->getErrorSummary($tenantId !== '' ? $tenantId : null),
                'cron_status' => $this->getCronStatus()
            ];

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
     * Check database connectivity and performance.
     *
     * @param string|null $tenantId Tenant identifier for scoping counts (optional)
     * @return array{status:string,response_time_ms?:float,total_mappings?:int,active_queries?:int,warnings?:array,message?:string}
     */
    private function checkDatabase(?string $tenantId = null)
    {
        try {
            $start = microtime(true);
            
            // Test basic connectivity
            $stmt = $this->db->query('SELECT 1');
            if (!$stmt) {
                return ['status' => 'critical', 'message' => 'Database connection failed'];
            }

            // Test read performance
            $sql = 'SELECT COUNT(*) as count FROM bridge_mappings' . ($tenantId ? ' WHERE (tenant_id IS NOT DISTINCT FROM :tenant_id)' : '');
            $stmt = $this->db->prepare($sql);
            $params = [];
            if ($tenantId) { $params[':tenant_id'] = (string)$tenantId; }
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $responseTime = round((microtime(true) - $start) * 1000, 2);

            // Check for long-running queries
            $stmt = $this->db->query("
                SELECT COUNT(*) as active_queries 
                FROM pg_stat_activity 
                WHERE state = 'active' AND query_start < NOW() - INTERVAL '30 seconds'
            ");
            $activeQueries = $stmt->fetch(PDO::FETCH_ASSOC);

            $status = 'healthy';
            $warnings = [];

            if ($responseTime > 1000) {
                $status = 'warning';
                $warnings[] = 'Slow database response time';
            }

            if ($activeQueries['active_queries'] > 5) {
                $status = 'warning';
                $warnings[] = 'High number of active queries';
            }

            return [
                'status' => $status,
                'response_time_ms' => $responseTime,
                'total_mappings' => $result['count'],
                'active_queries' => $activeQueries['active_queries'],
                'warnings' => $warnings
            ];

        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'Database check failed: ' . $e->getMessage()
            ];
        }
    }


    /**
     * Check cron job status.
     *
     * @param string|null $tenantId Tenant identifier (optional)
     * @return array{status:string,cron_daemon_running?:bool,recent_automated_syncs?:int,last_automated_sync?:string,warnings?:array,message?:string}
     */
    private function checkCronJobs(?string $tenantId = null)
    {
        try {
            // Check if cron daemon is running
            $cronRunning = false;
            $cronOutput = shell_exec('ps aux | grep -v grep | grep cron');
            if ($cronOutput) {
                $cronRunning = true;
            }

            // Check recent cron activity by looking at recent automated sync operations
            $sql = "SELECT COUNT(*) as recent_automated_syncs, MAX(created_at) as last_automated_sync FROM bridge_sync_logs WHERE created_at > NOW() - INTERVAL '1 hour' AND operation IN ('sync', 'update')" . ($tenantId ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "");
            $stmt = $this->db->prepare($sql);
            $params = [];
            if ($tenantId) { $params[':tenant_id'] = (string)$tenantId; }
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $status = 'healthy';
            $warnings = [];

            if (!$cronRunning) {
                $status = 'critical';
                $warnings[] = 'Cron daemon not running';
            }

            if ($result['recent_automated_syncs'] == 0) {
                $status = 'warning';
                $warnings[] = 'No recent automated sync activity';
            }

            return [
                'status' => $status,
                'cron_daemon_running' => $cronRunning,
                'recent_automated_syncs' => $result['recent_automated_syncs'],
                'last_automated_sync' => $result['last_automated_sync'],
                'warnings' => $warnings
            ];

        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'Cron job check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check disk space.
     *
     * @return array{status:string,usage_percent?:float,free_space_gb?:float,total_space_gb?:float,message?:string}
     */
    private function checkDiskSpace()
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

    /**
     * Check memory usage.
     *
     * @return array{status:string,usage_percent?:float,current_usage_mb?:float,memory_limit?:string,message?:string}
     */
    private function checkMemoryUsage()
    {
        try {
            $memoryUsage = memory_get_usage(true);
            $memoryLimit = ini_get('memory_limit');
            
            // Convert memory limit to bytes
            $memoryLimitBytes = $this->convertToBytes($memoryLimit);
            $usagePercent = round(($memoryUsage / $memoryLimitBytes) * 100, 2);

            $status = 'healthy';
            if ($usagePercent > 90) {
                $status = 'critical';
            } elseif ($usagePercent > 80) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'usage_percent' => $usagePercent,
                'current_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                'memory_limit' => $memoryLimit
            ];

        } catch (Exception $e) {
            return [
                'status' => 'warning',
                'message' => 'Memory usage check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check sync status with detailed breakdown.
     *
     * @param string|null $tenantId Tenant identifier (optional)
     * @return array
     */
    private function checkSyncStatus(?string $tenantId = null)
    {
        try {
            // Get sync status breakdown
            $sql = "SELECT sync_status, COUNT(*) as count, MAX(updated_at) as last_updated FROM bridge_mappings" . ($tenantId ? " WHERE (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "") . " GROUP BY sync_status";
            $stmt = $this->db->prepare($sql);
            $params = [];
            if ($tenantId) { $params[':tenant_id'] = (string)$tenantId; }
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stats = [];
            $totalItems = 0;
            $lastActivity = null;
            
            foreach ($results as $row) {
                $stats[$row['sync_status']] = [
                    'count' => $row['count'],
                    'last_updated' => $row['last_updated']
                ];
                $totalItems += $row['count'];
                
                if ($row['last_updated'] && (!$lastActivity || $row['last_updated'] > $lastActivity)) {
                    $lastActivity = $row['last_updated'];
                }
            }

            $errorCount = $stats['error']['count'] ?? 0;
            $pendingCount = $stats['pending']['count'] ?? 0;
            $syncedCount = $stats['synced']['count'] ?? 0;
            $cancelledCount = $stats['cancelled']['count'] ?? 0;
            
            $errorRate = $totalItems > 0 ? round(($errorCount / $totalItems) * 100, 2) : 0;
            $pendingRate = $totalItems > 0 ? round(($pendingCount / $totalItems) * 100, 2) : 0;

            // Determine overall sync health
            $status = 'healthy';
            $issues = [];
            
            if ($errorRate > 10) {
                $status = 'critical';
                $issues[] = "High error rate: {$errorRate}%";
            } elseif ($errorRate > 5) {
                $status = 'warning';
                $issues[] = "Elevated error rate: {$errorRate}%";
            }
            
            if ($pendingRate > 20) {
                $status = ($status === 'critical') ? 'critical' : 'warning';
                $issues[] = "High pending rate: {$pendingRate}%";
            }
            
            // Check for stuck syncs (pending items older than 1 hour)
            $sql2 = "SELECT COUNT(*) as stuck_count FROM bridge_mappings WHERE sync_status = 'pending' AND updated_at < NOW() - INTERVAL '1 hour'" . ($tenantId ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "");
            $stuckStmt = $this->db->prepare($sql2);
            $params2 = [];
            if ($tenantId) { $params2[':tenant_id'] = (string)$tenantId; }
            $stuckStmt->execute($params2);
            $stuckResult = $stuckStmt->fetch(PDO::FETCH_ASSOC);
            $stuckCount = $stuckResult['stuck_count'];
            
            if ($stuckCount > 0) {
                $status = ($status === 'critical') ? 'critical' : 'warning';
                $issues[] = "Stuck syncs detected: {$stuckCount}";
            }

            return [
                'status' => $status,
                'total_items' => $totalItems,
                'error_rate_percent' => $errorRate,
                'pending_rate_percent' => $pendingRate,
                'last_activity' => $lastActivity,
                'stuck_syncs' => $stuckCount,
                'issues' => $issues,
                'breakdown' => [
                    'synced' => $syncedCount,
                    'pending' => $pendingCount,
                    'error' => $errorCount,
                    'cancelled' => $cancelledCount
                ],
                'detailed_stats' => $stats
            ];

        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'Sync status check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check recent errors.
     *
     * @param string|null $tenantId Tenant identifier (optional)
     * @return array
     */
    private function checkRecentErrors(?string $tenantId = null)
    {
        try {
            $sql = "SELECT COUNT(*) as error_count FROM bridge_sync_logs WHERE status = 'error' AND created_at > NOW() - INTERVAL '24 hours'" . ($tenantId ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "");
            $stmt = $this->db->prepare($sql);
            $params = [];
            if ($tenantId) { $params[':tenant_id'] = (string)$tenantId; }
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $errorCount = $result['error_count'];
            
            $status = 'healthy';
            if ($errorCount > 50) {
                $status = 'critical';
            } elseif ($errorCount > 10) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'errors_last_24h' => $errorCount,
                'message' => $errorCount > 0 ? 
                    "$errorCount errors in the last 24 hours" : 
                    'No recent errors'
            ];

        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'Recent errors check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get system uptime.
     *
     * @return string
     */
    private function getSystemUptime()
    {
        try {
            $uptime = shell_exec('uptime -p');
            return trim($uptime) ?: 'Unknown';
        } catch (Exception $e) {
            return 'Unknown';
        }
    }

    /**
     * Get system overview.
     *
     * @return array
     */
    private function getSystemOverview()
    {
        try {
            // Get total mappings
            $stmt = $this->db->prepare("SELECT COUNT(*) as total_mappings FROM bridge_mappings");
            $stmt->execute();
            $totalResult = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get synced count (mappings with last_synced_at and recent successful sync)
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT bm.id) as synced_count
                FROM bridge_mappings bm
                WHERE bm.last_synced_at IS NOT NULL
                AND EXISTS (
                    SELECT 1 FROM bridge_sync_logs bsl 
                    WHERE (bsl.source_bridge = bm.source_bridge AND bsl.target_bridge = bm.target_bridge)
                    AND bsl.status = 'success' 
                    AND bsl.created_at > NOW() - INTERVAL '24 hours'
                )
            ");
            $stmt->execute();
            $syncedResult = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get error count (mappings with recent error status)
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT bm.id) as error_count
                FROM bridge_mappings bm
                WHERE EXISTS (
                    SELECT 1 FROM bridge_sync_logs bsl 
                    WHERE (bsl.source_bridge = bm.source_bridge AND bsl.target_bridge = bm.target_bridge)
                    AND bsl.status = 'error' 
                    AND bsl.created_at > NOW() - INTERVAL '24 hours'
                    AND NOT EXISTS (
                        SELECT 1 FROM bridge_sync_logs bsl2 
                        WHERE (bsl2.source_bridge = bm.source_bridge AND bsl2.target_bridge = bm.target_bridge)
                        AND bsl2.status = 'success' 
                        AND bsl2.created_at > bsl.created_at
                    )
                )
            ");
            $stmt->execute();
            $errorResult = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get pending count (mappings with recent pending status or never synced)
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT bm.id) as pending_count
                FROM bridge_mappings bm
                WHERE (
                    bm.last_synced_at IS NULL
                    OR EXISTS (
                        SELECT 1 FROM bridge_sync_logs bsl 
                        WHERE (bsl.source_bridge = bm.source_bridge AND bsl.target_bridge = bm.target_bridge)
                        AND bsl.status = 'pending' 
                        AND bsl.created_at > NOW() - INTERVAL '1 hour'
                    )
                )
                AND NOT EXISTS (
                    SELECT 1 FROM bridge_sync_logs bsl 
                    WHERE (bsl.source_bridge = bm.source_bridge AND bsl.target_bridge = bm.target_bridge)
                    AND bsl.status = 'error' 
                    AND bsl.created_at > NOW() - INTERVAL '24 hours'
                )
            ");
            $stmt->execute();
            $pendingResult = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'total_mappings' => $totalResult['total_mappings'],
                'synced_count' => $syncedResult['synced_count'],
                'pending_count' => $pendingResult['pending_count'],
                'error_count' => $errorResult['error_count']
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get all sync statistics.
     *
     * @param string|null $tenantId Tenant identifier (optional)
     * @return array
     */
    private function getAllSyncStatistics(?string $tenantId = null)
    {
        try {
            // Get stats from the last 24 hours from sync logs
            $sql = "SELECT source_bridge, target_bridge, operation, status, COUNT(*) as count, DATE_TRUNC('hour', created_at) as hour FROM bridge_sync_logs WHERE created_at > NOW() - INTERVAL '24 hours'" . ($tenantId ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "") . " GROUP BY source_bridge, target_bridge, operation, status, DATE_TRUNC('hour', created_at) ORDER BY hour DESC";
            $stmt = $this->db->prepare($sql);
            $params = [];
            if ($tenantId) { $params[':tenant_id'] = (string)$tenantId; }
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get recent activity.
     *
     * @param string|null $tenantId Tenant identifier (optional)
     * @return array
     */
    private function getRecentActivity(?string $tenantId = null)
    {
        try {
            $sql = "SELECT operation as reservation_type, CONCAT(source_bridge, ' → ', target_bridge) as sync_direction, status as sync_status, created_at as updated_at, error_message FROM bridge_sync_logs WHERE created_at > NOW() - INTERVAL '2 hours'" . ($tenantId ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "") . " ORDER BY created_at DESC LIMIT 20";
            $stmt = $this->db->prepare($sql);
            $params = [];
            if ($tenantId) { $params[':tenant_id'] = (string)$tenantId; }
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get performance metrics.
     *
     * @return array
     */
    private function getPerformanceMetrics()
    {
        try {
            return [
                'memory_usage' => [
                    'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
                ],
                'database_connections' => $this->getDatabaseConnections(),
                'sync_throughput' => $this->getSyncThroughput()
            ];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get error summary.
     *
     * @param string|null $tenantId Tenant identifier (optional)
     * @return array
     */
    private function getErrorSummary(?string $tenantId = null)
    {
        try {
            $sql = "SELECT error_message, COUNT(*) as error_count, MAX(created_at) as last_occurrence FROM bridge_sync_logs WHERE status = 'error' AND created_at > NOW() - INTERVAL '24 hours' AND error_message IS NOT NULL" . ($tenantId ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "") . " GROUP BY error_message ORDER BY error_count DESC LIMIT 10";
            $stmt = $this->db->prepare($sql);
            $params = [];
            if ($tenantId) { $params[':tenant_id'] = (string)$tenantId; }
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get cron status.
     *
     * @return array
     */
    private function getCronStatus()
    {
        try {
            $cronJobs = [
                'polling_changes' => ['schedule' => '*/15 * * * *', 'endpoint' => '/polling/poll-changes'],
                'detect_missing' => ['schedule' => '0 * * * *', 'endpoint' => '/polling/detect-missing-events'],
                'cancellation_detection' => ['schedule' => '*/10 * * * *', 'endpoint' => '/cancel/detect-and-process'],
                'daily_stats' => ['schedule' => '0 8 * * *', 'endpoint' => '/polling/stats']
            ];

            // Check if cron daemon is running
            $cronRunning = !empty(shell_exec('ps aux | grep -v grep | grep cron'));

            return [
                'cron_daemon_running' => $cronRunning,
                'configured_jobs' => $cronJobs,
                'last_check' => date('Y-m-d H:i:s')
            ];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get database connections.
     *
     * @return int Active database connections count
     */
    private function getDatabaseConnections()
    {
        try {
            $stmt = $this->db->query("
                SELECT COUNT(*) as active_connections 
                FROM pg_stat_activity 
                WHERE state = 'active'
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['active_connections'];
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get sync throughput.
     *
     * @return array{syncs_last_hour:int,syncs_per_minute:float}
     */
    private function getSyncThroughput()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as syncs_last_hour,
                    COUNT(*) / 60.0 as syncs_per_minute
                FROM bridge_sync_logs 
                WHERE created_at > NOW() - INTERVAL '1 hour'
            ");
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return ['syncs_last_hour' => 0, 'syncs_per_minute' => 0];
        }
    }

    /**
     * Convert memory limit string to bytes.
     *
     * @param string $memoryLimit e.g., 512M, 2G
     * @return int Bytes
     */
    private function convertToBytes($memoryLimit)
    {
        $memoryLimit = trim($memoryLimit);
        $last = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $memoryLimit = (int) $memoryLimit;
        
        switch ($last) {
            case 'g':
                $memoryLimit *= 1024;
            case 'm':
                $memoryLimit *= 1024;
            case 'k':
                $memoryLimit *= 1024;
        }
        
        return $memoryLimit;
    }

    /**
     * Get detailed sync status for monitoring.
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function getSyncStatusDetails(Request $request, Response $response, $args)
    {
        try {
            $syncDetails = [
                'timestamp' => date('Y-m-d H:i:s'),
                'overall_sync_health' => $this->checkSyncStatus(),
                'bridge_sync_stats' => $this->getBridgeSyncStats(),
                'retry_analysis' => $this->getRetryAnalysis(),
                'cancellation_stats' => $this->getCancellationStats(),
                'sync_performance' => $this->getSyncPerformanceMetrics()
            ];

            $response->getBody()->write(json_encode([
                'success' => true,
                'sync_status' => $syncDetails
            ], JSON_PRETTY_PRINT));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Sync status details retrieval failed: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
    
    /**
     * Re-enable failed events endpoint.
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function reEnableFailedEvents(Request $request, Response $response, $args)
    {
        try {
            $body = json_decode($request->getBody()->getContents(), true) ?? [];
            $bridgeName = $body['bridge_name'] ?? null;
            $eventIds = $body['event_ids'] ?? [];
            
            // Re-enable failed events via BridgeManager
            $sql = "
                UPDATE bridge_mappings 
                SET sync_status = 'pending', 
                    retry_count = 0, 
                    error_message = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE sync_status = 'error'
            ";
            
            $params = [];
            
            if ($bridgeName) {
                $sql .= " AND (source_bridge = ? OR target_bridge = ?)";
                $params = [$bridgeName, $bridgeName];
            }
            
            if (!empty($eventIds)) {
                $placeholders = str_repeat('?,', count($eventIds) - 1) . '?';
                $sql .= " AND (source_event_id IN ($placeholders) OR target_event_id IN ($placeholders))";
                $params = array_merge($params, $eventIds, $eventIds);
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $reEnabledCount = $stmt->rowCount();
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => "Re-enabled {$reEnabledCount} failed events",
                're_enabled_count' => $reEnabledCount,
                'bridge_name' => $bridgeName,
                'event_ids_filter' => $eventIds
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to re-enable failed events: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
    
    /**
     * Get queue statistics for dashboard monitoring.
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function getQueueStats(Request $request, Response $response, $args)
    {
        try {
            $tenantId = $request->getAttribute('tenant_id');
            
            $queueStats = [
                'timestamp' => date('Y-m-d H:i:s'),
                'webhook_queue' => $this->getWebhookQueueStats($tenantId),
                'deletion_queue' => $this->getDeletionQueueStats($tenantId),
                'processing_health' => $this->getQueueProcessingHealth($tenantId),
                'queue_metrics' => $this->getQueueMetrics($tenantId)
            ];
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $queueStats
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get queue statistics: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
    
    /**
     * Get bridge-specific sync stats.
     *
     * @return array
     */
    private function getBridgeSyncStats()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    source_bridge,
                    target_bridge,
                    sync_status,
                    COUNT(*) as count,
                    AVG(retry_count) as avg_retries,
                    MAX(updated_at) as last_activity
                FROM bridge_mappings 
                GROUP BY source_bridge, target_bridge, sync_status
                ORDER BY source_bridge, target_bridge, sync_status
            ");
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stats = [];
            foreach ($results as $row) {
                $bridgePair = $row['source_bridge'] . ' -> ' . $row['target_bridge'];
                if (!isset($stats[$bridgePair])) {
                    $stats[$bridgePair] = [];
                }
                $stats[$bridgePair][$row['sync_status']] = [
                    'count' => $row['count'],
                    'avg_retries' => round($row['avg_retries'], 2),
                    'last_activity' => $row['last_activity']
                ];
            }
            
            return $stats;
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Get retry analysis.
     *
     * @return array
     */
    private function getRetryAnalysis()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    retry_count,
                    sync_status,
                    COUNT(*) as count
                FROM bridge_mappings 
                WHERE retry_count > 0
                GROUP BY retry_count, sync_status
                ORDER BY retry_count, sync_status
            ");
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $analysis = [
                'total_with_retries' => 0,
                'max_retry_count' => 0,
                'retry_breakdown' => []
            ];
            
            foreach ($results as $row) {
                $analysis['total_with_retries'] += $row['count'];
                $analysis['max_retry_count'] = max($analysis['max_retry_count'], $row['retry_count']);
                
                if (!isset($analysis['retry_breakdown'][$row['retry_count']])) {
                    $analysis['retry_breakdown'][$row['retry_count']] = [];
                }
                $analysis['retry_breakdown'][$row['retry_count']][$row['sync_status']] = $row['count'];
            }
            
            return $analysis;
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Get cancellation statistics.
     *
     * @return array
     */
    private function getCancellationStats()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    source_bridge,
                    target_bridge,
                    COUNT(*) as cancelled_count,
                    MAX(updated_at) as last_cancellation
                FROM bridge_mappings 
                WHERE sync_status = 'cancelled'
                GROUP BY source_bridge, target_bridge
                ORDER BY cancelled_count DESC
            ");
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'total_cancelled' => array_sum(array_column($results, 'cancelled_count')),
                'by_bridge_pair' => $results
            ];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Get sync performance metrics.
     *
     * @param string|null $tenantId Tenant identifier (optional)
     * @return array
     */
    private function getSyncPerformanceMetrics(?string $tenantId = null)
    {
        try {
            $sql = "SELECT DATE(updated_at) as sync_date, sync_status, COUNT(*) as count FROM bridge_mappings WHERE updated_at > NOW() - INTERVAL '7 days'" . ($tenantId ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "") . " GROUP BY DATE(updated_at), sync_status ORDER BY sync_date DESC, sync_status";
            $stmt = $this->db->prepare($sql);
            $params = [];
            if ($tenantId) { $params[':tenant_id'] = (string)$tenantId; }
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $metrics = [
                'daily_sync_activity' => [],
                'status_trends' => []
            ];
            
            foreach ($results as $row) {
                $date = $row['sync_date'];
                if (!isset($metrics['daily_sync_activity'][$date])) {
                    $metrics['daily_sync_activity'][$date] = [];
                }
                $metrics['daily_sync_activity'][$date][$row['sync_status']] = $row['count'];
                
                if (!isset($metrics['status_trends'][$row['sync_status']])) {
                    $metrics['status_trends'][$row['sync_status']] = 0;
                }
                $metrics['status_trends'][$row['sync_status']] += $row['count'];
            }
            
            return $metrics;
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Get webhook queue statistics.
     *
     * @param string|null $tenantId Tenant identifier
     * @return array
     */
    private function getWebhookQueueStats($tenantId)
    {
        try {
            $sql = "
                SELECT 
                    status,
                    COUNT(*) as count,
                    AVG(attempts) as avg_attempts,
                    MIN(created_at) as oldest_item,
                    MAX(created_at) as newest_item
                FROM bridge_queue 
                WHERE queue_type = 'bridge_sync'
            ";
            
            $params = [];
            if ($tenantId) {
                $sql .= " AND tenant_id = ?";
                $params[] = $tenantId;
            }
            
            $sql .= " GROUP BY status ORDER BY status";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $statusStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total pending count
            $sql = "SELECT COUNT(*) as pending_count FROM bridge_queue WHERE queue_type = 'bridge_sync' AND status = 'pending'";
            if ($tenantId) {
                $sql .= " AND tenant_id = ?";
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($tenantId ? [$tenantId] : []);
            $pendingCount = $stmt->fetchColumn();
            
            return [
                'pending_count' => (int)$pendingCount,
                'status_breakdown' => $statusStats,
                'health_status' => $pendingCount > 100 ? 'warning' : ($pendingCount > 500 ? 'critical' : 'healthy')
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Get deletion queue statistics.
     *
     * @param string|null $tenantId Tenant identifier
     * @return array
     */
    private function getDeletionQueueStats($tenantId)
    {
        try {
            $sql = "
                SELECT 
                    status,
                    COUNT(*) as count,
                    AVG(attempts) as avg_attempts,
                    MIN(created_at) as oldest_item,
                    MAX(created_at) as newest_item
                FROM bridge_queue 
                WHERE queue_type = 'deletion_check'
            ";
            
            $params = [];
            if ($tenantId) {
                $sql .= " AND tenant_id = ?";
                $params[] = $tenantId;
            }
            
            $sql .= " GROUP BY status ORDER BY status";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $statusStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total pending count
            $sql = "SELECT COUNT(*) as pending_count FROM bridge_queue WHERE queue_type = 'deletion_check' AND status = 'pending'";
            if ($tenantId) {
                $sql .= " AND tenant_id = ?";
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($tenantId ? [$tenantId] : []);
            $pendingCount = $stmt->fetchColumn();
            
            return [
                'pending_count' => (int)$pendingCount,
                'status_breakdown' => $statusStats,
                'health_status' => $pendingCount > 50 ? 'warning' : ($pendingCount > 200 ? 'critical' : 'healthy')
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Get queue processing health metrics.
     *
     * @param string|null $tenantId Tenant identifier
     * @return array
     */
    private function getQueueProcessingHealth($tenantId)
    {
        try {
            // Check for stuck/old pending items
            $sql = "
                SELECT 
                    queue_type,
                    COUNT(*) as stuck_count
                FROM bridge_queue 
                WHERE status = 'pending' 
                AND created_at < NOW() - INTERVAL '1 HOUR'
            ";
            
            $params = [];
            if ($tenantId) {
                $sql .= " AND tenant_id = ?";
                $params[] = $tenantId;
            }
            
            $sql .= " GROUP BY queue_type";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $stuckItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Check processing rate (items processed in last hour)
            $sql = "
                SELECT 
                    queue_type,
                    COUNT(*) as processed_count
                FROM bridge_queue 
                WHERE status IN ('completed', 'failed')
                AND processed_at > NOW() - INTERVAL '1 HOUR'
            ";
            
            if ($tenantId) {
                $sql .= " AND tenant_id = ?";
            }
            
            $sql .= " GROUP BY queue_type";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($tenantId ? [$tenantId] : []);
            $recentProcessed = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'stuck_items' => $stuckItems,
                'processing_rate_last_hour' => $recentProcessed,
                'health_status' => count($stuckItems) > 0 ? 'warning' : 'healthy'
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Get general queue metrics.
     *
     * @param string|null $tenantId Tenant identifier
     * @return array
     */
    private function getQueueMetrics($tenantId)
    {
        try {
            // Get overall queue statistics
            $sql = "
                SELECT 
                    queue_type,
                    status,
                    COUNT(*) as count,
                    AVG(attempts) as avg_attempts,
                    MAX(attempts) as max_attempts
                FROM bridge_queue
            ";
            
            $params = [];
            if ($tenantId) {
                $sql .= " WHERE tenant_id = ?";
                $params[] = $tenantId;
            }
            
            $sql .= " GROUP BY queue_type, status ORDER BY queue_type, status";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get average processing time for completed items
            $sql = "
                SELECT 
                    queue_type,
                    AVG(EXTRACT(EPOCH FROM (processed_at - created_at))) as avg_processing_time_seconds
                FROM bridge_queue 
                WHERE status = 'completed' 
                AND processed_at IS NOT NULL
            ";
            
            if ($tenantId) {
                $sql .= " AND tenant_id = ?";
            }
            
            $sql .= " GROUP BY queue_type";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($tenantId ? [$tenantId] : []);
            $processingTimes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'queue_breakdown' => $metrics,
                'average_processing_times' => $processingTimes
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
