<?php

namespace App\Bridge;

use App\Bridge\AbstractCalendarBridge;

/**
 * BookingSystemBridge - Configurable API bridge for booking systems
 * 
 * This bridge communicates through the booking system's REST API using configurable mappings.
 * It adapts to different API structures without code changes through configuration.
 * 
 * Configuration supports:
 * - Custom endpoint URLs and HTTP methods
 * - Field mapping between bridge format and booking system format
 * - Authentication methods (API key, Bearer token, Basic auth)
 * - Request/response transformations
 * 
 * Example configuration:
 * ```php
 * 'api_endpoints' => [
 *     'list_events' => [
 *         'method' => 'GET',
 *         'url' => '/api/resources/{resource_id}/events',
 *         'params' => ['start_date', 'end_date', 'format' => 'json']
 *     ],
 *     'create_event' => [
 *         'method' => 'POST', 
 *         'url' => '/api/events',
 *         'field_mapping' => ['subject' => 'title', 'start' => 'start_time']
 *     ]
 * ]
 * ```
 */
class BookingSystemBridge extends AbstractCalendarBridge
{
    private $apiBaseUrl;
    private $apiKey;
    private $apiEndpoints;
    private $fieldMappings;
    private $authConfig;
    
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
        
        // Load configurable API mappings or use defaults
        $this->apiEndpoints = $this->config['api_endpoints'] ?? $this->getDefaultApiEndpoints();
        $this->fieldMappings = $this->config['field_mappings'] ?? $this->getDefaultFieldMappings();
        $this->authConfig = $this->config['auth'] ?? $this->getDefaultAuthConfig();
    }
    
    /**
     * Default API endpoint mappings (can be overridden in config)
     */
    private function getDefaultApiEndpoints(): array
    {
        return [
            'list_events' => [
                'method' => 'GET',
                'url' => '/api/resources/{resource_id}/events',
                'params' => ['start_date', 'end_date', 'format' => 'json']
            ],
            'create_event' => [
                'method' => 'POST',
                'url' => '/api/resources/{resource_id}/events'
            ],
            'update_event' => [
                'method' => 'PUT',
                'url' => '/api/resources/{resource_id}/events/{event_id}'
            ],
            'delete_event' => [
                'method' => 'DELETE',
                'url' => '/api/resources/{resource_id}/events/{event_id}'
            ],
            'list_resources' => [
                'method' => 'GET',
                'url' => '/api/resources'
            ]
        ];
    }
    
    /**
     * Default field mappings between bridge format and booking system format
     */
    private function getDefaultFieldMappings(): array
    {
        return [
            'to_booking_system' => [
                'subject' => 'title',
                'start' => 'start_time',
                'end' => 'end_time',
                'description' => 'description',
                'organizer' => 'contact_name',
                'attendees' => 'contact_email'  // First attendee becomes contact_email
            ],
            'from_booking_system' => [
                'title' => 'subject',
                'name' => 'subject',
                'start_time' => 'start',
                'end_time' => 'end',
                'description' => 'description',
                'contact_name' => 'organizer',
                'contact_email' => 'attendees'  // Contact email becomes attendees array
            ]
        ];
    }
    
    /**
     * Default authentication configuration
     */
    private function getDefaultAuthConfig(): array
    {
        return [
            'type' => 'bearer',  // 'bearer', 'basic', 'api_key', 'header'
            'header' => 'Authorization',
            'prefix' => 'Bearer '
        ];
    }
    
    /**
     * Get endpoint configuration with defaults and custom overrides
     * 
     * @param string $endpointName The name of the endpoint
     * @param array $defaultConfig Default configuration for the endpoint
     * @return array Merged endpoint configuration
     */
    private function getEndpointConfig(string $endpointName, array $defaultConfig = []): array
    {
        // Start with the provided default configuration
        $config = $defaultConfig;
        
        // Merge with default endpoint configurations if available
        $defaultEndpoints = $this->getDefaultApiEndpoints();
        if (isset($defaultEndpoints[$endpointName])) {
            $config = array_merge($config, $defaultEndpoints[$endpointName]);
        }
        
        // Merge with custom endpoint configurations from bridge config
        if (isset($this->apiEndpoints[$endpointName])) {
            $config = array_merge($config, $this->apiEndpoints[$endpointName]);
        }
        
        return $config;
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
    
    // Configurable API Methods
    private function getEventsViaApi($resourceId, $startDate, $endDate): array
    {
        $endpoint = $this->apiEndpoints['list_events'];
        $url = $this->buildUrl($endpoint['url'], ['resource_id' => $resourceId]);
        
        $params = [];
        foreach ($endpoint['params'] ?? [] as $key => $value) {
            if (is_numeric($key)) {
                // Dynamic parameter
                switch ($value) {
                    case 'start_date':
                        $params['start_date'] = $startDate;
                        break;
                    case 'end_date':
                        $params['end_date'] = $endDate;
                        break;
                }
            } else {
                // Static parameter
                $params[$key] = $value;
            }
        }
        
        $response = $this->makeConfigurableApiRequest($endpoint['method'], $url, $params);
        
        $events = $response['events'] ?? $response['data'] ?? $response;
        if (!is_array($events)) {
            return [];
        }
        
        return array_map([$this, 'mapBookingEventToGeneric'], $events);
    }
    
    private function createEventViaApi($resourceId, $event): string
    {
        $endpoint = $this->apiEndpoints['create_event'];
        $url = $this->buildUrl($endpoint['url'], ['resource_id' => $resourceId]);
        
        $mappedEvent = $this->mapGenericEventToBooking($event);
        
        $response = $this->makeConfigurableApiRequest($endpoint['method'], $url, [], $mappedEvent);
        
        return $response['event_id'] ?? $response['id'] ?? uniqid('event_');
    }
    
    private function updateEventViaApi($resourceId, $eventId, $event): bool
    {
        $endpoint = $this->apiEndpoints['update_event'];
        $url = $this->buildUrl($endpoint['url'], [
            'resource_id' => $resourceId,
            'event_id' => $eventId
        ]);
        
        $mappedEvent = $this->mapGenericEventToBooking($event);
        
        $response = $this->makeConfigurableApiRequest($endpoint['method'], $url, [], $mappedEvent);
        
        return $response['success'] ?? true;
    }
    
    private function deleteEventViaApi($resourceId, $eventId): bool
    {
        $endpoint = $this->apiEndpoints['delete_event'];
        $url = $this->buildUrl($endpoint['url'], [
            'resource_id' => $resourceId,
            'event_id' => $eventId
        ]);
        
        $response = $this->makeConfigurableApiRequest($endpoint['method'], $url);
        
        return $response['success'] ?? true;
    }
    
    private function getCalendarsViaApi(): array
    {
        $endpoint = $this->apiEndpoints['list_resources'];
        $url = $this->buildUrl($endpoint['url']);
        
        $response = $this->makeConfigurableApiRequest($endpoint['method'], $url);
        
        $resources = $response['resources'] ?? $response['data'] ?? $response;
        if (!is_array($resources)) {
            return [];
        }
        
        return array_map(function($resource) {
            return [
                'id' => $resource['id'],
                'name' => $resource['name'] ?? $resource['title'] ?? '',
                'description' => $resource['description'] ?? '',
                'type' => $resource['type'] ?? 'resource',
                'bridge_type' => $this->getBridgeType(),
                'raw_data' => $resource
            ];
        }, $resources);
    }
    
    /**
     * Build URL with parameter substitution
     */
    private function buildUrl($urlTemplate, $params = []): string
    {
        $url = $this->apiBaseUrl . $urlTemplate;
        
        foreach ($params as $key => $value) {
            $url = str_replace('{' . $key . '}', $value, $url);
        }
        
        return $url;
    }
    
    /**
     * Make API request with configurable authentication
     */
    private function makeConfigurableApiRequest($method, $url, $params = [], $data = [])
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        // Add authentication based on configuration
        if ($this->apiKey) {
            switch ($this->authConfig['type']) {
                case 'bearer':
                    $headers[] = $this->authConfig['header'] . ': ' . $this->authConfig['prefix'] . $this->apiKey;
                    break;
                case 'api_key':
                    $headers[] = 'X-API-Key: ' . $this->apiKey;
                    break;
                case 'header':
                    $headerName = $this->authConfig['header'] ?? 'Authorization';
                    $headers[] = $headerName . ': ' . $this->apiKey;
                    break;
                case 'basic':
                    $headers[] = 'Authorization: Basic ' . base64_encode($this->apiKey);
                    break;
            }
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
     * Map booking system event to generic format using configurable mappings
     */
    private function mapBookingEventToGeneric($bookingEvent): array
    {
        $mappings = $this->fieldMappings['from_booking_system'];
        $genericEvent = [];
        
        // Always include ID
        $genericEvent['id'] = $bookingEvent['id'];
        
        // Apply field mappings
        foreach ($mappings as $bookingField => $genericField) {
            if (isset($bookingEvent[$bookingField])) {
                if ($genericField === 'attendees' && $bookingField === 'contact_email') {
                    // Special handling: contact_email becomes attendees array
                    $genericEvent['attendees'] = [$bookingEvent[$bookingField]];
                } else {
                    $genericEvent[$genericField] = $bookingEvent[$bookingField];
                }
            }
        }
        
        // Fallback for common fields if not mapped
        $fallbacks = [
            'subject' => $bookingEvent['subject'] ?? $bookingEvent['name'] ?? $bookingEvent['title'] ?? '',
            'start' => $bookingEvent['start'] ?? $bookingEvent['start_time'] ?? '',
            'end' => $bookingEvent['end'] ?? $bookingEvent['end_time'] ?? '',
            'location' => $bookingEvent['location'] ?? $bookingEvent['resource_name'] ?? '',
            'description' => $bookingEvent['description'] ?? '',
            'organizer' => $bookingEvent['organizer'] ?? $bookingEvent['contact_name'] ?? '',
            'created' => $bookingEvent['created'] ?? $bookingEvent['created_at'] ?? date('c'),
            'last_modified' => $bookingEvent['last_modified'] ?? $bookingEvent['updated_at'] ?? date('c')
        ];
        
        foreach ($fallbacks as $field => $value) {
            if (!isset($genericEvent[$field]) && !empty($value)) {
                $genericEvent[$field] = $value;
            }
        }
        
        // Handle attendees extraction
        if (!isset($genericEvent['attendees'])) {
            $genericEvent['attendees'] = $this->extractAttendees($bookingEvent);
        }
        
        return $this->createGenericEvent($genericEvent);
    }
    
    /**
     * Map generic event to booking system format using configurable mappings
     */
    private function mapGenericEventToBooking($event): array
    {
        $mappings = $this->fieldMappings['to_booking_system'];
        $bookingEvent = [];
        
        // Apply field mappings
        foreach ($mappings as $genericField => $bookingField) {
            if (isset($event[$genericField])) {
                if ($genericField === 'attendees' && $bookingField === 'contact_email') {
                    // Special handling: first attendee becomes contact_email
                    $attendees = is_array($event['attendees']) ? $event['attendees'] : [$event['attendees']];
                    $bookingEvent['contact_email'] = !empty($attendees) ? $attendees[0] : '';
                } else {
                    $bookingEvent[$bookingField] = $event[$genericField];
                }
            }
        }
        
        // Add metadata
        $bookingEvent['source'] = 'calendar_bridge';
        $bookingEvent['bridge_import'] = true;
        
        return $bookingEvent;
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
    
    /**
     * Get available resources from the booking system
     */
    public function getAvailableResources(): array
    {
        try {
            $endpoint = $this->getEndpointConfig('list_resources', [
                'method' => 'GET',
                'url' => '/api/resources',
                'response_mapping' => [
                    'id' => 'id',
                    'name' => 'name',
                    'type' => 'type',
                    'capacity' => 'capacity'
                ]
            ]);
            
            $response = $this->makeApiRequest($endpoint['method'], $endpoint['url']);
            
            $resources = [];
            $dataKey = $endpoint['response_data_key'] ?? 'data';
            $responseData = isset($response[$dataKey]) ? $response[$dataKey] : $response;
            
            if (is_array($responseData)) {
                foreach ($responseData as $resource) {
                    $resources[] = [
                        'id' => $resource['id'] ?? $resource['resource_id'] ?? null,
                        'name' => $resource['name'] ?? $resource['title'] ?? 'N/A',
                        'type' => $resource['type'] ?? 'resource',
                        'capacity' => $resource['capacity'] ?? null,
                        'description' => $resource['description'] ?? null,
                        'bridge_type' => 'booking_system'
                    ];
                }
            }
            
            return $resources;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get available resources from booking system', [
                'error' => $e->getMessage(),
                'bridge' => 'booking_system'
            ]);
            
            // Return empty array if resources endpoint is not available
            return [];
        }
    }
    
    /**
     * Get available groups/collections from the booking system
     */
    public function getAvailableGroups(): array
    {
        try {
            $endpoint = $this->getEndpointConfig('list_groups', [
                'method' => 'GET',
                'url' => '/api/groups',
                'response_mapping' => [
                    'id' => 'id',
                    'name' => 'name',
                    'description' => 'description'
                ]
            ]);
            
            $response = $this->makeApiRequest($endpoint['method'], $endpoint['url']);
            
            $groups = [];
            $dataKey = $endpoint['response_data_key'] ?? 'data';
            $responseData = isset($response[$dataKey]) ? $response[$dataKey] : $response;
            
            if (is_array($responseData)) {
                foreach ($responseData as $group) {
                    $groups[] = [
                        'id' => $group['id'] ?? $group['group_id'] ?? null,
                        'name' => $group['name'] ?? $group['title'] ?? 'N/A',
                        'description' => $group['description'] ?? null,
                        'bridge_type' => 'booking_system'
                    ];
                }
            }
            
            return $groups;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get available groups from booking system', [
                'error' => $e->getMessage(),
                'bridge' => 'booking_system'
            ]);
            
            // Return empty array if groups endpoint is not available
            return [];
        }
    }
    
    /**
     * Get calendar items for a specific user/resource
     */
    public function getUserCalendarItems($userId, $startDate = null, $endDate = null): array
    {
        try {
            $endpoint = $this->getEndpointConfig('list_user_events', [
                'method' => 'GET',
                'url' => '/api/users/{user_id}/events',
                'params' => ['start_date', 'end_date']
            ]);
            
            // Replace user ID in URL
            $url = str_replace('{user_id}', urlencode($userId), $endpoint['url']);
            
            // Add date parameters if provided
            $params = [];
            if ($startDate) $params['start_date'] = $startDate;
            if ($endDate) $params['end_date'] = $endDate;
            
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
            
            $response = $this->makeApiRequest($endpoint['method'], $url);
            
            $events = [];
            $dataKey = $endpoint['response_data_key'] ?? 'data';
            $responseData = isset($response[$dataKey]) ? $response[$dataKey] : $response;
            
            if (is_array($responseData)) {
                foreach ($responseData as $event) {
                    $events[] = $this->normalizeBookingEvent($event);
                }
            }
            
            return $events;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get user calendar items from booking system', [
                'error' => $e->getMessage(),
                'bridge' => 'booking_system',
                'user_id' => $userId
            ]);
            
            // Return empty array if user events endpoint is not available
            return [];
        }
    }
    
    /**
     * Normalize booking system event data to bridge format
     */
    private function normalizeBookingEvent($event): array
    {
        // Apply field mappings if configured
        $mappedEvent = [];
        if (isset($this->fieldMappings['events'])) {
            foreach ($this->fieldMappings['events'] as $bridgeField => $bookingField) {
                $mappedEvent[$bridgeField] = $event[$bookingField] ?? null;
            }
        } else {
            $mappedEvent = $event;
        }
        
        // Return standardized event format
        return [
            'id' => $mappedEvent['id'] ?? $event['id'] ?? $event['event_id'] ?? null,
            'subject' => $mappedEvent['subject'] ?? $event['title'] ?? $event['name'] ?? $event['subject'] ?? 'N/A',
            'start' => $mappedEvent['start'] ?? $event['start_time'] ?? $event['start'] ?? null,
            'end' => $mappedEvent['end'] ?? $event['end_time'] ?? $event['end'] ?? null,
            'location' => $mappedEvent['location'] ?? $event['location'] ?? $event['room'] ?? null,
            'description' => $mappedEvent['description'] ?? $event['description'] ?? $event['notes'] ?? '',
            'organizer' => $mappedEvent['organizer'] ?? $event['organizer'] ?? $event['created_by'] ?? null,
            'attendees' => $this->extractAttendees($mappedEvent['attendees'] ?? $event['attendees'] ?? []),
            'all_day' => $mappedEvent['all_day'] ?? $event['all_day'] ?? false,
            'timezone' => $mappedEvent['timezone'] ?? $event['timezone'] ?? 'UTC',
            'bridge_type' => 'booking_system',
            'external_id' => $mappedEvent['id'] ?? $event['id'] ?? $event['event_id'] ?? null,
            'last_modified' => $mappedEvent['last_modified'] ?? $event['modified_at'] ?? $event['updated_at'] ?? date('c'),
            'created' => $mappedEvent['created'] ?? $event['created_at'] ?? date('c'),
            'raw_data' => $event
        ];
    }
}
