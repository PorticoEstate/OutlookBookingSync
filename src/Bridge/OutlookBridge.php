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
use PDO;

/**
 * OutlookBridge integrates with Microsoft Graph to manage calendars and events.
 *
 * Responsibilities:
 * - CRUD operations on Outlook events
 * - Listing calendars/resources/groups
 * - Webhook subscription lifecycle (create, renew, delete)
 * - Utility helpers to map Outlook SDK models to the bridge's generic event shape
 */
class OutlookBridge extends AbstractCalendarBridge
{
	private $graphServiceClient;

	// Extended property constants - unique GUIDs for this application
	// These should be unique per deployment to avoid conflicts with other applications
	private const EXTENDED_PROPERTY_NAMESPACE = 'OutlookBookingSync';
	private const BRIDGE_SOURCE_PROPERTY_ID = 'String {66f5a359-4659-4830-9070-00047ec6ac6e} Name BridgeSource';
	private const SOURCE_BRIDGE_PROPERTY_ID = 'String {66f5a359-4659-4830-9070-00047ec6ac6f} Name SourceBridge';
	private const SOURCE_EVENT_ID_PROPERTY_ID = 'String {66f5a359-4659-4830-9070-00047ec6ac70} Name SourceEventId';

	/**
	 * Get extended property IDs for Graph API queries
	 * @return array Array of extended property IDs
	 */
	private function getExtendedPropertyIds(): array
	{
		return [
			self::BRIDGE_SOURCE_PROPERTY_ID,
			self::SOURCE_BRIDGE_PROPERTY_ID,
			self::SOURCE_EVENT_ID_PROPERTY_ID
		];
	}

	/**
	 * Generate a unique extended property ID based on namespace and property name
	 * This method can be used to generate deployment-specific GUIDs if needed
	 * 
	 * @param string $propertyName The name of the property
	 * @return string Extended property ID string
	 */
	private function generateExtendedPropertyId(string $propertyName): string
	{
		// For production use, consider generating truly unique GUIDs per deployment
		// This could use a base namespace GUID + property name hash
		$namespace = $this->config['extended_property_namespace'] ?? self::EXTENDED_PROPERTY_NAMESPACE;
		
		// For now, return the constant (but this method enables future dynamic generation)
		switch ($propertyName) {
			case 'BridgeSource':
				return self::BRIDGE_SOURCE_PROPERTY_ID;
			case 'SourceBridge':
				return self::SOURCE_BRIDGE_PROPERTY_ID;
			case 'SourceEventId':
				return self::SOURCE_EVENT_ID_PROPERTY_ID;
			default:
				throw new \InvalidArgumentException("Unknown extended property: {$propertyName}");
		}
	}

	protected function validateConfig()
	{
		$required = ['client_id', 'client_secret', 'tenant_id'];

		foreach ($required as $key)
		{
			if (!isset($this->config[$key]) || empty($this->config[$key]))
			{
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
		if (!empty($_ENV['httpproxy_server']))
		{
			$guzzleConfig = [
				"proxy" => "{$_ENV['httpproxy_server']}:{$_ENV['httpproxy_port']}"
			];
		}
		else
		{
			$guzzleConfig = [];
		}

		$httpClient = GraphClientFactory::createWithConfig($guzzleConfig);
		$requestAdapter = new GraphRequestAdapter($authProvider, $httpClient);

		// Create Graph service client
		$this->graphServiceClient = GraphServiceClient::createWithRequestAdapter($requestAdapter);
	}

	/**
	 * Get the unique bridge type identifier.
	 *
	 * @return string 'outlook'
	 */
	public function getBridgeType(): string
	{
		return 'outlook';
	}

	/**
	 * Report capabilities supported by the Outlook bridge.
	 *
	 * @return array<string,mixed>
	 */
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

	/**
	 * Fetch events for a calendar within a time window.
	 *
	 * @param string $calendarId Outlook user/calendar identifier (UPN or ID)
	 * @param string $startDate ISO8601 start
	 * @param string $endDate ISO8601 end
	 * @return array List of generic event arrays
	 * @throws \Exception on API errors
	 */
	public function getEvents($calendarId, $startDate, $endDate): array
	{
		$this->logOperation('get_events', ['calendar_id' => $calendarId]);

		try
		{
			$requestConfig = new \Microsoft\Graph\Generated\Users\Item\Calendar\Events\EventsRequestBuilderGetRequestConfiguration();
			$requestConfig->queryParameters = new \Microsoft\Graph\Generated\Users\Item\Calendar\Events\EventsRequestBuilderGetQueryParameters();
			$requestConfig->queryParameters->filter = "start/dateTime ge '{$startDate}' and end/dateTime le '{$endDate}'";
			$requestConfig->queryParameters->select = ['id', 'subject', 'start', 'end', 'location', 'attendees', 'body', 'organizer', 'isAllDay', 'createdDateTime', 'lastModifiedDateTime'];
			$requestConfig->queryParameters->top = 999;
			$requestConfig->queryParameters->orderby = ['start/dateTime asc'];

			$eventsResponse = $this->graphServiceClient->users()->byUserId($calendarId)->calendar()->events()->get($requestConfig)->wait();
			$events = $eventsResponse->getValue();

			return array_map([$this, 'mapOutlookSDKEventToGeneric'], $events ?? []);
		}
		catch (\Exception $e)
		{
			throw new \Exception("Failed to get events: " . $e->getMessage());
		}
	}

	/**
	 * Get a single event by ID.
	 *
	 * @param string $calendarId Outlook user/calendar identifier
	 * @param string $eventId Outlook event ID
	 * @return array Generic event
	 * @throws \Exception if not found or on API errors
	 */
	public function getEvent($calendarId, $eventId): array
	{
		$this->logOperation('get_event', ['calendar_id' => $calendarId, 'event_id' => $eventId]);

		try
		{
			$eventResponse = $this->graphServiceClient->users()->byUserId($calendarId)->calendar()->events()->byEventId($eventId)->get()->wait();

			if (!$eventResponse)
			{
				throw new \Exception("Event not found");
			}

			return $this->mapOutlookSDKEventToGeneric($eventResponse);
		}
		catch (\Exception $e)
		{
			throw new \Exception("Failed to get event: " . $e->getMessage());
		}
	}

	/**
	 * Create an event in Outlook.
	 *
	 * @param string $calendarId Outlook user/calendar identifier
	 * @param array $event Generic event payload
	 * @return string Created Outlook event ID
	 */
	public function createEvent($calendarId, $event): string
	{
		$this->logOperation('create_event', ['calendar_id' => $calendarId]);

		if (!$this->validateEvent($event))
		{
			throw new \InvalidArgumentException('Invalid event data provided');
		}

		try
		{
			$outlookEvent = $this->mapGenericEventToOutlookSDK($event);
			$createdEvent = $this->graphServiceClient->users()->byUserId($calendarId)->calendar()->events()->post($outlookEvent)->wait();

			$eventId = $createdEvent->getId();

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
					$eventId,
					$event,
					$syncDirection
				);
			}

			return $eventId;
		}
		catch (\Exception $e)
		{
			// Mark as error if mapping context exists
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
			throw new \Exception("Failed to create event: " . $e->getMessage());
		}
	}

	/**
	 * Update an Outlook event.
	 *
	 * @param string $calendarId Outlook user/calendar identifier
	 * @param string $eventId Outlook event ID
	 * @param array $event Generic event payload
	 * @return bool True when updated
	 */
	public function updateEvent($calendarId, $eventId, $event): bool
	{
		$this->logOperation('update_event', ['calendar_id' => $calendarId, 'event_id' => $eventId]);

		if (!$this->validateEvent($event))
		{
			throw new \InvalidArgumentException('Invalid event data provided');
		}

		try
		{
			$outlookEvent = $this->mapGenericEventToOutlookSDK($event);
			$this->graphServiceClient->users()->byUserId($calendarId)->calendar()->events()->byEventId($eventId)->patch($outlookEvent)->wait();

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

			return true;
		}
		catch (\Exception $e)
		{
			// Mark as error if mapping context exists
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
			throw new \Exception("Failed to update event: " . $e->getMessage());
		}
	}

	/**
	 * Delete an Outlook event.
	 *
	 * @param string $calendarId Outlook user/calendar identifier
	 * @param string $eventId Outlook event ID
	 * @return bool True when deleted
	 */
	public function deleteEvent($calendarId, $eventId): bool
	{
		$this->logOperation('delete_event', ['calendar_id' => $calendarId, 'event_id' => $eventId]);

		try
		{
			$this->graphServiceClient->users()->byUserId($calendarId)->calendar()->events()->byEventId($eventId)->delete()->wait();

			// Mark related mappings as cancelled (find by target event ID)
			try
			{
				$stmt = $this->db->prepare("
                    UPDATE bridge_mappings 
                    SET sync_status = 'cancelled', updated_at = CURRENT_TIMESTAMP
                    WHERE target_event_id = ? AND target_bridge = ?
                ");
				$stmt->execute([$eventId, $this->getBridgeType()]);

				$this->logger->info('Marked mappings as cancelled for deleted Outlook event', [
					'event_id' => $eventId,
					'calendar_id' => $calendarId
				]);
			}
			catch (\Exception $e)
			{
				$this->logger->error('Failed to update mapping status for deleted Outlook event', [
					'event_id' => $eventId,
					'error' => $e->getMessage()
				]);
			}

			return true;
		}
		catch (\Exception $e)
		{
			$this->logger->error('Failed to delete Outlook event', [
				'calendar_id' => $calendarId,
				'event_id' => $eventId,
				'error' => $e->getMessage()
			]);
			throw new \Exception("Failed to delete event: " . $e->getMessage());
		}
	}

	/**
	 * List available calendars/resources for the tenant.
	 * Uses group mode if configured, otherwise Places API for rooms.
	 *
	 * @return array List of calendars/resources
	 */
	public function getCalendars(): array
	{
		$this->logOperation('get_calendars');

		// If group_id is configured, get calendars from group members
		if (isset($this->config['group_id']) && !empty($this->config['group_id']))
		{
			return $this->getCalendarsFromGroup($this->config['group_id']);
		}

		// Default: Get room mailboxes from /places endpoint
		try
		{
			$placesResponse = $this->graphServiceClient->places()->microsoftGraphRoom()->get()->wait();
			$places = $placesResponse->getValue();

			return array_map(function ($room)
			{
				return [
					'id' => $room->getId(),
					'name' => $room->getDisplayName(),
					'email' => $room->getAdditionalData()['emailAddress'] ?? '',
					'type' => 'room',
					'bridge_type' => $this->getBridgeType(),
					'raw_data' => $room->getAdditionalData()
				];
			}, $places ?? []);
		}
		catch (\Exception $e)
		{
			throw new \Exception("Failed to get calendars: " . $e->getMessage());
		}
	}


	/**
	 * Get calendars from a specific Outlook group.
	 *
	 * @param string $groupId Microsoft 365 group ID
	 * @return array List of calendars derived from group members
	 */
	private function getCalendarsFromGroup($groupId): array
	{
		$this->logOperation('get_calendars_from_group', ['group_id' => $groupId]);

		try
		{
			// Get group members
			$membersResponse = $this->graphServiceClient->groups()->byGroupId($groupId)->members()->get()->wait();

			$calendars = [];
			$totalMembers = count($membersResponse->getValue() ?? []);

			$this->logger->info('Processing group members', [
				'group_id' => $groupId,
				'total_members' => $totalMembers,
				'bridge' => 'outlook'
			]);

			foreach ($membersResponse->getValue() ?? [] as $member)
			{
				$this->logger->debug('Processing group member', [
					'member_id' => $member->getId(),
					'member_type' => get_class($member),
					'display_name' => $member->getDisplayName(),
					'odata_type' => $member->getOdataType(),
					'bridge' => 'outlook'
				]);

				// Check if this is a User object with calendar access
				if ($member instanceof \Microsoft\Graph\Generated\Models\User)
				{
					$userEmail = $member->getMail() ?? $member->getUserPrincipalName();
					if (!empty($userEmail))
					{
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
				elseif ($member instanceof \Microsoft\Graph\Generated\Models\Group)
				{
					$groupEmail = $member->getMail();
					if (!empty($groupEmail))
					{
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
				else
				{
					// Try to get basic info from any directory object
					$objectId = $member->getId();
					$displayName = $member->getDisplayName();

					if ($objectId && $displayName)
					{
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
		}
		catch (\Exception $e)
		{
			throw new \Exception("Failed to get calendars from group: " . $e->getMessage());
		}
	}

	/**
	 * Create a Microsoft Graph webhook subscription for a calendar's events.
	 *
	 * @param string $calendarId Outlook user/calendar identifier
	 * @param string $webhookUrl Publicly reachable webhook URL
	 * @return string Subscription ID
	 */
	public function subscribeToChanges($calendarId, $webhookUrl): string
	{
		$this->logOperation('subscribe_to_changes', ['calendar_id' => $calendarId, 'webhook_url' => $webhookUrl]);

		try
		{
			$subscription = new \Microsoft\Graph\Generated\Models\Subscription();
			$subscription->setChangeType('created,updated,deleted');
			$subscription->setNotificationUrl($webhookUrl);
			$subscription->setResource("users/{$calendarId}/calendar/events");
			$subscription->setExpirationDateTime(new \DateTime('+1 day'));
			$subscription->setClientState('outlook-bridge-' . uniqid());

			$createdSubscription = $this->graphServiceClient->subscriptions()->post($subscription)->wait();

			// Determine expiration from SDK model
			$expirationDt = method_exists($createdSubscription, 'getExpirationDateTime') && $createdSubscription->getExpirationDateTime()
				? $createdSubscription->getExpirationDateTime()->format('Y-m-d H:i:s')
				: (new \DateTime('+1 day'))->format('Y-m-d H:i:s');

			// Store subscription info in database (with explicit expiration)
			$this->storeSubscription(
				$createdSubscription->getId(),
				$calendarId,
				$webhookUrl,
				$createdSubscription->getAdditionalData(),
				$expirationDt
			);

			return $createdSubscription->getId();
		}
		catch (\Exception $e)
		{
			throw new \Exception("Failed to create subscription: " . $e->getMessage());
		}
	}

	/**
	 * Delete an existing Microsoft Graph webhook subscription.
	 *
	 * @param string $subscriptionId Subscription identifier
	 * @return bool True when removed
	 */
	public function unsubscribeFromChanges($subscriptionId): bool
	{
		$this->logOperation('unsubscribe_from_changes', ['subscription_id' => $subscriptionId]);

		try
		{
			$this->graphServiceClient->subscriptions()->bySubscriptionId($subscriptionId)->delete()->wait();

			// Remove subscription from database
			$this->removeSubscription($subscriptionId);

			return true;
		}
		catch (\Exception $e)
		{
			throw new \Exception("Failed to delete subscription: " . $e->getMessage());
		}
	}

	/**
	 * Renew an existing Microsoft Graph webhook subscription
	 * Extends expiration window and persists the new expiration in DB
	 *
	 * @param string $subscriptionId
	 * @param string $extendInterval DateInterval spec string (default P1D = +1 day)
	 * @return array{success:bool, subscription_id:string, new_expires_at?:string, error?:string}
	 */
	public function renewSubscription($subscriptionId, $extendInterval = 'P1D'): array
	{
		$this->logOperation('renew_subscription', ['subscription_id' => $subscriptionId]);

		try
		{
			$nowUtc = new \DateTime('now', new \DateTimeZone('UTC'));
			$newExpiration = (clone $nowUtc)->add(new \DateInterval($extendInterval));

			// Prepare subscription update
			$update = new \Microsoft\Graph\Generated\Models\Subscription();
			$update->setExpirationDateTime($newExpiration);

			// Send PATCH to extend the subscription
			$updated = $this->graphServiceClient
				->subscriptions()
				->bySubscriptionId($subscriptionId)
				->patch($update)
				->wait();

			// Prefer expiration returned by Graph if present
			$graphExpiration = null;
			if ($updated && method_exists($updated, 'getExpirationDateTime'))
			{
				$graphExpiration = $updated->getExpirationDateTime();
			}
			$effectiveExpiration = $graphExpiration instanceof \DateTime ? $graphExpiration : $newExpiration;

			// Persist new expiration
			$stmt = $this->db->prepare("UPDATE bridge_subscriptions 
                SET expires_at = :expires_at, last_renewed_at = CURRENT_TIMESTAMP, is_active = TRUE 
                WHERE subscription_id = :id AND bridge_type = :bridge");
			$stmt->execute([
				':expires_at' => $effectiveExpiration->format('Y-m-d H:i:s'),
				':id' => $subscriptionId,
				':bridge' => $this->getBridgeType()
			]);

			$this->logger->info('Subscription renewed', [
				'bridge' => $this->getBridgeType(),
				'subscription_id' => $subscriptionId,
				'new_expires_at' => $effectiveExpiration->format(DATE_ATOM)
			]);

			return [
				'success' => true,
				'subscription_id' => $subscriptionId,
				'new_expires_at' => $effectiveExpiration->format(DATE_ATOM)
			];
		}
		catch (\Exception $e)
		{
			$this->logger->error('Failed to renew subscription', [
				'bridge' => $this->getBridgeType(),
				'subscription_id' => $subscriptionId,
				'error' => $e->getMessage()
			]);
			return [
				'success' => false,
				'subscription_id' => $subscriptionId,
				'error' => $e->getMessage()
			];
		}
	}


	/**
	 * Convert an Outlook SDK Event model to the bridge's generic event format.
	 *
	 * @param \Microsoft\Graph\Generated\Models\Event $outlookEvent
	 * @return array Generic event
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
			'attendees' => array_map(function ($attendee)
			{
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
	 * Convert a generic event payload to an Outlook SDK Event model.
	 *
	 * @param array $event Generic event data
	 * @return \Microsoft\Graph\Generated\Models\Event
	 */
	private function mapGenericEventToOutlookSDK($event): \Microsoft\Graph\Generated\Models\Event
	{
		$outlookEvent = new \Microsoft\Graph\Generated\Models\Event();

		$outlookEvent->setSubject($event['subject']);

		$timezone = $event['timezone'] ?? 'UTC';

		// Set start time - convert TO the target timezone instead of UTC
		$startTime = new \Microsoft\Graph\Generated\Models\DateTimeTimeZone();
		$startTime->setDateTime($this->normalizeDateTimeForTimezone($event['start'], $timezone));
		$startTime->setTimeZone($timezone);
		$outlookEvent->setStart($startTime);

		// Set end time - convert TO the target timezone instead of UTC
		$endTime = new \Microsoft\Graph\Generated\Models\DateTimeTimeZone();
		$endTime->setDateTime($this->normalizeDateTimeForTimezone($event['end'], $timezone));
		$endTime->setTimeZone($timezone);
		$outlookEvent->setEnd($endTime);

		// Set body
		$body = new \Microsoft\Graph\Generated\Models\ItemBody();
		$body->setContentType(new \Microsoft\Graph\Generated\Models\BodyType('text'));
		$body->setContent($event['description'] ?? '');
		$outlookEvent->setBody($body);

		// Add location if provided
		if (!empty($event['location']))
		{
			$location = new \Microsoft\Graph\Generated\Models\Location();
			$location->setDisplayName($event['location']);
			$outlookEvent->setLocation($location);
		}

		// Add attendees if provided
		if (!empty($event['attendees']))
		{
			$attendees = [];
			foreach ($event['attendees'] as $email)
			{
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
		if ($event['all_day'] ?? false)
		{
			$outlookEvent->setIsAllDay(true);
		}

		// Add custom properties to track bridge source
		$extendedProperties = [];

		$bridgeSourceProp = new \Microsoft\Graph\Generated\Models\SingleValueLegacyExtendedProperty();
		$bridgeSourceProp->setId($this->generateExtendedPropertyId('BridgeSource'));
		$bridgeSourceProp->setValue(self::EXTENDED_PROPERTY_NAMESPACE);
		$extendedProperties[] = $bridgeSourceProp;

		$sourceBridgeProp = new \Microsoft\Graph\Generated\Models\SingleValueLegacyExtendedProperty();
		$sourceBridgeProp->setId($this->generateExtendedPropertyId('SourceBridge'));
		$sourceBridgeProp->setValue($event['bridge_type'] ?? 'unknown');
		$extendedProperties[] = $sourceBridgeProp;

		if (isset($event['external_id']))
		{
			$sourceEventIdProp = new \Microsoft\Graph\Generated\Models\SingleValueLegacyExtendedProperty();
			$sourceEventIdProp->setId($this->generateExtendedPropertyId('SourceEventId'));
			$sourceEventIdProp->setValue($event['external_id']);
			$extendedProperties[] = $sourceEventIdProp;
		}

		$outlookEvent->setSingleValueExtendedProperties($extendedProperties);

		return $outlookEvent;
	}

	/**
	 * Normalize datetime for a specific timezone (for Outlook event creation).
	 * Unlike normalizeDateTime(), this converts TO the target timezone instead of UTC.
	 *
	 * @param string $dateString Input datetime string
	 * @param string $targetTimezone Target timezone (e.g., "Europe/Oslo")
	 * @return string Datetime in target timezone without timezone suffix
	 */
	private function normalizeDateTimeForTimezone($dateString, $targetTimezone): string
	{
		if (empty($dateString))
		{
			return '';
		}

		try
		{
			// Parse the input datetime (which may be in any timezone)
			$date = new \DateTime($dateString);
			
			// Convert to the target timezone
			$date->setTimezone(new \DateTimeZone($targetTimezone));
			
			// Return in format expected by Graph API (no timezone suffix)
			// Graph API expects just the datetime part when timezone is specified separately
			return $date->format('Y-m-d\TH:i:s.v');
		}
		catch (\Exception $e)
		{
			$this->logger->warning('Failed to normalize datetime for timezone', [
				'input' => $dateString,
				'target_timezone' => $targetTimezone,
				'error' => $e->getMessage()
			]);
			return '';
		}
	}


	/**
	 * Extract plain text from HTML content.
	 *
	 * @param string|null $html HTML body
	 * @return string Plain text
	 */
	private function extractTextFromHtml($html): string
	{
		if (empty($html))
		{
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
	 * Persist a Graph subscription to database, upserting if exists.
	 *
	 * @param string $subscriptionId
	 * @param string $calendarId
	 * @param string $webhookUrl
	 * @param mixed $subscriptionData SDK model or array
	 * @param string|null $expiresAt Explicit expiration (Y-m-d H:i:s) or null to derive
	 * @return void
	 */
	private function storeSubscription($subscriptionId, $calendarId, $webhookUrl, $subscriptionData, $expiresAt = null)
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
		// Compute expiration value
		$expiresValue = $expiresAt;
		if ($expiresValue === null)
		{
			if (is_array($subscriptionData) && isset($subscriptionData['expirationDateTime']))
			{
				$expiresValue = $subscriptionData['expirationDateTime'];
			}
			elseif (is_object($subscriptionData) && method_exists($subscriptionData, 'getExpirationDateTime'))
			{
				$dt = $subscriptionData->getExpirationDateTime();
				$expiresValue = $dt instanceof \DateTime ? $dt->format('Y-m-d H:i:s') : null;
			}
		}
		if ($expiresValue === null)
		{
			$expiresValue = (new \DateTime('+1 day'))->format('Y-m-d H:i:s');
		}

		$stmt->execute([
			':bridge_type' => $this->getBridgeType(),
			':subscription_id' => $subscriptionId,
			':calendar_id' => $calendarId,
			':webhook_url' => $webhookUrl,
			':subscription_data' => json_encode($subscriptionData),
			':expires_at' => $expiresValue
		]);
	}


	/**
	 * Remove a subscription record from the database.
	 *
	 * @param string $subscriptionId
	 * @return void
	 */
	private function removeSubscription($subscriptionId)
	{
		$sql = "DELETE FROM bridge_subscriptions WHERE subscription_id = :subscription_id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':subscription_id' => $subscriptionId]);
	}


	/**
	 * List available resources with database-first approach and Graph API fallback.
	 *
	 * @param string|null $nameFilter Optional substring filter
	 * @param int $limit Server-side $top for group members
	 * @param int $offset Server-side $skip for group members
	 * @return array Resources and metadata (for group path) or array of resources (for places path)
	 */
	public function getAvailableResources($nameFilter = null, $limit = 0, $offset = 0): array
	{
		try
		{
			// First, try to get resources from database
			$dbResources = $this->getResourcesFromDatabase($nameFilter, $limit, $offset);
			
			// If we have resources in database and they're not too old, return them
			if (!empty($dbResources['resources']))
			{
				$this->logger->info('Retrieved resources from database cache', [
					'bridge' => 'outlook',
					'resource_count' => count($dbResources['resources']),
					'cache_hit' => true
				]);
				return $dbResources;
			}

			// Fallback to Graph API if no database resources found
			$this->logger->info('Database cache empty or stale, falling back to Graph API', [
				'bridge' => 'outlook'
			]);

			// Get group ID from configuration - no default fallback
			// If group_id is not configured, use the /places endpoint instead
			$groupId = $this->config['group_id'] ?? null;

			if (!$groupId)
			{
				// If no group_id configured, fall back to Microsoft Places API
				return $this->getResourcesFromPlaces($nameFilter);
			}

			// Get the request adapter from the Graph service client  
			$requestAdapter = $this->graphServiceClient->getRequestAdapter();

			// Build URL with pagination parameters for group members
			$queryParams = [];

			// Apply pagination parameters to Graph API query
			if ($limit > 0)
			{
				$queryParams[] = '$top=' . $limit;
			}

			if ($offset > 0)
			{
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

			if ($groupMembersResponse)
			{
				$members = $groupMembersResponse->getValue();
				if ($members && !empty($members))
				{
					foreach ($members as $member)
					{
						$displayName = $member->getDisplayName() ?? 'N/A';
						$email = '';
						$userPrincipalName = '';

						// Get additional properties if it's a User object
						if ($member instanceof \Microsoft\Graph\Generated\Models\User)
						{
							$email = $member->getMail() ?? '';
							$userPrincipalName = $member->getUserPrincipalName() ?? '';
						}

						// Apply name filter if provided
						if ($nameFilter !== null)
						{
							$nameFilterLower = strtolower($nameFilter);
							$displayNameLower = strtolower($displayName);
							$emailLower = strtolower($email);
							$upnLower = strtolower($userPrincipalName);

							// Check if filter matches displayName, email, or userPrincipalName
							if (
								strpos($displayNameLower, $nameFilterLower) === false &&
								strpos($emailLower, $nameFilterLower) === false &&
								strpos($upnLower, $nameFilterLower) === false
							)
							{
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
						if ($member instanceof \Microsoft\Graph\Generated\Models\User)
						{
							$memberData['userPrincipalName'] = $userPrincipalName;
							$memberData['email'] = $email;
							$memberData['jobTitle'] = $member->getJobTitle();
						}

						$resources[] = $memberData;
					}
				}
			}

			// Client-side filtering is still needed since Graph API has limited filtering for group members
			if ($nameFilter !== null)
			{
				$originalCount = count($resources);
				$resources = array_filter($resources, function ($resource) use ($nameFilter)
				{
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

			if ($nameFilter !== null)
			{
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
		}
		catch (\Exception $e)
		{
			$this->logger->error('Failed to get available resources from Outlook', [
				'error' => $e->getMessage(),
				'bridge' => 'outlook'
			]);
			throw $e;
		}
	}

	/**
	 * Get resources from database with filtering and pagination.
	 *
	 * @param string|null $nameFilter Optional substring filter
	 * @param int $limit Page size limit
	 * @param int $offset Page offset
	 * @return array Resources and metadata
	 */
	private function getResourcesFromDatabase($nameFilter = null, $limit = 0, $offset = 0): array
	{
		try
		{
			$tenantId = $this->config['context_tenant_id'] ?? null;
			
			$conditions = ['bridge_type = :bridge_type', 'is_active = true'];
			$params = ['bridge_type' => $this->getBridgeType()];
			
			// Add tenant filter
			if ($tenantId !== null)
			{
				$conditions[] = '(tenant_id IS NOT DISTINCT FROM :tenant_id)';
				$params['tenant_id'] = $tenantId;
			}
			else
			{
				$conditions[] = 'tenant_id IS NULL';
			}
			
			// Add name filter if provided
			if ($nameFilter !== null)
			{
				$conditions[] = '(resource_name ILIKE :name_filter OR resource_email ILIKE :name_filter)';
				$params['name_filter'] = '%' . $nameFilter . '%';
			}
			
			$whereClause = implode(' AND ', $conditions);
			
			// Get total count
			$countSql = "SELECT COUNT(*) FROM bridge_resources WHERE $whereClause";
			$countStmt = $this->db->prepare($countSql);
			$countStmt->execute($params);
			$totalCount = $countStmt->fetchColumn();
			
			if ($totalCount == 0)
			{
				return ['resources' => [], 'metadata' => ['total_records' => 0, 'filtered_count' => 0]];
			}
			
			// Build main query with pagination
			$sql = "SELECT * FROM bridge_resources WHERE $whereClause ORDER BY resource_name";
			
			if ($limit > 0)
			{
				$sql .= " LIMIT :limit";
				$params['limit'] = $limit;
				
				if ($offset > 0)
				{
					$sql .= " OFFSET :offset";
					$params['offset'] = $offset;
				}
			}
			
			$stmt = $this->db->prepare($sql);
			$stmt->execute($params);
			$dbRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
			
			// Transform database records to match API format
			$resources = [];
			foreach ($dbRows as $row)
			{
				$resources[] = [
					'id' => $row['resource_id'],
					'name' => $row['resource_name'],
					'email' => $row['resource_email'] ?? '',
					'type' => $row['resource_type'],
					'capacity' => $row['capacity'],
					'location' => $row['location'],
					'bridge_type' => $this->getBridgeType(),
					'@odata.type' => '#microsoft.graph.room', // Compatibility with Graph API format
					'source' => 'database',
					'raw_data' => [
						'database_id' => $row['id'],
						'resource_data' => $row['resource_data'] ? json_decode($row['resource_data'], true) : null,
						'created_at' => $row['created_at'],
						'updated_at' => $row['updated_at']
					]
				];
			}
			
			$this->logger->info('Retrieved resources from database', [
				'bridge' => 'outlook',
				'tenant_id' => $tenantId,
				'total_count' => $totalCount,
				'returned_count' => count($resources),
				'name_filter' => $nameFilter,
				'limit' => $limit,
				'offset' => $offset
			]);
			
			return [
				'resources' => $resources,
				'metadata' => [
					'total_records' => $totalCount,
					'filtered_count' => count($resources),
					'source' => 'database',
					'cache_hit' => true
				]
			];
		}
		catch (\Exception $e)
		{
			$this->logger->error('Failed to get resources from database', [
				'error' => $e->getMessage(),
				'bridge' => 'outlook'
			]);
			
			// Return empty result on database error to trigger fallback
			return ['resources' => [], 'metadata' => ['total_records' => 0, 'filtered_count' => 0]];
		}
	}

	/**
	 * Get available Microsoft 365 groups with basic details.
	 *
	 * @param string|null $nameFilter Optional name/mail filter
	 * @param int $limit Server-side $top page size
	 * @param int $offset Server-side $skip page offset
	 * @return array Groups and metadata
	 */
	public function getAvailableGroups($nameFilter = null, $limit = 0, $offset = 0): array
	{
		try
		{
			// Get the request adapter from the Graph service client
			$requestAdapter = $this->graphServiceClient->getRequestAdapter();

			// Build URL with pagination parameters
			$queryParams = [];

			// Apply pagination parameters to Graph API query
			if ($limit > 0)
			{
				$queryParams[] = '$top=' . $limit;
			}
			else
			{
				$queryParams[] = '$top=999'; // Default large number if no limit specified
			}

			if ($offset > 0)
			{
				$queryParams[] = '$skip=' . $offset;
			}

			// Add name filter if provided (using Graph API $filter)
			if ($nameFilter !== null)
			{
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

			if (method_exists($groupsResponse, 'getValue') && !empty($groupsResponse->getValue()))
			{
				$groups = $groupsResponse->getValue();

				foreach ($groups as $group)
				{
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
				if (method_exists($groupsResponse, 'getOdataCount'))
				{
					$totalCount = $groupsResponse->getOdataCount();
				}
			}

			$logData = [
				'bridge' => 'outlook',
				'returned_group_count' => count($allGroups),
				'api_limit' => $limit,
				'api_offset' => $offset
			];

			if ($nameFilter !== null)
			{
				$logData['name_filter'] = $nameFilter;
			}

			if ($totalCount !== null)
			{
				$logData['total_count_from_api'] = $totalCount;
			}

			$this->logger->info('Retrieved available groups from Outlook with server-side pagination', $logData);

			// Return groups with metadata for consistency
			$metadata = [
				'filtered_count' => count($allGroups)
			];

			// Include total count if available from API
			if ($totalCount !== null)
			{
				$metadata['total_records'] = $totalCount;
			}

			return [
				'resources' => $allGroups,
				'metadata' => $metadata
			];
		}
		catch (\Exception $e)
		{
			$this->logger->error('Failed to get available groups from Outlook', [
				'error' => $e->getMessage(),
				'bridge' => 'outlook'
			]);
			throw $e;
		}
	}


	/**
	 * Get calendar items for a specific resource (user mailbox).
	 *
	 * @param string $resourceId UPN or user ID
	 * @param string|null $startDate Optional ISO8601 start
	 * @param string|null $endDate Optional ISO8601 end
	 * @param int $limit Optional page size
	 * @param int $offset Optional page offset
	 * @return array Events and optional metadata
	 */
	public function getResourceCalendarItems($resourceId, $startDate = null, $endDate = null, $limit = 0, $offset = 0): array
	{
		try
		{
			if (!$resourceId)
			{
				throw new \InvalidArgumentException('Resource ID is required');
			}

			// Get the request adapter from the Graph service client
			$requestAdapter = $this->graphServiceClient->getRequestAdapter();

			// Build query parameters
			$queryParams = [];

			// Add pagination parameters
			if ($limit > 0)
			{
				$queryParams['$top'] = $limit;
			}
			if ($offset > 0)
			{
				$queryParams['$skip'] = $offset;
			}

			// Add date filtering if provided
			$filters = [];
			if ($startDate)
			{
				$filters[] = "start/dateTime ge '{$startDate}'";
			}
			if ($endDate)
			{
				$filters[] = "end/dateTime le '{$endDate}'";
			}

			if (!empty($filters))
			{
				$queryParams['$filter'] = implode(' and ', $filters);
			}

			// Add ordering for consistent pagination
			$queryParams['$orderby'] = 'start/dateTime';

			// Make a direct API call to get calendar items for the resource (same as OutlookController)
			$calendarItemsRequest = new RequestInformation();

			// Build the URL with query parameters
			$baseUrl = "https://graph.microsoft.com/v1.0/users/{$resourceId}/events";
			if (!empty($queryParams))
			{
				$baseUrl .= '?' . http_build_query($queryParams);
			}

			$calendarItemsRequest->urlTemplate = $baseUrl;
			$calendarItemsRequest->httpMethod = HttpMethod::GET;
			$calendarItemsRequest->addHeader("Accept", "application/json");

			$calendarItemsResponse = $requestAdapter->sendAsync(
				$calendarItemsRequest,
				[\Microsoft\Graph\Generated\Models\EventCollectionResponse::class, 'createFromDiscriminatorValue'],
				[ODataError::class, 'createFromDiscriminatorValue']
			)->wait();

			$events = [];
			$totalCount = null;

			if ($calendarItemsResponse && method_exists($calendarItemsResponse, 'getValue'))
			{
				$items = $calendarItemsResponse->getValue();
				if ($items && !empty($items))
				{
					foreach ($items as $item)
					{
						$events[] = [
							'id' => $item->getId(),
							'subject' => $item->getSubject(),
							'start' => $item->getStart()->getDateTime(),
							'end' => $item->getEnd()->getDateTime(),
							'timezone' => $item->getStart()->getTimeZone(),
							'organizer' => $item->getOrganizer() ? $item->getOrganizer()->getEmailAddress()->getAddress() : null,
							'location' => $item->getLocation() ? $item->getLocation()->getDisplayName() : null,
							'description' => $this->extractTextFromHtml($item->getBody() ? $item->getBody()->getContent() : ''),
							'attendees' => array_values(array_filter(array_map(function ($attendee)
							{
								$emailAddress = $attendee->getEmailAddress();
								if ($emailAddress === null)
								{
									return null;
								}
								return [
									'email' => $emailAddress->getAddress() ?? '',
									'name'  => $emailAddress->getName() ?? ''
								];
							}, $item->getAttendees() ?? []))),

							'bridge_type' => 'outlook'
						];
					}
				}

				// Try to get the total count from @odata.count if available
				if (method_exists($calendarItemsResponse, 'getOdataCount'))
				{
					$totalCount = $calendarItemsResponse->getOdataCount();
				}
			}

			$this->logger->info('Retrieved resource calendar items from Outlook', [
				'bridge' => 'outlook',
				'resource_id' => $resourceId,
				'event_count' => count($events),
				'start_date' => $startDate,
				'end_date' => $endDate,
				'limit' => $limit,
				'offset' => $offset
			]);

			// Return events with metadata if pagination was requested or total count is available
			if ($limit > 0 || $offset > 0 || $totalCount !== null)
			{
				$result = [
					'calendar_items' => $events,
					'metadata' => []
				];

				// Add total_records to metadata if available
				if ($totalCount !== null)
				{
					$result['metadata']['total_records'] = $totalCount;
				}

				return $result;
			}

			// Backward compatibility: return just the events array
			return $events;
		}
		catch (\Exception $e)
		{
			$this->logger->error('Failed to get resource calendar items from Outlook', [
				'error' => $e->getMessage(),
				'bridge' => 'outlook',
				'resource_id' => $resourceId
			]);
			throw $e;
		}
	}


	/**
	 * Debug helper to return raw group and member info.
	 *
	 * @param string|null $groupId Group ID; defaults to configured group_id
	 * @return array Group and members or error
	 */
	public function debugGroupInfo($groupId = null): array
	{
		$targetGroupId = $groupId ?? $this->config['group_id'] ?? null;

		if (!$targetGroupId)
		{
			return ['error' => 'No group ID provided'];
		}

		try
		{
			// Get group basic info
			$group = $this->graphServiceClient->groups()->byGroupId($targetGroupId)->get()->wait();

			// Get group members
			$membersResponse = $this->graphServiceClient->groups()->byGroupId($targetGroupId)->members()->get()->wait();
			$members = $membersResponse->getValue() ?? [];

			$memberDetails = [];
			foreach ($members as $member)
			{
				$memberInfo = [
					'id' => $member->getId(),
					'displayName' => $member->getDisplayName(),
					'odataType' => $member->getOdataType(),
					'class' => get_class($member)
				];

				if ($member instanceof \Microsoft\Graph\Generated\Models\User)
				{
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
		}
		catch (\Exception $e)
		{
			return [
				'error' => $e->getMessage(),
				'group_id' => $targetGroupId
			];
		}
	}


	/**
	 * Fallback: list resources (rooms) using Places API when no group is configured.
	 *
	 * @param string|null $nameFilter Optional substring filter
	 * @return array List of resources
	 */
	private function getResourcesFromPlaces($nameFilter = null): array
	{
		try
		{
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

			if ($placesResponse)
			{
				$places = $placesResponse->getValue();
				if ($places && !empty($places))
				{
					foreach ($places as $place)
					{
						$displayName = $place->getDisplayName() ?? 'N/A';
						$email = $place->getAdditionalData()['emailAddress'] ?? '';

						// Apply name filter if provided
						if ($nameFilter !== null)
						{
							$nameFilterLower = strtolower($nameFilter);
							$displayNameLower = strtolower($displayName);
							$emailLower = strtolower($email);

							// Check if filter matches displayName or email
							if (
								strpos($displayNameLower, $nameFilterLower) === false &&
								strpos($emailLower, $nameFilterLower) === false
							)
							{
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

			if ($nameFilter !== null)
			{
				$logData['name_filter'] = $nameFilter;
				$logData['filtered_results'] = count($resources);
			}

			$this->logger->info('Retrieved available resources from Outlook Places API', $logData);

			return $resources;
		}
		catch (\Exception $e)
		{
			$this->logger->error('Failed to get available resources from Outlook Places API', [
				'error' => $e->getMessage(),
				'bridge' => 'outlook'
			]);
			throw $e;
		}
	}


	/**
	 * Re-enable mappings in error state for the Outlook bridge by setting them to pending.
	 *
	 * @param array $eventIds Optional list of event IDs to scope; empty for all
	 * @return array Summary with re_enabled_count and any errors
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

			$this->logger->info("OutlookBridge: Re-enabled {$results['re_enabled_count']} failed events");
		}
		catch (\Exception $e)
		{
			$results['errors']++;
			$results['error_details'][] = [
				'error' => 'Failed to re-enable failed events for Outlook bridge: ' . $e->getMessage()
			];

			$this->logger->error('Failed to re-enable failed events for Outlook bridge', [
				'error' => $e->getMessage()
			]);
		}

		return $results;
	}


	/**
	 * Process pending sync mappings where Outlook is involved.
	 *
	 * @param int $batchSize Max mappings to process in this call
	 * @return array Summary with processed and errors counts
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
			$this->logger->error('Failed to process pending syncs for Outlook bridge', [
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
	 * Handle a pending sync when Outlook is the source.
	 *
	 * @param array $mapping Bridge mapping row
	 * @return array Action taken and context
	 */
	private function processPendingSyncAsSource($mapping): array
	{
		// Get the current event from Outlook
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
	 * Handle a pending sync when Outlook is the target.
	 *
	 * @param array $mapping Bridge mapping row
	 * @return array Action taken and context
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
	 * Helper to fetch and map a single Outlook event by ID.
	 *
	 * @param string $calendarId Outlook user/calendar identifier
	 * @param string $eventId Outlook event ID
	 * @return array|null Generic event or null when missing
	 */
	private function getEventById($calendarId, $eventId): ?array
	{
		try
		{
			$event = $this->graphServiceClient->users()->byUserId($calendarId)->calendar()->events()->byEventId($eventId)->get()->wait();

			if ($event)
			{
				return $this->mapOutlookSDKEventToGeneric($event);
			}

			return null;
		}
		catch (\Exception $e)
		{
			$this->logger->error('Failed to get Outlook event by ID', [
				'calendar_id' => $calendarId,
				'event_id' => $eventId,
				'error' => $e->getMessage()
			]);
			return null;
		}
	}
}
