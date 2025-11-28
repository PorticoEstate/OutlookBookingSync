<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\AlertService;
use PDO;
use Exception;

/**
 * AlertController exposes endpoints to run and inspect operational alerts.
 */
class AlertController
{
    private $alertService;

    /**
     * @param AlertService $alertService
     */
    public function __construct(AlertService $alertService)
    {
        $this->alertService = $alertService;
    }

    /**
     * Run alert checks.
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function runAlertChecks(Request $request, Response $response, $args)
    {
        try {
            $result = $this->alertService->checkAndAlert();

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
    * Get recent alerts.
    *
    * @param Request $request
    * @param Response $response
    * @param array $args
    * @return Response
     */
    public function getRecentAlerts(Request $request, Response $response, $args)
    {
        try {
            $queryParams = $request->getQueryParams();
            $hours = isset($queryParams['hours']) ? intval($queryParams['hours']) : 24;
            
            $result = $this->alertService->getRecentAlerts($hours);

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
    * Acknowledge an alert.
    *
    * @param Request $request
    * @param Response $response
    * @param array $args Must include id
    * @return Response
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

            $success = $this->alertService->acknowledgeAlert($alertId, $acknowledgedBy);

            if ($success) {
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
    * Clear old alerts.
    *
    * @param Request $request
    * @param Response $response
    * @param array $args
    * @return Response
     */
    public function clearOldAlerts(Request $request, Response $response, $args)
    {
        try {
            $queryParams = $request->getQueryParams();
            $days = isset($queryParams['days']) ? intval($queryParams['days']) : 7;
            
            $result = $this->alertService->clearOldAlerts($days);

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
    * Get alert statistics.
    *
    * @param Request $request
    * @param Response $response
    * @param array $args
    * @return Response
     */
    public function getAlertStats(Request $request, Response $response, $args)
    {
        try {
            $queryParams = $request->getQueryParams();
            $hours = isset($queryParams['hours']) ? intval($queryParams['hours']) : 24;

            $stats = $this->alertService->getAlertStats($hours);

            $response->getBody()->write(json_encode([
                'success' => true,
                'hours' => $stats['hours'],
                'summary' => $stats['summary'],
                'breakdown' => $stats['breakdown']
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
