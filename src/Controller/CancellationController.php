<?php

namespace App\Controller;

use App\Services\CancellationService;
use App\Services\CancellationDetectionService;
use App\Services\CalendarMappingService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class CancellationController
{
    /**
     * Cancel a booking system reservation and its corresponding Outlook event
     */
    public function cancelReservation(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            $reservationType = $args['reservationType'];
            $reservationId = (int)$args['reservationId'];
            $resourceId = (int)$args['resourceId'];
            
            // Initialize services
            $mappingService = new CalendarMappingService($db, $logger);
            $cancellationService = new CancellationService($db, $logger, $mappingService);
            
            // Handle the cancellation
            $result = $cancellationService->handleBookingSystemCancellation($reservationType, $reservationId, $resourceId);
            
            $statusCode = $result['success'] ? 200 : 500;
            
            $response->getBody()->write(json_encode([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Reservation cancelled successfully' : 'Cancellation failed',
                'results' => $result
            ]));
            
            return $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Cancellation failed: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Cancel an Outlook event and its corresponding booking system entry
     */
    public function cancelOutlookEvent(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            $outlookEventId = $args['outlookEventId'];
            
            // Initialize services
            $mappingService = new CalendarMappingService($db, $logger);
            $cancellationService = new CancellationService($db, $logger, $mappingService);
            
            // Handle the cancellation
            $result = $cancellationService->handleOutlookCancellation($outlookEventId);
            
            $statusCode = $result['success'] ? 200 : 500;
            
            $response->getBody()->write(json_encode([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Outlook event cancelled successfully' : 'Cancellation failed',
                'results' => $result
            ]));
            
            return $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Cancellation failed: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Process bulk cancellations from booking system
     */
    public function processBulkCancellations(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            $body = json_decode((string)$request->getBody(), true);
            
            if (!isset($body['reservations']) || !is_array($body['reservations'])) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Invalid request body. Expected "reservations" array.'
                ]));
                
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            
            // Initialize services
            $mappingService = new CalendarMappingService($db, $logger);
            $cancellationService = new CancellationService($db, $logger, $mappingService);
            
            // Process bulk cancellations
            $result = $cancellationService->processBulkCancellations($body['reservations']);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Bulk cancellations processed',
                'results' => $result
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Bulk cancellation failed: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Get cancellation statistics
     */
    public function getCancellationStats(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            // Initialize services
            $mappingService = new CalendarMappingService($db, $logger);
            $cancellationService = new CancellationService($db, $logger, $mappingService);
            
            $stats = $cancellationService->getCancellationStats();
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'statistics' => $stats
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get statistics: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Get all cancelled reservations
     */
    public function getCancelledReservations(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            $queryParams = $request->getQueryParams();
            $limit = (int)($queryParams['limit'] ?? 50);
            $direction = $queryParams['direction'] ?? null;
            
            $sql = "
                SELECT 
                    id,
                    reservation_type,
                    reservation_id,
                    resource_id,
                    outlook_event_id,
                    sync_direction,
                    created_at,
                    updated_at
                FROM outlook_calendar_mapping 
                WHERE sync_status = 'cancelled'
            ";
            
            $params = [];
            
            if ($direction && in_array($direction, ['booking_to_outlook', 'outlook_to_booking'])) {
                $sql .= " AND sync_direction = :direction";
                $params['direction'] = $direction;
            }
            
            $sql .= " ORDER BY updated_at DESC LIMIT :limit";
            $params['limit'] = $limit;
            
            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(":$key", $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->execute();
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'count' => count($results),
                'cancelled_reservations' => $results
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get cancelled reservations: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Detect and process newly cancelled reservations
     */
    public function detectCancellations(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            // Initialize services
            $calendarMappingService = new CalendarMappingService($db, $logger);
            $cancellationService = new CancellationService($db, $logger, $calendarMappingService);
            $detectionService = new CancellationDetectionService($db, $logger, $cancellationService);
            
            // Detect and process cancellations
            $results = $detectionService->detectAndProcessCancellations();
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Cancellation detection completed',
                'results' => $results
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Cancellation detection failed: ' . $e->getMessage()
            ]));

            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Check if specific reservation is cancelled
     */
    public function checkReservationStatus(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            $reservationType = $args['reservationType'];
            $reservationId = (int)$args['reservationId'];
            
            // Initialize services
            $calendarMappingService = new CalendarMappingService($db, $logger);
            $cancellationService = new CancellationService($db, $logger, $calendarMappingService);
            $detectionService = new CancellationDetectionService($db, $logger, $cancellationService);
            
            // Check reservation status
            $status = $detectionService->checkReservationCancellation($reservationType, $reservationId);
            
            if ($status === null) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Reservation not found'
                ]));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'reservation_status' => $status
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to check reservation status: ' . $e->getMessage()
            ]));

            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Get cancellation detection statistics
     */
    public function getDetectionStats(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            // Initialize services
            $calendarMappingService = new CalendarMappingService($db, $logger);
            $cancellationService = new CancellationService($db, $logger, $calendarMappingService);
            $detectionService = new CancellationDetectionService($db, $logger, $cancellationService);
            
            // Get detection statistics
            $stats = $detectionService->getDetectionStats();
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'detection_stats' => $stats
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get detection stats: ' . $e->getMessage()
            ]));

            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}
