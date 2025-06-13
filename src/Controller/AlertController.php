<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\AlertService;
use PDO;
use Exception;

class AlertController
{
    private $db;
    private $logger;

    public function __construct(PDO $db, $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Run alert checks
     */
    public function runAlertChecks(Request $request, Response $response, $args)
    {
        try {
            $alertService = new AlertService($this->db, $this->logger);
            $result = $alertService->checkAndAlert();

            $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Alert check failed: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Get recent alerts
     */
    public function getRecentAlerts(Request $request, Response $response, $args)
    {
        try {
            $queryParams = $request->getQueryParams();
            $hours = isset($queryParams['hours']) ? intval($queryParams['hours']) : 24;
            
            $alertService = new AlertService($this->db, $this->logger);
            $result = $alertService->getRecentAlerts($hours);

            $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get alerts: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Acknowledge an alert
     */
    public function acknowledgeAlert(Request $request, Response $response, $args)
    {
        try {
            $alertId = $args['id'] ?? null;
            $body = json_decode($request->getBody()->getContents(), true);
            $acknowledgedBy = $body['acknowledged_by'] ?? 'system';

            if (!$alertId) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Alert ID is required'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $stmt = $this->db->prepare("
                UPDATE outlook_sync_alerts 
                SET acknowledged_at = NOW(), acknowledged_by = ?
                WHERE id = ? AND acknowledged_at IS NULL
            ");
            $stmt->execute([$acknowledgedBy, $alertId]);

            $affectedRows = $stmt->rowCount();

            if ($affectedRows > 0) {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'message' => 'Alert acknowledged successfully'
                ]));
            } else {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Alert not found or already acknowledged'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to acknowledge alert: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Clear old alerts
     */
    public function clearOldAlerts(Request $request, Response $response, $args)
    {
        try {
            $queryParams = $request->getQueryParams();
            $days = isset($queryParams['days']) ? intval($queryParams['days']) : 7;
            
            $alertService = new AlertService($this->db, $this->logger);
            $result = $alertService->clearOldAlerts($days);

            $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to clear alerts: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Get alert statistics
     */
    public function getAlertStats(Request $request, Response $response, $args)
    {
        try {
            $queryParams = $request->getQueryParams();
            $hours = isset($queryParams['hours']) ? intval($queryParams['hours']) : 24;

            $stmt = $this->db->prepare("
                SELECT 
                    severity,
                    alert_type,
                    COUNT(*) as count,
                    MAX(created_at) as latest_occurrence
                FROM outlook_sync_alerts 
                WHERE created_at > NOW() - INTERVAL '{$hours} hours'
                GROUP BY severity, alert_type
                ORDER BY severity DESC, count DESC
            ");
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get summary stats
            $summaryStmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_alerts,
                    COUNT(CASE WHEN severity = 'critical' THEN 1 END) as critical_alerts,
                    COUNT(CASE WHEN severity = 'warning' THEN 1 END) as warning_alerts,
                    COUNT(CASE WHEN acknowledged_at IS NOT NULL THEN 1 END) as acknowledged_alerts
                FROM outlook_sync_alerts 
                WHERE created_at > NOW() - INTERVAL '{$hours} hours'
            ");
            $summaryStmt->execute();
            $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

            $response->getBody()->write(json_encode([
                'success' => true,
                'hours' => $hours,
                'summary' => $summary,
                'breakdown' => $results
            ], JSON_PRETTY_PRINT));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get alert stats: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
