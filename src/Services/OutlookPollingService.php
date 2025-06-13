<?php

namespace App\Services;

use PDO;
use Psr\Log\LoggerInterface;
use Microsoft\Graph\GraphServiceClient;

/**
 * Service for polling Outlook calendars to detect changes without webhooks
 * Uses Microsoft Graph delta queries for efficient change detection
 */
class OutlookPollingService
{
    private PDO $db;
    private LoggerInterface $logger;
    private GraphServiceClient $graphServiceClient;
    private CalendarMappingService $mappingService;
    private CancellationService $cancellationService;

    public function __construct(
        PDO $db, 
        LoggerInterface $logger, 
        GraphServiceClient $graphServiceClient,
        CalendarMappingService $mappingService,
        CancellationService $cancellationService
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->graphServiceClient = $graphServiceClient;
        $this->mappingService = $mappingService;
        $this->cancellationService = $cancellationService;
    }

    /**
     * Poll all room calendars for changes and detect deletions/cancellations
     * 
     * @return array Results of the polling operation
     */
    public function pollForOutlookChanges()
    {
        $results = [
            'success' => true,
            'calendars_checked' => 0,
            'changes_detected' => 0,
            'deletions_processed' => 0,
            'errors' => [],
            'details' => []
        ];

        try {
            $this->logger->info('Starting Outlook polling for changes');

            // Get all room calendars
            $roomCalendars = $this->getRoomCalendars();
            
            foreach ($roomCalendars as $calendar) {
                try {
                    $calendarResult = $this->pollCalendarForChanges($calendar);
                    
                    $results['calendars_checked']++;
                    $results['changes_detected'] += $calendarResult['changes_detected'];
                    $results['deletions_processed'] += $calendarResult['deletions_processed'];
                    
                    $results['details'][] = [
                        'calendar_id' => $calendar['outlook_item_id'],
                        'resource_id' => $calendar['resource_id'],
                        'changes_detected' => $calendarResult['changes_detected'],
                        'deletions_processed' => $calendarResult['deletions_processed']
                    ];
                    
                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'calendar_id' => $calendar['outlook_item_id'],
                        'error' => $e->getMessage()
                    ];
                    
                    $this->logger->error('Error polling calendar', [
                        'calendar_id' => $calendar['outlook_item_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            if (!empty($results['errors'])) {
                $results['success'] = false;
            }
            
            $this->logger->info('Completed Outlook polling', $results);
            
        } catch (\Exception $e) {
            $results['success'] = false;
            $results['errors'][] = 'Polling failed: ' . $e->getMessage();
            
            $this->logger->error('Outlook polling failed', [
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }

    /**
     * Poll a specific calendar for changes
     * 
     * @param array $calendar
     * @return array
     */
    private function pollCalendarForChanges($calendar)
    {
        $calendarId = $calendar['outlook_item_id'];
        $resourceId = $calendar['resource_id'];
        
        $results = [
            'changes_detected' => 0,
            'deletions_processed' => 0
        ];

        $this->logger->info('Polling calendar for changes', [
            'calendar_id' => $calendarId,
            'resource_id' => $resourceId
        ]);

        // Get delta token for this calendar
        $deltaToken = $this->getDeltaToken($calendarId);
        
        // Fetch changes using delta query or full sync if no delta token
        if ($deltaToken) {
            $changes = $this->fetchDeltaChanges($calendarId, $deltaToken);
        } else {
            $changes = $this->fetchFullCalendarSnapshot($calendarId);
        }
        
        // Process detected changes
        foreach ($changes['events'] as $event) {
            $this->processEventChange($event, $calendarId, $resourceId, $results);
        }
        
        // Store new delta token for next poll
        if (isset($changes['delta_token'])) {
            $this->storeDeltaToken($calendarId, $changes['delta_token']);
        }
        
        return $results;
    }

    /**
     * Fetch delta changes for a calendar
     * 
     * @param string $calendarId
     * @param string $deltaToken
     * @return array
     */
    private function fetchDeltaChanges($calendarId, $deltaToken)
    {
        try {
            $this->logger->info('Fetching delta changes', [
                'calendar_id' => $calendarId,
                'delta_token' => substr($deltaToken, 0, 50) . '...'
            ]);

            // Use the delta token to get only changes since last poll
            $requestAdapter = $this->graphServiceClient->getRequestAdapter();
            
            $deltaRequest = new \Microsoft\Kiota\Abstractions\RequestInformation();
            $deltaRequest->urlTemplate = $deltaToken; // Delta token contains the full URL
            $deltaRequest->httpMethod = \Microsoft\Kiota\Abstractions\HttpMethod::GET;
            $deltaRequest->addHeader("Accept", "application/json");

            $response = $requestAdapter->sendAsync(
                $deltaRequest,
                [\Microsoft\Graph\Generated\Models\EventCollectionResponse::class, 'createFromDiscriminatorValue'],
                [\Microsoft\Graph\Generated\Models\ODataErrors\ODataError::class, 'createFromDiscriminatorValue']
            )->wait();

            $events = [];
            $newDeltaToken = null;
            
            if ($response && method_exists($response, 'getValue')) {
                $events = $response->getValue() ?: [];
                
                // Extract new delta token from @odata.deltaLink
                $deltaLink = $response->getOdataDeltaLink();
                if ($deltaLink) {
                    $newDeltaToken = $deltaLink;
                }
            }

            return [
                'events' => $events,
                'delta_token' => $newDeltaToken
            ];

        } catch (\Exception $e) {
            $this->logger->warning('Delta query failed, falling back to full sync', [
                'calendar_id' => $calendarId,
                'error' => $e->getMessage()
            ]);
            
            // Fall back to full sync if delta query fails
            return $this->fetchFullCalendarSnapshot($calendarId);
        }
    }

    /**
     * Fetch full calendar snapshot when delta is not available
     * 
     * @param string $calendarId
     * @return array
     */
    private function fetchFullCalendarSnapshot($calendarId)
    {
        try {
            $this->logger->info('Fetching full calendar snapshot', [
                'calendar_id' => $calendarId
            ]);

            // Get events from the last 30 days to next 30 days
            $fromDate = date('Y-m-d\TH:i:s\Z', strtotime('-30 days'));
            $toDate = date('Y-m-d\TH:i:s\Z', strtotime('+30 days'));
            
            $filter = "start/dateTime ge '{$fromDate}' and end/dateTime le '{$toDate}'";
            
            $requestConfiguration = new \Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilderGetRequestConfiguration();
            $requestConfiguration->queryParameters = new \Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilderGetQueryParameters();
            $requestConfiguration->queryParameters->filter = $filter;
            $requestConfiguration->queryParameters->orderby = ['start/dateTime'];
            $requestConfiguration->queryParameters->select = ['id','subject','start','end','organizer','body','singleValueExtendedProperties'];
            
            $events = $this->graphServiceClient
                ->users()
                ->byUserId($calendarId)
                ->events()
                ->get($requestConfiguration)
                ->wait();
                
            $eventList = $events->getValue() ?: [];
            
            // For full sync, we need to initialize delta token
            $deltaToken = $this->initializeDeltaToken($calendarId);

            return [
                'events' => $eventList,
                'delta_token' => $deltaToken
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch calendar snapshot', [
                'calendar_id' => $calendarId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'events' => [],
                'delta_token' => null
            ];
        }
    }

    /**
     * Initialize delta token for a calendar
     * This method tries to get a delta token but doesn't fail if it can't
     * 
     * @param string $calendarId
     * @return string|null
     */
    private function initializeDeltaToken($calendarId)
    {
        try {
            $this->logger->info('Attempting to initialize delta token for calendar', [
                'calendar_id' => $calendarId
            ]);

            // Try to get events delta using the standard Graph SDK approach
            $requestConfiguration = new \Microsoft\Graph\Generated\Users\Item\Events\Delta\DeltaRequestBuilderGetRequestConfiguration();
            
            $deltaResponse = $this->graphServiceClient
                ->users()
                ->byUserId($calendarId)
                ->events()
                ->delta()
                ->get($requestConfiguration)
                ->wait();

            $this->logger->info('Delta request completed successfully', [
                'response_type' => get_class($deltaResponse)
            ]);

            // Get the delta link from the response for future delta queries
            $deltaLink = null;
            if ($deltaResponse && method_exists($deltaResponse, 'getOdataDeltaLink')) {
                $deltaLink = $deltaResponse->getOdataDeltaLink();
            } elseif ($deltaResponse && method_exists($deltaResponse, 'getAdditionalData')) {
                $additionalData = $deltaResponse->getAdditionalData();
                $deltaLink = $additionalData['@odata.deltaLink'] ?? null;
            }

            if ($deltaLink) {
                $this->logger->info('Successfully retrieved delta link', [
                    'calendar_id' => $calendarId,
                    'delta_link_preview' => substr($deltaLink, 0, 100) . '...'
                ]);
            } else {
                $this->logger->warning('No delta link retrieved from response', [
                    'calendar_id' => $calendarId,
                    'response_methods' => method_exists($deltaResponse, '__toString') ? 
                        'Has __toString' : (is_object($deltaResponse) ? get_class_methods($deltaResponse) : 'Not an object')
                ]);
            }

            return $deltaLink;

        } catch (\Microsoft\Graph\Generated\Models\ODataErrors\ODataError $e) {
            $this->logger->warning('Microsoft Graph OData error during delta token initialization', [
                'calendar_id' => $calendarId,
                'error_message' => $e->getMessage(),
                'error_code' => method_exists($e, 'getError') ? $e->getError() : 'Unknown'
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('General error during delta token initialization', [
                'calendar_id' => $calendarId,
                'error' => $e->getMessage(),
                'error_class' => get_class($e)
            ]);
        }

        // Return null if we can't get a delta token - polling will work without it
        $this->logger->info('Delta token initialization failed, polling will use full sync method', [
            'calendar_id' => $calendarId
        ]);
        
        return null;
    }

    /**
     * Process an individual event change
     * 
     * @param object $event
     * @param string $calendarId
     * @param int $resourceId
     * @param array &$results
     */
    private function processEventChange($event, $calendarId, $resourceId, &$results)
    {
        $eventId = $event->getId();
        
        $this->logger->info('Processing event change', [
            'event_id' => $eventId,
            'calendar_id' => $calendarId,
            'subject' => $event->getSubject()
        ]);

        // Check if this event has a mapping in our system
        $mapping = $this->mappingService->findMappingByOutlookEvent($eventId);
        
        if ($mapping) {
            $this->logger->info('Found mapping for event', [
                'event_id' => $eventId,
                'mapping_id' => $mapping['id'],
                'reservation_type' => $mapping['reservation_type'],
                'reservation_id' => $mapping['reservation_id']
            ]);

            // Check if event still exists by trying to fetch its details
            $eventExists = $this->checkEventExists($eventId, $calendarId);
            
            if (!$eventExists) {
                $this->logger->info('Event no longer exists - processing as deletion', [
                    'event_id' => $eventId
                ]);
                
                // Handle the deletion
                $result = $this->cancellationService->handleOutlookCancellation($eventId);
                
                if ($result['success']) {
                    $results['deletions_processed']++;
                    $this->logger->info('Successfully processed Outlook event deletion', [
                        'event_id' => $eventId,
                        'booking_cancelled' => $result['booking_cancelled']
                    ]);
                } else {
                    $this->logger->error('Failed to process Outlook event deletion', [
                        'event_id' => $eventId,
                        'errors' => $result['errors']
                    ]);
                }
            }
        }
        
        $results['changes_detected']++;
    }

    /**
     * Check if an event still exists in Outlook
     * 
     * @param string $eventId
     * @param string $calendarId
     * @return bool
     */
    private function checkEventExists($eventId, $calendarId)
    {
        try {
            $event = $this->graphServiceClient
                ->users()
                ->byUserId($calendarId)
                ->events()
                ->byEventId($eventId)
                ->get()
                ->wait();
                
            return $event !== null;
            
        } catch (\Exception $e) {
            // If we get a 404 or similar error, the event doesn't exist
            $this->logger->info('Event existence check failed - assuming deleted', [
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get all room calendars for polling
     * 
     * @return array
     */
    private function getRoomCalendars()
    {
        $sql = "
            SELECT DISTINCT outlook_item_id, resource_id 
            FROM bb_resource_outlook_item 
            WHERE active = 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get stored delta token for a calendar
     * 
     * @param string $calendarId
     * @return string|null
     */
    private function getDeltaToken($calendarId)
    {
        $sql = "
            SELECT delta_token 
            FROM outlook_polling_state 
            WHERE calendar_id = :calendar_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['calendar_id' => $calendarId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['delta_token'] : null;
    }

    /**
     * Store delta token for a calendar
     * 
     * @param string $calendarId
     * @param string $deltaToken
     */
    private function storeDeltaToken($calendarId, $deltaToken)
    {
        $sql = "
            INSERT INTO outlook_polling_state (calendar_id, delta_token, last_poll_at, updated_at)
            VALUES (:calendar_id, :delta_token, NOW(), NOW())
            ON CONFLICT (calendar_id) 
            DO UPDATE SET 
                delta_token = :delta_token,
                last_poll_at = NOW(),
                updated_at = NOW()
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'calendar_id' => $calendarId,
            'delta_token' => $deltaToken
        ]);
    }

    /**
     * Detect missing events by comparing our mappings with current Outlook events
     * 
     * @return array
     */
    public function detectMissingEvents()
    {
        $results = [
            'success' => true,
            'missing_events_detected' => 0,
            'cancellations_processed' => 0,
            'errors' => [],
            'details' => []
        ];

        try {
            $this->logger->info('Starting missing event detection');

            // Get all synced mappings that should have Outlook events
            $syncedMappings = $this->getSyncedMappings();
            
            foreach ($syncedMappings as $mapping) {
                try {
                    $eventExists = $this->checkEventExists(
                        $mapping['outlook_event_id'], 
                        $mapping['outlook_item_id']
                    );
                    
                    if (!$eventExists) {
                        $this->logger->info('Detected missing Outlook event', [
                            'event_id' => $mapping['outlook_event_id'],
                            'mapping_id' => $mapping['id'],
                            'reservation_type' => $mapping['reservation_type'],
                            'reservation_id' => $mapping['reservation_id']
                        ]);
                        
                        $results['missing_events_detected']++;
                        
                        // Handle the cancellation
                        $cancelResult = $this->cancellationService->handleOutlookCancellation(
                            $mapping['outlook_event_id']
                        );
                        
                        if ($cancelResult['success']) {
                            $results['cancellations_processed']++;
                            $results['details'][] = [
                                'event_id' => $mapping['outlook_event_id'],
                                'reservation_type' => $mapping['reservation_type'],
                                'reservation_id' => $mapping['reservation_id'],
                                'action' => 'cancelled',
                                'booking_cancelled' => $cancelResult['booking_cancelled']
                            ];
                        } else {
                            $results['errors'][] = [
                                'event_id' => $mapping['outlook_event_id'],
                                'error' => 'Failed to process cancellation: ' . implode(', ', $cancelResult['errors'])
                            ];
                        }
                    }
                    
                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'mapping_id' => $mapping['id'],
                        'event_id' => $mapping['outlook_event_id'],
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            if (!empty($results['errors'])) {
                $results['success'] = false;
            }
            
            $this->logger->info('Completed missing event detection', $results);
            
        } catch (\Exception $e) {
            $results['success'] = false;
            $results['errors'][] = 'Missing event detection failed: ' . $e->getMessage();
        }

        return $results;
    }

    /**
     * Get all synced mappings that should have Outlook events
     * 
     * @return array
     */
    private function getSyncedMappings()
    {
        $sql = "
            SELECT id, outlook_event_id, outlook_item_id, reservation_type, reservation_id, resource_id
            FROM outlook_calendar_mapping 
            WHERE sync_status = 'synced' 
            AND outlook_event_id IS NOT NULL
            AND sync_direction IN ('booking_to_outlook', 'bidirectional')
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get polling statistics
     * 
     * @return array
     */
    public function getPollingStats()
    {
        $sql = "
            SELECT 
                COUNT(*) as total_calendars,
                COUNT(*) FILTER (WHERE last_poll_at > NOW() - INTERVAL '1 hour') as recently_polled,
                MAX(last_poll_at) as last_poll_time,
                MIN(last_poll_at) as oldest_poll_time
            FROM outlook_polling_state
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'statistics' => $stats
        ];
    }

    /**
     * Initialize polling state for all room calendars
     * Creates entries in outlook_polling_state table for delta query tracking
     * 
     * @return array Results of the initialization
     */
    public function initializePollingState()
    {
        $results = [
            'success' => true,
            'calendars_initialized' => 0,
            'calendars_updated' => 0, 
            'calendars_failed' => 0,
            'errors' => [],
            'details' => []
        ];

        try {
            $this->logger->info('Starting polling state initialization');

            // Get all room calendars
            $roomCalendars = $this->getRoomCalendars();
            
            foreach ($roomCalendars as $calendar) {
                $calendarId = $calendar['outlook_item_id'];
                $resourceId = $calendar['resource_id'];
                
                try {
                    $this->logger->info('Initializing polling state for calendar', [
                        'calendar_id' => $calendarId,
                        'resource_id' => $resourceId
                    ]);

                    // Check if polling state already exists
                    $existingState = $this->getPollingState($calendarId);
                    
                    if ($existingState) {
                        // Update existing state - try to get delta token but don't fail if it doesn't work
                        $deltaToken = $this->initializeDeltaToken($calendarId);
                        
                        // Update the existing record regardless of delta token success
                        $this->updatePollingState($calendarId, $deltaToken);
                        $results['calendars_updated']++;
                        
                        $results['details'][] = [
                            'calendar_id' => $calendarId,
                            'resource_id' => $resourceId,
                            'action' => 'updated',
                            'status' => 'success',
                            'has_delta_token' => $deltaToken !== null
                        ];
                        
                        $this->logger->info('Updated polling state for calendar', [
                            'calendar_id' => $calendarId,
                            'has_delta_token' => $deltaToken !== null
                        ]);
                    } else {
                        // Create new polling state - try to get delta token but don't fail if it doesn't work
                        $deltaToken = $this->initializeDeltaToken($calendarId);
                        
                        // Create the record regardless of delta token success
                        $this->createPollingState($calendarId, $deltaToken);
                        $results['calendars_initialized']++;
                        
                        $results['details'][] = [
                            'calendar_id' => $calendarId,
                            'resource_id' => $resourceId,
                            'action' => 'created',
                            'status' => 'success',
                            'has_delta_token' => $deltaToken !== null
                        ];
                        
                        $this->logger->info('Created polling state for calendar', [
                            'calendar_id' => $calendarId,
                            'has_delta_token' => $deltaToken !== null
                        ]);
                    }
                    
                } catch (\Exception $e) {
                    $results['calendars_failed']++;
                    $results['errors'][] = [
                        'calendar_id' => $calendarId,
                        'resource_id' => $resourceId,
                        'error' => $e->getMessage()
                    ];
                    
                    $results['details'][] = [
                        'calendar_id' => $calendarId,
                        'resource_id' => $resourceId,
                        'action' => 'failed',
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ];
                    
                    $this->logger->error('Failed to initialize polling state for calendar', [
                        'calendar_id' => $calendarId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            if ($results['calendars_failed'] > 0) {
                $results['success'] = false;
            }
            
            $this->logger->info('Completed polling state initialization', [
                'calendars_initialized' => $results['calendars_initialized'],
                'calendars_updated' => $results['calendars_updated'],
                'calendars_failed' => $results['calendars_failed']
            ]);
            
        } catch (\Exception $e) {
            $results['success'] = false;
            $results['errors'][] = 'Polling state initialization failed: ' . $e->getMessage();
            
            $this->logger->error('Polling state initialization failed', [
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }

    /**
     * Get existing polling state for a calendar
     * 
     * @param string $calendarId
     * @return array|null
     */
    private function getPollingState($calendarId)
    {
        $sql = "
            SELECT calendar_id, delta_token, last_poll_at, created_at, updated_at
            FROM outlook_polling_state 
            WHERE calendar_id = :calendar_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['calendar_id' => $calendarId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Create new polling state record
     * 
     * @param string $calendarId
     * @param string $deltaToken
     */
    private function createPollingState($calendarId, $deltaToken)
    {
        $sql = "
            INSERT INTO outlook_polling_state 
            (calendar_id, delta_token, last_poll_at, created_at, updated_at)
            VALUES (:calendar_id, :delta_token, NOW(), NOW(), NOW())
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'calendar_id' => $calendarId,
            'delta_token' => $deltaToken
        ]);
    }

    /**
     * Update existing polling state record
     * 
     * @param string $calendarId
     * @param string|null $deltaToken
     */
    private function updatePollingState($calendarId, $deltaToken = null)
    {
        $sql = "
            UPDATE outlook_polling_state 
            SET delta_token = :delta_token,
                last_poll_at = NOW(),
                updated_at = NOW()
            WHERE calendar_id = :calendar_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'calendar_id' => $calendarId,
            'delta_token' => $deltaToken
        ]);
    }
}
