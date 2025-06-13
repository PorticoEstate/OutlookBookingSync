<?php
namespace App\Services;

use PDO;
use Exception;

class AlertService
{
    private $db;
    private $logger;

    public function __construct(PDO $db, $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Check system health and trigger alerts if needed
     */
    public function checkAndAlert()
    {
        try {
            $alerts = [];
            
            // Check for high error rates
            $errorRateAlert = $this->checkErrorRate();
            if ($errorRateAlert) {
                $alerts[] = $errorRateAlert;
            }

            // Check for stalled sync operations
            $stalledSyncAlert = $this->checkStalledSyncs();
            if ($stalledSyncAlert) {
                $alerts[] = $stalledSyncAlert;
            }

            // Check for database connectivity issues
            $dbAlert = $this->checkDatabaseHealth();
            if ($dbAlert) {
                $alerts[] = $dbAlert;
            }

            // Check for missing cron job activity
            $cronAlert = $this->checkCronActivity();
            if ($cronAlert) {
                $alerts[] = $cronAlert;
            }

            // Process alerts
            foreach ($alerts as $alert) {
                $this->processAlert($alert);
            }

            return [
                'success' => true,
                'alerts_triggered' => count($alerts),
                'alerts' => $alerts
            ];

        } catch (Exception $e) {
            if ($this->logger) {
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
     * Check error rate over the last hour
     */
    private function checkErrorRate()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_operations,
                    COUNT(CASE WHEN sync_status = 'error' THEN 1 END) as error_count
                FROM outlook_calendar_mapping 
                WHERE updated_at > NOW() - INTERVAL '1 hour'
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $totalOps = $result['total_operations'];
            $errorCount = $result['error_count'];
            
            if ($totalOps > 0) {
                $errorRate = ($errorCount / $totalOps) * 100;
                
                if ($errorRate > 25) {
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
                } elseif ($errorRate > 10) {
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

        } catch (Exception $e) {
            throw new Exception("Error checking error rate: " . $e->getMessage());
        }
    }

    /**
     * Check for stalled sync operations
     */
    private function checkStalledSyncs()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as stalled_count
                FROM outlook_calendar_mapping 
                WHERE sync_status = 'pending' 
                AND created_at < NOW() - INTERVAL '2 hours'
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $stalledCount = $result['stalled_count'];
            
            if ($stalledCount > 10) {
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

        } catch (Exception $e) {
            throw new Exception("Error checking stalled syncs: " . $e->getMessage());
        }
    }

    /**
     * Check database health
     */
    private function checkDatabaseHealth()
    {
        try {
            $start = microtime(true);
            $stmt = $this->db->query('SELECT 1');
            $responseTime = (microtime(true) - $start) * 1000;

            if ($responseTime > 5000) { // 5 seconds
                return [
                    'type' => 'slow_database',
                    'severity' => 'critical',
                    'message' => "Database response time is critically slow: {$responseTime}ms",
                    'data' => [
                        'response_time_ms' => $responseTime
                    ]
                ];
            } elseif ($responseTime > 2000) { // 2 seconds
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

        } catch (Exception $e) {
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
     * Check cron job activity
     */
    private function checkCronActivity()
    {
        try {
            // Check for recent automated sync activity
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as recent_activity
                FROM outlook_calendar_mapping 
                WHERE updated_at > NOW() - INTERVAL '30 minutes'
                AND sync_direction IN ('polling', 'automated')
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $recentActivity = $result['recent_activity'];
            
            // If no automated activity in 30 minutes, something might be wrong
            if ($recentActivity == 0) {
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

        } catch (Exception $e) {
            throw new Exception("Error checking cron activity: " . $e->getMessage());
        }
    }

    /**
     * Process an individual alert
     */
    private function processAlert($alert)
    {
        try {
            // Log the alert
            if ($this->logger) {
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
            if ($alert['severity'] === 'critical') {
                $this->sendCriticalAlert($alert);
            } elseif ($alert['severity'] === 'warning') {
                $this->sendWarningAlert($alert);
            }

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Failed to process alert', [
                    'alert' => $alert,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Store alert in database
     */
    private function storeAlert($alert)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO outlook_sync_alerts (
                    alert_type, 
                    severity, 
                    message, 
                    alert_data, 
                    created_at
                ) VALUES (?, ?, ?, ?, NOW())
            ");

            $alertData = json_encode($alert['data'] ?? []);
            
            $stmt->execute([
                $alert['type'],
                $alert['severity'],
                $alert['message'],
                $alertData
            ]);

        } catch (Exception $e) {
            // Don't throw here to avoid alert processing loops
            if ($this->logger) {
                $this->logger->error('Failed to store alert in database', [
                    'error' => $e->getMessage(),
                    'alert' => $alert
                ]);
            }
        }
    }

    /**
     * Send critical alert notifications
     */
    private function sendCriticalAlert($alert)
    {
        // In a real implementation, you would send emails, Slack messages, etc.
        // For now, just log at critical level
        if ($this->logger) {
            $this->logger->critical("🚨 CRITICAL ALERT: " . $alert['message'], [
                'alert_type' => $alert['type'],
                'data' => $alert['data'] ?? []
            ]);
        }

        // Example: Send to webhook endpoint
        $this->sendWebhookNotification($alert, 'critical');
    }

    /**
     * Send warning alert notifications
     */
    private function sendWarningAlert($alert)
    {
        // In a real implementation, you would send less urgent notifications
        if ($this->logger) {
            $this->logger->warning("⚠️ WARNING: " . $alert['message'], [
                'alert_type' => $alert['type'],
                'data' => $alert['data'] ?? []
            ]);
        }

        // Example: Send to webhook endpoint
        $this->sendWebhookNotification($alert, 'warning');
    }

    /**
     * Send webhook notification
     */
    private function sendWebhookNotification($alert, $urgency)
    {
        $webhookUrl = $_ENV['ALERT_WEBHOOK_URL'] ?? null;
        
        if (!$webhookUrl) {
            return; // No webhook configured
        }

        try {
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
            
            if ($httpCode !== 200) {
                if ($this->logger) {
                    $this->logger->warning('Webhook notification failed', [
                        'url' => $webhookUrl,
                        'http_code' => $httpCode,
                        'response' => $response
                    ]);
                }
            }

            curl_close($ch);

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Webhook notification error', [
                    'error' => $e->getMessage(),
                    'alert' => $alert
                ]);
            }
        }
    }

    /**
     * Get recent alerts
     */
    public function getRecentAlerts($hours = 24)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    alert_type,
                    severity,
                    message,
                    alert_data,
                    created_at
                FROM outlook_sync_alerts 
                WHERE created_at > NOW() - INTERVAL '{$hours} hours'
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $stmt->execute();
            
            $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode JSON data
            foreach ($alerts as &$alert) {
                $alert['alert_data'] = json_decode($alert['alert_data'], true);
            }
            
            return [
                'success' => true,
                'alerts' => $alerts
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Clear old alerts
     */
    public function clearOldAlerts($days = 7)
    {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM outlook_sync_alerts 
                WHERE created_at < NOW() - INTERVAL '{$days} days'
            ");
            $stmt->execute();
            
            $deletedCount = $stmt->rowCount();
            
            return [
                'success' => true,
                'deleted_count' => $deletedCount
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
