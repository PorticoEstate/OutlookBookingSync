<?php

namespace App\Services;

use PDO;
use Psr\Log\LoggerInterface;
use Microsoft\Graph\GraphServiceClient;

/**
 * Service for managing Microsoft Graph webhooks to detect Outlook-side changes
 */
class OutlookWebhookService
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
     * Create webhook subscriptions for all room calendars
     * 
     * @return array Results of subscription creation
     */
    public function createWebhookSubscriptions()
    {
        $results = [
            'success' => true,
            'subscriptions_created' => 0,
            'errors' => [],
            'subscriptions' => []
        ];

        try {
            // Get all room calendars from mapping
            $roomCalendars = $this->getRoomCalendars();
            
            foreach ($roomCalendars as $calendar) {
                try {
                    $subscription = $this->createSubscriptionForCalendar($calendar['outlook_item_id']);
                    
                    if ($subscription['success']) {
                        $results['subscriptions_created']++;
                        $results['subscriptions'][] = [
                            'calendar_id' => $calendar['outlook_item_id'],
                            'resource_id' => $calendar['resource_id'],
                            'subscription_id' => $subscription['subscription_id'],
                            'expires_at' => $subscription['expires_at']
                        ];
                        
                        // Store subscription in database
                        $this->storeSubscription(
                            $subscription['subscription_id'],
                            $calendar['outlook_item_id'],
                            $calendar['resource_id'],
                            $subscription['expires_at']
                        );
                    } else {
                        $results['errors'][] = [
                            'calendar_id' => $calendar['outlook_item_id'],
                            'error' => $subscription['error']
                        ];
                    }
                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'calendar_id' => $calendar['outlook_item_id'],
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            if (!empty($results['errors'])) {
                $results['success'] = false;
            }
            
            $this->logger->info('Webhook subscription creation completed', $results);
            
        } catch (\Exception $e) {
            $results['success'] = false;
            $results['errors'][] = 'Failed to create webhook subscriptions: ' . $e->getMessage();
            
            $this->logger->error('Failed to create webhook subscriptions', [
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }

    /**
     * Create a webhook subscription for a specific calendar
     * 
     * @param string $calendarId
     * @return array
     */
    private function createSubscriptionForCalendar($calendarId)
    {
        try {
            // Create subscription object
            $subscription = new \Microsoft\Graph\Generated\Models\Subscription();
            $subscription->setChangeType('created,updated,deleted');
            $subscription->setNotificationUrl($this->getWebhookNotificationUrl());
            $subscription->setResource("/users/{$calendarId}/events");
            $subscription->setExpirationDateTime(new \DateTime('+4230 minutes')); // Max 3 days
            $subscription->setClientState($this->generateClientState());

            // Create the subscription
            $createdSubscription = $this->graphServiceClient
                ->subscriptions()
                ->post($subscription)
                ->wait();

            return [
                'success' => true,
                'subscription_id' => $createdSubscription->getId(),
                'expires_at' => $createdSubscription->getExpirationDateTime()->format('Y-m-d H:i:s')
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process webhook notification from Microsoft Graph
     * 
     * @param array $notification
     * @return array
     */
    public function processWebhookNotification($notification)
    {
        $results = [
            'success' => false,
            'processed' => 0,
            'errors' => []
        ];

        try {
            $this->logger->info('Processing webhook notification', $notification);

            // Validate notification
            if (!$this->validateNotification($notification)) {
                throw new \Exception('Invalid notification received');
            }

            // Process each notification item
            foreach ($notification['value'] as $item) {
                try {
                    $this->processNotificationItem($item);
                    $results['processed']++;
                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'item' => $item,
                        'error' => $e->getMessage()
                    ];
                }
            }

            $results['success'] = true;
            
            $this->logger->info('Webhook notification processing completed', $results);

        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
            
            $this->logger->error('Failed to process webhook notification', [
                'notification' => $notification,
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }

    /**
     * Process a single notification item
     * 
     * @param array $item
     */
    private function processNotificationItem($item)
    {
        $changeType = $item['changeType'];
        $resourceUrl = $item['resource'];
        
        // Extract event ID from resource URL
        // Format: /users/{calendarId}/events/{eventId}
        if (preg_match('/\/users\/([^\/]+)\/events\/([^\/\?]+)/', $resourceUrl, $matches)) {
            $calendarId = $matches[1];
            $eventId = $matches[2];
            
            $this->logger->info('Processing Outlook event change', [
                'change_type' => $changeType,
                'calendar_id' => $calendarId,
                'event_id' => $eventId
            ]);

            switch ($changeType) {
                case 'deleted':
                    $this->handleEventDeletion($eventId, $calendarId);
                    break;
                    
                case 'created':
                    $this->handleEventCreation($eventId, $calendarId);
                    break;
                    
                case 'updated':
                    $this->handleEventUpdate($eventId, $calendarId);
                    break;
            }
        } else {
            $this->logger->warning('Could not parse resource URL', [
                'resource_url' => $resourceUrl
            ]);
        }
    }

    /**
     * Handle Outlook event deletion
     * 
     * @param string $eventId
     * @param string $calendarId
     */
    private function handleEventDeletion($eventId, $calendarId)
    {
        $this->logger->info('Handling Outlook event deletion', [
            'event_id' => $eventId,
            'calendar_id' => $calendarId
        ]);

        // Check if this event has a mapping in our system
        $mapping = $this->mappingService->findMappingByOutlookEvent($eventId);
        
        if ($mapping) {
            $this->logger->info('Found mapping for deleted Outlook event', [
                'event_id' => $eventId,
                'mapping_id' => $mapping['id'],
                'reservation_type' => $mapping['reservation_type'],
                'reservation_id' => $mapping['reservation_id']
            ]);

            // Handle the cancellation using our existing cancellation service
            $result = $this->cancellationService->handleOutlookCancellation($eventId);
            
            if ($result['success']) {
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
        } else {
            $this->logger->info('No mapping found for deleted Outlook event', [
                'event_id' => $eventId
            ]);
        }
    }

    /**
     * Handle Outlook event creation (for reverse sync)
     * 
     * @param string $eventId
     * @param string $calendarId
     */
    private function handleEventCreation($eventId, $calendarId)
    {
        $this->logger->info('Handling Outlook event creation', [
            'event_id' => $eventId,
            'calendar_id' => $calendarId
        ]);

        // Check if this is one of our own events (to prevent loops)
        try {
            $event = $this->graphServiceClient
                ->users()
                ->byUserId($calendarId)
                ->events()
                ->byEventId($eventId)
                ->get()
                ->wait();

            // Check for our custom properties
            $isOurEvent = false;
            $extendedProperties = $event->getSingleValueExtendedProperties();
            
            if ($extendedProperties) {
                foreach ($extendedProperties as $property) {
                    if (strpos($property->getId(), 'BookingSystemType') !== false) {
                        $isOurEvent = true;
                        break;
                    }
                }
            }

            if (!$isOurEvent) {
                $this->logger->info('External Outlook event created, may need reverse sync', [
                    'event_id' => $eventId,
                    'subject' => $event->getSubject()
                ]);
                
                // Here you could trigger reverse sync logic if needed
                // For now, just log it
            } else {
                $this->logger->info('Own event created in Outlook, ignoring', [
                    'event_id' => $eventId
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->warning('Could not fetch event details for creation', [
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle Outlook event update
     * 
     * @param string $eventId
     * @param string $calendarId
     */
    private function handleEventUpdate($eventId, $calendarId)
    {
        $this->logger->info('Handling Outlook event update', [
            'event_id' => $eventId,
            'calendar_id' => $calendarId
        ]);

        // Check if this event has a mapping in our system
        $mapping = $this->mappingService->findMappingByOutlookEvent($eventId);
        
        if ($mapping && $mapping['sync_direction'] === 'booking_to_outlook') {
            $this->logger->info('Outlook event updated - may need to sync back to booking system', [
                'event_id' => $eventId,
                'mapping_id' => $mapping['id']
            ]);
            
            // Here you could implement update sync logic if needed
            // For now, just log it
        }
    }

    /**
     * Get all room calendars for webhook subscription
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
     * Store webhook subscription in database
     * 
     * @param string $subscriptionId
     * @param string $calendarId
     * @param int $resourceId
     * @param string $expiresAt
     */
    private function storeSubscription($subscriptionId, $calendarId, $resourceId, $expiresAt)
    {
        $sql = "
            INSERT INTO outlook_webhook_subscriptions 
            (subscription_id, calendar_id, resource_id, expires_at, created_at, updated_at)
            VALUES (:subscription_id, :calendar_id, :resource_id, :expires_at, NOW(), NOW())
            ON CONFLICT (calendar_id) 
            DO UPDATE SET 
                subscription_id = :subscription_id,
                expires_at = :expires_at,
                updated_at = NOW()
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'subscription_id' => $subscriptionId,
            'calendar_id' => $calendarId,
            'resource_id' => $resourceId,
            'expires_at' => $expiresAt
        ]);
    }

    /**
     * Renew expiring webhook subscriptions
     * 
     * @return array
     */
    public function renewExpiringSubscriptions()
    {
        $results = [
            'success' => true,
            'renewed' => 0,
            'errors' => []
        ];

        try {
            // Find subscriptions expiring in the next 6 hours
            $sql = "
                SELECT * FROM outlook_webhook_subscriptions 
                WHERE expires_at <= NOW() + INTERVAL '6 hours'
                AND expires_at > NOW()
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $expiringSubscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($expiringSubscriptions as $subscription) {
                try {
                    $this->renewSubscription($subscription['subscription_id']);
                    $results['renewed']++;
                    
                    $this->logger->info('Renewed webhook subscription', [
                        'subscription_id' => $subscription['subscription_id'],
                        'calendar_id' => $subscription['calendar_id']
                    ]);
                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'subscription_id' => $subscription['subscription_id'],
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
     * Renew a specific subscription
     * 
     * @param string $subscriptionId
     */
    private function renewSubscription($subscriptionId)
    {
        $subscription = new \Microsoft\Graph\Generated\Models\Subscription();
        $subscription->setExpirationDateTime(new \DateTime('+4230 minutes')); // Max 3 days

        $this->graphServiceClient
            ->subscriptions()
            ->bySubscriptionId($subscriptionId)
            ->patch($subscription)
            ->wait();

        // Update database
        $sql = "
            UPDATE outlook_webhook_subscriptions 
            SET expires_at = :expires_at, updated_at = NOW()
            WHERE subscription_id = :subscription_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'expires_at' => $subscription->getExpirationDateTime()->format('Y-m-d H:i:s'),
            'subscription_id' => $subscriptionId
        ]);
    }

    /**
     * Get webhook notification URL
     * 
     * @return string
     */
    private function getWebhookNotificationUrl()
    {
        $baseUrl = $_ENV['WEBHOOK_BASE_URL'] ?? 'https://your-server.com';
        return $baseUrl . '/webhook/outlook-notifications';
    }

    /**
     * Generate client state for webhook validation
     * 
     * @return string
     */
    private function generateClientState()
    {
        return hash('sha256', $_ENV['WEBHOOK_CLIENT_SECRET'] ?? 'default-secret' . time());
    }

    /**
     * Validate webhook notification
     * 
     * @param array $notification
     * @return bool
     */
    private function validateNotification($notification)
    {
        // Basic validation - check if notification has required fields
        if (!isset($notification['value']) || !is_array($notification['value'])) {
            return false;
        }

        foreach ($notification['value'] as $item) {
            if (!isset($item['changeType']) || !isset($item['resource'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get webhook subscription statistics
     * 
     * @return array
     */
    public function getWebhookStats()
    {
        $sql = "
            SELECT 
                COUNT(*) as total_subscriptions,
                COUNT(*) FILTER (WHERE expires_at > NOW()) as active_subscriptions,
                COUNT(*) FILTER (WHERE expires_at <= NOW()) as expired_subscriptions,
                MIN(expires_at) as earliest_expiry,
                MAX(expires_at) as latest_expiry
            FROM outlook_webhook_subscriptions
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'statistics' => $stats
        ];
    }
}
