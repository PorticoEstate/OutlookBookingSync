<?php

namespace App\Controller;

use App\Services\OutlookEventDetectionService;
use App\Services\OutlookWebhookService;
use App\Services\CalendarMappingService;
use App\Services\CancellationService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class OutlookDetectionController
{
    /**
     * Detect changes in Outlook events (polling-based detection)
     */
    public function detectOutlookChanges(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            // Initialize services
            $mappingService = new CalendarMappingService($db, $logger);
            $cancellationService = new CancellationService($db, $logger, $mappingService);
            
            // Get GraphServiceClient from OutlookController
            $outlookController = new \App\Controller\OutlookController();
            $reflection = new \ReflectionClass($outlookController);
            $property = $reflection->getProperty('graphServiceClient');
            $property->setAccessible(true);
            $graphServiceClient = $property->getValue($outlookController);
            
            $detectionService = new OutlookEventDetectionService(
                $db, 
                $logger, 
                $graphServiceClient,
                $mappingService,
                $cancellationService
            );
            
            $result = $detectionService->detectOutlookEventChanges();
            
            $statusCode = $result['success'] ? 200 : 500;
            
            $response->getBody()->write(json_encode([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Outlook change detection completed' : 'Some changes could not be processed',
                'detected_changes' => $result['detected_changes'],
                'deleted_events' => $result['deleted_events'],
                'processed_deletions' => $result['processed_deletions'],
                'errors' => $result['errors']
            ]));
            
            return $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to detect Outlook changes: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Detect new Outlook events that might need to be imported
     */
    public function detectNewOutlookEvents(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            // Get date range from query parameters
            $queryParams = $request->getQueryParams();
            $fromDate = $queryParams['from_date'] ?? null;
            $toDate = $queryParams['to_date'] ?? null;
            
            // Initialize services
            $mappingService = new CalendarMappingService($db, $logger);
            $cancellationService = new CancellationService($db, $logger, $mappingService);
            
            // Get GraphServiceClient from OutlookController
            $outlookController = new \App\Controller\OutlookController();
            $reflection = new \ReflectionClass($outlookController);
            $property = $reflection->getProperty('graphServiceClient');
            $property->setAccessible(true);
            $graphServiceClient = $property->getValue($outlookController);
            
            $detectionService = new OutlookEventDetectionService(
                $db, 
                $logger, 
                $graphServiceClient,
                $mappingService,
                $cancellationService
            );
            
            $result = $detectionService->detectNewOutlookEvents($fromDate, $toDate);
            
            $response->getBody()->write(json_encode([
                'success' => $result['success'],
                'message' => 'New Outlook event detection completed',
                'new_events_found' => $result['new_events_found'],
                'events' => $result['events'],
                'errors' => $result['errors']
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to detect new Outlook events: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Get detection statistics
     */
    public function getDetectionStats(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            // Initialize services
            $mappingService = new CalendarMappingService($db, $logger);
            $cancellationService = new CancellationService($db, $logger, $mappingService);
            
            // Get GraphServiceClient from OutlookController
            $outlookController = new \App\Controller\OutlookController();
            $reflection = new \ReflectionClass($outlookController);
            $property = $reflection->getProperty('graphServiceClient');
            $property->setAccessible(true);
            $graphServiceClient = $property->getValue($outlookController);
            
            $detectionService = new OutlookEventDetectionService(
                $db, 
                $logger, 
                $graphServiceClient,
                $mappingService,
                $cancellationService
            );
            
            $result = $detectionService->getDetectionStats();
            
            $response->getBody()->write(json_encode($result));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get detection statistics: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Clean up old event change logs
     */
    public function cleanupEventLogs(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            // Get days to keep from query parameters
            $queryParams = $request->getQueryParams();
            $daysToKeep = (int)($queryParams['days'] ?? 30);
            
            // Initialize services
            $mappingService = new CalendarMappingService($db, $logger);
            $cancellationService = new CancellationService($db, $logger, $mappingService);
            
            // Get GraphServiceClient from OutlookController
            $outlookController = new \App\Controller\OutlookController();
            $reflection = new \ReflectionClass($outlookController);
            $property = $reflection->getProperty('graphServiceClient');
            $property->setAccessible(true);
            $graphServiceClient = $property->getValue($outlookController);
            
            $detectionService = new OutlookEventDetectionService(
                $db, 
                $logger, 
                $graphServiceClient,
                $mappingService,
                $cancellationService
            );
            
            $result = $detectionService->cleanupEventChangeLogs($daysToKeep);
            
            $statusCode = $result['success'] ? 200 : 500;
            
            $response->getBody()->write(json_encode([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Event logs cleaned up successfully' : 'Failed to cleanup event logs',
                'deleted_logs' => $result['deleted_logs'],
                'days_kept' => $daysToKeep,
                'error' => $result['error']
            ]));
            
            return $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to cleanup event logs: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}
