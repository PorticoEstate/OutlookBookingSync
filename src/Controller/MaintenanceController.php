<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use Exception;

class MaintenanceController
{
    private $db;
    private $logger;
    private $bridgeManager;

    public function __construct(PDO $db, $logger = null, $bridgeManager = null)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->bridgeManager = $bridgeManager;
    }

    /**
     * Cleanup old bridge sync logs using DB function cleanup_old_bridge_logs(days)
     */
    public function cleanupLogs(Request $request, Response $response, $args)
    {
        try {
            if (!$this->db) {
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

            if ($this->logger) {
                $this->logger->info('cleanup_old_bridge_logs executed', $payload);
            }

            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            if ($this->logger) {
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
     */
    public function renewSubscriptions(Request $request, Response $response, $args)
    {
        try {
            if (!$this->db) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Database connection not available'
                ]));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }

            $query = $request->getQueryParams();
            $bridge = $query['bridge'] ?? 'outlook';
            $minutes = isset($query['renew_before_minutes']) ? max(5, (int)$query['renew_before_minutes']) : 1440;
            $limit = isset($query['limit']) ? max(1, (int)$query['limit']) : 50;

            // Select active subscriptions expiring before the threshold
            $stmt = $this->db->prepare(
                "SELECT subscription_id, calendar_id, expires_at FROM bridge_subscriptions 
                 WHERE bridge_type = :bridge AND is_active = TRUE AND expires_at IS NOT NULL 
                 AND expires_at < (NOW() + (:minutes || ' minutes')::interval)
                 ORDER BY expires_at ASC
                 LIMIT :limit"
            );
            $stmt->bindValue(':bridge', $bridge, \PDO::PARAM_STR);
            $stmt->bindValue(':minutes', (string)$minutes, \PDO::PARAM_STR);
            $stmt->bindValue(':limit', (int)$limit, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $renewed = [];
            $failed = [];

            if (!empty($rows)) {
                if (!$this->bridgeManager) {
                    throw new Exception('BridgeManager not available');
                }
                $bridgeInstance = $this->bridgeManager->getBridge($bridge);

                foreach ($rows as $row) {
                    if (method_exists($bridgeInstance, 'renewSubscription')) {
                        $result = $bridgeInstance->renewSubscription($row['subscription_id']);
                        if (!empty($result['success'])) {
                            $renewed[] = $result;
                        } else {
                            $failed[] = $result;
                        }
                    } else {
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

            if ($this->logger) {
                $this->logger->info('renew_subscriptions executed', [
                    'bridge' => $bridge,
                    'checked' => count($rows),
                    'renewed_count' => count($renewed),
                    'failed_count' => count($failed),
                ]);
            }

            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            if ($this->logger) {
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
