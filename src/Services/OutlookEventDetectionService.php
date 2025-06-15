<?php

namespace App\Services;

use PDO;
use Psr\Log\LoggerInterface;
use Microsoft\Graph\GraphServiceClient;

/**
 * Service for detecting changes in Outlook events via polling (fallback when webhooks fail)
 */
class OutlookEventDetectionService
{    private PDO $db;
    private LoggerInterface $logger;
    private GraphServiceClient $graphServiceClient;

    public function __construct(
        PDO $db,
        LoggerInterface $logger,
        GraphServiceClient $graphServiceClient
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->graphServiceClient = $graphServiceClient;
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
     * Check if an Outlook event still exists (bridge-compatible)
     * 
     * @param array $mapping
     * @return array|false
     */
    private function checkEventExists($mapping)
    {
        try {
            // Determine which event ID and calendar ID to check based on bridge mapping
            $outlookEventId = null;
            $calendarId = null;
            
            if ($mapping['source_bridge'] === 'outlook') {
                $outlookEventId = $mapping['source_event_id'];
                $calendarId = $mapping['source_calendar_id'];
            } elseif ($mapping['target_bridge'] === 'outlook') {
                $outlookEventId = $mapping['target_event_id'];
                $calendarId = $mapping['target_calendar_id'];
            } else {
                // Not an Outlook mapping, skip
                return false;
            }

            // Try to fetch the event from Outlook
            $event = $this->graphServiceClient
                ->users()
                ->byUserId($calendarId)
                ->events()
                ->byEventId($outlookEventId)
                ->get()
                ->wait();

            if (!$event) {
                // Event not found - it was deleted
                $this->logger->info('Outlook event no longer exists (deleted)', [
                    'mapping_id' => $mapping['id'],
                    'event_id' => $outlookEventId,
                    'calendar_id' => $calendarId
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
                    'event_id' => $outlookEventId,
                    'calendar_id' => $calendarId
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
     * Process detected event deletion using bridge pattern
     * 
     * @param array $mapping
     * @return array
     */
    private function processEventDeletion($mapping)
    {
        try {
            $outlookEventId = null;
            $calendarId = null;
            
            if ($mapping['source_bridge'] === 'outlook') {
                $outlookEventId = $mapping['source_event_id'];
                $calendarId = $mapping['source_calendar_id'];
            } elseif ($mapping['target_bridge'] === 'outlook') {
                $outlookEventId = $mapping['target_event_id'];
                $calendarId = $mapping['target_calendar_id'];
            }

            $this->logger->info('Processing detected Outlook event deletion via bridge', [
                'mapping_id' => $mapping['id'],
                'event_id' => $outlookEventId,
                'source_bridge' => $mapping['source_bridge'],
                'target_bridge' => $mapping['target_bridge']
            ]);

            // Add deletion operation to bridge queue for processing
            $this->queueDeletionOperation($mapping);

            // Log the change for audit purposes
            $this->logEventChange(
                $calendarId,
                $outlookEventId,
                'deleted',
                'queued',
                null,
                ['mapping_id' => $mapping['id']]
            );

            return [
                'success' => true,
                'error' => null,
                'queued' => true
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to process event deletion', [
                'mapping_id' => $mapping['id'],
                'error' => $e->getMessage()
            ]);

            // Log the failed processing
            $this->logEventChange(
                $calendarId ?? 'unknown',
                $outlookEventId ?? 'unknown',
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
     * Queue deletion operation for bridge processing
     */
    private function queueDeletionOperation($mapping)
    {
        $sql = "
            INSERT INTO bridge_queue (queue_type, source_bridge, target_bridge, priority, payload, status)
            VALUES ('deletion', :source_bridge, :target_bridge, 1, :payload, 'pending')
        ";

        $payload = json_encode([
            'mapping_id' => $mapping['id'],
            'source_event_id' => $mapping['source_event_id'],
            'target_event_id' => $mapping['target_event_id'],
            'source_calendar_id' => $mapping['source_calendar_id'],
            'target_calendar_id' => $mapping['target_calendar_id'],
            'reason' => 'outlook_event_deleted'
        ]);

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'source_bridge' => $mapping['source_bridge'],
            'target_bridge' => $mapping['target_bridge'],
            'payload' => $payload
        ]);
    }

    /**
     * Get all synced mappings that should have Outlook events (bridge-compatible)
     * 
     * @return array
     */
    private function getSyncedMappings()
    {
        $sql = "
            SELECT 
                id,
                source_bridge,
                target_bridge,
                source_calendar_id,
                target_calendar_id,
                source_event_id,
                target_event_id,
                sync_direction,
                last_synced_at,
                created_at
            FROM bridge_mappings 
            WHERE (source_bridge = 'outlook' OR target_bridge = 'outlook')
                AND (source_event_id IS NOT NULL AND target_event_id IS NOT NULL)
            ORDER BY last_synced_at DESC
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

            // Get overall statistics from bridge tables
            $sql = "
                SELECT 
                    COUNT(*) as total_bridge_mappings,
                    COUNT(*) FILTER (WHERE source_bridge = 'outlook' OR target_bridge = 'outlook') as outlook_mappings,
                    COUNT(*) FILTER (WHERE last_synced_at > NOW() - INTERVAL '1 day') as recently_synced,
                    COUNT(*) FILTER (WHERE last_synced_at IS NULL OR last_synced_at < NOW() - INTERVAL '7 days') as stale_mappings
                FROM bridge_mappings
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

            // Get all mapped calendar IDs from bridge resource mappings
            $sql = "
                SELECT DISTINCT calendar_id 
                FROM bridge_resource_mappings 
                WHERE bridge_to = 'outlook' 
                    AND is_active = true 
                    AND sync_enabled = true
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $calendars = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($calendars as $calendar) {
                try {
                    $events = $this->fetchCalendarEvents($calendar['calendar_id'], $fromDate, $toDate);
                    
                    foreach ($events as $event) {
                        // Check if we already have this event mapped in bridge_mappings
                        $existingMapping = $this->findBridgeMappingByOutlookEvent($event->getId());
                        
                        if (!$existingMapping) {
                            // Check if this is one of our own events (to prevent loops)
                            if (!$this->isOurEvent($event)) {
                                $results['new_events_found']++;
                                $results['events'][] = [
                                    'calendar_id' => $calendar['calendar_id'],
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

    /**
     * Find bridge mapping by Outlook event ID (replaces legacy mapping lookup)
     */
    private function findBridgeMappingByOutlookEvent($outlookEventId): ?array
    {
        $sql = "
            SELECT * FROM bridge_mappings 
            WHERE (source_bridge = 'outlook' AND source_event_id = :event_id)
               OR (target_bridge = 'outlook' AND target_event_id = :event_id)
            LIMIT 1
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['event_id' => $outlookEventId]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
}
