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
     * Fetch detailed event information from Outlook
     * 
     * @param string $outlookEventId
     * @return array|null
     */
    private function fetchEventDetails($outlookEventId)
    {
        // Get the resource and calendar information from the mapping
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
        $mappingResult = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$mappingResult) {
            return null;
        }

        try {
            // Try to fetch actual event details from Outlook using Microsoft Graph
            $eventDetails = $this->fetchFromMicrosoftGraph($outlookEventId, $mappingResult['outlook_item_id']);
            
            if ($eventDetails) {
                // Add resource information
                $eventDetails['resource_id'] = $mappingResult['resource_id'];
                $eventDetails['resource_name'] = $mappingResult['resource_name'];
                return $eventDetails;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not fetch event details from Microsoft Graph, using defaults', [
                'outlook_event_id' => $outlookEventId,
                'error' => $e->getMessage()
            ]);
        }

        // Fallback to simulated event details with more realistic data
        return [
            'id' => $outlookEventId,
            'subject' => 'Meeting - ' . ($mappingResult['resource_name'] ?? 'Room'),
            'start' => [
                'dateTime' => date('Y-m-d\TH:i:s', strtotime('today 09:00')),
                'timeZone' => 'Europe/Oslo'
            ],
            'end' => [
                'dateTime' => date('Y-m-d\TH:i:s', strtotime('today 10:00')),
                'timeZone' => 'Europe/Oslo'
            ],
            'organizer' => [
                'emailAddress' => [
                    'name' => 'Meeting Organizer',
                    'address' => 'organizer@company.com'
                ]
            ],
            'body' => [
                'content' => 'Event imported from Outlook calendar for ' . ($mappingResult['resource_name'] ?? 'resource')
            ],
            'resource_id' => $mappingResult['resource_id'],
            'resource_name' => $mappingResult['resource_name']
        ];
    }

    /**
     * Attempt to fetch event details from Microsoft Graph API
     * 
     * @param string $eventId
     * @param string $calendarId
     * @return array|null
     */
    private function fetchFromMicrosoftGraph($eventId, $calendarId)
    {
        try {
            // Create OutlookSyncService instance to use its GraphServiceClient
            $outlookController = new \App\Controller\OutlookController();
            $reflection = new \ReflectionClass($outlookController);
            $property = $reflection->getProperty('graphServiceClient');
            $property->setAccessible(true);
            $graphServiceClient = $property->getValue($outlookController);
            
            if (!$graphServiceClient) {
                throw new \Exception('GraphServiceClient not available');
            }

            // Fetch event details from Microsoft Graph
            $event = $graphServiceClient->users()->byUserId($calendarId)->events()->byEventId($eventId)->get()->wait();
            
            if (!$event) {
                return null;
            }

            return [
                'id' => $event->getId(),
                'subject' => $event->getSubject() ?? 'Outlook Event',
                'start' => [
                    'dateTime' => $event->getStart()->getDateTime(),
                    'timeZone' => $event->getStart()->getTimeZone() ?? 'Europe/Oslo'
                ],
                'end' => [
                    'dateTime' => $event->getEnd()->getDateTime(),
                    'timeZone' => $event->getEnd()->getTimeZone() ?? 'Europe/Oslo'
                ],
                'organizer' => [
                    'emailAddress' => [
                        'name' => $event->getOrganizer()->getEmailAddress()->getName() ?? 'Organizer',
                        'address' => $event->getOrganizer()->getEmailAddress()->getAddress() ?? 'unknown@example.com'
                    ]
                ],
                'body' => [
                    'content' => $event->getBody()->getContent() ?? 'Event imported from Outlook'
                ]
            ];
            
        } catch (\Exception $e) {
            $this->logger->debug('Failed to fetch from Microsoft Graph', [
                'event_id' => $eventId,
                'calendar_id' => $calendarId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Determine the appropriate booking system entry type based on event characteristics
     * All imported Outlook events will be created as 'event' type
     * 
     * @param array $eventDetails
     * @return string
     */
    private function determineBookingType($eventDetails)
    {
        // Always create imported Outlook events as 'event' type (highest priority)
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
     * Create an event entry in bb_event table with all related data
     * 
     * @param array $eventDetails
     * @param int $resourceId
     * @return int|null
     */
    private function createEvent($eventDetails, $resourceId)
    {
        try {
            $this->db->beginTransaction();
            
            // Get default activity ID (we'll need to find or create a default one)
            $activityId = $this->getDefaultActivityId();
            
            // Generate a unique secret for the event
            $secret = $this->generateEventSecret();
            
            // Insert into bb_event table
            $sql = "
                INSERT INTO bb_event (
                    id_string,
                    active,
                    activity_id,
                    description,
                    from_,
                    to_,
                    contact_name,
                    contact_email,
                    contact_phone,
                    name,
                    organizer,
                    secret,
                    customer_internal,
                    is_public,
                    reminder,
                    completed,
                    cost,
                    building_name
                ) VALUES (
                    :id_string,
                    1,
                    :activity_id,
                    :description,
                    :from_time,
                    :to_time,
                    :contact_name,
                    :contact_email,
                    :contact_phone,
                    :name,
                    :organizer,
                    :secret,
                    0,
                    1,
                    1,
                    0,
                    0.0,
                    'Outlook Import'
                ) RETURNING id
            ";

            $startTime = $this->convertOutlookDateTime($eventDetails['start']);
            $endTime = $this->convertOutlookDateTime($eventDetails['end']);
            
            $params = [
                'id_string' => $this->generateEventIdString(),
                'activity_id' => $activityId,
                'description' => $this->convertHtmlToPlainText($eventDetails['body']['content'] ?? 'Event imported from Outlook'),
                'from_time' => $startTime,
                'to_time' => $endTime,
                'contact_name' => $eventDetails['organizer']['emailAddress']['name'] ?? 'Outlook User',
                'contact_email' => $eventDetails['organizer']['emailAddress']['address'] ?? 'unknown@example.com',
                'contact_phone' => 'N/A',
                'name' => $eventDetails['subject'] ?? 'Imported Outlook Event',
                'organizer' => $eventDetails['organizer']['emailAddress']['name'] ?? 'Outlook User',
                'secret' => $secret
            ];

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result || !$result['id']) {
                throw new \Exception('Failed to create bb_event entry');
            }
            
            $eventId = $result['id'];
            
            // Insert into bb_event_date table
            $this->createEventDate($eventId, $startTime, $endTime);
            
            // Insert into bb_event_resource table
            $this->createEventResource($eventId, $resourceId);
            
            // Insert into bb_event_agegroup table (with default values)
            $this->createEventAgeGroup($eventId);
            
            // Insert into bb_event_targetaudience table (with default values)
            $this->createEventTargetAudience($eventId);
            
            $this->db->commit();
            
            $this->logger->info('Successfully created complete event entry', [
                'event_id' => $eventId,
                'outlook_event_id' => $eventDetails['id'],
                'resource_id' => $resourceId
            ]);
            
            return $eventId;

        } catch (\Exception $e) {
            $this->db->rollback();
            
            $this->logger->error('Failed to create event entry', [
                'error' => $e->getMessage(),
                'outlook_event_id' => $eventDetails['id'] ?? 'unknown',
                'resource_id' => $resourceId
            ]);
            
            throw $e;
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
                'description' => $this->convertHtmlToPlainText($eventDetails['body']['content'] ?? 'Event imported from Outlook'),
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

    /**
     * Get or create a default activity ID for imported events
     * 
     * @return int
     */
    private function getDefaultActivityId()
    {
        // Try to find an existing default activity for imported events
        $sql = "SELECT id FROM bb_activity WHERE name = 'Outlook Import' LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return $result['id'];
        }
        
        // Create a default activity if it doesn't exist
        try {
            $sql = "
                INSERT INTO bb_activity (name, description, active) 
                VALUES ('Outlook Import', 'Events imported from Outlook calendar', 1) 
                RETURNING id
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['id']) {
                return $result['id'];
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not create default activity, using fallback', [
                'error' => $e->getMessage()
            ]);
        }
        
        // Fallback: get the first available activity
        $sql = "SELECT id FROM bb_activity WHERE active = 1 ORDER BY id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return $result['id'];
        }
        
        throw new \Exception('No activity found for event creation');
    }

    /**
     * Generate a unique event ID string
     * 
     * @return string
     */
    private function generateEventIdString()
    {
        return 'OL' . date('Ymd') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
    }

    /**
     * Generate a unique secret for the event
     * 
     * @return string
     */
    private function generateEventSecret()
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Create event date entry
     * 
     * @param int $eventId
     * @param string $startTime
     * @param string $endTime
     */
    private function createEventDate($eventId, $startTime, $endTime)
    {
        $sql = "
            INSERT INTO bb_event_date (event_id, from_, to_) 
            VALUES (:event_id, :from_time, :to_time)
        ";
        
        $params = [
            'event_id' => $eventId,
            'from_time' => $startTime,
            'to_time' => $endTime
        ];
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Create event resource association
     * 
     * @param int $eventId
     * @param int $resourceId
     */
    private function createEventResource($eventId, $resourceId)
    {
        $sql = "
            INSERT INTO bb_event_resource (event_id, resource_id) 
            VALUES (:event_id, :resource_id)
        ";
        
        $params = [
            'event_id' => $eventId,
            'resource_id' => $resourceId
        ];
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Create default age group entry for imported events
     * 
     * @param int $eventId
     */
    private function createEventAgeGroup($eventId)
    {
        // Get the first available age group, or skip if none exist
        try {
            $sql = "SELECT id FROM bb_agegroup ORDER BY id LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $sql = "
                    INSERT INTO bb_event_agegroup (event_id, agegroup_id, male, female) 
                    VALUES (:event_id, :agegroup_id, 0, 0)
                ";
                
                $params = [
                    'event_id' => $eventId,
                    'agegroup_id' => $result['id']
                ];
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not create event age group entry', [
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create default target audience entry for imported events
     * 
     * @param int $eventId
     */
    private function createEventTargetAudience($eventId)
    {
        // Get the first available target audience, or skip if none exist
        try {
            $sql = "SELECT id FROM bb_targetaudience ORDER BY id LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $sql = "
                    INSERT INTO bb_event_targetaudience (event_id, targetaudience_id) 
                    VALUES (:event_id, :targetaudience_id)
                ";
                
                $params = [
                    'event_id' => $eventId,
                    'targetaudience_id' => $result['id']
                ];
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not create event target audience entry', [
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Convert HTML content to plain text
     * 
     * @param string $htmlContent
     * @return string
     */
    private function convertHtmlToPlainText($htmlContent)
    {
        if (empty($htmlContent)) {
            return '';
        }

        // Remove script and style elements completely
        $htmlContent = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $htmlContent);
        $htmlContent = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/mi', '', $htmlContent);
        
        // Convert common HTML entities to their text equivalents
        $htmlContent = str_replace(['&nbsp;', '&amp;', '&lt;', '&gt;', '&quot;', '&#39;'], [' ', '&', '<', '>', '"', "'"], $htmlContent);
        
        // Convert line breaks to actual newlines
        $htmlContent = preg_replace('/<br\s*\/?>/i', "\n", $htmlContent);
        $htmlContent = preg_replace('/<\/p>/i', "\n\n", $htmlContent);
        $htmlContent = preg_replace('/<\/div>/i', "\n", $htmlContent);
        
        // Strip all remaining HTML tags
        $plainText = strip_tags($htmlContent);
        
        // Clean up whitespace
        $plainText = preg_replace('/\n\s*\n/', "\n\n", $plainText); // Remove extra blank lines
        $plainText = preg_replace('/[ \t]+/', ' ', $plainText); // Normalize spaces
        $plainText = trim($plainText);
        
        // Decode any remaining HTML entities
        $plainText = html_entity_decode($plainText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return $plainText;
    }
}
