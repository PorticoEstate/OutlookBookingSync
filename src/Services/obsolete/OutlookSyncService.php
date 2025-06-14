<?php

namespace App\Services;

use Microsoft\Graph\GraphServiceClient;
use Microsoft\Graph\Generated\Models\Event;
use Microsoft\Graph\Generated\Models\DateTimeTimeZone;
use Microsoft\Graph\Generated\Models\ItemBody;
use Microsoft\Graph\Generated\Models\BodyType;
use Microsoft\Graph\Generated\Models\Recipient;
use Microsoft\Graph\Generated\Models\EmailAddress;
use Microsoft\Graph\Generated\Models\SingleValueLegacyExtendedProperty;

class OutlookSyncService
{
    private $graphServiceClient;
    private $calendarMappingService;
    private $logger;

    public function __construct(GraphServiceClient $graphServiceClient, CalendarMappingService $calendarMappingService, $logger)
    {
        $this->graphServiceClient = $graphServiceClient;
        $this->calendarMappingService = $calendarMappingService;
        $this->logger = $logger;
    }

    /**
     * Sync pending calendar items to Outlook
     */
    public function syncPendingItems($limit = 50)
    {
        $pendingItems = $this->calendarMappingService->getPendingSyncItems($limit);
        $results = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'details' => []
        ];

        foreach ($pendingItems as $item) {
            try {
                $result = $this->syncItemToOutlook($item);
                $results['processed']++;
                
                if ($result['action'] === 'created') {
                    $results['created']++;
                } elseif ($result['action'] === 'updated') {
                    $results['updated']++;
                }
                
                $results['details'][] = $result;
                
            } catch (\Exception $e) {
                $results['errors']++;
                $errorMsg = 'Failed to sync item: ' . $e->getMessage();
                
                $this->calendarMappingService->markMappingError(
                    $item['reservation_type'],
                    $item['reservation_id'],
                    $item['resource_id'],
                    $errorMsg
                );
                
                $results['details'][] = [
                    'item_type' => $item['reservation_type'],
                    'item_id' => $item['reservation_id'],
                    'resource_id' => $item['resource_id'],
                    'action' => 'error',
                    'error' => $errorMsg
                ];

                $this->logger->error('Sync error', [
                    'item' => $item['reservation_type'] . ':' . $item['reservation_id'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->logger->info('Sync batch completed', $results);
        return $results;
    }

    /**
     * Sync a single calendar item to Outlook
     */
    public function syncItemToOutlook($mappingItem)
    {
        // Get the full calendar item details
        $calendarItems = $this->calendarMappingService->getCalendarItemsForSync(
            $mappingItem['resource_id'],
            null, // fromDate
            null  // toDate
        );

        // Find the specific item we're syncing
        $calendarItem = null;
        foreach ($calendarItems as $item) {
            if ($item['item_type'] === $mappingItem['reservation_type'] &&
                $item['item_id'] == $mappingItem['reservation_id'] &&
                $item['resource_id'] == $mappingItem['resource_id']) {
                $calendarItem = $item;
                break;
            }
        }

        if (!$calendarItem) {
            throw new \Exception('Calendar item not found for mapping');
        }

        // Check if this is an update or create
        $isUpdate = !empty($mappingItem['outlook_event_id']);
        
        if ($isUpdate) {
            return $this->updateOutlookEvent($calendarItem, $mappingItem);
        } else {
            return $this->createOutlookEvent($calendarItem, $mappingItem);
        }
    }

    /**
     * Create a new Outlook event
     */
    private function createOutlookEvent($calendarItem, $mappingItem)
    {
        $outlookEvent = $this->buildOutlookEvent($calendarItem);
        
        // Create event in the specific calendar (room)
        $calendarId = $mappingItem['outlook_item_id'];
        
        $createdEvent = $this->graphServiceClient
            ->users()
            ->byUserId($calendarId)
            ->events()
            ->post($outlookEvent)
            ->wait();

        $outlookEventId = $createdEvent->getId();

        // Update mapping with the new Outlook event ID
        $this->calendarMappingService->updateMappingWithOutlookEvent(
            $mappingItem['reservation_type'],
            $mappingItem['reservation_id'],
            $mappingItem['resource_id'],
            $outlookEventId,
            'synced'
        );

        $this->logger->info('Created Outlook event', [
            'calendar_item' => $calendarItem['item_type'] . ':' . $calendarItem['item_id'],
            'outlook_event_id' => $outlookEventId,
            'calendar_id' => $calendarId
        ]);

        return [
            'item_type' => $calendarItem['item_type'],
            'item_id' => $calendarItem['item_id'],
            'resource_id' => $calendarItem['resource_id'],
            'action' => 'created',
            'outlook_event_id' => $outlookEventId,
            'title' => $calendarItem['title']
        ];
    }

    /**
     * Update an existing Outlook event
     */
    private function updateOutlookEvent($calendarItem, $mappingItem)
    {
        $outlookEvent = $this->buildOutlookEvent($calendarItem);
        $calendarId = $mappingItem['outlook_item_id'];
        $eventId = $mappingItem['outlook_event_id'];

        // Update the existing event
        $updatedEvent = $this->graphServiceClient
            ->users()
            ->byUserId($calendarId)
            ->events()
            ->byEventId($eventId)
            ->patch($outlookEvent)
            ->wait();

        // Update sync timestamp
        $this->calendarMappingService->updateMappingWithOutlookEvent(
            $mappingItem['reservation_type'],
            $mappingItem['reservation_id'],
            $mappingItem['resource_id'],
            $eventId,
            'synced'
        );

        $this->logger->info('Updated Outlook event', [
            'calendar_item' => $calendarItem['item_type'] . ':' . $calendarItem['item_id'],
            'outlook_event_id' => $eventId,
            'calendar_id' => $calendarId
        ]);

        return [
            'item_type' => $calendarItem['item_type'],
            'item_id' => $calendarItem['item_id'],
            'resource_id' => $calendarItem['resource_id'],
            'action' => 'updated',
            'outlook_event_id' => $eventId,
            'title' => $calendarItem['title']
        ];
    }

    /**
     * Build an Outlook Event object from calendar item data
     */
    private function buildOutlookEvent($calendarItem)
    {
        $event = new Event();
        
        // Set basic properties
        $event->setSubject($this->sanitizeTitle($calendarItem['title']));
        
        // Set start time
        $start = new DateTimeTimeZone();
        $start->setDateTime(date('c', strtotime($calendarItem['start_time'])));
        $start->setTimeZone('Europe/Oslo');
        $event->setStart($start);
        
        // Set end time
        $end = new DateTimeTimeZone();
        $end->setDateTime(date('c', strtotime($calendarItem['end_time'])));
        $end->setTimeZone('Europe/Oslo');
        $event->setEnd($end);
        
        // Set body/description
        $body = new ItemBody();
        $body->setContentType(new BodyType(BodyType::TEXT));
        $body->setContent($calendarItem['description'] ?: $calendarItem['title']);
        $event->setBody($body);
        
        // Set organizer if available
        if (!empty($calendarItem['organizer_email'])) {
            $organizer = new Recipient();
            $emailAddress = new EmailAddress();
            $emailAddress->setName($calendarItem['organizer']);
            $emailAddress->setAddress($calendarItem['organizer_email']);
            $organizer->setEmailAddress($emailAddress);
            $event->setOrganizer($organizer);
        }
        
        // Set other properties
        $event->setIsReminderOn(false);
        $event->setShowAs(new \Microsoft\Graph\Generated\Models\FreeBusyStatus('busy'));
        
        // Add custom properties to track source (for loop prevention)
        $customProperties = [];
        
        $bookingSystemType = new SingleValueLegacyExtendedProperty();
        $bookingSystemType->setId('String {66f5a359-4659-4830-9070-00047ec6ac6e} Name BookingSystemType');
        $bookingSystemType->setValue($calendarItem['item_type']);
        $customProperties[] = $bookingSystemType;
        
        $bookingSystemId = new SingleValueLegacyExtendedProperty();
        $bookingSystemId->setId('String {66f5a359-4659-4830-9070-00047ec6ac6f} Name BookingSystemId');
        $bookingSystemId->setValue((string)$calendarItem['item_id']);
        $customProperties[] = $bookingSystemId;
        
        $event->setSingleValueExtendedProperties($customProperties);
        
        return $event;
    }

    /**
     * Delete an Outlook event
     */
    public function deleteOutlookEvent($mappingItem)
    {
        if (empty($mappingItem['outlook_event_id'])) {
            throw new \Exception('No Outlook event ID to delete');
        }

        $calendarId = $mappingItem['outlook_item_id'];
        $eventId = $mappingItem['outlook_event_id'];

        // Delete the event from Outlook
        $this->graphServiceClient
            ->users()
            ->byUserId($calendarId)
            ->events()
            ->byEventId($eventId)
            ->delete()
            ->wait();

        // Update mapping to mark as deleted/inactive
        $this->calendarMappingService->markMappingError(
            $mappingItem['reservation_type'],
            $mappingItem['reservation_id'],
            $mappingItem['resource_id'],
            'Event deleted from booking system'
        );

        $this->logger->info('Deleted Outlook event', [
            'outlook_event_id' => $eventId,
            'calendar_id' => $calendarId
        ]);

        return [
            'action' => 'deleted',
            'outlook_event_id' => $eventId
        ];
    }

    /**
     * Sanitize title for Outlook
     */
    private function sanitizeTitle($title)
    {
        return substr(trim($title), 0, 255) ?: 'Unnamed Event';
    }

    /**
     * Check if an Outlook event was created by our sync service
     */
    public function isOurSyncEvent($outlookEvent)
    {
        $extendedProperties = $outlookEvent->getSingleValueExtendedProperties();
        
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
     * Fetch existing Outlook events from all room calendars
     */
    public function fetchOutlookEvents($fromDate = null, $toDate = null)
    {
        $fromDate = $fromDate ?: date('Y-m-d\TH:i:s\Z');
        $toDate = $toDate ?: date('Y-m-d\TH:i:s\Z', strtotime('+3 months'));
        
        // Get all resource mappings
        $resourceMappings = $this->calendarMappingService->getResourceMappings();
        $outlookEvents = [];
        
        foreach ($resourceMappings as $mapping) {
            try {
                $calendarId = $mapping['outlook_item_id'];
                $resourceId = $mapping['resource_id'];
                
                // Fetch events from this calendar
                $events = $this->getCalendarEvents($calendarId, $fromDate, $toDate);
                
                foreach ($events as $event) {
                    // Skip events that were created by our sync service
                    if ($this->isOurSyncEvent($event)) {
                        continue;
                    }
                    
                    $outlookEvents[] = [
                        'outlook_event_id' => $event->getId(),
                        'resource_id' => $resourceId,
                        'calendar_id' => $calendarId,
                        'subject' => $event->getSubject() ?: 'No Subject',
                        'start_time' => $event->getStart()->getDateTime(),
                        'end_time' => $event->getEnd()->getDateTime(),
                        'organizer' => $event->getOrganizer() ? $event->getOrganizer()->getEmailAddress()->getName() : '',
                        'organizer_email' => $event->getOrganizer() ? $event->getOrganizer()->getEmailAddress()->getAddress() : '',
                        'description' => $event->getBody() ? $event->getBody()->getContent() : '',
                        'resource_name' => $mapping['outlook_item_name']
                    ];
                }
                
            } catch (\Exception $e) {
                $this->logger->error('Failed to fetch events from calendar', [
                    'calendar_id' => $calendarId,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $outlookEvents;
    }
    
    /**
     * Get events from a specific calendar
     */
    private function getCalendarEvents($calendarId, $fromDate, $toDate)
    {
        // Build the filter query
        $filter = "start/dateTime ge '{$fromDate}' and end/dateTime le '{$toDate}'";
        
        // Create request configuration
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
            
        return $events->getValue() ?: [];
    }
    
    /**
     * Populate mapping table with existing Outlook events
     */
    public function populateFromOutlook($fromDate = null, $toDate = null)
    {
        $outlookEvents = $this->fetchOutlookEvents($fromDate, $toDate);
        $created = 0;
        $errors = [];
        
        foreach ($outlookEvents as $event) {
            try {
                // Check if mapping already exists
                $existingMapping = $this->calendarMappingService->findMappingByOutlookEvent($event['outlook_event_id']);
                
                if (!$existingMapping) {
                    // Create new mapping entry for Outlook-originated event
                    $this->calendarMappingService->createOutlookOriginatedMapping(
                        $event['outlook_event_id'],
                        $event['resource_id'],
                        $event['calendar_id'],
                        $event['subject'],
                        $event['start_time'],
                        $event['end_time'],
                        $event['organizer'],
                        $event['organizer_email'],
                        $event['description']
                    );
                    $created++;
                }
                
            } catch (\Exception $e) {
                $errors[] = [
                    'outlook_event_id' => $event['outlook_event_id'],
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $this->logger->info('Populated mappings from Outlook', [
            'created' => $created,
            'errors_count' => count($errors)
        ]);
        
        return ['created' => $created, 'errors' => $errors];
    }
}
