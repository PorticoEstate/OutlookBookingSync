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
        $this->initializeGraphClient();
    }
    
    /**
     * Initialize Microsoft Graph Service Client with proxy support
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
        
        // Create HTTP client with proxy support if configured
        if (!empty($_ENV['httpproxy_server'])) {
            $guzzleConfig = [
                "proxy" => "{$_ENV['httpproxy_server']}:{$_ENV['httpproxy_port']}"
            ];
        } else {
            $guzzleConfig = [];
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
        
        try {
            $requestConfig = new \Microsoft\Graph\Generated\Users\Item\Calendar\Events\EventsRequestBuilderGetRequestConfiguration();
            $requestConfig->queryParameters = new \Microsoft\Graph\Generated\Users\Item\Calendar\Events\EventsRequestBuilderGetQueryParameters();
            $requestConfig->queryParameters->filter = "start/dateTime ge '{$startDate}' and end/dateTime le '{$endDate}'";
            $requestConfig->queryParameters->select = ['id','subject','start','end','location','attendees','body','organizer','isAllDay','createdDateTime','lastModifiedDateTime'];
            $requestConfig->queryParameters->top = 999;
            $requestConfig->queryParameters->orderby = ['start/dateTime asc'];
            
            $eventsResponse = $this->graphServiceClient->users()->byUserId($calendarId)->calendar()->events()->get($requestConfig)->wait();
            $events = $eventsResponse->getValue();
            
            return array_map([$this, 'mapOutlookSDKEventToGeneric'], $events ?? []);
        } catch (\Exception $e) {
            throw new \Exception("Failed to get events: " . $e->getMessage());
        }
    }
    
    public function createEvent($calendarId, $event): string
    {
        $this->logOperation('create_event', ['calendar_id' => $calendarId]);
        
        if (!$this->validateEvent($event)) {
            throw new \InvalidArgumentException('Invalid event data provided');
        }
        
        try {
            $outlookEvent = $this->mapGenericEventToOutlookSDK($event);
            $createdEvent = $this->graphServiceClient->users()->byUserId($calendarId)->calendar()->events()->post($outlookEvent)->wait();
            
            return $createdEvent->getId();
        } catch (\Exception $e) {
            throw new \Exception("Failed to create event: " . $e->getMessage());
        }
    }
    
    public function updateEvent($calendarId, $eventId, $event): bool
    {
        $this->logOperation('update_event', ['calendar_id' => $calendarId, 'event_id' => $eventId]);
        
        if (!$this->validateEvent($event)) {
            throw new \InvalidArgumentException('Invalid event data provided');
        }
        
        try {
            $outlookEvent = $this->mapGenericEventToOutlookSDK($event);
            $this->graphServiceClient->users()->byUserId($calendarId)->calendar()->events()->byEventId($eventId)->patch($outlookEvent)->wait();
            
            return true;
        } catch (\Exception $e) {
            throw new \Exception("Failed to update event: " . $e->getMessage());
        }
    }
    
    public function deleteEvent($calendarId, $eventId): bool
    {
        $this->logOperation('delete_event', ['calendar_id' => $calendarId, 'event_id' => $eventId]);
        
        try {
            $this->graphServiceClient->users()->byUserId($calendarId)->calendar()->events()->byEventId($eventId)->delete()->wait();
            
            return true;
        } catch (\Exception $e) {
            throw new \Exception("Failed to delete event: " . $e->getMessage());
        }
    }
    
    public function getCalendars(): array
    {
        $this->logOperation('get_calendars');
        
        // If group_id is configured, get calendars from group members
        if (isset($this->config['group_id']) && !empty($this->config['group_id'])) {
            return $this->getCalendarsFromGroup($this->config['group_id']);
        }
        
        // Default: Get room mailboxes from /places endpoint
        try {
            $placesResponse = $this->graphServiceClient->places()->microsoftGraphRoom()->get()->wait();
            $places = $placesResponse->getValue();
            
            return array_map(function($room) {
                return [
                    'id' => $room->getId(),
                    'name' => $room->getDisplayName(),
                    'email' => $room->getAdditionalData()['emailAddress'] ?? '',
                    'type' => 'room',
                    'bridge_type' => $this->getBridgeType(),
                    'raw_data' => $room->getAdditionalData()
                ];
            }, $places ?? []);
        } catch (\Exception $e) {
            throw new \Exception("Failed to get calendars: " . $e->getMessage());
        }
    }
    
    /**
     * Get calendars from a specific Outlook group
     */
    private function getCalendarsFromGroup($groupId): array
    {
        $this->logOperation('get_calendars_from_group', ['group_id' => $groupId]);
        
        try {
            // Get group members
            $membersResponse = $this->graphServiceClient->groups()->byGroupId($groupId)->members()->get()->wait();
            
            $calendars = [];
            $totalMembers = count($membersResponse->getValue() ?? []);
            
            $this->logger->info('Processing group members', [
                'group_id' => $groupId,
                'total_members' => $totalMembers,
                'bridge' => 'outlook'
            ]);
            
            foreach ($membersResponse->getValue() ?? [] as $member) {
                $this->logger->debug('Processing group member', [
                    'member_id' => $member->getId(),
                    'member_type' => get_class($member),
                    'display_name' => $member->getDisplayName(),
                    'odata_type' => $member->getOdataType(),
                    'bridge' => 'outlook'
                ]);
                
                // Check if this is a User object with calendar access
                if ($member instanceof \Microsoft\Graph\Generated\Models\User) {
                    $userEmail = $member->getMail() ?? $member->getUserPrincipalName();
                    if (!empty($userEmail)) {
                        $calendars[] = [
                            'id' => $userEmail, // Use email/UPN as calendar ID
                            'name' => $member->getDisplayName() ?? $userEmail,
                            'email' => $userEmail,
                            'type' => 'user',
                            'bridge_type' => $this->getBridgeType(),
                            'raw_data' => [
                                'id' => $member->getId(),
                                'userPrincipalName' => $member->getUserPrincipalName(),
                                'mail' => $member->getMail(),
                                'displayName' => $member->getDisplayName(),
                                'jobTitle' => $member->getJobTitle(),
                                'odataType' => $member->getOdataType()
                            ]
                        ];
                    }
                }
                // Check if this is a Group object (nested groups)
                elseif ($member instanceof \Microsoft\Graph\Generated\Models\Group) {
                    $groupEmail = $member->getMail();
                    if (!empty($groupEmail)) {
                        $calendars[] = [
                            'id' => $groupEmail,
                            'name' => $member->getDisplayName() ?? $groupEmail,
                            'email' => $groupEmail,
                            'type' => 'group',
                            'bridge_type' => $this->getBridgeType(),
                            'raw_data' => [
                                'id' => $member->getId(),
                                'displayName' => $member->getDisplayName(),
                                'mail' => $member->getMail(),
                                'odataType' => $member->getOdataType()
                            ]
                        ];
                    }
                }
                // Handle other directory objects (like service principals, etc.)
                else {
                    // Try to get basic info from any directory object
                    $objectId = $member->getId();
                    $displayName = $member->getDisplayName();
                    
                    if ($objectId && $displayName) {
                        $calendars[] = [
                            'id' => $objectId,
                            'name' => $displayName,
                            'email' => '', // May not have email
                            'type' => 'other',
                            'bridge_type' => $this->getBridgeType(),
                            'raw_data' => [
                                'id' => $objectId,
                                'displayName' => $displayName,
                                'odataType' => $member->getOdataType()
                            ]
                        ];
                    }
                }
            }
            
            $this->logger->info('Retrieved calendars from group', [
                'group_id' => $groupId,
                'calendar_count' => count($calendars),
                'bridge' => 'outlook'
            ]);
            
            return $calendars;
        } catch (\Exception $e) {
            throw new \Exception("Failed to get calendars from group: " . $e->getMessage());
        }
    }
    
    public function subscribeToChanges($calendarId, $webhookUrl): string
    {
        $this->logOperation('subscribe_to_changes', ['calendar_id' => $calendarId, 'webhook_url' => $webhookUrl]);
        
        try {
            $subscription = new \Microsoft\Graph\Generated\Models\Subscription();
            $subscription->setChangeType('created,updated,deleted');
            $subscription->setNotificationUrl($webhookUrl);
            $subscription->setResource("users/{$calendarId}/calendar/events");
            $subscription->setExpirationDateTime(new \DateTime('+1 day'));
            $subscription->setClientState('outlook-bridge-' . uniqid());
            
            $createdSubscription = $this->graphServiceClient->subscriptions()->post($subscription)->wait();
            
            // Store subscription info in database
            $this->storeSubscription($createdSubscription->getId(), $calendarId, $webhookUrl, $createdSubscription->getAdditionalData());
            
            return $createdSubscription->getId();
        } catch (\Exception $e) {
            throw new \Exception("Failed to create subscription: " . $e->getMessage());
        }
    }
    
    public function unsubscribeFromChanges($subscriptionId): bool
    {
        $this->logOperation('unsubscribe_from_changes', ['subscription_id' => $subscriptionId]);
        
        try {
            $this->graphServiceClient->subscriptions()->bySubscriptionId($subscriptionId)->delete()->wait();
            
            // Remove subscription from database
            $this->removeSubscription($subscriptionId);
            
            return true;
        } catch (\Exception $e) {
            throw new \Exception("Failed to delete subscription: " . $e->getMessage());
        }
    }
    
    /**
     * Map Outlook SDK Event object to generic format
     */
    private function mapOutlookSDKEventToGeneric(\Microsoft\Graph\Generated\Models\Event $outlookEvent): array
    {
        return $this->createGenericEvent([
            'id' => $outlookEvent->getId(),
            'subject' => $outlookEvent->getSubject() ?? '',
            'start' => $outlookEvent->getStart() ? $outlookEvent->getStart()->getDateTime() : '',
            'end' => $outlookEvent->getEnd() ? $outlookEvent->getEnd()->getDateTime() : '',
            'location' => $outlookEvent->getLocation() ? $outlookEvent->getLocation()->getDisplayName() : '',
            'description' => $this->extractTextFromHtml($outlookEvent->getBody() ? $outlookEvent->getBody()->getContent() : ''),
            'attendees' => array_map(function($attendee) {
                return $attendee->getEmailAddress() ? $attendee->getEmailAddress()->getAddress() : '';
            }, $outlookEvent->getAttendees() ?? []),
            'organizer' => $outlookEvent->getOrganizer() && $outlookEvent->getOrganizer()->getEmailAddress() ? 
                         $outlookEvent->getOrganizer()->getEmailAddress()->getAddress() : '',
            'all_day' => $outlookEvent->getIsAllDay() ?? false,
            'timezone' => $outlookEvent->getStart() ? $outlookEvent->getStart()->getTimeZone() : 'UTC',
            'created' => $outlookEvent->getCreatedDateTime() ? $outlookEvent->getCreatedDateTime()->format('c') : date('c'),
            'last_modified' => $outlookEvent->getLastModifiedDateTime() ? $outlookEvent->getLastModifiedDateTime()->format('c') : date('c')
        ]);
    }
    
    /**
     * Map generic event to Outlook format
     */
    /**
     * Map generic event to Outlook SDK Event object
     */
    private function mapGenericEventToOutlookSDK($event): \Microsoft\Graph\Generated\Models\Event
    {
        $outlookEvent = new \Microsoft\Graph\Generated\Models\Event();
        
        $outlookEvent->setSubject($event['subject']);
        
        // Set start time
        $startTime = new \Microsoft\Graph\Generated\Models\DateTimeTimeZone();
        $startTime->setDateTime($this->normalizeDateTime($event['start']));
        $startTime->setTimeZone($event['timezone'] ?? 'UTC');
        $outlookEvent->setStart($startTime);
        
        // Set end time
        $endTime = new \Microsoft\Graph\Generated\Models\DateTimeTimeZone();
        $endTime->setDateTime($this->normalizeDateTime($event['end']));
        $endTime->setTimeZone($event['timezone'] ?? 'UTC');
        $outlookEvent->setEnd($endTime);
        
        // Set body
        $body = new \Microsoft\Graph\Generated\Models\ItemBody();
        $body->setContentType(new \Microsoft\Graph\Generated\Models\BodyType('text'));
        $body->setContent($event['description'] ?? '');
        $outlookEvent->setBody($body);
        
        // Add location if provided
        if (!empty($event['location'])) {
            $location = new \Microsoft\Graph\Generated\Models\Location();
            $location->setDisplayName($event['location']);
            $outlookEvent->setLocation($location);
        }
        
        // Add attendees if provided
        if (!empty($event['attendees'])) {
            $attendees = [];
            foreach ($event['attendees'] as $email) {
                $attendee = new \Microsoft\Graph\Generated\Models\Attendee();
                $emailAddress = new \Microsoft\Graph\Generated\Models\EmailAddress();
                $emailAddress->setAddress($email);
                $attendee->setEmailAddress($emailAddress);
                $attendee->setType(new \Microsoft\Graph\Generated\Models\AttendeeType('required'));
                $attendees[] = $attendee;
            }
            $outlookEvent->setAttendees($attendees);
        }
        
        // Add all-day flag if needed
        if ($event['all_day'] ?? false) {
            $outlookEvent->setIsAllDay(true);
        }
        
        // Add custom properties to track bridge source
        $extendedProperties = [];
        
        $bridgeSourceProp = new \Microsoft\Graph\Generated\Models\SingleValueLegacyExtendedProperty();
        $bridgeSourceProp->setId('String {66f5a359-4659-4830-9070-00047ec6ac6e} Name BridgeSource');
        $bridgeSourceProp->setValue('calendar_bridge');
        $extendedProperties[] = $bridgeSourceProp;
        
        $sourceBridgeProp = new \Microsoft\Graph\Generated\Models\SingleValueLegacyExtendedProperty();
        $sourceBridgeProp->setId('String {66f5a359-4659-4830-9070-00047ec6ac6f} Name SourceBridge');
        $sourceBridgeProp->setValue($event['bridge_type'] ?? 'unknown');
        $extendedProperties[] = $sourceBridgeProp;
        
        if (isset($event['external_id'])) {
            $sourceEventIdProp = new \Microsoft\Graph\Generated\Models\SingleValueLegacyExtendedProperty();
            $sourceEventIdProp->setId('String {66f5a359-4659-4830-9070-00047ec6ac70} Name SourceEventId');
            $sourceEventIdProp->setValue($event['external_id']);
            $extendedProperties[] = $sourceEventIdProp;
        }
        
        $outlookEvent->setSingleValueExtendedProperties($extendedProperties);
        
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
    public function getAvailableResources($nameFilter = null, $limit = 0, $offset = 0): array
    {
        try {
            // Get group ID from configuration - no default fallback
            // If group_id is not configured, use the /places endpoint instead
            $groupId = $this->config['group_id'] ?? null;
            
            if (!$groupId) {
                // If no group_id configured, fall back to Microsoft Places API
                return $this->getResourcesFromPlaces($nameFilter);
            }

            // Get the request adapter from the Graph service client  
            $requestAdapter = $this->graphServiceClient->getRequestAdapter();

            // Build URL with pagination parameters for group members
            $queryParams = [];
            
            // Apply pagination parameters to Graph API query
            if ($limit > 0) {
                $queryParams[] = '$top=' . $limit;
            }
            
            if ($offset > 0) {
                $queryParams[] = '$skip=' . $offset;
            }
            
            $queryString = !empty($queryParams) ? '?' . implode('&', $queryParams) : '';
            $membersUrl = "https://graph.microsoft.com/v1.0/groups/{$groupId}/members" . $queryString;

            // Make a direct API call to get group members with server-side pagination
            $groupMembersRequest = new RequestInformation();
            $groupMembersRequest->urlTemplate = $membersUrl;
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
                        $displayName = $member->getDisplayName() ?? 'N/A';
                        $email = '';
                        $userPrincipalName = '';
                        
                        // Get additional properties if it's a User object
                        if ($member instanceof \Microsoft\Graph\Generated\Models\User) {
                            $email = $member->getMail() ?? '';
                            $userPrincipalName = $member->getUserPrincipalName() ?? '';
                        }
                        
                        // Apply name filter if provided
                        if ($nameFilter !== null) {
                            $nameFilterLower = strtolower($nameFilter);
                            $displayNameLower = strtolower($displayName);
                            $emailLower = strtolower($email);
                            $upnLower = strtolower($userPrincipalName);
                            
                            // Check if filter matches displayName, email, or userPrincipalName
                            if (strpos($displayNameLower, $nameFilterLower) === false &&
                                strpos($emailLower, $nameFilterLower) === false &&
                                strpos($upnLower, $nameFilterLower) === false) {
                                continue; // Skip this member if no match
                            }
                        }
                        
                        $memberData = [
                            'id' => $member->getId(),
                            'name' => $displayName,
                            '@odata.type' => $member->getOdataType(),
                            'bridge_type' => 'outlook'
                        ];

                        // Add additional properties if it's a User object
                        if ($member instanceof \Microsoft\Graph\Generated\Models\User) {
                            $memberData['userPrincipalName'] = $userPrincipalName;
                            $memberData['email'] = $email;
                            $memberData['jobTitle'] = $member->getJobTitle();
                        }

                        $resources[] = $memberData;
                    }
                }
            }

            // Client-side filtering is still needed since Graph API has limited filtering for group members
            if ($nameFilter !== null) {
                $originalCount = count($resources);
                $resources = array_filter($resources, function($resource) use ($nameFilter) {
                    $nameFilterLower = strtolower($nameFilter);
                    $displayNameLower = strtolower($resource['name'] ?? '');
                    $emailLower = strtolower($resource['email'] ?? '');
                    $upnLower = strtolower($resource['userPrincipalName'] ?? '');
                    
                    return strpos($displayNameLower, $nameFilterLower) !== false ||
                           strpos($emailLower, $nameFilterLower) !== false ||
                           strpos($upnLower, $nameFilterLower) !== false;
                });
                $resources = array_values($resources); // Re-index array
            }

            $totalCount = count($resources);

            $logData = [
                'bridge' => 'outlook',
                'group_id' => $groupId,
                'returned_resource_count' => count($resources),
                'api_limit' => $limit,
                'api_offset' => $offset,
                'server_side_pagination' => true
            ];
            
            if ($nameFilter !== null) {
                $logData['name_filter'] = $nameFilter;
                $logData['filtered_results'] = count($resources);
            }
            
            $this->logger->info('Retrieved available resources from Outlook with server-side pagination', $logData);

            // Return resources with metadata for consistency with BookingSystemBridge
            return [
                'resources' => $resources,
                'metadata' => [
                    'total_records' => $totalCount,
                    'filtered_count' => count($resources),
                    'server_side_pagination' => true
                ]
            ];
            
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
    public function getAvailableGroups($nameFilter = null, $limit = 0, $offset = 0): array
    {
        try {
            // Get the request adapter from the Graph service client
            $requestAdapter = $this->graphServiceClient->getRequestAdapter();

            // Build URL with pagination parameters
            $queryParams = [];
            
            // Apply pagination parameters to Graph API query
            if ($limit > 0) {
                $queryParams[] = '$top=' . $limit;
            } else {
                $queryParams[] = '$top=9999'; // Default large number if no limit specified
            }
            
            if ($offset > 0) {
                $queryParams[] = '$skip=' . $offset;
            }
            
            // Add name filter if provided (using Graph API $filter)
            if ($nameFilter !== null) {
                $escapedFilter = str_replace("'", "''", $nameFilter); // Escape single quotes for OData
                $filterQuery = "startswith(displayName,'{$escapedFilter}') or " .
                              "startswith(description,'{$escapedFilter}') or " .
                              "startswith(mail,'{$escapedFilter}')";
                $queryParams[] = '$filter=' . urlencode($filterQuery);
            }
            
            $queryString = implode('&', $queryParams);
            $url = "https://graph.microsoft.com/v1.0/groups?" . $queryString;

            // Make a direct API call to get groups with server-side pagination
            $groupsRequest = new RequestInformation();
            $groupsRequest->urlTemplate = $url;
            $groupsRequest->httpMethod = HttpMethod::GET;
            $groupsRequest->addHeader("Accept", "application/json");

            $groupsResponse = $requestAdapter->sendAsync(
                $groupsRequest,
                [\Microsoft\Graph\Generated\Models\GroupCollectionResponse::class, 'createFromDiscriminatorValue'],
                [ODataError::class, 'createFromDiscriminatorValue']
            )->wait();

            $allGroups = [];
            $totalCount = null;

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
                
                // Try to get total count from the response (if available)
                // Note: Microsoft Graph doesn't always provide total count for security reasons
                if (method_exists($groupsResponse, 'getOdataCount')) {
                    $totalCount = $groupsResponse->getOdataCount();
                }
            }

            $logData = [
                'bridge' => 'outlook',
                'returned_group_count' => count($allGroups),
                'api_limit' => $limit,
                'api_offset' => $offset
            ];
            
            if ($nameFilter !== null) {
                $logData['name_filter'] = $nameFilter;
            }
            
            if ($totalCount !== null) {
                $logData['total_count_from_api'] = $totalCount;
            }
            
            $this->logger->info('Retrieved available groups from Outlook with server-side pagination', $logData);

            // Return groups with metadata for consistency
            $metadata = [
                'filtered_count' => count($allGroups)
            ];
            
            // Include total count if available from API
            if ($totalCount !== null) {
                $metadata['total_records'] = $totalCount;
            }
            
            return [
                'resources' => $allGroups,
                'metadata' => $metadata
            ];
            
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
    
    /**
     * Debug method: Get raw group information
     */
    public function debugGroupInfo($groupId = null): array
    {
        $targetGroupId = $groupId ?? $this->config['group_id'] ?? null;
        
        if (!$targetGroupId) {
            return ['error' => 'No group ID provided'];
        }
        
        try {
            // Get group basic info
            $group = $this->graphServiceClient->groups()->byGroupId($targetGroupId)->get()->wait();
            
            // Get group members
            $membersResponse = $this->graphServiceClient->groups()->byGroupId($targetGroupId)->members()->get()->wait();
            $members = $membersResponse->getValue() ?? [];
            
            $memberDetails = [];
            foreach ($members as $member) {
                $memberInfo = [
                    'id' => $member->getId(),
                    'displayName' => $member->getDisplayName(),
                    'odataType' => $member->getOdataType(),
                    'class' => get_class($member)
                ];
                
                if ($member instanceof \Microsoft\Graph\Generated\Models\User) {
                    $memberInfo['userPrincipalName'] = $member->getUserPrincipalName();
                    $memberInfo['mail'] = $member->getMail();
                    $memberInfo['jobTitle'] = $member->getJobTitle();
                }
                
                $memberDetails[] = $memberInfo;
            }
            
            return [
                'group' => [
                    'id' => $group->getId(),
                    'displayName' => $group->getDisplayName(),
                    'mail' => $group->getMail(),
                    'description' => $group->getDescription()
                ],
                'members' => $memberDetails,
                'member_count' => count($members)
            ];
            
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
                'group_id' => $targetGroupId
            ];
        }
    }
    
    /**
     * Get resources from Microsoft Places API when no group_id is configured
     */
    private function getResourcesFromPlaces($nameFilter = null): array
    {
        try {
            // Get the request adapter from the Graph service client  
            $requestAdapter = $this->graphServiceClient->getRequestAdapter();

            // Make a direct API call to get places (rooms/equipment)
            $placesRequest = new RequestInformation();
            $placesRequest->urlTemplate = "https://graph.microsoft.com/v1.0/places/microsoft.graph.room";
            $placesRequest->httpMethod = HttpMethod::GET;
            $placesRequest->addHeader("Accept", "application/json");

            $placesResponse = $requestAdapter->sendAsync(
                $placesRequest,
                [\Microsoft\Graph\Generated\Models\RoomCollectionResponse::class, 'createFromDiscriminatorValue'],
                [ODataError::class, 'createFromDiscriminatorValue']
            )->wait();

            $resources = [];

            if ($placesResponse) {
                $places = $placesResponse->getValue();
                if ($places && !empty($places)) {
                    foreach ($places as $place) {
                        $displayName = $place->getDisplayName() ?? 'N/A';
                        $email = $place->getAdditionalData()['emailAddress'] ?? '';
                        
                        // Apply name filter if provided
                        if ($nameFilter !== null) {
                            $nameFilterLower = strtolower($nameFilter);
                            $displayNameLower = strtolower($displayName);
                            $emailLower = strtolower($email);
                            
                            // Check if filter matches displayName or email
                            if (strpos($displayNameLower, $nameFilterLower) === false &&
                                strpos($emailLower, $nameFilterLower) === false) {
                                continue; // Skip this place if no match
                            }
                        }
                        
                        $resources[] = [
                            'id' => $place->getId(),
                            'name' => $displayName,
                            'email' => $email,
                            '@odata.type' => $place->getOdataType(),
                            'bridge_type' => 'outlook'
                        ];
                    }
                }
            }

            $logData = [
                'bridge' => 'outlook',
                'source' => 'places_api',
                'resource_count' => count($resources)
            ];
            
            if ($nameFilter !== null) {
                $logData['name_filter'] = $nameFilter;
                $logData['filtered_results'] = count($resources);
            }
            
            $this->logger->info('Retrieved available resources from Outlook Places API', $logData);

            return $resources;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get available resources from Outlook Places API', [
                'error' => $e->getMessage(),
                'bridge' => 'outlook'
            ]);
            throw $e;
        }
    }
}
