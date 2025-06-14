<?php

namespace App\Controller;

use App\Services\CalendarMappingService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class SyncMappingController
{
    /**
     * Populate mapping table from existing calendar items
     */
    public function populateMapping(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            $calendarMappingService = new CalendarMappingService($db, $logger);
            
            $resourceId = $request->getQueryParams()['resource_id'] ?? null;
            
            $result = $calendarMappingService->populateMappingTable($resourceId);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Mapping table populated successfully',
                'created' => $result['created'],
                'errors' => $result['errors']
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to populate mapping table: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Get pending sync items
     */
    public function getPendingItems(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            $calendarMappingService = new CalendarMappingService($db, $logger);
            
            $limit = (int)($request->getQueryParams()['limit'] ?? 50);
            
            $pendingItems = $calendarMappingService->getPendingSyncItems($limit);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'count' => count($pendingItems),
                'items' => $pendingItems
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get pending items: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Clean up orphaned mappings
     */
    public function cleanupOrphaned(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            $calendarMappingService = new CalendarMappingService($db, $logger);
            
            $deletedCount = $calendarMappingService->cleanupOrphanedMappings();
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Cleanup completed successfully',
                'deleted_count' => $deletedCount
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to cleanup orphaned mappings: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Get mapping statistics
     */
    public function getStats(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            $calendarMappingService = new CalendarMappingService($db, $logger);
            
            // Get overall statistics
            $sql = "
            SELECT 
                reservation_type,
                sync_status,
                sync_direction,
                COUNT(*) as count
            FROM outlook_calendar_mapping 
            GROUP BY reservation_type, sync_status, sync_direction
            ORDER BY reservation_type, sync_status, sync_direction
            ";
            
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $results = $stmt->fetchAll();
            
            $stats = [
                'total_mappings' => 0,
                'by_type' => [],
                'by_status' => [],
                'by_direction' => [],
                'summary' => []
            ];
            
            foreach ($results as $row) {
                $count = (int)$row['count'];
                $type = $row['reservation_type'];
                $status = $row['sync_status'];
                $direction = $row['sync_direction'] ?: 'booking_to_outlook';
                
                $stats['total_mappings'] += $count;
                
                // By type
                if (!isset($stats['by_type'][$type])) {
                    $stats['by_type'][$type] = [];
                }
                $stats['by_type'][$type][$status] = $count;
                
                // By status
                if (!isset($stats['by_status'][$status])) {
                    $stats['by_status'][$status] = 0;
                }
                $stats['by_status'][$status] += $count;
                
                // By direction
                if (!isset($stats['by_direction'][$direction])) {
                    $stats['by_direction'][$direction] = 0;
                }
                $stats['by_direction'][$direction] += $count;
            }
            
            // Create summary
            $stats['summary'] = [
                'pending' => $stats['by_status']['pending'] ?? 0,
                'synced' => $stats['by_status']['synced'] ?? 0,
                'error' => $stats['by_status']['error'] ?? 0,
                'booking_to_outlook' => $stats['by_direction']['booking_to_outlook'] ?? 0,
                'outlook_to_booking' => $stats['by_direction']['outlook_to_booking'] ?? 0
            ];
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'statistics' => $stats
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get stats: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}
