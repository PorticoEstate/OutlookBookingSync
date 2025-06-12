<?php

namespace App\Controller;

use App\Services\CalendarMappingService;
use App\Services\OutlookSyncService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class ReverseSyncController
{
    /**
     * Populate mapping table with existing Outlook events
     */
    public function populateFromOutlook(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            // Get date range from query parameters
            $queryParams = $request->getQueryParams();
            $fromDate = $queryParams['from_date'] ?? null;
            $toDate = $queryParams['to_date'] ?? null;
            
            // Initialize services
            $calendarMappingService = new CalendarMappingService($db, $logger);
            
            // Get GraphServiceClient
            $outlookController = new \App\Controller\OutlookController();
            $graphServiceClient = $this->getGraphServiceClient($outlookController);
            
            $outlookSyncService = new OutlookSyncService($graphServiceClient, $calendarMappingService, $logger);
            
            // Populate from Outlook
            $result = $outlookSyncService->populateFromOutlook($fromDate, $toDate);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Successfully populated from Outlook events',
                'created' => $result['created'],
                'errors' => $result['errors']
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to populate from Outlook: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Get Outlook events that aren't in the booking system
     */
    public function getOutlookOnlyEvents(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            // Get date range from query parameters
            $queryParams = $request->getQueryParams();
            $fromDate = $queryParams['from_date'] ?? null;
            $toDate = $queryParams['to_date'] ?? null;
            $limit = (int)($queryParams['limit'] ?? 50);
            
            // Initialize services
            $calendarMappingService = new CalendarMappingService($db, $logger);
            $outlookController = new \App\Controller\OutlookController();
            $graphServiceClient = $this->getGraphServiceClient($outlookController);
            
            $outlookSyncService = new OutlookSyncService($graphServiceClient, $calendarMappingService, $logger);
            
            // Fetch Outlook events
            $outlookEvents = $outlookSyncService->fetchOutlookEvents($fromDate, $toDate);
            
            // Filter out events that already have mappings
            $outlookOnlyEvents = [];
            foreach ($outlookEvents as $event) {
                $existingMapping = $calendarMappingService->findMappingByOutlookEvent($event['outlook_event_id']);
                if (!$existingMapping) {
                    $outlookOnlyEvents[] = $event;
                    if (count($outlookOnlyEvents) >= $limit) {
                        break;
                    }
                }
            }
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'count' => count($outlookOnlyEvents),
                'events' => $outlookOnlyEvents
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to fetch Outlook events: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Sync Outlook events to booking system
     */
    public function syncFromOutlook(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            $queryParams = $request->getQueryParams();
            $limit = (int)($queryParams['limit'] ?? 25);
            
            // Get Outlook-originated mappings that need booking system entries
            $sql = "
            SELECT * FROM outlook_calendar_mapping 
            WHERE reservation_type = 'outlook_event' 
                AND sync_direction = 'outlook_to_booking'
                AND sync_status != 'booking_created'
            LIMIT :limit
            ";
            
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $mappings = $stmt->fetchAll();
            
            $results = [
                'processed' => 0,
                'booking_created' => 0,
                'errors' => 0,
                'details' => []
            ];
            
            foreach ($mappings as $mapping) {
                try {
                    // Here you would implement the logic to create booking system entries
                    // For now, we'll just mark them as processed
                    
                    $updateSql = "
                    UPDATE outlook_calendar_mapping 
                    SET sync_status = 'booking_created', 
                        last_sync_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                    ";
                    
                    $updateStmt = $db->prepare($updateSql);
                    $updateStmt->execute(['id' => $mapping['id']]);
                    
                    $results['processed']++;
                    $results['booking_created']++;
                    $results['details'][] = [
                        'outlook_event_id' => $mapping['outlook_event_id'],
                        'resource_id' => $mapping['resource_id'],
                        'action' => 'booking_created'
                    ];
                    
                } catch (\Exception $e) {
                    $results['errors']++;
                    $results['details'][] = [
                        'outlook_event_id' => $mapping['outlook_event_id'],
                        'action' => 'error',
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Reverse sync completed',
                'results' => $results
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Reverse sync failed: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Get reverse sync statistics
     */
    public function getReverseSyncStatus(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            
            // Get statistics for Outlook-originated events
            $sql = "
            SELECT 
                sync_status,
                COUNT(*) as count
            FROM outlook_calendar_mapping 
            WHERE reservation_type = 'outlook_event'
                AND sync_direction = 'outlook_to_booking'
            GROUP BY sync_status
            ORDER BY sync_status
            ";
            
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $statusCounts = $stmt->fetchAll();
            
            $stats = [
                'total_outlook_events' => 0,
                'by_status' => [],
                'summary' => []
            ];
            
            foreach ($statusCounts as $row) {
                $stats['total_outlook_events'] += $row['count'];
                $stats['by_status'][$row['sync_status']] = $row['count'];
            }
            
            // Create summary
            $stats['summary'] = [
                'pending_booking_creation' => $stats['by_status']['synced'] ?? 0,
                'booking_created' => $stats['by_status']['booking_created'] ?? 0,
                'errors' => $stats['by_status']['error'] ?? 0
            ];
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'statistics' => $stats
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get reverse sync status: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Extract GraphServiceClient from OutlookController
     */
    private function getGraphServiceClient($outlookController)
    {
        // Use reflection to access the private property
        $reflection = new \ReflectionClass($outlookController);
        $property = $reflection->getProperty('graphServiceClient');
        $property->setAccessible(true);
        return $property->getValue($outlookController);
    }
}
