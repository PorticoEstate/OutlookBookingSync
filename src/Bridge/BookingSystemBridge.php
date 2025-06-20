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
 *         'url' => '/booking/resources/{resource_id}/schedule',
 *         'params' => ['start_date', 'end_date', 'format' => 'json']
 *     ],
 *     'create_event' => [
 *         'method' => 'POST', 
 *         'url' => '/booking/events',
 *         'field_mapping' => ['subject' => 'title', 'start' => 'start_time']
 *     ]
 * ]
 * ```
 */
class BookingSystemBridge extends AbstractCalendarBridge
{
    private $apiBaseUrl;
    private $systemLogin;
    private $systemPassword;
    private $systemDomain;
    private $apiEndpoints;
    private $fieldMappings;
    private $authConfig;
    private $sessionInfo = [];
    private $sessionTimeout = 1800; // 30 minutes
    private $debug = false;

    protected function validateConfig()
    {
        $required = ['api_base_url', 'system_login', 'system_password', 'system_domain'];

        foreach ($required as $key)
        {
            if (!isset($this->config[$key]) || empty($this->config[$key]))
            {
                throw new \InvalidArgumentException("BookingSystem bridge requires '{$key}' in configuration");
            }
        }

        $this->apiBaseUrl = rtrim($this->config['api_base_url'], '/');
        $this->systemLogin = $this->config['system_login'] ?? null;
        $this->systemPassword = $this->config['system_password'] ?? null;
        $this->systemDomain = $this->config['system_domain'] ?? null;
        $this->debug = $this->config['debug'] ?? false;

        // Load configurable API mappings or use defaults
        $this->apiEndpoints = $this->config['api_endpoints'] ?? $this->getDefaultApiEndpoints();
        $this->fieldMappings = $this->config['field_mappings'] ?? $this->getDefaultFieldMappings();
        $this->authConfig = $this->config['auth'] ?? $this->getDefaultAuthConfig();

        // Initialize session
        $this->initializeSession();
    }

    /**
     * Initialize session - login or refresh existing session
     */
    private function initializeSession()
    {
        try
        {
            // Load session from global session storage
            $this->sessionInfo = $this->getSession('auth_session', []);

            // Check if we have cached session info and if it's still valid
            if ($this->isSessionValid())
            {
                if ($this->debug ?? false)
                {
                    error_log("BookingSystemBridge: Using existing valid session from storage");
                }
                return;
            }

            // Try to refresh session first, if that fails, perform login
            if (!$this->refreshSession())
            {
                if ($this->debug ?? false)
                {
                    error_log("BookingSystemBridge: Session refresh failed, performing new login");
                }
                $this->performLogin();
            }
            else if ($this->debug ?? false)
            {
                error_log("BookingSystemBridge: Session refreshed successfully");
            }
        }
        catch (\Exception $e)
        {
            throw new \Exception("Failed to initialize session: " . $e->getMessage());
        }
    }

    /**
     * Check if current session is valid (not expired)
     */
    private function isSessionValid(): bool
    {
        // First check if we have session data in memory
        if (empty($this->sessionInfo) || !isset($this->sessionInfo['session_id']))
        {
            // Try to load from global session storage
            $this->sessionInfo = $this->getSession('auth_session', []);

            if (empty($this->sessionInfo) || !isset($this->sessionInfo['session_id']))
            {
                return false;
            }
        }

        // Check if session has expired using last_activity
        $lastActivity = $this->sessionInfo['last_activity'] ?? 0;
        $isValid = (time() - $lastActivity) < $this->sessionTimeout;

        if (!$isValid)
        {
            // Session expired, clear it from storage
            $this->clearSession('auth_session');
            $this->sessionInfo = [];
        }

        if ($this->debug ?? false)
        {
            error_log("BookingSystemBridge: Session valid check: " . ($isValid ? 'valid' : 'expired'));
        }

        return $isValid;
    }

    /**
     * Perform login to get session information
     */
    private function performLogin()
    {
        if ($this->debug ?? false)
        {
            error_log("BookingSystemBridge: Attempting login for user: " . $this->systemLogin);
        }

        $url = $this->apiBaseUrl . '/login';
        $postData = [
            'logindomain' => $this->systemDomain,
            'login' => $this->systemLogin,
            'passwd' => $this->systemPassword
        ];

        $response = $this->makeHttpRequest('POST', $url, [], $postData);

        if (!$response)
        {
            $this->clearBookingSystemSession();
            throw new \Exception("Login to booking system failed - empty response");
        }

        $this->sessionInfo = is_array($response) ? $response : json_decode($response, true);
        if (!$this->sessionInfo || !isset($this->sessionInfo['session_id']))
        {
            $this->clearBookingSystemSession();
            throw new \Exception("Invalid login response from booking system: " . print_r($response, true));
        }

        $this->sessionInfo['last_activity'] = time();

        // Store session in global session storage with TTL
        $this->setSession('auth_session', $this->sessionInfo, $this->sessionTimeout);

        if ($this->debug ?? false)
        {
            error_log("BookingSystemBridge: Login successful, session ID: " . substr($this->sessionInfo['session_id'], 0, 8) . "... (stored in session)");
        }
    }

    /**
     * Refresh existing session
     */
    private function refreshSession(): bool
    {
        if (empty($this->sessionInfo) || !isset($this->sessionInfo['session_name']) || !isset($this->sessionInfo['session_id']))
        {
            return false;
        }

        $url = $this->apiBaseUrl . '/refreshsession/?' . http_build_query([
            $this->sessionInfo['session_name'] => $this->sessionInfo['session_id'],
            'domain' => $this->systemDomain,
            'api_mode' => true,
        ]);

        try
        {
            $response = $this->makeHttpRequest('GET', $url);
            $this->sessionInfo['last_activity'] = time();

            // Update session in global session storage
            $this->setSession('auth_session', $this->sessionInfo, $this->sessionTimeout);

            if ($this->debug ?? false)
            {
                error_log("BookingSystemBridge: Session refreshed and updated in storage");
            }

            return true;
        }
        catch (\Exception $e)
        {
            // Refresh failed, clear session and will need to login again
            $this->clearBookingSystemSession();

            if ($this->debug ?? false)
            {
                error_log("BookingSystemBridge: Session refresh failed: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Get session parameters to include in API requests
     */
    private function getSessionParams(): array
    {
        if (empty($this->sessionInfo) || !isset($this->sessionInfo['session_id']))
        {
            return [];
        }

        return [
            $this->sessionInfo['session_name'] => $this->sessionInfo['session_id'],
            'domain' => $this->systemDomain,
            'phpgw_return_as' => 'json',
            'api_mode' => true
        ];
    }

    /**
     * Default API endpoint mappings (can be overridden in config)
     */
    private function getDefaultApiEndpoints(): array
    {
        return [
            'list_events' => [
                'method' => 'GET',
                'url' => '/booking/resources/{resource_id}/schedule',
                'params' => ['start_date', 'end_date', 'format' => 'json']
            ],
            'create_event' => [
                'method' => 'POST',
                'url' => '/booking/resources/{resource_id}/events'
            ],
            'update_event' => [
                'method' => 'PUT',
                'url' => '/booking/events/{event_id}'
            ],
            // 'delete_event' => [
            //     'method' => 'DELETE',
            //     'url' => '/booking/events/{event_id}'
            // ],
            'toggle_event' => [
                'method' => 'PATCH',
                'url' => '/booking/events/{event_id}/toggle-active'
            ],
            'list_resources' => [
                'method' => 'GET',
                'url' => '/booking/resources'
            ],
            // Optional webhook endpoints (if booking system supports them)
            'subscribe_webhook' => [
                'method' => 'POST',
                'url' => '/booking/webhooks/subscribe'
            ],
            'unsubscribe_webhook' => [
                'method' => 'DELETE',
                'url' => '/booking/webhooks/{subscription_id}'
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
                'start' => 'from_',
                'end' => 'to_',
                'description' => 'description',
                'organizer' => 'contact_name',
                'attendees' => 'contact_email'  // First attendee becomes contact_email
            ],
            'from_booking_system' => [
                'title' => 'subject',
                'name' => 'subject',
                'from_' => 'start',
                'to_' => 'end',
                'description' => 'description',
                'contact_name' => 'organizer',
                'contact_email' => 'attendees'  // Contact email becomes attendees array
            ]
        ];
    }

    /**
     * Default authentication configuration - now session-based
     */
    private function getDefaultAuthConfig(): array
    {
        return [
            'type' => 'session',  // session-based authentication
            'session_timeout' => 1800  // 30 minutes
        ];
    }

    /**
     * Get endpoint configuration with proper priority order:
     * 1. Constructor input settings (HIGHEST PRIORITY - from $this->apiEndpoints)
     * 2. Internal default endpoints (from getDefaultApiEndpoints())
     * 3. Method parameter defaults (LOWEST PRIORITY - from $defaultConfig)
     * 
     * @param string $endpointName The name of the endpoint
     * @param array $defaultConfig Basic default configuration (lowest priority)
     * @return array Merged endpoint configuration with input settings taking precedence
     */
    private function getEndpointConfig(string $endpointName, array $defaultConfig = []): array
    {
        // Start with basic defaults (lowest priority)
        $config = $defaultConfig;

        // Override with internal default endpoints (medium priority)
        $defaultEndpoints = $this->getDefaultApiEndpoints();
        if (isset($defaultEndpoints[$endpointName]))
        {
            $config = array_merge($config, $defaultEndpoints[$endpointName]);
        }

        // Final override with constructor input settings (HIGHEST PRIORITY)
        // These are the settings passed to the bridge constructor
        if (isset($this->apiEndpoints[$endpointName]))
        {
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

    /**
     * Create event in booking system (when BookingSystemBridge is target)
     */
    public function createEvent($calendarId, $event): string
    {
        try
        {
            // When we're the target, we receive events from other bridges
            // We need to create a new reservation in our booking system
            $createdId = $this->createEventViaApi($calendarId, $event);

            if ($this->debug)
            {
                error_log("BookingSystemBridge: Created event with composite ID: {$createdId}");
            }

            // Create event mapping with synced status
            if (isset($event['source_bridge']) && isset($event['source_event_id']) && isset($event['source_calendar_id']))
            {
                // Look up the resource mapping to get the configured sync direction
                $syncDirection = $this->getResourceMappingSyncDirection(
                    $event['source_bridge'],
                    $this->getBridgeType(),
                    $event['source_calendar_id'],
                    $calendarId
                );

                $this->createEventMapping(
                    $event['source_bridge'],
                    $this->getBridgeType(),
                    $event['source_calendar_id'],
                    $calendarId,
                    $event['source_event_id'],
                    $createdId,
                    $event,
                    $syncDirection
                );
            }

            return $createdId; // Returns composite ID (e.g., "event_12345")

        }
        catch (\Exception $e)
        {
            // Mark as error if mapping exists
            if (isset($event['source_bridge']) && isset($event['source_event_id']) && isset($event['source_calendar_id']))
            {
                $this->updateSyncStatus(
                    $event['source_bridge'],
                    $this->getBridgeType(),
                    $event['source_calendar_id'],
                    $calendarId,
                    $event['source_event_id'],
                    'error',
                    $e->getMessage()
                );
            }
            throw $e;
        }
    }

    /**
     * Update event in booking system (when BookingSystemBridge is target)
     */
    public function updateEvent($calendarId, $eventId, $event): bool
    {
        try
        {
            // Extract original ID from composite ID for API call
            $originalId = $this->extractOriginalId($eventId);

            if ($this->debug)
            {
                error_log("BookingSystemBridge: Updating event - composite ID: {$eventId}, original ID: {$originalId}");
            }

            $success = $this->updateEventViaApi($calendarId, $eventId, $event);

            // Update sync status
            if (isset($event['source_bridge']) && isset($event['source_event_id']) && isset($event['source_calendar_id']))
            {
                $this->updateSyncStatus(
                    $event['source_bridge'],
                    $this->getBridgeType(),
                    $event['source_calendar_id'],
                    $calendarId,
                    $event['source_event_id'],
                    'synced'
                );
            }

            return $success;
        }
        catch (\Exception $e)
        {
            // Mark as error if mapping exists
            if (isset($event['source_bridge']) && isset($event['source_event_id']) && isset($event['source_calendar_id']))
            {
                $this->updateSyncStatus(
                    $event['source_bridge'],
                    $this->getBridgeType(),
                    $event['source_calendar_id'],
                    $calendarId,
                    $event['source_event_id'],
                    'error',
                    $e->getMessage()
                );
            }
            throw $e;
        }
    }

    /**
     * Delete event in booking system (when BookingSystemBridge is target)
     * For events imported from Outlook, this sets active=0 instead of actual deletion
     */
    public function deleteEvent($calendarId, $eventId): bool
    {
        try
        {
            // Extract original ID from composite ID for API call
            $originalId = $this->extractOriginalId($eventId);

            if ($this->debug)
            {
                error_log("BookingSystemBridge: Deleting event - composite ID: {$eventId}, original ID: {$originalId}");
            }

            // Check if this event was imported from Outlook (find mapping where this is target)
            $wasImportedFromOutlook = $this->checkIfEventImportedFromOutlook($eventId);

            if ($wasImportedFromOutlook)
            {
                // For events imported from Outlook, toggle active status to 0 instead of deleting
                $success = $this->toggleEventActiveStatus($calendarId, $eventId, false);

                if ($this->debug)
                {
                    error_log("BookingSystemBridge: Set active=0 for Outlook-imported event: {$eventId}");
                }
            }
            else
            {
                // For events created in booking system, perform actual deletion
                $success = $this->deleteEventViaApi($calendarId, $eventId);

                if ($this->debug)
                {
                    error_log("BookingSystemBridge: Actually deleted booking system native event: {$eventId}");
                }
            }

            // Mark related mappings as cancelled (find by target event ID)
            try
            {
                $stmt = $this->db->prepare("
                    UPDATE bridge_mappings 
                    SET sync_status = 'cancelled', updated_at = CURRENT_TIMESTAMP
                    WHERE target_event_id = ? AND target_bridge = ?
                ");
                $stmt->execute([$eventId, $this->getBridgeType()]);

                if ($this->debug)
                {
                    error_log("BookingSystemBridge: Marked mappings as cancelled for event: {$eventId}");
                }
            }
            catch (\Exception $e)
            {
                $this->logger->error('Failed to update mapping status for deleted event', [
                    'event_id' => $eventId,
                    'error' => $e->getMessage()
                ]);
            }

            return $success;
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to delete event in booking system', [
                'calendar_id' => $calendarId,
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Handle composite ID mapping for bidirectional sync
     * This method helps resolve composite IDs when they come from bridge mappings
     */
    public function resolveEventId($eventId, $context = 'unknown'): array
    {
        $originalId = $this->extractOriginalId($eventId);
        $reservationType = $this->extractReservationType($eventId);

        return [
            'composite_id' => $eventId,
            'original_id' => $originalId,
            'reservation_type' => $reservationType,
            'context' => $context
        ];
    }

    /**
     * Check if an event was imported from Outlook by looking at bridge mappings
     */
    private function checkIfEventImportedFromOutlook($eventId): bool
    {
        try
        {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM bridge_mappings 
                WHERE target_event_id = ? 
                AND target_bridge = ? 
                AND source_bridge = 'outlook'
                AND sync_status != 'cancelled'
            ");
            $stmt->execute([$eventId, $this->getBridgeType()]);
            $result = $stmt->fetch();

            return ($result['count'] ?? 0) > 0;
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to check if event was imported from Outlook', [
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);
            return false; // Default to false if we can't determine
        }
    }

    /**
     * Toggle event active status in booking system
     */
    private function toggleEventActiveStatus($resourceId, $eventId, $active = false): bool
    {
        try
        {
            // Extract original ID from composite ID if needed
            $originalEventId = $this->extractOriginalId($eventId);

            $endpoint = $this->apiEndpoints['toggle_event'];
            $url = $this->buildUrl($endpoint['url'], [
                'event_id' => $originalEventId
            ]);

            // Prepare data for the toggle request
            $data = [
                'active' => $active ? 1 : 0
            ];

            $response = $this->makeApiRequest($endpoint['method'], $url, [], $data);

            if ($this->debug)
            {
                error_log("BookingSystemBridge: Toggled event {$eventId} active status to " . ($active ? 'true' : 'false'));
            }

            return $response['success'] ?? true;
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to toggle event active status', [
                'resource_id' => $resourceId,
                'event_id' => $eventId,
                'active' => $active,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    // Configurable API Methods
    private function getEventsViaApi($resourceId, $startDate, $endDate): array
    {
        $endpoint = $this->apiEndpoints['list_events'];
        $url = $this->buildUrl($endpoint['url'], ['resource_id' => $resourceId]);

        $params = [];
        foreach ($endpoint['params'] ?? [] as $key => $value)
        {
            if (is_numeric($key))
            {
                // Dynamic parameter
                switch ($value)
                {
                    case 'start_date':
                        $params['start_date'] = $startDate;
                        break;
                    case 'end_date':
                        $params['end_date'] = $endDate;
                        break;
                }
            }
            else
            {
                // Static parameter
                $params[$key] = $value;
            }
        }

        $response = $this->makeApiRequest($endpoint['method'], $url, $params);

        $events = $response['events'] ?? $response['data'] ?? $response;
        if (!is_array($events))
        {
            return [];
        }

        // Filter overlapping reservations by priority (Event > Booking > Allocation)
        $filteredEvents = $this->filterReservationsByPriority($events);

        return array_map([$this, 'mapBookingEventToGeneric'], $filteredEvents);
    }

    private function createEventViaApi($resourceId, $event): string
    {
        $endpoint = $this->apiEndpoints['create_event'];
        $url = $this->buildUrl($endpoint['url'], ['resource_id' => $resourceId]);

        $mappedEvent = $this->mapGenericEventToBooking($event);

        $response = $this->makeApiRequest($endpoint['method'], $url, [], $mappedEvent);

        $originalId = $response['event_id'] ?? $response['id'] ?? uniqid('event_');

        // Determine reservation type (default to 'event' for new creations)
        // Don't use the mapped event type as it might contain the source event ID
        $reservationType = 'event';

        // Return composite ID for consistent tracking
        return $this->createCompositeId($reservationType, $originalId);
    }

    private function updateEventViaApi($resourceId, $eventId, $event): bool
    {
        // Extract original ID from composite ID if needed
        $originalEventId = $this->extractOriginalId($eventId);

        $endpoint = $this->apiEndpoints['update_event'];
        $url = $this->buildUrl($endpoint['url'], [
            'resource_id' => $resourceId,
            'event_id' => $originalEventId
        ]);

        $mappedEvent = $this->mapGenericEventToBooking($event);

        $response = $this->makeApiRequest($endpoint['method'], $url, [], $mappedEvent);

        return $response['success'] ?? true;
    }

    private function deleteEventViaApi($resourceId, $eventId): bool
    {
        // Extract original ID from composite ID if needed
        $originalEventId = $this->extractOriginalId($eventId);

        $endpoint = $this->apiEndpoints['delete_event'];
        $url = $this->buildUrl($endpoint['url'], [
            'resource_id' => $resourceId,
            'event_id' => $originalEventId
        ]);

        $response = $this->makeApiRequest($endpoint['method'], $url);

        return $response['success'] ?? true;
    }

    private function getCalendarsViaApi(): array
    {
        $endpoint = $this->apiEndpoints['list_resources'];
        $url = $this->buildUrl($endpoint['url']);

        $response = $this->makeApiRequest($endpoint['method'], $url);

        $resources = $response['resources'] ?? $response['data'] ?? $response;
        if (!is_array($resources))
        {
            return [];
        }

        return array_map(function ($resource)
        {
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
        // Check if urlTemplate is already a full URL
        if (filter_var($urlTemplate, FILTER_VALIDATE_URL))
        {
            $url = $urlTemplate;
        }
        else
        {
            // Only add base URL if it's a relative path
            $url = rtrim($this->apiBaseUrl, '/') . '/' . ltrim($urlTemplate, '/');
        }

        foreach ($params as $key => $value)
        {
            $url = str_replace('{' . $key . '}', $value, $url);
        }

        return $url;
    }

    /**
     * Make HTTP request using cURL (similar to ApiClient.php)
     */
    private function makeHttpRequest($method, $url, $params = [], $data = [])
    {
        // Ensure we have valid session before making requests (except for login)
        if (!str_contains($url, '/login') && !str_contains($url, '/refreshsession'))
        {
            if (!$this->isSessionValid() && !$this->refreshSession())
            {
                $this->performLogin();
            }
        }

        // If URL doesn't start with http:// or https://, prepend the API base URL
        if (!preg_match('/^https?:\/\//', $url))
        {
            $url = $this->apiBaseUrl . $url;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

        if ($_ENV['BOOKING_SYSTEM_PROXY'] == "none")
        {
            // No proxy configured, use direct connection
            curl_setopt($ch, CURLOPT_PROXY, '');
        }
        else if (!empty($_ENV['BOOKING_SYSTEM_PROXY']))
        {
            curl_setopt($ch, CURLOPT_PROXY, $_ENV['BOOKING_SYSTEM_PROXY']);
        }

        // Determine if this is a login/refresh request (should use form data)
        $isLoginRequest = str_contains($url, '/login') || str_contains($url, '/refreshsession');

        // Use form-encoded data for all requests (traditional PHP applications expect $_GET/$_POST)
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ];

        // For non-login requests, add session parameters to URL
        if (!$isLoginRequest)
        {
            $sessionParams = $this->getSessionParams();
            if (!empty($sessionParams))
            {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($sessionParams);
            }
        }

        if ($method === 'GET' && !empty($params))
        {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }

        // Set the final URL for all methods
        curl_setopt($ch, CURLOPT_URL, $url);

        if ($method === 'POST')
        {
            curl_setopt($ch, CURLOPT_POST, true);

            // Always send as form data (for $_POST to work on receiving end)
            if (!empty($data))
            {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }
            else if (!empty($params))
            {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            }
        }
        else if (in_array($method, ['PUT', 'DELETE', 'PATCH']))
        {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (!empty($data))
            {
                // Use form data for traditional PHP applications
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }
        }

        if (!empty($headers))
        {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ($this->debug)
        {
            error_log("BookingSystemBridge HTTP Request: {$method} {$url}");
            if (!empty($data))
            {
                error_log("BookingSystemBridge Data: " . print_r($data, true));
            }
            if (!empty($params))
            {
                error_log("BookingSystemBridge Params: " . print_r($params, true));
            }
        }

        $result = curl_exec($ch);

        if (curl_errno($ch))
        {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception('HTTP request failed: ' . $error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($this->debug)
        {
            error_log("BookingSystemBridge HTTP Response: {$httpCode}");
            error_log("BookingSystemBridge Response body: " . substr($result, 0, 500));
        }

        if ($httpCode >= 400)
        {
            throw new \Exception("HTTP request failed with status {$httpCode}: " . $result);
        }

        return $result;
    }

    /**
     * Make API request to booking system with session authentication
     */
    private function makeApiRequest($method, $url, $params = [], $data = [])
    {
        $response = $this->makeHttpRequest($method, $url, $params, $data);

        if ($response === false)
        {
            throw new \Exception("API request failed: {$method} {$url}");
        }

        // For login/refresh, return raw response
        if (str_contains($url, '/login') || str_contains($url, '/refreshsession'))
        {
            return $response;
        }

        // For other requests, decode JSON
        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE)
        {
            throw new \Exception("Invalid JSON response from booking system API: " . $response);
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

        // Create composite ID including reservation type and ID for unique identification
        $reservationType = strtolower($bookingEvent['type'] ?? 'unknown');
        $reservationId = $bookingEvent['id'] ?? 'unknown';
        $compositeId = $reservationType . '_' . $reservationId;

        // Use composite ID for internal tracking
        $genericEvent['id'] = $compositeId;

        // Store original ID and type for reference
        $genericEvent['original_id'] = $reservationId;
        $genericEvent['reservation_type'] = $reservationType;

        // Apply field mappings
        foreach ($mappings as $bookingField => $genericField)
        {
            if (isset($bookingEvent[$bookingField]))
            {
                if ($genericField === 'attendees' && $bookingField === 'contact_email')
                {
                    // Special handling: contact_email becomes attendees array
                    $genericEvent['attendees'] = [$bookingEvent[$bookingField]];
                }
                else
                {
                    $genericEvent[$genericField] = $bookingEvent[$bookingField];
                }
            }
        }

        // Fallback for common fields if not mapped
        $fallbacks = [
            'subject' => $bookingEvent['subject'] ?? $bookingEvent['name'] ?? $bookingEvent['title'] ?? $bookingEvent['organizer'] ?? $bookingEvent['contact_name'] ?? $reservationType ?? '',
            'start' => $bookingEvent['start'] ?? $bookingEvent['start_time'] ?? '',
            'end' => $bookingEvent['end'] ?? $bookingEvent['end_time'] ?? '',
            'location' => $bookingEvent['location'] ?? $bookingEvent['resource_name'] ?? '',
            'description' => $bookingEvent['description'] ?? '',
            'organizer' => $bookingEvent['organizer'] ?? $bookingEvent['contact_name'] ?? '',
            'created' => $bookingEvent['created'] ?? $bookingEvent['created_at'] ?? date('c'),
            'last_modified' => $bookingEvent['last_modified'] ?? $bookingEvent['updated_at'] ?? date('c')
        ];

        foreach ($fallbacks as $field => $value)
        {
            if (!isset($genericEvent[$field]) && !empty($value))
            {
                $genericEvent[$field] = $value;
            }
        }

        // Handle attendees extraction
        if (!isset($genericEvent['attendees']))
        {
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

        // Extract original ID and type from composite ID if present
        if (isset($event['id']) && strpos($event['id'], '_') !== false)
        {
            // Composite ID format: "type_id" - only use this for booking system events
            $parts = explode('_', $event['id'], 2);
            // Only set type if it looks like a valid reservation type (not a long Outlook ID)
            $potentialType = $parts[0];
            if (in_array($potentialType, ['event', 'booking', 'allocation']) && strlen($potentialType) < 20)
            {
                $bookingEvent['type'] = $potentialType;
                $bookingEvent['id'] = $parts[1];
            }
            else
            {
                // This is likely an Outlook event ID, don't use it as type
                $bookingEvent['id'] = $event['id'] ?? null;
            }
        }
        elseif (isset($event['original_id']))
        {
            // Use stored original ID if available
            $bookingEvent['id'] = $event['original_id'];
            if (isset($event['reservation_type']))
            {
                $bookingEvent['type'] = $event['reservation_type'];
            }
        }
        else
        {
            // Fallback to direct ID - don't set type from unknown source
            $bookingEvent['id'] = $event['id'] ?? null;
        }

        // Apply field mappings
        foreach ($mappings as $genericField => $bookingField)
        {
            if (isset($event[$genericField]))
            {
                if ($genericField === 'attendees' && $bookingField === 'contact_email')
                {
                    // Special handling: first attendee becomes contact_email
                    $attendees = is_array($event['attendees']) ? $event['attendees'] : [$event['attendees']];
                    $bookingEvent['contact_email'] = !empty($attendees) ? $attendees[0] : '';
                }
                else
                {
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

        if (!empty($bookingEvent['contact_email']))
        {
            $attendees[] = $bookingEvent['contact_email'];
        }

        if (!empty($bookingEvent['attendees']))
        {
            if (is_array($bookingEvent['attendees']))
            {
                $attendees = array_merge($attendees, $bookingEvent['attendees']);
            }
            else
            {
                $attendees[] = $bookingEvent['attendees'];
            }
        }

        return array_unique(array_filter($attendees));
    }

    /**
     * Get available resources from the booking system
     */
    public function getAvailableResources($nameFilter = null, $limit = 0, $offset = 0): array
    {
        try
        {
            $endpoint = $this->getEndpointConfig('list_resources', [
                'method' => 'GET',
                'url' => '/booking/resources'
            ]);

            $params = ['results' => -1];
            if ($offset)
            {
                $params['start'] = $offset;
            }
            if ($limit)
            {
                $params['results'] = $limit;
            }
            $response = $this->makeApiRequest($endpoint['method'], $endpoint['url'], $params);

            $resources = [];
            $dataKey = $endpoint['response_data_key'] ?? 'results';
            $responseData = isset($response[$dataKey]) ? $response[$dataKey] : $response;

            if (is_array($responseData))
            {
                foreach ($responseData as $resource)
                {
                    $resourceData = [
                        'id' => $resource['id'] ?? $resource['resource_id'] ?? null,
                        'name' => $resource['name'] ?? $resource['title'] ?? 'N/A',
                        'type' => $resource['type'] ?? 'resource',
                        'active' => $resource['active'] ?? null,
                        'description' => $resource['description_json'] ? array_map('html_entity_decode', json_decode($resource['description_json'], true)) : null,
                        'bridge_type' => 'booking_system'
                    ];

                    // Apply name filter if provided
                    if ($nameFilter !== null)
                    {
                        $nameFilterLower = strtolower($nameFilter);
                        $resourceNameLower = strtolower($resourceData['name'] ?? '');

                        // Check if filter matches name
                        if (strpos($resourceNameLower, $nameFilterLower) === false)
                        {
                            continue; // Skip this resource if no match
                        }
                    }

                    $resources[] = $resourceData;
                }
            }

            // Extract total_records from response if available
            $totalRecords = $response['total_records'] ?? null;

            // Return resources with metadata
            $result = [
                'resources' => $resources,
                'metadata' => []
            ];

            // Add total_records to metadata if available
            if ($totalRecords !== null)
            {
                $result['metadata']['total_records'] = $totalRecords;
            }

            return $result;
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to get available resources from booking system', [
                'error' => $e->getMessage(),
                'bridge' => 'booking_system'
            ]);

            // Check if we should throw exceptions or return empty results
            $throwOnApiFailure = $this->config['throw_on_api_failure'] ?? false;
            if ($throwOnApiFailure)
            {
                throw new \Exception("Failed to get available resources: " . $e->getMessage());
            }

            // Return empty array if resources endpoint is not available (legacy behavior)
            return [];
        }
    }

    /**
     * Get available groups/collections from the booking system
     */
    public function getAvailableGroups($nameFilter = null, $limit = 0, $offset = 0): array
    {
        try
        {
            $endpoint = $this->getEndpointConfig('list_groups', [
                'method' => 'GET',
                'url' => '/api/groups',
                'response_mapping' => [
                    'id' => 'id',
                    'name' => 'name',
                    'description' => 'description'
                ]
            ]);

            $params = [];
            if ($offset)
            {
                $params['start'] = $offset;
            }
            if ($limit)
            {
                $params['results'] = $limit;
            }

            $response = $this->makeApiRequest($endpoint['method'], $endpoint['url'], $params);

            $groups = [];
            $dataKey = $endpoint['response_data_key'] ?? 'data';
            $responseData = isset($response[$dataKey]) ? $response[$dataKey] : $response;

            if (is_array($responseData))
            {
                foreach ($responseData as $group)
                {
                    $groupData = [
                        'id' => $group['id'] ?? $group['group_id'] ?? null,
                        'name' => $group['name'] ?? $group['title'] ?? 'N/A',
                        'description' => $group['description'] ?? null,
                        'bridge_type' => 'booking_system'
                    ];

                    // Apply name filter if provided
                    if ($nameFilter !== null)
                    {
                        $nameFilterLower = strtolower($nameFilter);
                        $groupNameLower = strtolower($groupData['name'] ?? '');
                        $groupDescLower = strtolower($groupData['description'] ?? '');

                        // Check if filter matches name or description
                        if (
                            strpos($groupNameLower, $nameFilterLower) === false &&
                            strpos($groupDescLower, $nameFilterLower) === false
                        )
                        {
                            continue; // Skip this group if no match
                        }
                    }

                    $groups[] = $groupData;
                }
            }

            // Extract total_records from response if available
            $totalRecords = $response['total_records'] ?? null;

            // Return groups with metadata
            $result = [
                'resources' => $groups,
                'metadata' => []
            ];

            // Add total_records to metadata if available
            if ($totalRecords !== null)
            {
                $result['metadata']['total_records'] = $totalRecords;
            }

            return $result;
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to get available groups from booking system', [
                'error' => $e->getMessage(),
                'bridge' => 'booking_system'
            ]);

            // Check if we should throw exceptions or return empty results
            $throwOnApiFailure = $this->config['throw_on_api_failure'] ?? false;
            if ($throwOnApiFailure)
            {
                throw new \Exception("Failed to get available groups: " . $e->getMessage());
            }

            // Return empty array if groups endpoint is not available (legacy behavior)
            return [];
        }
    }

    /**
     * Get calendar items for a specific resource
     */
    public function getResourceCalendarItems($resourceId, $startDate = null, $endDate = null): array
    {
        try
        {
            $endpoint = $this->getEndpointConfig('list_events', [
                'method' => 'GET',
                'url' => '/booking/resources/{resource_id}/schedule',
                'params' => ['start_date', 'end_date']
            ]);

            // Replace resource ID in URL
            $url = str_replace('{resource_id}', urlencode($resourceId), $endpoint['url']);

            // Add date parameters if provided
            $params = [];
            if ($startDate) $params['start_date'] = $startDate;
            if ($endDate) $params['end_date'] = $endDate;

            if (!empty($params))
            {
                $url .= '?' . http_build_query($params);
            }

            $response = $this->makeApiRequest($endpoint['method'], $url);

            $events = [];
            $dataKey = $endpoint['response_data_key'] ?? 'data';
            $responseData = isset($response[$dataKey]) ? $response[$dataKey] : $response;

            if (is_array($responseData))
            {
                foreach ($responseData as $event)
                {
                    $events[] = $this->normalizeBookingEvent($event);
                }
            }

            return $events;
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to get resource calendar items from booking system', [
                'error' => $e->getMessage(),
                'bridge' => 'booking_system',
                'resource_id' => $resourceId
            ]);

            // Check if we should throw exceptions or return empty results
            $throwOnApiFailure = $this->config['throw_on_api_failure'] ?? false;
            if ($throwOnApiFailure)
            {
                throw new \Exception("Failed to get resource calendar items: " . $e->getMessage());
            }

            // Return empty array if resource events endpoint is not available (legacy behavior)
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
        if (isset($this->fieldMappings['events']))
        {
            foreach ($this->fieldMappings['events'] as $bridgeField => $bookingField)
            {
                $mappedEvent[$bridgeField] = $event[$bookingField] ?? null;
            }
        }
        else
        {
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

    /**
     * Get comprehensive session diagnostics for debugging
     */
    public function getSessionDiagnostics(): array
    {
        $sessionStats = $this->getSessionStats();
        $currentSession = $this->getSession('auth_session', []);
        $sessionDebug = $this->debugSession();

        return [
            'bridge_type' => $this->getBridgeType(),
            'session_valid' => $this->isSessionValid(),
            'current_session' => $currentSession,
            'session_stats' => $sessionStats,
            'session_debug' => $sessionDebug,
            'session_info_populated' => !empty($this->sessionInfo),
            'session_timeout' => $this->sessionTimeout,
            'last_refresh_attempt' => $this->lastRefreshAttempt ?? 'never'
        ];
    }

    /**
     * Filter overlapping reservations by priority (Event > Booking > Allocation)
     * Only the highest priority reservation remains for each time slot
     */
    private function filterReservationsByPriority($reservations): array
    {
        if (empty($reservations) || !is_array($reservations))
        {
            return [];
        }

        // Define priority levels (lower number = higher priority)
        $priorities = [
            'event' => 1,
            'booking' => 2,
            'allocation' => 3
        ];

        // Parse and prepare reservations with normalized data
        $parsed = [];
        foreach ($reservations as $reservation)
        {
            $type = strtolower($reservation['type'] ?? 'unknown');
            $resourceId = $this->getReservationResourceId($reservation);
            $timeData = $this->getReservationTimeData($reservation);

            if (!$resourceId || !$timeData)
            {
                continue; // Skip invalid reservations
            }

            $parsed[] = [
                'reservation' => $reservation,
                'type' => $type,
                'priority' => $priorities[$type] ?? 99,
                'resource_id' => $resourceId,
                'start_timestamp' => $timeData['start_timestamp'],
                'end_timestamp' => $timeData['end_timestamp']
            ];
        }

        // Group by resource
        $byResource = [];
        foreach ($parsed as $item)
        {
            $byResource[$item['resource_id']][] = $item;
        }

        $filtered = [];

        // Process each resource separately
        foreach ($byResource as $resourceReservations)
        {
            $filtered = array_merge($filtered, $this->filterOverlappingReservations($resourceReservations));
        }

        if ($this->debug)
        {
            error_log("BookingSystemBridge: Filtered " . count($reservations) . " reservations down to " . count($filtered) . " after priority filtering");
        }

        return array_map(function ($item)
        {
            return $item['reservation'];
        }, $filtered);
    }

    /**
     * Filter overlapping reservations for a single resource
     */
    private function filterOverlappingReservations($reservations): array
    {
        // Sort by start time, then by priority
        usort($reservations, function ($a, $b)
        {
            $timeCompare = $a['start_timestamp'] <=> $b['start_timestamp'];
            return $timeCompare !== 0 ? $timeCompare : $a['priority'] <=> $b['priority'];
        });

        $result = [];

        foreach ($reservations as $current)
        {
            $shouldAdd = true;

            // Check if this reservation overlaps with any higher priority reservation already added
            foreach ($result as $existing)
            {
                if ($this->reservationsOverlap($current, $existing))
                {
                    if ($current['priority'] > $existing['priority'])
                    {
                        // Current has lower priority, skip it
                        $shouldAdd = false;
                        break;
                    }
                    else if ($current['priority'] < $existing['priority'])
                    {
                        // Current has higher priority, remove the existing one
                        $result = array_filter($result, function ($item) use ($existing)
                        {
                            return $item !== $existing;
                        });
                    }
                    // If same priority, keep the first one (already sorted by time)
                }
            }

            if ($shouldAdd)
            {
                $result[] = $current;
            }
        }

        return array_values($result);
    }

    /**
     * Check if two reservations overlap in time
     */
    private function reservationsOverlap($res1, $res2): bool
    {
        return $res1['start_timestamp'] < $res2['end_timestamp'] &&
            $res2['start_timestamp'] < $res1['end_timestamp'];
    }

    /**
     * Extract resource ID from reservation for grouping
     */
    private function getReservationResourceId($reservation): ?string
    {
        // Try multiple possible fields for resource identification
        if (isset($reservation['resources']) && is_array($reservation['resources']) && !empty($reservation['resources']))
        {
            return (string)$reservation['resources'][0]['id'];
        }

        if (isset($reservation['resource_id']))
        {
            return (string)$reservation['resource_id'];
        }

        return null;
    }

    /**
     * Get reservation time data with timestamps for overlap detection
     */
    private function getReservationTimeData($reservation): ?array
    {
        $start = $reservation['from_'] ?? $reservation['start_time'] ?? $reservation['start'] ?? null;
        $end = $reservation['to_'] ?? $reservation['end_time'] ?? $reservation['end'] ?? null;

        if (!$start || !$end)
        {
            return null;
        }

        try
        {
            // Use DateTime for reliable ISO 8601 parsing with timezone support
            $startDateTime = new \DateTime($start);
            $endDateTime = new \DateTime($end);

            $startTimestamp = $startDateTime->getTimestamp();
            $endTimestamp = $endDateTime->getTimestamp();

            return [
                'start_timestamp' => $startTimestamp,
                'end_timestamp' => $endTimestamp,
                'start_iso' => $startDateTime->format('Y-m-d\TH:i:s'),
                'end_iso' => $endDateTime->format('Y-m-d\TH:i:s')
            ];
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to parse reservation dates', [
                'start' => $start,
                'end' => $end,
                'error' => $e->getMessage(),
                'bridge' => 'booking_system'
            ]);
            return null;
        }
    }

    /**
     * Extract original reservation ID from composite ID
     * Composite format: "type_id" (e.g., "event_78269", "booking_25634", "allocation_800395")
     */
    private function extractOriginalId($compositeId): ?string
    {
        if (empty($compositeId))
        {
            return null;
        }

        // If it's already a simple ID (no underscore), return as-is
        if (strpos($compositeId, '_') === false)
        {
            return $compositeId;
        }

        // Extract ID from composite format
        $parts = explode('_', $compositeId, 2);
        return count($parts) >= 2 ? $parts[1] : $compositeId;
    }

    /**
     * Extract reservation type from composite ID
     */
    private function extractReservationType($compositeId): ?string
    {
        if (empty($compositeId) || strpos($compositeId, '_') === false)
        {
            return null;
        }

        $parts = explode('_', $compositeId, 2);
        return $parts[0] ?? null;
    }

    /**
     * Create composite ID from type and original ID
     */
    private function createCompositeId($type, $originalId): string
    {
        return strtolower($type) . '_' . $originalId;
    }

    /**
     * Get calendars/resources from booking system
     */
    public function getCalendars(): array
    {
        return $this->getCalendarsViaApi();
    }

    /**
     * Subscribe to changes in booking system (webhook support)
     */
    public function subscribeToChanges($calendarId, $webhookUrl): string
    {
        // Most booking systems don't support webhooks, but we can implement if needed
        if ($this->debug)
        {
            error_log("BookingSystemBridge: Webhook subscription requested for calendar {$calendarId} to {$webhookUrl}");
        }

        // Check if the booking system supports webhook subscriptions
        if (isset($this->apiEndpoints['subscribe_webhook']))
        {
            $endpoint = $this->apiEndpoints['subscribe_webhook'];
            $url = $this->buildUrl($endpoint['url']);

            $subscriptionData = [
                'calendar_id' => $calendarId,
                'webhook_url' => $webhookUrl,
                'events' => ['created', 'updated', 'deleted']
            ];

            try
            {
                $response = $this->makeApiRequest($endpoint['method'], $url, [], $subscriptionData);
                return $response['subscription_id'] ?? uniqid('booking_webhook_');
            }
            catch (\Exception $e)
            {
                if ($this->debug)
                {
                    error_log("BookingSystemBridge: Webhook subscription failed: " . $e->getMessage());
                }
                throw new \Exception("Booking system does not support webhook subscriptions: " . $e->getMessage());
            }
        }
        else
        {
            // Fallback: Return a fake subscription ID and log that polling should be used
            $fakeSubscriptionId = 'polling_' . $calendarId . '_' . uniqid();

            if ($this->debug)
            {
                error_log("BookingSystemBridge: No webhook support, using polling. Fake subscription ID: {$fakeSubscriptionId}");
            }

            return $fakeSubscriptionId;
        }
    }

    /**
     * Unsubscribe from changes in booking system
     */
    public function unsubscribeFromChanges($subscriptionId): bool
    {
        if ($this->debug)
        {
            error_log("BookingSystemBridge: Unsubscribe requested for subscription {$subscriptionId}");
        }

        // If it's a polling subscription (fake), just return true
        if (strpos($subscriptionId, 'polling_') === 0)
        {
            if ($this->debug)
            {
                error_log("BookingSystemBridge: Polling subscription removed: {$subscriptionId}");
            }
            return true;
        }

        // Real webhook unsubscription
        if (isset($this->apiEndpoints['unsubscribe_webhook']))
        {
            $endpoint = $this->apiEndpoints['unsubscribe_webhook'];
            $url = $this->buildUrl($endpoint['url'], ['subscription_id' => $subscriptionId]);

            try
            {
                $response = $this->makeApiRequest($endpoint['method'], $url);
                return $response['success'] ?? true;
            }
            catch (\Exception $e)
            {
                if ($this->debug)
                {
                    error_log("BookingSystemBridge: Webhook unsubscription failed: " . $e->getMessage());
                }
                return false;
            }
        }

        return true; // Assume success if no webhook support
    }

    /**
     * Clear booking system session from storage
     */
    private function clearBookingSystemSession(): void
    {
        $this->clearSession('auth_session');
        $this->sessionInfo = [];

        if ($this->debug ?? false)
        {
            error_log("BookingSystemBridge: Session cleared from storage");
        }
    }

    /**
     * Re-enable failed events (set from error back to pending)
     */
    public function reEnableFailedEvents(array $eventIds = []): array
    {
        $results = [
            're_enabled_count' => 0,
            'errors' => 0,
            'error_details' => []
        ];

        try
        {
            $sql = "
                UPDATE bridge_mappings 
                SET sync_status = 'pending', 
                    retry_count = 0, 
                    error_message = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE sync_status = 'error'
                AND (target_bridge = ? OR source_bridge = ?)
            ";

            $params = [$this->getBridgeType(), $this->getBridgeType()];

            if (!empty($eventIds))
            {
                $placeholders = str_repeat('?,', count($eventIds) - 1) . '?';
                $sql .= " AND (source_event_id IN ($placeholders) OR target_event_id IN ($placeholders))";
                $params = array_merge($params, $eventIds, $eventIds);
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            $results['re_enabled_count'] = $stmt->rowCount();

            if ($this->debug)
            {
                error_log("BookingSystemBridge: Re-enabled {$results['re_enabled_count']} failed events");
            }
        }
        catch (\Exception $e)
        {
            $results['errors']++;
            $results['error_details'][] = [
                'error' => 'Failed to re-enable failed events for BookingSystem bridge: ' . $e->getMessage()
            ];

            $this->logger->error('Failed to re-enable failed events', [
                'bridge' => $this->getBridgeType(),
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }

    /**
     * Process pending synchronizations for this bridge
     */
    public function processPendingSyncs($batchSize = 50): array
    {
        try
        {
            $pendingEvents = $this->getEventsToSync($this->getBridgeType(), 3);
            $processed = [];
            $errors = [];

            foreach (array_slice($pendingEvents, 0, $batchSize) as $mapping)
            {
                try
                {
                    // Determine sync direction and process accordingly
                    if ($mapping['source_bridge'] === $this->getBridgeType())
                    {
                        // We are the source - sync to target
                        $processed[] = $this->processPendingSyncAsSource($mapping);
                    }
                    else
                    {
                        // We are the target - sync from source  
                        $processed[] = $this->processPendingSyncAsTarget($mapping);
                    }
                }
                catch (\Exception $e)
                {
                    $errors[] = [
                        'mapping_id' => $mapping['id'],
                        'error' => $e->getMessage()
                    ];

                    // Update mapping with error status
                    $this->updateSyncStatus(
                        $mapping['source_bridge'],
                        $mapping['target_bridge'],
                        $mapping['source_calendar_id'],
                        $mapping['target_calendar_id'],
                        $mapping['source_event_id'],
                        'error',
                        $e->getMessage()
                    );
                }
            }

            return [
                'processed' => count($processed),
                'errors' => count($errors),
                'error_details' => $errors,
                'success_details' => $processed
            ];
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to process pending syncs', [
                'bridge' => $this->getBridgeType(),
                'error' => $e->getMessage()
            ]);

            return [
                'processed' => 0,
                'errors' => 1,
                'error_details' => [['error' => $e->getMessage()]]
            ];
        }
    }

    /**
     * Process pending sync where this bridge is the source
     */
    private function processPendingSyncAsSource($mapping): array
    {
        // Get the current event from our bridge
        $event = $this->getEventById($mapping['source_calendar_id'], $mapping['source_event_id']);

        if (!$event)
        {
            // Event no longer exists - mark as cancelled
            $this->markEventCancelled(
                $mapping['source_bridge'],
                $mapping['target_bridge'],
                $mapping['source_calendar_id'],
                $mapping['target_calendar_id'],
                $mapping['source_event_id']
            );

            return [
                'action' => 'cancelled',
                'reason' => 'source_event_not_found',
                'mapping_id' => $mapping['id']
            ];
        }

        // Event exists - update target bridge (handled by BridgeManager)
        return [
            'action' => 'updated',
            'mapping_id' => $mapping['id'],
            'requires_target_update' => true
        ];
    }

    /**
     * Process pending sync where this bridge is the target
     */
    private function processPendingSyncAsTarget($mapping): array
    {
        // For target processing, we would need the source bridge to provide the event
        // This is typically handled by the BridgeManager coordinating between bridges

        return [
            'action' => 'pending_source_coordination',
            'mapping_id' => $mapping['id'],
            'requires_source_coordination' => true
        ];
    }

    /**
     * Get a single event by ID (helper for sync processing)
     */
    private function getEventById($calendarId, $eventId): ?array
    {
        try
        {
            $events = $this->getEvents($calendarId, date('Y-m-d', strtotime('-1 year')), date('Y-m-d', strtotime('+1 year')));

            foreach ($events as $event)
            {
                if ($event['id'] === $eventId)
                {
                    return $event;
                }
            }

            return null;
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to get event by ID', [
                'calendar_id' => $calendarId,
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
