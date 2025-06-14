<?php

namespace App\Bridge;

use App\Bridge\AbstractCalendarBridge;

/**
 * BookingSystemBridge - Pure API-based bridge for booking systems
 * 
 * This bridge communicates exclusively through the booking system's REST API.
 * It does NOT directly access booking system database tables (like bb_event).
 * The booking system is responsible for managing its own data layer.
 * 
 * Required API endpoints:
 * - GET /api/events?resource_id={id}&start={date}&end={date} - List events
 * - POST /api/events - Create event  
 * - PUT /api/events/{id} - Update event
 * - DELETE /api/events/{id} - Delete event
 * - GET /api/resources - List available resources
 */
class BookingSystemBridge extends AbstractCalendarBridge
{
    private $apiBaseUrl;
    private $apiKey;
    
    protected function validateConfig()
    {
        $required = ['api_base_url'];
        
        foreach ($required as $key) {
            if (!isset($this->config[$key]) || empty($this->config[$key])) {
                throw new \InvalidArgumentException("BookingSystem bridge requires '{$key}' in configuration");
            }
        }
        
        $this->apiBaseUrl = rtrim($this->config['api_base_url'], '/');
        $this->apiKey = $this->config['api_key'] ?? null;
    }
    
    public function getBridgeType(): string
    {
        return 'booking_system';
    }
    
    public function getCapabilities(): array
    {
        return [
            'supports_webhooks' => true,
            'supports_recurring' => false,
            'supports_all_day' => false,
            'supports_attendees' => true,
            'supports_attachments' => false,
            'max_events_per_request' => 100,
            'rate_limit_per_minute' => 60
        ];
    }
    
    public function getEvents($resourceId, $startDate, $endDate): array
    {
        $this->logOperation('get_events', ['resource_id' => $resourceId]);
        
        return $this->getEventsViaApi($resourceId, $startDate, $endDate);
    }
    
    public function createEvent($resourceId, $event): string
    {
        $this->logOperation('create_event', ['resource_id' => $resourceId]);
        
        return $this->createEventViaApi($resourceId, $event);
    }
    
    public function updateEvent($resourceId, $eventId, $event): bool
    {
        $this->logOperation('update_event', ['resource_id' => $resourceId, 'event_id' => $eventId]);
        
        return $this->updateEventViaApi($resourceId, $eventId, $event);
    }
    
    public function deleteEvent($resourceId, $eventId): bool
    {
        $this->logOperation('delete_event', ['resource_id' => $resourceId, 'event_id' => $eventId]);
        
        return $this->deleteEventViaApi($resourceId, $eventId);
    }
    
    public function getCalendars(): array
    {
        $this->logOperation('get_calendars');
        
        return $this->getCalendarsViaApi();
    }
    
    public function subscribeToChanges($resourceId, $webhookUrl): string
    {
        $this->logOperation('subscribe_to_changes', ['resource_id' => $resourceId, 'webhook_url' => $webhookUrl]);
        
        try {
            $url = "{$this->apiBaseUrl}/api/webhooks/subscribe";
            
            $subscription = [
                'resource_id' => $resourceId,
                'callback_url' => $webhookUrl,
                'events' => ['created', 'updated', 'deleted']
            ];
            
            $response = $this->makeApiRequest('POST', $url, [], $subscription);
            
            return $response['subscription_id'] ?? uniqid('booking_system_');
            
        } catch (\Exception $e) {
            // For direct database access, we can't create webhooks
            // Return a pseudo subscription ID for tracking
            $this->logger->info('Webhook subscription not available, using polling mode');
            return 'polling_' . $resourceId . '_' . uniqid();
        }
    }
    
    public function unsubscribeFromChanges($subscriptionId): bool
    {
        $this->logOperation('unsubscribe_from_changes', ['subscription_id' => $subscriptionId]);
        
        if (strpos($subscriptionId, 'polling_') === 0) {
            // Pseudo subscription for polling mode
            return true;
        }
        
        try {
            $url = "{$this->apiBaseUrl}/api/webhooks/{$subscriptionId}";
            $this->makeApiRequest('DELETE', $url);
            return true;
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to unsubscribe webhook', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    // API Methods
    private function getEventsViaApi($resourceId, $startDate, $endDate): array
    {
        $url = "{$this->apiBaseUrl}/api/resources/{$resourceId}/events";
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'format' => 'json'
        ];
        
        $response = $this->makeApiRequest('GET', $url, $params);
        
        return array_map([$this, 'mapBookingEventToGeneric'], $response['events'] ?? []);
    }
    
    private function createEventViaApi($resourceId, $event): string
    {
        $url = "{$this->apiBaseUrl}/api/resources/{$resourceId}/events";
        
        $bookingEvent = $this->mapGenericEventToBooking($event);
        
        $response = $this->makeApiRequest('POST', $url, [], $bookingEvent);
        
        return (string)$response['event_id'];
    }
    
    private function updateEventViaApi($resourceId, $eventId, $event): bool
    {
        $url = "{$this->apiBaseUrl}/api/resources/{$resourceId}/events/{$eventId}";
        
        $bookingEvent = $this->mapGenericEventToBooking($event);
        
        $response = $this->makeApiRequest('PUT', $url, [], $bookingEvent);
        
        return $response['success'] === true;
    }
    
    private function deleteEventViaApi($resourceId, $eventId): bool
    {
        $url = "{$this->apiBaseUrl}/api/resources/{$resourceId}/events/{$eventId}";
        
        $response = $this->makeApiRequest('DELETE', $url);
        
        return $response['success'] === true;
    }
    
    private function getCalendarsViaApi(): array
    {
        $url = "{$this->apiBaseUrl}/api/resources";
        
        $response = $this->makeApiRequest('GET', $url);
        
        return array_map(function($resource) {
            return [
                'id' => $resource['id'],
                'name' => $resource['name'],
                'description' => $resource['description'] ?? '',
                'type' => 'resource',
                'bridge_type' => $this->getBridgeType(),
                'raw_data' => $resource
            ];
        }, $response['resources'] ?? []);
    }
    
    /**
     * Make API request to booking system
     */
    private function makeApiRequest($method, $url, $params = [], $data = [])
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        if ($this->apiKey) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => !empty($data) ? json_encode($data) : null,
                'timeout' => 30
            ]
        ]);
        
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new \Exception("API request failed: {$method} {$url}");
        }
        
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON response from booking system API");
        }
        
        return $decoded;
    }
    
    /**
     * Map booking system event to generic format
     */
    private function mapBookingEventToGeneric($bookingEvent): array
    {
        return $this->createGenericEvent([
            'id' => $bookingEvent['id'],
            'subject' => $bookingEvent['subject'] ?? $bookingEvent['name'] ?? '',
            'start' => $bookingEvent['start'] ?? $bookingEvent['start_time'],
            'end' => $bookingEvent['end'] ?? $bookingEvent['end_time'],
            'location' => $bookingEvent['location'] ?? $bookingEvent['resource_name'] ?? '',
            'description' => $bookingEvent['description'] ?? '',
            'attendees' => $this->extractAttendees($bookingEvent),
            'organizer' => $bookingEvent['organizer'] ?? $bookingEvent['contact_name'] ?? '',
            'created' => $bookingEvent['created'] ?? $bookingEvent['created_at'] ?? date('c'),
            'last_modified' => $bookingEvent['last_modified'] ?? $bookingEvent['updated_at'] ?? date('c')
        ]);
    }
    
    /**
     * Map generic event to booking system format
     */
    private function mapGenericEventToBooking($event): array
    {
        return [
            'title' => $event['subject'],
            'name' => $event['subject'],
            'start_time' => $event['start'],
            'end_time' => $event['end'],
            'description' => $event['description'] ?? '',
            'contact_name' => $event['organizer'] ?? 'Calendar Bridge',
            'contact_email' => !empty($event['attendees']) ? $event['attendees'][0] : '',
            'source' => 'calendar_bridge',
            'bridge_import' => true
        ];
    }
    
    /**
     * Extract attendees from booking event
     */
    private function extractAttendees($bookingEvent): array
    {
        $attendees = [];
        
        if (!empty($bookingEvent['contact_email'])) {
            $attendees[] = $bookingEvent['contact_email'];
        }
        
        if (!empty($bookingEvent['attendees'])) {
            if (is_array($bookingEvent['attendees'])) {
                $attendees = array_merge($attendees, $bookingEvent['attendees']);
            } else {
                $attendees[] = $bookingEvent['attendees'];
            }
        }
        
        return array_unique(array_filter($attendees));
    }
}
