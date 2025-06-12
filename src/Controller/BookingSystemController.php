<?php

namespace App\Controller;

use App\Services\BookingSystemService;
use App\Services\CalendarMappingService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class BookingSystemController
{
    /**
     * Process imported Outlook events and create booking system entries
     */
    public function processImportedEvents(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            // Get limit from query parameters
            $queryParams = $request->getQueryParams();
            $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : null;
            
            // Initialize services
            $calendarMappingService = new CalendarMappingService($db, $logger);
            $bookingSystemService = new BookingSystemService($db, $logger, $calendarMappingService);
            
            // Process imported events
            $results = $bookingSystemService->processImportedEvents($limit);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Successfully processed imported Outlook events',
                'results' => $results
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to process imported events: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Get statistics about imported event processing
     */
    public function getProcessingStats(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            // Initialize services  
            $calendarMappingService = new CalendarMappingService($db, $logger);
            $bookingSystemService = new BookingSystemService($db, $logger, $calendarMappingService);
            
            // Get processing statistics
            $stats = $bookingSystemService->getProcessingStats();
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'statistics' => $stats
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get processing stats: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Get details of imported events that are pending processing
     */
    public function getPendingImports(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            
            // Get query parameters
            $queryParams = $request->getQueryParams();
            $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;
            
            // Query for pending imported events
            $sql = "
                SELECT 
                    m.id,
                    m.outlook_event_id,
                    m.resource_id,
                    m.outlook_item_id,
                    m.sync_status,
                    m.sync_direction,
                    m.created_at,
                    r.name as resource_name
                FROM outlook_calendar_mapping m
                LEFT JOIN bb_resource r ON m.resource_id = r.id
                WHERE m.sync_status = 'imported' 
                    AND m.sync_direction = 'outlook_to_booking'
                    AND m.reservation_id IS NULL
                    AND m.reservation_type IS NULL
                ORDER BY m.created_at ASC
                LIMIT :limit
            ";
            
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'count' => count($events),
                'pending_imports' => $events
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get pending imports: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Get details of processed imported events (those converted to booking system entries)
     */
    public function getProcessedImports(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            
            // Get query parameters
            $queryParams = $request->getQueryParams();
            $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;
            
            // Query for processed imported events
            $sql = "
                SELECT 
                    m.id,
                    m.outlook_event_id,
                    m.resource_id,
                    m.reservation_type,
                    m.reservation_id,
                    m.sync_status,
                    m.sync_direction,
                    m.created_at,
                    m.updated_at,
                    r.name as resource_name
                FROM outlook_calendar_mapping m
                LEFT JOIN bb_resource r ON m.resource_id = r.id
                WHERE m.sync_direction = 'outlook_to_booking'
                    AND m.reservation_id IS NOT NULL
                    AND m.reservation_type IS NOT NULL
                ORDER BY m.updated_at DESC
                LIMIT :limit
            ";
            
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'count' => count($events),
                'processed_imports' => $events
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get processed imports: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}
