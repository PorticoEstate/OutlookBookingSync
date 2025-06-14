<?php

namespace App\Bridge;

use App\Bridge\AbstractCalendarBridge;

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
        
        // Try API first, fallback to direct database if API not available
        try {
            return $this->getEventsViaApi($resourceId, $startDate, $endDate);
        } catch (\Exception $e) {
            $this->logger->warning('API unavailable, falling back to direct database access', [
                'error' => $e->getMessage()
            ]);
            return $this->getEventsViaDatabase($resourceId, $startDate, $endDate);
        }
    }
    
    public function createEvent($resourceId, $event): string
    {
        $this->logOperation('create_event', ['resource_id' => $resourceId]);
        
        // Try API first, fallback to direct database if API not available
        try {
            return $this->createEventViaApi($resourceId, $event);
        } catch (\Exception $e) {
            $this->logger->warning('API unavailable, falling back to direct database access', [
                'error' => $e->getMessage()
            ]);
            return $this->createEventViaDatabase($resourceId, $event);
        }
    }
    
    public function updateEvent($resourceId, $eventId, $event): bool
    {
        $this->logOperation('update_event', ['resource_id' => $resourceId, 'event_id' => $eventId]);
        
        // Try API first, fallback to direct database if API not available
        try {
            return $this->updateEventViaApi($resourceId, $eventId, $event);
        } catch (\Exception $e) {
            $this->logger->warning('API unavailable, falling back to direct database access', [
                'error' => $e->getMessage()
            ]);
            return $this->updateEventViaDatabase($resourceId, $eventId, $event);
        }
    }
    
    public function deleteEvent($resourceId, $eventId): bool
    {
        $this->logOperation('delete_event', ['resource_id' => $resourceId, 'event_id' => $eventId]);
        
        // Try API first, fallback to direct database if API not available
        try {
            return $this->deleteEventViaApi($resourceId, $eventId);
        } catch (\Exception $e) {
            $this->logger->warning('API unavailable, falling back to direct database access', [
                'error' => $e->getMessage()
            ]);
            return $this->deleteEventViaDatabase($resourceId, $eventId);
        }
    }
    
    public function getCalendars(): array
    {
        $this->logOperation('get_calendars');
        
        // Try API first, fallback to direct database if API not available
        try {
            return $this->getCalendarsViaApi();
        } catch (\Exception $e) {
            $this->logger->warning('API unavailable, falling back to direct database access', [
                'error' => $e->getMessage()
            ]);
            return $this->getCalendarsViaDatabase();
        }
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
    
    // Database Fallback Methods
    private function getEventsViaDatabase($resourceId, $startDate, $endDate): array
    {
        $sql = "
            SELECT 
                v.id,
                v.reservation_type,
                v.name as subject,
                v.start_time as start,
                v.end_time as end,
                v.description,
                v.contact_name,
                v.contact_email,
                v.organization_name,
                v.resource_name as location,
                v.active
            FROM v_all_calendar_items v
            WHERE v.resource_id = :resource_id
            AND v.start_time >= :start_date
            AND v.end_time <= :end_date
            AND v.active = 1
            ORDER BY v.start_time ASC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':resource_id' => $resourceId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        
        return array_map([$this, 'mapBookingEventToGeneric'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }
    
    private function createEventViaDatabase($resourceId, $event): string
    {
        $this->db->beginTransaction();
        
        try {
            // Insert into bb_event
            $sql = "
                INSERT INTO bb_event (
                    name, description, start_time, end_time, active, 
                    contact_name, contact_email, created_at
                ) VALUES (
                    :name, :description, :start_time, :end_time, 1,
                    :contact_name, :contact_email, CURRENT_TIMESTAMP
                ) RETURNING id
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':name' => $event['subject'],
                ':description' => $event['description'] ?? '',
                ':start_time' => $event['start'],
                ':end_time' => $event['end'],
                ':contact_name' => $event['organizer'] ?? 'Bridge Import',
                ':contact_email' => !empty($event['attendees']) ? $event['attendees'][0] : ''
            ]);
            
            $eventId = $stmt->fetchColumn();
            
            // Link to resource
            $sql = "INSERT INTO bb_event_resource (event_id, resource_id) VALUES (:event_id, :resource_id)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':event_id' => $eventId, ':resource_id' => $resourceId]);
            
            // Add event date
            $sql = "
                INSERT INTO bb_event_date (event_id, event_date, start_time, end_time)
                VALUES (:event_id, :event_date, :start_time, :end_time)
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':event_id' => $eventId,
                ':event_date' => date('Y-m-d', strtotime($event['start'])),
                ':start_time' => date('H:i:s', strtotime($event['start'])),
                ':end_time' => date('H:i:s', strtotime($event['end']))
            ]);
            
            $this->db->commit();
            return (string)$eventId;
            
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    private function updateEventViaDatabase($resourceId, $eventId, $event): bool
    {
        $this->db->beginTransaction();
        
        try {
            // Update bb_event
            $sql = "
                UPDATE bb_event SET
                    name = :name,
                    description = :description,
                    start_time = :start_time,
                    end_time = :end_time,
                    contact_name = :contact_name,
                    contact_email = :contact_email
                WHERE id = :event_id
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':event_id' => $eventId,
                ':name' => $event['subject'],
                ':description' => $event['description'] ?? '',
                ':start_time' => $event['start'],
                ':end_time' => $event['end'],
                ':contact_name' => $event['organizer'] ?? 'Bridge Import',
                ':contact_email' => !empty($event['attendees']) ? $event['attendees'][0] : ''
            ]);
            
            // Update event date
            $sql = "
                UPDATE bb_event_date SET
                    event_date = :event_date,
                    start_time = :start_time,
                    end_time = :end_time
                WHERE event_id = :event_id
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':event_id' => $eventId,
                ':event_date' => date('Y-m-d', strtotime($event['start'])),
                ':start_time' => date('H:i:s', strtotime($event['start'])),
                ':end_time' => date('H:i:s', strtotime($event['end']))
            ]);
            
            $this->db->commit();
            return true;
            
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    private function deleteEventViaDatabase($resourceId, $eventId): bool
    {
        // Soft delete by setting active = 0
        $sql = "UPDATE bb_event SET active = 0 WHERE id = :event_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':event_id' => $eventId]);
        
        return $stmt->rowCount() > 0;
    }
    
    private function getCalendarsViaDatabase(): array
    {
        $sql = "SELECT id, name, description FROM bb_resource WHERE active = 1 ORDER BY name";
        $stmt = $this->db->query($sql);
        
        return array_map(function($resource) {
            return [
                'id' => $resource['id'],
                'name' => $resource['name'],
                'description' => $resource['description'] ?? '',
                'type' => 'resource',
                'bridge_type' => $this->getBridgeType(),
                'raw_data' => $resource
            ];
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
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
