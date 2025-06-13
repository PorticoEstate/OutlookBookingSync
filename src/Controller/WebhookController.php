<?php

namespace App\Controller;

use App\Services\OutlookWebhookService;
use App\Services\CalendarMappingService;
use App\Services\CancellationService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class WebhookController
{
    /**
     * Handle webhook notifications from Microsoft Graph
     */
    public function handleOutlookNotification(Request $request, Response $response, $args)
    {
        try {
            $db = $request->getAttribute('db');
            $logger = $request->getAttribute('logger');
            
            // Get request body
            $body = $request->getBody()->getContents();
            $notification = json_decode($body, true);
            
            $logger->info('Received webhook notification', [
                'body' => $body,
                'notification' => $notification
            ]);
            
            // Handle validation token (required by Microsoft Graph)
            $queryParams = $request->getQueryParams();
            if (isset($queryParams['validationToken'])) {
                $logger->info('Webhook validation requested', [
                    'validation_token' => $queryParams['validationToken']
                ]);
                
                $response->getBody()->write($queryParams['validationToken']);
                return $response->withHeader('Content-Type', 'text/plain');
            }
            
            // Process the notification
            if ($notification) {
                // Initialize services
                $mappingService = new CalendarMappingService($db, $logger);
                $cancellationService = new CancellationService($db, $logger, $mappingService);
                
                // Get GraphServiceClient from OutlookController
                $outlookController = new \App\Controller\OutlookController();
                $reflection = new \ReflectionClass($outlookController);
                $property = $reflection->getProperty('graphServiceClient');
                $property->setAccessible(true);
                $graphServiceClient = $property->getValue($outlookController);
                
                $webhookService = new OutlookWebhookService(
                    $db, 
                    $logger, 
                    $graphServiceClient,
                    $mappingService,
                    $cancellationService
                );
                
                $result = $webhookService->processWebhookNotification($notification);
                
                $response->getBody()->write(json_encode([
                    'success' => $result['success'],
                    'processed' => $result['processed'],
                    'errors' => $result['errors']
                ]));
                
                return $response->withStatus($result['success'] ? 200 : 500)
                    ->withHeader('Content-Type', 'application/json');
            }
            
            // Empty notification
            $response->getBody()->write('OK');
            return $response->withStatus(200);
            
        } catch (\Exception $e) {
            $logger->error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Create webhook subscriptions for all room calendars
     */
    public function createWebhookSubscriptions(Request $request, Response $response, $args)
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
            
            $webhookService = new OutlookWebhookService(
                $db, 
                $logger, 
                $graphServiceClient,
                $mappingService,
                $cancellationService
            );
            
            $result = $webhookService->createWebhookSubscriptions();
            
            $statusCode = $result['success'] ? 200 : 500;
            
            $response->getBody()->write(json_encode([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Webhook subscriptions created successfully' : 'Failed to create some webhook subscriptions',
                'subscriptions_created' => $result['subscriptions_created'],
                'subscriptions' => $result['subscriptions'],
                'errors' => $result['errors']
            ]));
            
            return $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to create webhook subscriptions: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Renew expiring webhook subscriptions
     */
    public function renewWebhookSubscriptions(Request $request, Response $response, $args)
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
            
            $webhookService = new OutlookWebhookService(
                $db, 
                $logger, 
                $graphServiceClient,
                $mappingService,
                $cancellationService
            );
            
            $result = $webhookService->renewExpiringSubscriptions();
            
            $statusCode = $result['success'] ? 200 : 500;
            
            $response->getBody()->write(json_encode([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Webhook subscriptions renewed successfully' : 'Failed to renew some webhook subscriptions',
                'renewed' => $result['renewed'],
                'errors' => $result['errors']
            ]));
            
            return $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to renew webhook subscriptions: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Get webhook subscription statistics
     */
    public function getWebhookStats(Request $request, Response $response, $args)
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
            
            $webhookService = new OutlookWebhookService(
                $db, 
                $logger, 
                $graphServiceClient,
                $mappingService,
                $cancellationService
            );
            
            $result = $webhookService->getWebhookStats();
            
            $response->getBody()->write(json_encode($result));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to get webhook statistics: ' . $e->getMessage()
            ]));
            
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}
