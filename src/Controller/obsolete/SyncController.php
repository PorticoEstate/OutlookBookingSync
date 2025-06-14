<?php

namespace App\Controller;

use App\Services\CalendarMappingService;
use App\Services\OutlookSyncService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class SyncController
{
    /**
     * Sync pending items to Outlook
     */
    public function syncToOutlook(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            // Get limit from query parameters
            $limit = (int)($request->getQueryParams()['limit'] ?? 50);
            
            // Initialize services
            $calendarMappingService = new CalendarMappingService($db, $logger);
            
            // Get GraphServiceClient from OutlookController pattern
            $outlookController = new \App\Controller\OutlookController();
            $graphServiceClient = $this->getGraphServiceClient($outlookController);
            
            $outlookSyncService = new OutlookSyncService($graphServiceClient, $calendarMappingService, $logger);
            
            // Perform the sync
            $results = $outlookSyncService->syncPendingItems($limit);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Sync completed',
                'results' => $results
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Sync failed: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Sync a specific calendar item to Outlook
     */
    public function syncSpecificItem(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            // Get parameters
            $reservationType = $args['reservationType'] ?? null;
            $reservationId = $args['reservationId'] ?? null;
            $resourceId = $args['resourceId'] ?? null;
            
            if (!$reservationType || !$reservationId || !$resourceId) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Missing required parameters: reservationType, reservationId, resourceId'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            
            // Initialize services
            $calendarMappingService = new CalendarMappingService($db, $logger);
            
            // Get the mapping item
            $pendingItems = $calendarMappingService->getPendingSyncItems(1000); // Get more items to find specific one
            $targetItem = null;
            
            foreach ($pendingItems as $item) {
                if ($item['reservation_type'] === $reservationType &&
                    $item['reservation_id'] == $reservationId &&
                    $item['resource_id'] == $resourceId) {
                    $targetItem = $item;
                    break;
                }
            }
            
            if (!$targetItem) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Item not found or not pending sync'
                ]));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }
            
            // Get GraphServiceClient
            $outlookController = new \App\Controller\OutlookController();
            $graphServiceClient = $this->getGraphServiceClient($outlookController);
            
            $outlookSyncService = new OutlookSyncService($graphServiceClient, $calendarMappingService, $logger);
            
            // Sync the specific item
            $result = $outlookSyncService->syncItemToOutlook($targetItem);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Item synced successfully',
                'result' => $result
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Sync failed: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Get sync status/statistics
     */
    public function getSyncStatus(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            $calendarMappingService = new CalendarMappingService($db, $logger);
            
            // Get sync statistics
            $stats = $this->getSyncStatistics($db);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'statistics' => $stats
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get sync status: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Get Outlook events that aren't in the booking system
     */
    public function getOutlookEvents(Request $request, Response $response, $args)
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

    /**
     * Get sync statistics from database
     */
    private function getSyncStatistics($db)
    {
        $sql = "
        SELECT 
            reservation_type,
            sync_status,
            COUNT(*) as count
        FROM outlook_calendar_mapping 
        GROUP BY reservation_type, sync_status
        ORDER BY reservation_type, sync_status
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        $stats = [
            'total_mappings' => 0,
            'by_status' => [],
            'by_type' => [],
            'summary' => [
                'pending' => 0,
                'synced' => 0,
                'error' => 0,
                'conflict' => 0
            ]
        ];
        
        foreach ($results as $row) {
            $stats['total_mappings'] += $row['count'];
            $stats['by_status'][$row['sync_status']] = ($stats['by_status'][$row['sync_status']] ?? 0) + $row['count'];
            $stats['by_type'][$row['reservation_type']][$row['sync_status']] = $row['count'];
            
            if (isset($stats['summary'][$row['sync_status']])) {
                $stats['summary'][$row['sync_status']] += $row['count'];
            }
        }
        
        return $stats;
    }
}
