<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use Exception;

class HealthController
{
    private $db;
    private $logger;

    public function __construct(PDO $db, $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Comprehensive system health check
     */
    public function getSystemHealth(Request $request, Response $response, $args)
    {
        try {
            $health = [
                'timestamp' => date('Y-m-d H:i:s'),
                'status' => 'healthy',
                'uptime' => $this->getSystemUptime(),
                'checks' => [
                    'database' => $this->checkDatabase(),
                    'outlook_connectivity' => $this->checkOutlookConnectivity(),
                    'cron_jobs' => $this->checkCronJobs(),
                    'disk_space' => $this->checkDiskSpace(),
                    'memory_usage' => $this->checkMemoryUsage(),
                    'sync_status' => $this->checkSyncStatus(),
                    'recent_errors' => $this->checkRecentErrors()
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
     * Quick health check for load balancers
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
     * Get system monitoring dashboard data
     */
    public function getDashboardData(Request $request, Response $response, $args)
    {
        try {
            $dashboard = [
                'timestamp' => date('Y-m-d H:i:s'),
                'system_overview' => $this->getSystemOverview(),
                'sync_statistics' => $this->getAllSyncStatistics(),
                'recent_activity' => $this->getRecentActivity(),
                'performance_metrics' => $this->getPerformanceMetrics(),
                'error_summary' => $this->getErrorSummary(),
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
     * Check database connectivity and performance
     */
    private function checkDatabase()
    {
        try {
            $start = microtime(true);
            
            // Test basic connectivity
            $stmt = $this->db->query('SELECT 1');
            if (!$stmt) {
                return ['status' => 'critical', 'message' => 'Database connection failed'];
            }

            // Test read performance
            $stmt = $this->db->query('SELECT COUNT(*) as count FROM bridge_mappings');
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
     * Check Outlook connectivity
     */
    private function checkOutlookConnectivity()
    {
        try {
            // Check if Graph credentials are configured
            $clientId = $_ENV['OUTLOOK_CLIENT_ID'] ?? null;
            $clientSecret = $_ENV['GRAPH_CLIENT_SECRET'] ?? null;
            
            if (!$clientId || !$clientSecret) {
                return [
                    'status' => 'warning',
                    'message' => 'Graph API credentials not configured'
                ];
            }

            // Check recent sync activity as proxy for connectivity
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as recent_syncs 
                FROM bridge_mappings 
                WHERE updated_at > NOW() - INTERVAL '1 hour'
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $status = $result['recent_syncs'] > 0 ? 'healthy' : 'warning';
            $message = $result['recent_syncs'] > 0 ? 
                'Recent sync activity detected' : 
                'No recent sync activity - connectivity may be impaired';

            return [
                'status' => $status,
                'message' => $message,
                'recent_syncs' => $result['recent_syncs'],
                'credentials_configured' => true
            ];

        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'Outlook connectivity check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check cron job status
     */
    private function checkCronJobs()
    {
        try {
            // Check if cron daemon is running
            $cronRunning = false;
            $cronOutput = shell_exec('ps aux | grep -v grep | grep cron');
            if ($cronOutput) {
                $cronRunning = true;
            }

            // Check recent cron activity by looking at recent automated sync operations
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as recent_automated_syncs,
                    MAX(updated_at) as last_automated_sync
                FROM bridge_mappings 
                WHERE updated_at > NOW() - INTERVAL '1 hour'
                AND sync_direction IN ('polling', 'automated')
            ");
            $stmt->execute();
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
     * Check disk space
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
     * Check memory usage
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
     * Check sync status
     */
    private function checkSyncStatus()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    sync_status,
                    COUNT(*) as count
                FROM bridge_mappings 
                GROUP BY sync_status
            ");
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stats = [];
            $totalItems = 0;
            foreach ($results as $row) {
                $stats[$row['sync_status']] = $row['count'];
                $totalItems += $row['count'];
            }

            $errorCount = $stats['error'] ?? 0;
            $errorRate = $totalItems > 0 ? round(($errorCount / $totalItems) * 100, 2) : 0;

            $status = 'healthy';
            if ($errorRate > 10) {
                $status = 'critical';
            } elseif ($errorRate > 5) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'total_items' => $totalItems,
                'error_rate_percent' => $errorRate,
                'sync_breakdown' => $stats
            ];

        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'Sync status check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check recent errors
     */
    private function checkRecentErrors()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as error_count
                FROM bridge_mappings 
                WHERE sync_status = 'error' 
                AND updated_at > NOW() - INTERVAL '24 hours'
            ");
            $stmt->execute();
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
     * Get system uptime
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
     * Get system overview
     */
    private function getSystemOverview()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_mappings,
                    COUNT(CASE WHEN sync_status = 'synced' THEN 1 END) as synced_count,
                    COUNT(CASE WHEN sync_status = 'pending' THEN 1 END) as pending_count,
                    COUNT(CASE WHEN sync_status = 'error' THEN 1 END) as error_count
                FROM bridge_mappings
            ");
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get all sync statistics
     */
    private function getAllSyncStatistics()
    {
        try {
            // Get stats from the last 24 hours
            $stmt = $this->db->prepare("
                SELECT 
                    sync_direction,
                    sync_status,
                    COUNT(*) as count,
                    DATE_TRUNC('hour', updated_at) as hour
                FROM bridge_mappings 
                WHERE updated_at > NOW() - INTERVAL '24 hours'
                GROUP BY sync_direction, sync_status, DATE_TRUNC('hour', updated_at)
                ORDER BY hour DESC
            ");
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get recent activity
     */
    private function getRecentActivity()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    reservation_type,
                    sync_direction,
                    sync_status,
                    updated_at,
                    error_message
                FROM bridge_mappings 
                WHERE updated_at > NOW() - INTERVAL '2 hours'
                ORDER BY updated_at DESC
                LIMIT 20
            ");
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get performance metrics
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
     * Get error summary
     */
    private function getErrorSummary()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    error_message,
                    COUNT(*) as error_count,
                    MAX(updated_at) as last_occurrence
                FROM bridge_mappings 
                WHERE sync_status = 'error' 
                AND updated_at > NOW() - INTERVAL '24 hours'
                AND error_message IS NOT NULL
                GROUP BY error_message
                ORDER BY error_count DESC
                LIMIT 10
            ");
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get cron status
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
     * Get database connections
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
     * Get sync throughput
     */
    private function getSyncThroughput()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as syncs_last_hour,
                    COUNT(*) / 60.0 as syncs_per_minute
                FROM bridge_mappings 
                WHERE updated_at > NOW() - INTERVAL '1 hour'
            ");
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return ['syncs_last_hour' => 0, 'syncs_per_minute' => 0];
        }
    }

    /**
     * Convert memory limit string to bytes
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
}
