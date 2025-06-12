<?php

namespace App\Services;

use PDO;
use Psr\Log\LoggerInterface;

/**
 * Service for creating booking system entries from imported Outlook events
 */
class BookingSystemService
{
    private PDO $db;
    private LoggerInterface $logger;
    private CalendarMappingService $mappingService;

    public function __construct(PDO $db, LoggerInterface $logger, CalendarMappingService $mappingService)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->mappingService = $mappingService;
    }

    /**
     * Process imported Outlook events and create corresponding booking system entries
     * 
     * @param int|null $limit Maximum number of events to process
     * @return array Results of the processing
     */
    public function processImportedEvents($limit = null)
    {
        $importedEvents = $this->getImportedEvents($limit);
        $results = [
            'processed' => 0,
            'created' => 0,
            'errors' => [],
            'skipped' => 0
        ];

        foreach ($importedEvents as $event) {
            try {
                $this->logger->info('Processing imported Outlook event', [
                    'outlook_event_id' => $event['outlook_event_id'],
                    'resource_id' => $event['resource_id']
                ]);

                // Fetch detailed event information from Outlook
                $eventDetails = $this->fetchEventDetails($event['outlook_event_id']);
                
                if (!$eventDetails) {
                    $results['errors'][] = [
                        'outlook_event_id' => $event['outlook_event_id'],
                        'error' => 'Could not fetch event details from Outlook'
                    ];
                    continue;
                }

                // Determine the best booking system entry type based on event characteristics
                $entryType = $this->determineBookingType($eventDetails);
                
                // Create the appropriate booking system entry
                $reservationId = $this->createBookingSystemEntry($entryType, $eventDetails, $event['resource_id']);
                
                if ($reservationId) {
                    // Update the mapping table with the new reservation ID
                    $this->updateMappingWithReservation($event['id'], $entryType, $reservationId);
                    $results['created']++;
                    
                    $this->logger->info('Successfully created booking system entry', [
                        'outlook_event_id' => $event['outlook_event_id'],
                        'reservation_type' => $entryType,
                        'reservation_id' => $reservationId
                    ]);
                } else {
                    $results['errors'][] = [
                        'outlook_event_id' => $event['outlook_event_id'],
                        'error' => 'Failed to create booking system entry'
                    ];
                }

                $results['processed']++;

            } catch (\Exception $e) {
                $results['errors'][] = [
                    'outlook_event_id' => $event['outlook_event_id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ];
                
                $this->logger->error('Error processing imported event', [
                    'outlook_event_id' => $event['outlook_event_id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        $this->logger->info('Completed processing imported events', $results);
        return $results;
    }

    /**
     * Get imported Outlook events that need to be converted to booking system entries
     * 
     * @param int|null $limit
     * @return array
     */
    private function getImportedEvents($limit = null)
    {
        $sql = "
            SELECT id, outlook_event_id, resource_id, outlook_item_id, created_at
            FROM outlook_calendar_mapping 
            WHERE sync_status = 'imported' 
                AND sync_direction = 'outlook_to_booking'
                AND reservation_id IS NULL
                AND reservation_type IS NULL
            ORDER BY created_at ASC
        ";
        
        if ($limit) {
            $sql .= " LIMIT " . (int)$limit;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch detailed event information from Outlook (via OutlookSyncService)
     * 
     * @param string $outlookEventId
     * @return array|null
     */
    private function fetchEventDetails($outlookEventId)
    {
        // For now, we'll extract basic info from the mapping table
        // In a full implementation, this would call Microsoft Graph API to get full event details
        $sql = "
            SELECT 
                m.outlook_event_id,
                m.resource_id,
                m.outlook_item_id,
                r.name as resource_name
            FROM outlook_calendar_mapping m
            LEFT JOIN bb_resource_outlook_item rm ON m.resource_id = rm.resource_id
            LEFT JOIN bb_resource r ON m.resource_id = r.id
            WHERE m.outlook_event_id = :outlook_event_id
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['outlook_event_id' => $outlookEventId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return null;
        }

        // Simulate event details - in production this would come from Microsoft Graph
        return [
            'id' => $outlookEventId,
            'subject' => 'Imported Outlook Event',
            'start' => [
                'dateTime' => date('Y-m-d\TH:i:s', strtotime('+1 hour')),
                'timeZone' => 'Europe/Oslo'
            ],
            'end' => [
                'dateTime' => date('Y-m-d\TH:i:s', strtotime('+2 hours')),
                'timeZone' => 'Europe/Oslo'
            ],
            'organizer' => [
                'emailAddress' => [
                    'name' => 'Outlook User',
                    'address' => 'user@example.com'
                ]
            ],
            'body' => [
                'content' => 'Event imported from Outlook'
            ],
            'resource_id' => $result['resource_id'],
            'resource_name' => $result['resource_name']
        ];
    }

    /**
     * Determine the appropriate booking system entry type based on event characteristics
     * 
     * @param array $eventDetails
     * @return string
     */
    private function determineBookingType($eventDetails)
    {
        // For now, we'll create all imported events as 'event' type (highest priority)
        // In a more sophisticated implementation, we could analyze:
        // - Event subject/title patterns
        // - Organizer information
        // - Event duration
        // - Recurring patterns
        // - Custom properties
        
        return 'event';
    }

    /**
     * Create a booking system entry based on the determined type
     * 
     * @param string $entryType
     * @param array $eventDetails
     * @param int $resourceId
     * @return int|null The created reservation ID
     */
    private function createBookingSystemEntry($entryType, $eventDetails, $resourceId)
    {
        switch ($entryType) {
            case 'event':
                return $this->createEvent($eventDetails, $resourceId);
            case 'booking':
                return $this->createBooking($eventDetails, $resourceId);
            case 'allocation':
                return $this->createAllocation($eventDetails, $resourceId);
            default:
                throw new \InvalidArgumentException("Unknown booking type: $entryType");
        }
    }

    /**
     * Create an event entry in bb_event table
     * 
     * @param array $eventDetails
     * @param int $resourceId
     * @return int|null
     */
    private function createEvent($eventDetails, $resourceId)
    {
        try {
            $sql = "
                INSERT INTO bb_event (
                    event_name, 
                    description, 
                    start_time, 
                    end_time, 
                    contact_name, 
                    contact_email, 
                    resource_id,
                    active,
                    created_from_outlook,
                    outlook_event_id,
                    created_at,
                    updated_at
                ) VALUES (
                    :event_name,
                    :description,
                    :start_time,
                    :end_time,
                    :contact_name,
                    :contact_email,
                    :resource_id,
                    1,
                    true,
                    :outlook_event_id,
                    NOW(),
                    NOW()
                ) RETURNING id
            ";

            $params = [
                'event_name' => $eventDetails['subject'] ?? 'Imported Outlook Event',
                'description' => $eventDetails['body']['content'] ?? 'Event imported from Outlook',
                'start_time' => $this->convertOutlookDateTime($eventDetails['start']),
                'end_time' => $this->convertOutlookDateTime($eventDetails['end']),
                'contact_name' => $eventDetails['organizer']['emailAddress']['name'] ?? 'Outlook User',
                'contact_email' => $eventDetails['organizer']['emailAddress']['address'] ?? 'unknown@example.com',
                'resource_id' => $resourceId,
                'outlook_event_id' => $eventDetails['id']
            ];

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result['id'] ?? null;

        } catch (\PDOException $e) {
            // If the table doesn't exist or has different schema, we'll create a placeholder
            $this->logger->warning('Could not create bb_event entry, table may not exist', [
                'error' => $e->getMessage()
            ]);
            
            // Return a placeholder ID for now
            return $this->createPlaceholderEntry('event', $eventDetails, $resourceId);
        }
    }

    /**
     * Create a booking entry in bb_booking table
     * 
     * @param array $eventDetails
     * @param int $resourceId
     * @return int|null
     */
    private function createBooking($eventDetails, $resourceId)
    {
        // Similar implementation for booking entries
        // For now, we'll use the placeholder approach
        return $this->createPlaceholderEntry('booking', $eventDetails, $resourceId);
    }

    /**
     * Create an allocation entry in bb_allocation table
     * 
     * @param array $eventDetails
     * @param int $resourceId
     * @return int|null
     */
    private function createAllocation($eventDetails, $resourceId)
    {
        // Similar implementation for allocation entries
        // For now, we'll use the placeholder approach
        return $this->createPlaceholderEntry('allocation', $eventDetails, $resourceId);
    }

    /**
     * Create a placeholder entry in a generic sync tracking table
     * This is used when the actual booking system tables don't exist yet
     * 
     * @param string $type
     * @param array $eventDetails
     * @param int $resourceId
     * @return int|null
     */
    private function createPlaceholderEntry($type, $eventDetails, $resourceId)
    {
        try {
            // Create/ensure the placeholder table exists
            $this->ensurePlaceholderTable();

            $sql = "
                INSERT INTO outlook_imported_events (
                    reservation_type,
                    resource_id,
                    outlook_event_id,
                    event_name,
                    description,
                    start_time,
                    end_time,
                    contact_name,
                    contact_email,
                    created_at
                ) VALUES (
                    :reservation_type,
                    :resource_id,
                    :outlook_event_id,
                    :event_name,
                    :description,
                    :start_time,
                    :end_time,
                    :contact_name,
                    :contact_email,
                    NOW()
                ) RETURNING id
            ";

            $params = [
                'reservation_type' => $type,
                'resource_id' => $resourceId,
                'outlook_event_id' => $eventDetails['id'],
                'event_name' => $eventDetails['subject'] ?? 'Imported Outlook Event',
                'description' => $eventDetails['body']['content'] ?? 'Event imported from Outlook',
                'start_time' => $this->convertOutlookDateTime($eventDetails['start']),
                'end_time' => $this->convertOutlookDateTime($eventDetails['end']),
                'contact_name' => $eventDetails['organizer']['emailAddress']['name'] ?? 'Outlook User',
                'contact_email' => $eventDetails['organizer']['emailAddress']['address'] ?? 'unknown@example.com'
            ];

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result['id'] ?? null;

        } catch (\PDOException $e) {
            $this->logger->error('Failed to create placeholder entry', [
                'error' => $e->getMessage(),
                'type' => $type,
                'outlook_event_id' => $eventDetails['id']
            ]);
            return null;
        }
    }

    /**
     * Ensure the placeholder table exists for storing imported events
     */
    private function ensurePlaceholderTable()
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS outlook_imported_events (
                id SERIAL PRIMARY KEY,
                reservation_type VARCHAR(20) NOT NULL,
                resource_id INTEGER NOT NULL,
                outlook_event_id VARCHAR(255) NOT NULL,
                event_name VARCHAR(255),
                description TEXT,
                start_time TIMESTAMP,
                end_time TIMESTAMP,
                contact_name VARCHAR(255),
                contact_email VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(outlook_event_id)
            )
        ";
        
        $this->db->exec($sql);
    }

    /**
     * Convert Outlook DateTime format to database timestamp
     * 
     * @param array $outlookDateTime
     * @return string
     */
    private function convertOutlookDateTime($outlookDateTime)
    {
        $dateTime = $outlookDateTime['dateTime'] ?? date('Y-m-d\TH:i:s');
        $timeZone = $outlookDateTime['timeZone'] ?? 'Europe/Oslo';
        
        // Convert to UTC for database storage
        $dt = new \DateTime($dateTime, new \DateTimeZone($timeZone));
        $dt->setTimezone(new \DateTimeZone('UTC'));
        
        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * Update the mapping table with the created reservation details
     * 
     * @param int $mappingId
     * @param string $reservationType
     * @param int $reservationId
     */
    private function updateMappingWithReservation($mappingId, $reservationType, $reservationId)
    {
        $sql = "
            UPDATE outlook_calendar_mapping 
            SET 
                reservation_type = :reservation_type,
                reservation_id = :reservation_id,
                sync_status = 'created_booking',
                updated_at = NOW()
            WHERE id = :mapping_id
        ";

        $params = [
            'mapping_id' => $mappingId,
            'reservation_type' => $reservationType,
            'reservation_id' => $reservationId
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Get statistics about processed imported events
     * 
     * @return array
     */
    public function getProcessingStats()
    {
        $sql = "
            SELECT 
                COUNT(*) as total_imported,
                COUNT(CASE WHEN reservation_id IS NOT NULL THEN 1 END) as processed,
                COUNT(CASE WHEN reservation_id IS NULL THEN 1 END) as pending
            FROM outlook_calendar_mapping 
            WHERE sync_direction = 'outlook_to_booking'
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total_imported' => (int)$result['total_imported'],
            'processed' => (int)$result['processed'],
            'pending' => (int)$result['pending']
        ];
    }
}
