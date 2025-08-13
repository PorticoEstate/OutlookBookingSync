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

    public function __construct(PDO $db = null, $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
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
}
