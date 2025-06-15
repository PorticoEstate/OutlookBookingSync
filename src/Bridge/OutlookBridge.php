<?php

namespace App\Bridge;

use App\Bridge\AbstractCalendarBridge;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Graph\Core\Authentication\GraphPhpLeagueAuthenticationProvider;
use Microsoft\Graph\GraphRequestAdapter;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Graph\Core\GraphClientFactory;
use Microsoft\Graph\Generated\Models\ODataErrors\ODataError;
use Microsoft\Kiota\Abstractions\RequestInformation;
use Microsoft\Kiota\Abstractions\HttpMethod;

class OutlookBridge extends AbstractCalendarBridge
{
    private $accessToken;
    private $graphBaseUrl = 'https://graph.microsoft.com/v1.0';
    private $graphServiceClient;
    
    protected function validateConfig()
    {
        $required = ['client_id', 'client_secret', 'tenant_id'];
        
        foreach ($required as $key) {
            if (!isset($this->config[$key]) || empty($this->config[$key])) {
                throw new \InvalidArgumentException("Outlook bridge requires '{$key}' in configuration");
            }
        }
        
        // group_id is optional - used for discovering room calendars from a specific group
        // If not provided, will use the default /places/microsoft.graph.room endpoint
    }
    
    protected function initialize()
    {
        $this->accessToken = $this->getAccessToken();
        $this->initializeGraphClient();
    }
    
    /**
     * Initialize Microsoft Graph Service Client (same as OutlookController)
     */
    private function initializeGraphClient()
    {
        $tenantId = $this->config['tenant_id'];
        $clientId = $this->config['client_id'];
        $clientSecret = $this->config['client_secret'];
        
        // Create authentication context
        $tokenRequestContext = new ClientCredentialContext(
            $tenantId,
            $clientId,
            $clientSecret
        );
        
        // Create authentication provider
        $authProvider = new GraphPhpLeagueAuthenticationProvider($tokenRequestContext);
        
        // Create HTTP client (with proxy support if configured)
        $guzzleConfig = [];
        if (!empty($_ENV['httpproxy_server'] ?? '')) {
            $guzzleConfig = [
                "proxy" => "{$_ENV['httpproxy_server']}:{$_ENV['httpproxy_port']}"
            ];
        }
        
        $httpClient = GraphClientFactory::createWithConfig($guzzleConfig);
        $requestAdapter = new GraphRequestAdapter($authProvider, $httpClient);
        
        // Create Graph service client
        $this->graphServiceClient = GraphServiceClient::createWithRequestAdapter($requestAdapter);
    }
    
    public function getBridgeType(): string
    {
        return 'outlook';
    }
    
    public function getCapabilities(): array
    {
        return [
            'supports_webhooks' => true,
            'supports_recurring' => true,
            'supports_all_day' => true,
            'supports_attendees' => true,
            'supports_attachments' => false,
            'max_events_per_request' => 999,
            'rate_limit_per_minute' => 1000
        ];
    }
    
    public function getEvents($calendarId, $startDate, $endDate): array
    {
        $this->logOperation('get_events', ['calendar_id' => $calendarId]);
        
        $url = "{$this->graphBaseUrl}/users/{$calendarId}/calendar/events";
        $params = [
            '$filter' => "start/dateTime ge '{$startDate}' and end/dateTime le '{$endDate}'",
            '$select' => 'id,subject,start,end,location,attendees,body,organizer,isAllDay,createdDateTime,lastModifiedDateTime',
            '$top' => 999,
            '$orderby' => 'start/dateTime asc'
        ];
        
        $response = $this->makeGraphRequest('GET', $url, $params);
        
        return array_map([$this, 'mapOutlookEventToGeneric'], $response['value'] ?? []);
    }
    
    public function createEvent($calendarId, $event): string
    {
        $this->logOperation('create_event', ['calendar_id' => $calendarId]);
        
        if (!$this->validateEvent($event)) {
            throw new \InvalidArgumentException('Invalid event data provided');
        }
        
        $outlookEvent = $this->mapGenericEventToOutlook($event);
        
        $url = "{$this->graphBaseUrl}/users/{$calendarId}/calendar/events";
        $response = $this->makeGraphRequest('POST', $url, [], $outlookEvent);
        
        return $response['id'];
    }
    
    public function updateEvent($calendarId, $eventId, $event): bool
    {
        $this->logOperation('update_event', ['calendar_id' => $calendarId, 'event_id' => $eventId]);
        
        if (!$this->validateEvent($event)) {
            throw new \InvalidArgumentException('Invalid event data provided');
        }
        
        $outlookEvent = $this->mapGenericEventToOutlook($event);
        
        $url = "{$this->graphBaseUrl}/users/{$calendarId}/calendar/events/{$eventId}";
        $response = $this->makeGraphRequest('PATCH', $url, [], $outlookEvent);
        
        return !empty($response);
    }
    
    public function deleteEvent($calendarId, $eventId): bool
    {
        $this->logOperation('delete_event', ['calendar_id' => $calendarId, 'event_id' => $eventId]);
        
        $url = "{$this->graphBaseUrl}/users/{$calendarId}/calendar/events/{$eventId}";
        $this->makeGraphRequest('DELETE', $url);
        
        return true;
    }
    
    public function getCalendars(): array
    {
        $this->logOperation('get_calendars');
        
        // If group_id is configured, get calendars from group members
        if (isset($this->config['group_id']) && !empty($this->config['group_id'])) {
            return $this->getCalendarsFromGroup($this->config['group_id']);
        }
        
        // Default: Get room mailboxes from /places endpoint
        $url = "{$this->graphBaseUrl}/places/microsoft.graph.room";
        $response = $this->makeGraphRequest('GET', $url);
        
        return array_map(function($room) {
            return [
                'id' => $room['id'],
                'name' => $room['displayName'],
                'email' => $room['emailAddress'],
                'type' => 'room',
                'bridge_type' => $this->getBridgeType(),
                'raw_data' => $room
            ];
        }, $response['value'] ?? []);
    }
    
    /**
     * Get calendars from a specific Outlook group
     */
    private function getCalendarsFromGroup($groupId): array
    {
        $this->logOperation('get_calendars_from_group', ['group_id' => $groupId]);
        
        // Get group members
        $url = "{$this->graphBaseUrl}/groups/{$groupId}/members";
        $response = $this->makeGraphRequest('GET', $url);
        
        $calendars = [];
        
        foreach ($response['value'] ?? [] as $member) {
            // Filter for mailbox-enabled members (rooms, resources, or users with calendars)
            if (isset($member['mail']) && !empty($member['mail'])) {
                $calendars[] = [
                    'id' => $member['mail'], // Use email as calendar ID for group members
                    'name' => $member['displayName'] ?? $member['mail'],
                    'email' => $member['mail'],
                    'type' => $this->determineCalendarType($member),
                    'bridge_type' => $this->getBridgeType(),
                    'raw_data' => $member
                ];
            }
        }
        
        return $calendars;
    }
    
    /**
     * Determine the type of calendar based on member properties
     */
    private function determineCalendarType($member): string
    {
        // Check if it's a room mailbox
        if (isset($member['@odata.type']) && strpos($member['@odata.type'], 'room') !== false) {
            return 'room';
        }
        
        // Check if it's a resource mailbox  
        if (isset($member['@odata.type']) && strpos($member['@odata.type'], 'equipment') !== false) {
            return 'equipment';
        }
        
        // Check for room-like properties in the display name
        $displayName = strtolower($member['displayName'] ?? '');
        if (strpos($displayName, 'room') !== false || 
            strpos($displayName, 'conference') !== false ||
            strpos($displayName, 'meeting') !== false) {
            return 'room';
        }
        
        // Default to resource for mailbox-enabled group members
        return 'resource';
    }
    
    public function subscribeToChanges($calendarId, $webhookUrl): string
    {
        $this->logOperation('subscribe_to_changes', ['calendar_id' => $calendarId, 'webhook_url' => $webhookUrl]);
        
        $subscription = [
            'changeType' => 'created,updated,deleted',
            'notificationUrl' => $webhookUrl,
            'resource' => "users/{$calendarId}/calendar/events",
            'expirationDateTime' => date('c', strtotime('+1 day')),
            'clientState' => 'outlook-bridge-' . uniqid()
        ];
        
        $url = "{$this->graphBaseUrl}/subscriptions";
        $response = $this->makeGraphRequest('POST', $url, [], $subscription);
        
        // Store subscription info in database
        $this->storeSubscription($response['id'], $calendarId, $webhookUrl, $response);
        
        return $response['id'];
    }
    
    public function unsubscribeFromChanges($subscriptionId): bool
    {
        $this->logOperation('unsubscribe_from_changes', ['subscription_id' => $subscriptionId]);
        
        $url = "{$this->graphBaseUrl}/subscriptions/{$subscriptionId}";
        $this->makeGraphRequest('DELETE', $url);
        
        // Remove subscription from database
        $this->removeSubscription($subscriptionId);
        
        return true;
    }
    
    /**
     * Get access token for Microsoft Graph API
     */
    private function getAccessToken(): string
    {
        $tokenUrl = "https://login.microsoftonline.com/{$this->config['tenant_id']}/oauth2/v2.0/token";
        
        $postData = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials'
        ];
        
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query($postData),
                'timeout' => 30
            ]
        ]);
        
        $response = file_get_contents($tokenUrl, false, $context);
        
        if ($response === false) {
            throw new \Exception('Failed to get access token from Microsoft');
        }
        
        $tokenData = json_decode($response, true);
        
        if (!isset($tokenData['access_token'])) {
            throw new \Exception('Access token not found in response: ' . $response);
        }
        
        return $tokenData['access_token'];
    }
    
    /**
     * Make request to Microsoft Graph API
     */
    private function makeGraphRequest($method, $url, $params = [], $data = [])
    {
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => !empty($data) ? json_encode($data) : null,
                'timeout' => 60
            ]
        ]);
        
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            throw new \Exception("Graph API request failed: {$method} {$url} - " . $error['message']);
        }
        
        // Handle empty responses for DELETE operations
        if ($method === 'DELETE' && empty($response)) {
            return [];
        }
        
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON response from Graph API: " . json_last_error_msg());
        }
        
        // Check for Graph API errors
        if (isset($decoded['error'])) {
            throw new \Exception("Graph API error: " . $decoded['error']['message']);
        }
        
        return $decoded;
    }
    
    /**
     * Map Outlook event to generic format
     */
    private function mapOutlookEventToGeneric($outlookEvent): array
    {
        return $this->createGenericEvent([
            'id' => $outlookEvent['id'],
            'subject' => $outlookEvent['subject'] ?? '',
            'start' => $outlookEvent['start']['dateTime'] ?? '',
            'end' => $outlookEvent['end']['dateTime'] ?? '',
            'location' => $outlookEvent['location']['displayName'] ?? '',
            'description' => $this->extractTextFromHtml($outlookEvent['body']['content'] ?? ''),
            'attendees' => array_map(function($attendee) {
                return $attendee['emailAddress']['address'] ?? '';
            }, $outlookEvent['attendees'] ?? []),
            'organizer' => $outlookEvent['organizer']['emailAddress']['address'] ?? '',
            'all_day' => $outlookEvent['isAllDay'] ?? false,
            'timezone' => $outlookEvent['start']['timeZone'] ?? 'UTC',
            'created' => $outlookEvent['createdDateTime'] ?? date('c'),
            'last_modified' => $outlookEvent['lastModifiedDateTime'] ?? date('c')
        ]);
    }
    
    /**
     * Map generic event to Outlook format
     */
    private function mapGenericEventToOutlook($event): array
    {
        $outlookEvent = [
            'subject' => $event['subject'],
            'start' => [
                'dateTime' => $this->normalizeDateTime($event['start']),
                'timeZone' => $event['timezone'] ?? 'UTC'
            ],
            'end' => [
                'dateTime' => $this->normalizeDateTime($event['end']),
                'timeZone' => $event['timezone'] ?? 'UTC'
            ],
            'body' => [
                'contentType' => 'text',
                'content' => $event['description'] ?? ''
            ]
        ];
        
        // Add location if provided
        if (!empty($event['location'])) {
            $outlookEvent['location'] = [
                'displayName' => $event['location']
            ];
        }
        
        // Add attendees if provided
        if (!empty($event['attendees'])) {
            $outlookEvent['attendees'] = array_map(function($email) {
                return [
                    'emailAddress' => ['address' => $email],
                    'type' => 'required'
                ];
            }, $event['attendees']);
        }
        
        // Add all-day flag if needed
        if ($event['all_day'] ?? false) {
            $outlookEvent['isAllDay'] = true;
        }
        
        // Add custom properties to track bridge source
        $outlookEvent['singleValueExtendedProperties'] = [
            [
                'id' => 'String {66f5a359-4659-4830-9070-00047ec6ac6e} Name BridgeSource',
                'value' => 'calendar_bridge'
            ],
            [
                'id' => 'String {66f5a359-4659-4830-9070-00047ec6ac6f} Name SourceBridge',
                'value' => $event['bridge_type'] ?? 'unknown'
            ]
        ];
        
        if (isset($event['external_id'])) {
            $outlookEvent['singleValueExtendedProperties'][] = [
                'id' => 'String {66f5a359-4659-4830-9070-00047ec6ac70} Name SourceEventId',
                'value' => $event['external_id']
            ];
        }
        
        return $outlookEvent;
    }
    
    /**
     * Extract plain text from HTML content
     */
    private function extractTextFromHtml($html): string
    {
        if (empty($html)) {
            return '';
        }
        
        // Remove HTML tags and decode entities
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        return $text;
    }
    
    /**
     * Store subscription in database
     */
    private function storeSubscription($subscriptionId, $calendarId, $webhookUrl, $subscriptionData)
    {
        $sql = "
            INSERT INTO bridge_subscriptions (
                bridge_type, subscription_id, calendar_id, webhook_url, 
                subscription_data, expires_at, created_at
            ) VALUES (
                :bridge_type, :subscription_id, :calendar_id, :webhook_url,
                :subscription_data, :expires_at, CURRENT_TIMESTAMP
            )
            ON CONFLICT (subscription_id) DO UPDATE SET
                webhook_url = EXCLUDED.webhook_url,
                subscription_data = EXCLUDED.subscription_data,
                expires_at = EXCLUDED.expires_at
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':bridge_type' => $this->getBridgeType(),
            ':subscription_id' => $subscriptionId,
            ':calendar_id' => $calendarId,
            ':webhook_url' => $webhookUrl,
            ':subscription_data' => json_encode($subscriptionData),
            ':expires_at' => $subscriptionData['expirationDateTime']
        ]);
    }
    
    /**
     * Remove subscription from database
     */
    private function removeSubscription($subscriptionId)
    {
        $sql = "DELETE FROM bridge_subscriptions WHERE subscription_id = :subscription_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':subscription_id' => $subscriptionId]);
    }
    
    /**
     * Get available resources (rooms/equipment) from Outlook
     * Uses the same method as OutlookController::getAvailableRooms()
     */
    public function getAvailableResources(): array
    {
        try {
            // Get group ID from configuration or use default
            $groupId = $this->config['group_id'] ?? '90ba4505-3855-4739-81fa-6b0008ae9216';

            // Get the request adapter from the Graph service client  
            $requestAdapter = $this->graphServiceClient->getRequestAdapter();

            // Make a direct API call to get group members (same as OutlookController)
            $groupMembersRequest = new RequestInformation();
            $groupMembersRequest->urlTemplate = "https://graph.microsoft.com/v1.0/groups/{$groupId}/members";
            $groupMembersRequest->httpMethod = HttpMethod::GET;
            $groupMembersRequest->addHeader("Accept", "application/json");

            $groupMembersResponse = $requestAdapter->sendAsync(
                $groupMembersRequest,
                [\Microsoft\Graph\Generated\Models\DirectoryObjectCollectionResponse::class, 'createFromDiscriminatorValue'],
                [ODataError::class, 'createFromDiscriminatorValue']
            )->wait();

            $resources = [];

            if ($groupMembersResponse) {
                $members = $groupMembersResponse->getValue();
                if ($members && !empty($members)) {
                    foreach ($members as $member) {
                        $memberData = [
                            'id' => $member->getId(),
                            'name' => $member->getDisplayName() ?? 'N/A',
                            '@odata.type' => $member->getOdataType(),
                            'bridge_type' => 'outlook'
                        ];

                        // Add additional properties if it's a User object
                        if ($member instanceof \Microsoft\Graph\Generated\Models\User) {
                            $memberData['userPrincipalName'] = $member->getUserPrincipalName();
                            $memberData['email'] = $member->getMail();
                            $memberData['jobTitle'] = $member->getJobTitle();
                        }

                        $resources[] = $memberData;
                    }
                }
            }

            $this->logger->info('Retrieved available resources from Outlook', [
                'bridge' => 'outlook',
                'group_id' => $groupId,
                'resource_count' => count($resources)
            ]);

            return $resources;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get available resources from Outlook', [
                'error' => $e->getMessage(),
                'bridge' => 'outlook'
            ]);
            throw $e;
        }
    }
    
    /**
     * Get available groups/collections from Outlook
     * Uses the same method as OutlookController::getAvailableGroups()
     */
    public function getAvailableGroups(): array
    {
        try {
            // Get the request adapter from the Graph service client
            $requestAdapter = $this->graphServiceClient->getRequestAdapter();

            // Make a direct API call to get groups (same as OutlookController)
            $groupsRequest = new RequestInformation();
            $groupsRequest->urlTemplate = "https://graph.microsoft.com/v1.0/groups?\$top=999";
            $groupsRequest->httpMethod = HttpMethod::GET;
            $groupsRequest->addHeader("Accept", "application/json");

            $allGroups = [];
            $nextLink = null;

            do {
                // Update URL for pagination if we have a next link
                if ($nextLink) {
                    $groupsRequest->urlTemplate = $nextLink;
                }

                $groupsResponse = $requestAdapter->sendAsync(
                    $groupsRequest,
                    [\Microsoft\Graph\Generated\Models\GroupCollectionResponse::class, 'createFromDiscriminatorValue'],
                    [ODataError::class, 'createFromDiscriminatorValue']
                )->wait();

                if (method_exists($groupsResponse, 'getValue') && !empty($groupsResponse->getValue())) {
                    $groups = $groupsResponse->getValue();

                    foreach ($groups as $group) {
                        $groupData = [
                            'id' => $group->getId(),
                            'name' => $group->getDisplayName() ?? 'N/A',
                            'description' => $group->getDescription() ?? 'N/A',
                            'email' => $group->getMail() ?? 'N/A',
                            'group_types' => $group->getGroupTypes() ?? [],
                            'bridge_type' => 'outlook'
                        ];

                        $allGroups[] = $groupData;
                    }
                }

                // Check for next page
                $nextLink = $groupsResponse ? $groupsResponse->getOdataNextLink() : null;

            } while ($nextLink);

            $this->logger->info('Retrieved available groups from Outlook', [
                'bridge' => 'outlook',
                'group_count' => count($allGroups)
            ]);

            return $allGroups;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get available groups from Outlook', [
                'error' => $e->getMessage(),
                'bridge' => 'outlook'
            ]);
            throw $e;
        }
    }
    
    /**
     * Get calendar items for a specific user
     * Uses the same method as OutlookController::getUserCalendarItems()
     */
    public function getUserCalendarItems($userId, $startDate = null, $endDate = null): array
    {
        try {
            if (!$userId) {
                throw new \InvalidArgumentException('User ID is required');
            }

            // Get the request adapter from the Graph service client
            $requestAdapter = $this->graphServiceClient->getRequestAdapter();

            // Make a direct API call to get calendar items for the user (same as OutlookController)
            $calendarItemsRequest = new RequestInformation();
            $calendarItemsRequest->urlTemplate = "https://graph.microsoft.com/v1.0/users/{$userId}/events";
            $calendarItemsRequest->httpMethod = HttpMethod::GET;
            $calendarItemsRequest->addHeader("Accept", "application/json");

            $calendarItemsResponse = $requestAdapter->sendAsync(
                $calendarItemsRequest,
                [\Microsoft\Graph\Generated\Models\EventCollectionResponse::class, 'createFromDiscriminatorValue'],
                [ODataError::class, 'createFromDiscriminatorValue']
            )->wait();

            $events = [];

            if ($calendarItemsResponse && method_exists($calendarItemsResponse, 'getValue')) {
                $items = $calendarItemsResponse->getValue();
                if ($items && !empty($items)) {
                    foreach ($items as $item) {
                        // Filter by date range if provided
                        if ($startDate && $endDate) {
                            $itemStart = $item->getStart()->getDateTime();
                            $itemEnd = $item->getEnd()->getDateTime();
                            
                            if ($itemStart < $startDate || $itemEnd > $endDate) {
                                continue; // Skip events outside date range
                            }
                        }

                        $events[] = [
                            'id' => $item->getId(),
                            'subject' => $item->getSubject(),
                            'start' => $item->getStart()->getDateTime(),
                            'end' => $item->getEnd()->getDateTime(),
                            'organizer' => $item->getOrganizer() ? $item->getOrganizer()->getEmailAddress()->getAddress() : null,
                            'bridge_type' => 'outlook'
                        ];
                    }
                }
            }

            $this->logger->info('Retrieved user calendar items from Outlook', [
                'bridge' => 'outlook',
                'user_id' => $userId,
                'event_count' => count($events),
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);

            return $events;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get user calendar items from Outlook', [
                'error' => $e->getMessage(),
                'bridge' => 'outlook',
                'user_id' => $userId
            ]);
            throw $e;
        }
    }
}
