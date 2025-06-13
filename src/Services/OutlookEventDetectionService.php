<?php

namespace App\Services;

use PDO;
use Psr\Log\LoggerInterface;
use Microsoft\Graph\GraphServiceClient;

/**
 * Service for detecting changes in Outlook events via polling (fallback when webhooks fail)
 */
class OutlookEventDetectionService
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
     * Detect changes in Outlook events by comparing current state with known mappings
     * This is a fallback method when webhooks are not available or missed
     * 
     * @return array Results of change detection
     */
    public function detectOutlookEventChanges()
    {
        $results = [
            'success' => true,
            'detected_changes' => 0,
            'deleted_events' => 0,
            'processed_deletions' => 0,
            'errors' => []
        ];

        try {
            $this->logger->info('Starting Outlook event change detection');

            // Get all synced mappings that should have Outlook events
            $syncedMappings = $this->getSyncedMappings();
            
            foreach ($syncedMappings as $mapping) {
                try {
                    $changeDetected = $this->checkEventExists($mapping);
                    
                    if ($changeDetected) {
                        $results['detected_changes']++;
                        
                        if ($changeDetected['type'] === 'deleted') {
                            $results['deleted_events']++;
                            
                            // Process the deletion
                            $deletionResult = $this->processEventDeletion($mapping);
                            
                            if ($deletionResult['success']) {
                                $results['processed_deletions']++;
                            } else {
                                $results['errors'][] = [
                                    'mapping_id' => $mapping['id'],
                                    'event_id' => $mapping['outlook_event_id'],
                                    'error' => $deletionResult['error']
                                ];
                            }
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

            $this->logger->info('Outlook event change detection completed', $results);

        } catch (\Exception $e) {
            $results['success'] = false;
            $results['errors'][] = 'Detection failed: ' . $e->getMessage();
            
            $this->logger->error('Failed to detect Outlook event changes', [
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }

    /**
     * Check if an Outlook event still exists
     * 
     * @param array $mapping
     * @return array|false
     */
    private function checkEventExists($mapping)
    {
        try {
            // Try to fetch the event from Outlook
            $event = $this->graphServiceClient
                ->users()
                ->byUserId($mapping['outlook_item_id'])
                ->events()
                ->byEventId($mapping['outlook_event_id'])
                ->get()
                ->wait();

            if (!$event) {
                // Event not found - it was deleted
                $this->logger->info('Outlook event no longer exists (deleted)', [
                    'mapping_id' => $mapping['id'],
                    'event_id' => $mapping['outlook_event_id'],
                    'calendar_id' => $mapping['outlook_item_id']
                ]);

                return [
                    'type' => 'deleted',
                    'mapping' => $mapping
                ];
            }

            // Event still exists
            return false;

        } catch (\Microsoft\Graph\Generated\Models\ODataErrors\ODataError $e) {
            // Check if it's a "not found" error (404)
            if (strpos($e->getMessage(), 'ItemNotFound') !== false || 
                strpos($e->getMessage(), '404') !== false ||
                strpos($e->getMessage(), 'NotFound') !== false) {
                
                $this->logger->info('Outlook event not found (deleted)', [
                    'mapping_id' => $mapping['id'],
                    'event_id' => $mapping['outlook_event_id'],
                    'calendar_id' => $mapping['outlook_item_id']
                ]);

                return [
                    'type' => 'deleted',
                    'mapping' => $mapping
                ];
            }

            // Other error - rethrow
            throw $e;
        }
    }

    /**
     * Process detected event deletion
     * 
     * @param array $mapping
     * @return array
     */
    private function processEventDeletion($mapping)
    {
        try {
            $this->logger->info('Processing detected Outlook event deletion', [
                'mapping_id' => $mapping['id'],
                'event_id' => $mapping['outlook_event_id'],
                'reservation_type' => $mapping['reservation_type'],
                'reservation_id' => $mapping['reservation_id']
            ]);

            // Use our existing cancellation service to handle the deletion
            $result = $this->cancellationService->handleOutlookCancellation($mapping['outlook_event_id']);

            // Log the change for audit purposes
            $this->logEventChange(
                $mapping['outlook_item_id'],
                $mapping['outlook_event_id'],
                'deleted',
                'processed',
                null,
                $result
            );

            return [
                'success' => $result['success'],
                'error' => $result['success'] ? null : implode(', ', $result['errors'])
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to process event deletion', [
                'mapping_id' => $mapping['id'],
                'event_id' => $mapping['outlook_event_id'],
                'error' => $e->getMessage()
            ]);

            // Log the failed processing
            $this->logEventChange(
                $mapping['outlook_item_id'],
                $mapping['outlook_event_id'],
                'deleted',
                'error',
                $e->getMessage()
            );

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get all synced mappings that should have Outlook events
     * 
     * @return array
     */
    private function getSyncedMappings()
    {
        $sql = "
            SELECT 
                id,
                reservation_type,
                reservation_id,
                resource_id,
                outlook_item_id,
                outlook_event_id,
                sync_status,
                sync_direction,
                last_sync_at
            FROM outlook_calendar_mapping 
            WHERE outlook_event_id IS NOT NULL 
                AND sync_status IN ('synced', 'error')
                AND sync_direction IN ('booking_to_outlook', 'bidirectional')
            ORDER BY last_sync_at DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Log detected event change
     * 
     * @param string $calendarId
     * @param string $eventId
     * @param string $changeType
     * @param string $processingStatus
     * @param string|null $errorMessage
     * @param array|null $processingResult
     */
    private function logEventChange($calendarId, $eventId, $changeType, $processingStatus = 'pending', $errorMessage = null, $processingResult = null)
    {
        $sql = "
            INSERT INTO outlook_event_changes 
            (calendar_id, event_id, change_type, processing_status, error_message, processed_at, current_hash)
            VALUES (:calendar_id, :event_id, :change_type, :processing_status, :error_message, :processed_at, :current_hash)
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'calendar_id' => $calendarId,
            'event_id' => $eventId,
            'change_type' => $changeType,
            'processing_status' => $processingStatus,
            'error_message' => $errorMessage,
            'processed_at' => $processingStatus === 'processed' ? date('Y-m-d H:i:s') : null,
            'current_hash' => hash('sha256', $eventId . $changeType . time())
        ]);
    }

    /**
     * Clean up old event change logs
     * 
     * @param int $daysToKeep
     * @return array
     */
    public function cleanupEventChangeLogs($daysToKeep = 30)
    {
        $results = [
            'success' => true,
            'deleted_logs' => 0,
            'error' => null
        ];

        try {
            $sql = "
                DELETE FROM outlook_event_changes 
                WHERE created_at < NOW() - INTERVAL '{$daysToKeep} days'
                    AND processing_status IN ('processed', 'ignored')
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $results['deleted_logs'] = $stmt->rowCount();

            $this->logger->info('Cleaned up event change logs', [
                'days_to_keep' => $daysToKeep,
                'deleted_logs' => $results['deleted_logs']
            ]);

        } catch (\Exception $e) {
            $results['success'] = false;
            $results['error'] = $e->getMessage();

            $this->logger->error('Failed to cleanup event change logs', [
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }

    /**
     * Get statistics about event change detection
     * 
     * @return array
     */
    public function getDetectionStats()
    {
        $stats = [
            'success' => true,
            'statistics' => []
        ];

        try {
            // Get change detection statistics
            $sql = "
                SELECT 
                    change_type,
                    processing_status,
                    COUNT(*) as count
                FROM outlook_event_changes 
                WHERE detected_at >= NOW() - INTERVAL '24 hours'
                GROUP BY change_type, processing_status
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $stats['statistics']['last_24_hours'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get overall statistics
            $sql = "
                SELECT 
                    COUNT(*) as total_tracked_mappings,
                    COUNT(*) FILTER (WHERE outlook_event_id IS NOT NULL) as mappings_with_outlook_events,
                    COUNT(*) FILTER (WHERE sync_status = 'synced') as synced_mappings,
                    COUNT(*) FILTER (WHERE sync_status = 'error') as error_mappings
                FROM outlook_calendar_mapping
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $stats['statistics']['mapping_overview'] = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get recent event changes
            $sql = "
                SELECT 
                    calendar_id,
                    event_id,
                    change_type,
                    processing_status,
                    detected_at,
                    processed_at,
                    error_message
                FROM outlook_event_changes 
                WHERE detected_at >= NOW() - INTERVAL '1 hour'
                ORDER BY detected_at DESC
                LIMIT 10
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $stats['statistics']['recent_changes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (\Exception $e) {
            $stats['success'] = false;
            $stats['error'] = $e->getMessage();

            $this->logger->error('Failed to get detection statistics', [
                'error' => $e->getMessage()
            ]);
        }

        return $stats;
    }

    /**
     * Detect newly created Outlook events that might need to be imported
     * 
     * @param string $fromDate
     * @param string $toDate
     * @return array
     */
    public function detectNewOutlookEvents($fromDate = null, $toDate = null)
    {
        $results = [
            'success' => true,
            'new_events_found' => 0,
            'events' => [],
            'errors' => []
        ];

        try {
            $fromDate = $fromDate ?: date('Y-m-d\TH:i:s\Z');
            $toDate = $toDate ?: date('Y-m-d\TH:i:s\Z', strtotime('+1 week'));

            // Get all room calendars
            $sql = "SELECT DISTINCT outlook_item_id FROM bb_resource_outlook_item WHERE active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $calendars = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($calendars as $calendar) {
                try {
                    $events = $this->fetchCalendarEvents($calendar['outlook_item_id'], $fromDate, $toDate);
                    
                    foreach ($events as $event) {
                        // Check if we already have this event mapped
                        $existingMapping = $this->mappingService->findMappingByOutlookEvent($event->getId());
                        
                        if (!$existingMapping) {
                            // Check if this is one of our own events (to prevent loops)
                            if (!$this->isOurEvent($event)) {
                                $results['new_events_found']++;
                                $results['events'][] = [
                                    'calendar_id' => $calendar['outlook_item_id'],
                                    'event_id' => $event->getId(),
                                    'subject' => $event->getSubject(),
                                    'start' => $event->getStart()->getDateTime(),
                                    'end' => $event->getEnd()->getDateTime(),
                                    'organizer' => $event->getOrganizer()->getEmailAddress()->getAddress()
                                ];

                                $this->logger->info('Detected new external Outlook event', [
                                    'calendar_id' => $calendar['outlook_item_id'],
                                    'event_id' => $event->getId(),
                                    'subject' => $event->getSubject()
                                ]);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'calendar_id' => $calendar['outlook_item_id'],
                        'error' => $e->getMessage()
                    ];
                }
            }

        } catch (\Exception $e) {
            $results['success'] = false;
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Fetch events from a specific calendar
     * 
     * @param string $calendarId
     * @param string $fromDate
     * @param string $toDate
     * @return array
     */
    private function fetchCalendarEvents($calendarId, $fromDate, $toDate)
    {
        $filter = "start/dateTime ge '{$fromDate}' and end/dateTime le '{$toDate}'";
        
        $requestConfiguration = new \Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilderGetRequestConfiguration();
        $requestConfiguration->queryParameters = new \Microsoft\Graph\Generated\Users\Item\Events\EventsRequestBuilderGetQueryParameters();
        $requestConfiguration->queryParameters->filter = $filter;
        $requestConfiguration->queryParameters->orderby = ['start/dateTime'];
        $requestConfiguration->queryParameters->select = ['id','subject','start','end','organizer','singleValueExtendedProperties'];
        
        $events = $this->graphServiceClient
            ->users()
            ->byUserId($calendarId)
            ->events()
            ->get($requestConfiguration)
            ->wait();
            
        return $events->getValue() ?: [];
    }

    /**
     * Check if an Outlook event was created by our sync service
     * 
     * @param \Microsoft\Graph\Generated\Models\Event $event
     * @return bool
     */
    private function isOurEvent($event)
    {
        $extendedProperties = $event->getSingleValueExtendedProperties();
        
        if (!$extendedProperties) {
            return false;
        }
        
        foreach ($extendedProperties as $property) {
            if (strpos($property->getId(), 'BookingSystemType') !== false) {
                return true;
            }
        }
        
        return false;
    }
}
