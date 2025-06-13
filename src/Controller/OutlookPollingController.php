<?php

namespace App\Controller;

use App\Services\OutlookPollingService;
use App\Services\CalendarMappingService;
use App\Services\CancellationService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class OutlookPollingController
{
    /**
     * Poll all Outlook calendars for changes (alternative to webhooks)
     */
    public function pollForChanges(Request $request, Response $response, $args)
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
            
            $pollingService = new OutlookPollingService(
                $db, 
                $logger, 
                $graphServiceClient,
                $mappingService,
                $cancellationService
            );
            
            $result = $pollingService->pollForOutlookChanges();
            
            $statusCode = $result['success'] ? 200 : 500;
            
            $response->getBody()->write(json_encode([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Outlook polling completed successfully' : 'Outlook polling completed with errors',
                'calendars_checked' => $result['calendars_checked'],
                'changes_detected' => $result['changes_detected'],
                'deletions_processed' => $result['deletions_processed'],
                'details' => $result['details'],
                'errors' => $result['errors']
            ]));
            
            return $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to poll for Outlook changes: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Detect missing events by checking if mapped events still exist in Outlook
     */
    public function detectMissingEvents(Request $request, Response $response, $args)
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
            
            $pollingService = new OutlookPollingService(
                $db, 
                $logger, 
                $graphServiceClient,
                $mappingService,
                $cancellationService
            );
            
            $result = $pollingService->detectMissingEvents();
            
            $statusCode = $result['success'] ? 200 : 500;
            
            $response->getBody()->write(json_encode([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Missing event detection completed successfully' : 'Missing event detection completed with errors',
                'missing_events_detected' => $result['missing_events_detected'],
                'cancellations_processed' => $result['cancellations_processed'],
                'details' => $result['details'],
                'errors' => $result['errors']
            ]));
            
            return $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to detect missing events: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Get polling statistics
     */
    public function getPollingStats(Request $request, Response $response, $args)
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
            
            $pollingService = new OutlookPollingService(
                $db, 
                $logger, 
                $graphServiceClient,
                $mappingService,
                $cancellationService
            );
            
            $result = $pollingService->getPollingStats();
            
            $response->getBody()->write(json_encode($result));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get polling statistics: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Initialize polling state for all room calendars
     */
    public function initializePolling(Request $request, Response $response, $args)
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
            
            $pollingService = new OutlookPollingService(
                $db, 
                $logger, 
                $graphServiceClient,
                $mappingService,
                $cancellationService
            );
            
            // Initialize polling state for all room calendars
            $result = $pollingService->initializePollingState();
            
            $statusCode = $result['success'] ? 200 : 500;
            
            $response->getBody()->write(json_encode([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Polling initialization completed successfully' : 'Polling initialization completed with errors',
                'calendars_initialized' => $result['calendars_initialized'] ?? 0,
                'errors' => $result['errors'] ?? []
            ]));
            
            return $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to initialize polling: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}
