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
use Psr\Http\Message\ResponseInterface;
use PDO;

/**
 * OutlookBridge integrates with Microsoft Graph to manage calendars and events.
 *
 * Responsibilities:
 * - CRUD operations on Outlook events
 * - Listing calendars/resources/groups
 * - Webhook subscription lifecycle (create, renew, delete)
 * - Secure webhook notification processing with per-tenant authentication
 * - Utility helpers to map Outlook SDK models to the bridge's generic event shape
 * 
 * Required Configuration:
 * - client_id: Microsoft Graph application client ID
 * - client_secret: Microsoft Graph application client secret  
 * - tenant_id: Azure AD tenant ID
 * - webhook_client_secret: Per-tenant secret for webhook validation
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
		$required = ['client_id', 'client_secret', 'tenant_id', 'webhook_client_secret'];

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

	/**
	 * Validate that calendar ID is in email format for Outlook bridge.
	 *
	 * @param string $calendarId The calendar ID to validate
	 * @throws \InvalidArgumentException if calendar ID is not a valid email address
	 */
	private function validateCalendarId(string $calendarId): void
	{
		if (!filter_var($calendarId, FILTER_VALIDATE_EMAIL))
		{
			throw new \InvalidArgumentException(
				"Outlook bridge requires calendar ID to be in email format. Got: {$calendarId}"
			);
		}
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
		if (!empty($_ENV['http_proxy']))
		{
			$guzzleConfig = [
				"proxy" => "{$_ENV['http_proxy']}"
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
	 * Uses calendarView instead of events so that recurring series are automatically
	 * expanded into individual occurrence instances within the requested window.
	 *
	 * @param string $calendarId Outlook user email address (UPN format)
	 * @param string $startDate Date string (e.g., "2025-09-15")
	 * @param string $endDate Date string (e.g., "2025-09-16")
	 * @return array List of generic event arrays; each recurrence instance is a separate entry
	 * @throws \Exception on API errors
	 */
	public function getEvents($calendarId, $startDate, $endDate): array
	{
		$this->validateCalendarId($calendarId);
		$this->logOperation('get_events', ['calendar_id' => $calendarId]);

		try
		{
			$requestConfig = new \Microsoft\Graph\Generated\Users\Item\Calendar\CalendarView\CalendarViewRequestBuilderGetRequestConfiguration();
			$requestConfig->queryParameters = new \Microsoft\Graph\Generated\Users\Item\Calendar\CalendarView\CalendarViewRequestBuilderGetQueryParameters();

			// calendarView requires startDateTime/endDateTime as plain query params (not OData $filter)
			// and automatically expands recurring series into individual occurrence instances
			$requestConfig->queryParameters->startDateTime = $startDate . 'T00:00:00Z';
			$requestConfig->queryParameters->endDateTime   = $endDate   . 'T23:59:59Z';
			$requestConfig->queryParameters->select = ['id', 'subject', 'start', 'end', 'location', 'attendees', 'body', 'organizer', 'isAllDay', 'createdDateTime', 'lastModifiedDateTime'];
			$requestConfig->queryParameters->top = 999;
			$requestConfig->queryParameters->orderby = ['start/dateTime asc'];

			$eventsResponse = $this->graphServiceClient->users()->byUserId($calendarId)->calendar()->calendarView()->get($requestConfig)->wait();
			$events = $eventsResponse->getValue();

			return array_map([$this, 'mapOutlookSDKEventToGeneric'], $events ?? []);
		}
		catch (\Exception $e)
		{
			throw new \Exception("Failed to get events: " . $this->exceptionSummary($e));
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
			throw new \Exception("Failed to get event: " . $this->exceptionSummary($e));
		}
	}

	/**
	 * Create an event in Outlook.
	 *
	 * @param string $calendarId Outlook user email address (UPN format)
	 * @param array $event Generic event payload
	 * @return string Created Outlook event ID
	 */
	public function createEvent($calendarId, $event): string
	{
		$this->validateCalendarId($calendarId);
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
					$this->exceptionSummary($e)
				);
			}
			throw new \Exception("Failed to create event: " . $this->exceptionSummary($e));
		}
	}

	/**
	 * Update an Outlook event.
	 *
	 * @param string $calendarId Outlook user email address (UPN format)
	 * @param string $eventId Outlook event ID
	 * @param array $event Generic event payload
	 * @return bool True when updated
	 */
	public function updateEvent($calendarId, $eventId, $event): bool
	{
		$this->validateCalendarId($calendarId);
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
					$this->exceptionSummary($e)
				);
			}
			throw new \Exception("Failed to update event: " . $this->exceptionSummary($e));
		}
	}

	/**
	 * Delete an Outlook event.
	 *
	 * @param string $calendarId Outlook user email address (UPN format)
	 * @param string $eventId Outlook event ID
	 * @return bool True when deleted
	 */
	public function deleteEvent($calendarId, $eventId): bool
	{
		$this->validateCalendarId($calendarId);
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
					'error' => $this->exceptionSummary($e)
				]);
			}

			return true;
		}
		catch (\Exception $e)
		{
			$this->logger->error('Failed to delete Outlook event', [
				'calendar_id' => $calendarId,
				'event_id' => $eventId,
				'error' => $this->exceptionSummary($e)
			]);
			throw new \Exception("Failed to delete event: " . $this->exceptionSummary($e));
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

		// If group_id is configured, get calendars from group members using getAvailableResources
		if (isset($this->config['group_id']) && !empty($this->config['group_id']))
		{
			// Use getAvailableResources for better pagination and performance
			$resourcesResult = $this->getAvailableResources();
			$resources = $resourcesResult['resources'] ?? $resourcesResult; // Handle both formats
			
			// Convert resources to calendar format
			$calendars = [];
			foreach ($resources as $resource)
			{
				// Skip if no email/UPN available for calendar subscription
				$calendarId = $resource['email'] ?? $resource['userPrincipalName'] ?? null;
				if (empty($calendarId))
				{
					continue;
				}
				
				$calendars[] = [
					'id' => $calendarId, // Use email/UPN as calendar ID for subscriptions
					'name' => $resource['name'] ?? $calendarId,
					'email' => $resource['email'] ?? '',
					'type' => $resource['type'] ?? 'user', // Use existing type or default to 'user'
					'bridge_type' => $this->getBridgeType(),
					'raw_data' => $resource['raw_data'] ?? $resource
				];
			}
			
			return $calendars;
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
			throw new \Exception("Failed to get calendars: " . $this->exceptionSummary($e));
		}
	}




	/**
	 * Create a Microsoft Graph webhook subscription for a calendar's events.
	 *
	 * @param string $calendarId Outlook user email address (UPN format)
	 * @param string $webhookUrl Publicly reachable webhook URL
	 * @return string Subscription ID
	 */
	public function subscribeToChanges($calendarId, $webhookUrl, $subscriptionId = null): string
	{
		$this->validateCalendarId($calendarId);
		$this->logOperation('subscribe_to_changes', ['calendar_id' => $calendarId, 'webhook_url' => $webhookUrl]);

		try
		{
			$subscription = new \Microsoft\Graph\Generated\Models\Subscription();
			$subscription->setChangeType('created,updated,deleted');
			$subscription->setNotificationUrl($webhookUrl);
			$subscription->setResource("users/{$calendarId}/calendar/events");
			$subscription->setExpirationDateTime(new \DateTime('+1 day'));
			$subscription->setClientState($this->generateClientState());

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
			$summary = $this->exceptionSummary($e);
			$this->logger->error('Subscription creation failed', [
				'calendar_id' => $calendarId,
				'webhook_url' => $webhookUrl,
				'error' => $summary,
				'error_type' => get_class($e)
			]);
			throw new \Exception("Failed to create subscription: " . $summary);
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
			$this->subscriptionRepository->delete(
				$subscriptionId, 
				$this->getBridgeType(), 
				(string)($this->config['context_tenant_id'] ?? 'default')
			);

			return true;
		}
		catch (\Exception $e)
		{
			throw new \Exception("Failed to delete subscription: " . $this->exceptionSummary($e));
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
	public function renewSubscription(string $subscriptionId, $extendInterval = 'P1D'): array
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
                WHERE subscription_id = :id AND bridge_type = :bridge AND (tenant_id IS NOT DISTINCT FROM :tenant_id)");
			$stmt->execute([
				':expires_at' => $effectiveExpiration->format('Y-m-d H:i:s'),
				':id' => $subscriptionId,
				':bridge' => $this->getBridgeType(),
				':tenant_id' => (string)($this->config['context_tenant_id'] ?? 'default')
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
				'error' => $this->exceptionSummary($e)
			]);
			return [
				'success' => false,
				'subscription_id' => $subscriptionId,
				'error' => $this->exceptionSummary($e)
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
	 * IMPORTANT: When datetime strings come from webhook payloads (especially from booking system),
	 * they are already in the source timezone but without timezone information appended.
	 * This method assumes that datetime strings WITHOUT explicit timezone info are already
	 * in the targetTimezone, so no conversion is needed.
	 *
	 * @param string $dateString Input datetime string (may or may not include timezone info)
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
			// Check if the datetime string includes timezone information
			// If it contains 'Z', '+', or explicit timezone offset, it has timezone info
			$hasTimezoneInfo = (strpos($dateString, 'Z') !== false) || 
			                   (preg_match('/[+-]\d{2}:\d{2}$/', $dateString)) ||
			                   (preg_match('/[+-]\d{4}$/', $dateString));
			
			if ($hasTimezoneInfo)
			{
				// Parse datetime with its included timezone and convert to target timezone
				$date = new \DateTime($dateString);
				$date->setTimezone(new \DateTimeZone($targetTimezone));
			}
			else
			{
				// No timezone info in string - assume it's already in the target timezone
				// This is the case for booking system webhook payloads
				$date = new \DateTime($dateString, new \DateTimeZone($targetTimezone));
			}
			
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
                subscription_data, expires_at, tenant_id, created_at
            ) VALUES (
                :bridge_type, :subscription_id, :calendar_id, :webhook_url,
                :subscription_data, :expires_at, :tenant_id, CURRENT_TIMESTAMP
            )
            ON CONFLICT (subscription_id) DO UPDATE SET
                webhook_url = EXCLUDED.webhook_url,
                subscription_data = EXCLUDED.subscription_data,
                expires_at = EXCLUDED.expires_at,
                tenant_id = EXCLUDED.tenant_id
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
			':expires_at' => $expiresValue,
			':tenant_id' => (string)($this->config['context_tenant_id'] ?? 'default')
		]);
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
	 * @param string|null $startDate Optional date string (e.g., "2025-09-15") - converted to start of day
	 * @param string|null $endDate Optional date string (e.g., "2025-09-16") - converted to end of day
	 * @param int $limit Optional page size
	 * @param int $offset Optional page offset
	 * @return array Events and optional metadata (includes overlapping events)
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

			// Add date filtering if provided.
			// When both dates are supplied use calendarView so recurring series are expanded
			// into individual occurrences. Single-date filters fall back to /events + $filter.
			if ($startDate && $endDate)
			{
				// calendarView requires startDateTime/endDateTime as plain query params, not $filter
				$queryParams['startDateTime'] = $startDate . 'T00:00:00Z';
				$queryParams['endDateTime']   = $endDate   . 'T23:59:59Z';
			}
			elseif ($startDate)
			{
				// Single date filter: events that end after start of the day
				$windowStart = $startDate . 'T00:00:00.000Z';
				$queryParams['$filter'] = "end/dateTime gt '{$windowStart}'";
			}
			elseif ($endDate)
			{
				// Single date filter: events that start before end of the day
				$windowEnd = $endDate . 'T23:59:59.999Z';
				$queryParams['$filter'] = "start/dateTime lt '{$windowEnd}'";
			}

			// Add ordering for consistent pagination
			$queryParams['$orderby'] = 'start/dateTime';

			// Make a direct API call to get calendar items for the resource
			$calendarItemsRequest = new RequestInformation();

			// Use calendarView when a full date range is provided (expands recurring series),
			// otherwise fall back to /events for single-bound or open-ended queries
			$endpoint = ($startDate && $endDate) ? 'calendarView' : 'events';
			$baseUrl = "https://graph.microsoft.com/v1.0/users/{$resourceId}/{$endpoint}";
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
						// Prepare data for createGenericEvent standardization  
						$genericData = [
							'id' => $item->getId(),
							'subject' => $item->getSubject(),
							'start' => $item->getStart()->getDateTime(),
							'end' => $item->getEnd()->getDateTime(),
							'location' => $item->getLocation() ? $item->getLocation()->getDisplayName() : null,
							'description' => $this->extractTextFromHtml($item->getBody() ? $item->getBody()->getContent() : ''),
							'organizer' => $item->getOrganizer() ? $item->getOrganizer()->getEmailAddress()->getAddress() : null,
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
							'all_day' => $item->getIsAllDay() ?? false,
							'timezone' => $item->getStart()->getTimeZone(),
							'last_modified' => $item->getLastModifiedDateTime() ? $item->getLastModifiedDateTime()->format('c') : date('c'),
							'created' => $item->getCreatedDateTime() ? $item->getCreatedDateTime()->format('c') : date('c'),
							'raw_data' => [
								'id' => $item->getId(),
								'subject' => $item->getSubject(),
								'start' => $item->getStart(),
								'end' => $item->getEnd(),
								'location' => $item->getLocation(),
								'organizer' => $item->getOrganizer(),
								'attendees' => $item->getAttendees(),
								'body' => $item->getBody(),
								'isAllDay' => $item->getIsAllDay(),
								'createdDateTime' => $item->getCreatedDateTime(),
								'lastModifiedDateTime' => $item->getLastModifiedDateTime()
							]
						];

						// Use the standardized createGenericEvent method
						$events[] = $this->createGenericEvent($genericData);
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
				'error' => $this->exceptionSummary($e),
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
				'error' => $this->exceptionSummary($e),
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
	public function reEnableFailedEvents($eventIds = []): array
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
	 * Generate a secure client state for webhook subscriptions.
	 * 
	 * Combines the configured webhook client secret with tenant ID and timestamp
	 * to create a verifiable client state for incoming webhook notifications.
	 * 
	 * @return string Secure client state for webhook validation
	 */
	private function generateClientState(): string
	{
		$tenantId = $this->config['context_tenant_id'] ?? 'default';
		$secret = $this->config['webhook_client_secret'] ?? $_ENV['WEBHOOK_CLIENT_SECRET'] ?? null;
		$timestamp = time();
		
		// Create a secure hash using webhook secret, tenant ID, and timestamp
		$payload = sprintf('outlook-bridge-%s-%s-%d', $tenantId, $secret, $timestamp);
		return hash('sha256', $payload);
	}

	/**
	 * Validate incoming webhook notification authenticity.
	 * 
	 * Verifies that the clientState in the webhook notification matches
	 * what we expect based on our configured webhook client secret.
	 * 
	 * @param string $clientState The clientState from the webhook notification
	 * @return bool True if the webhook is authentic, false otherwise
	 */
	public function validateWebhookNotification(string $clientState): bool
	{
		$tenantId = $this->config['context_tenant_id'] ?? $_ENV['DEFAULT_TENANT_ID'] ?? 'default';
		$secret = $this->config['webhook_client_secret'] ?? $_ENV['WEBHOOK_CLIENT_SECRET'] ?? null;
		
		// Check against client states from the last 24 hours (86400 seconds)
		$currentTime = time();
		$maxAge = 86400; // 24 hours
		
		for ($i = 0; $i < $maxAge; $i += 60) // Check every minute in the last 24h
		{
			$testTimestamp = $currentTime - $i;
			$expectedPayload = sprintf('outlook-bridge-%s-%s-%d', $tenantId, $secret, $testTimestamp);
			$expectedClientState = hash('sha256', $expectedPayload);
			
			if (hash_equals($expectedClientState, $clientState))
			{
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Process and validate incoming Microsoft Graph webhook notifications.
	 * 
	 * Handles the complete webhook processing workflow including validation,
	 * parsing, and logging of incoming change notifications from Microsoft Graph.
	 * 
	 * @param array $notification The webhook notification payload
	 * @return array Processing result with status and details
	 */
	public function processWebhookNotification(array $notification): array
	{
		try
		{
			// Validate required fields
			if (!isset($notification['clientState']))
			{
				return [
					'status' => 'error',
					'message' => 'Missing clientState in webhook notification'
				];
			}

			// Validate webhook authenticity
			if (!$this->validateWebhookNotification($notification['clientState']))
			{
				$this->logger->warning('Invalid webhook notification received', [
					'bridge' => 'outlook',
					'tenant_id' => $this->config['context_tenant_id'] ?? 'default',
					'client_state' => $notification['clientState'],
					'notification_id' => $notification['id'] ?? 'unknown'
				]);
				
				return [
					'status' => 'error',
					'message' => 'Invalid clientState - webhook authentication failed'
				];
			}

			// Log successful webhook reception
			$this->logger->info('Valid webhook notification received', [
				'bridge' => 'outlook',
				'tenant_id' => $this->config['context_tenant_id'] ?? 'default',
				'notification_id' => $notification['id'] ?? 'unknown',
				'change_type' => $notification['changeType'] ?? 'unknown',
				'resource' => $notification['resource'] ?? 'unknown'
			]);

			return [
				'status' => 'success',
				'message' => 'Webhook notification processed successfully',
				'notification_id' => $notification['id'] ?? null,
				'change_type' => $notification['changeType'] ?? null,
				'resource' => $notification['resource'] ?? null
			];
		}
		catch (\Exception $e)
		{
			$this->logger->error('Error processing webhook notification', [
				'bridge' => 'outlook',
				'tenant_id' => $this->config['context_tenant_id'] ?? 'default',
				'error' => $this->exceptionSummary($e),
				'notification' => $notification
			]);

			return [
				'status' => 'error',
				'message' => 'Internal error processing webhook: ' . $this->exceptionSummary($e)
			];
		}
	}

	/**
	 * Resolve a user GUID to their email address using Microsoft Graph API
	 * 
	 * @param string $userGuid The user's GUID from Microsoft Graph
	 * @return string|null The user's email address, or null if not found
	 */
	public function resolveUserGuidToEmail(string $userGuid): ?string
	{
		try
		{
			$requestConfig = new \Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilderGetRequestConfiguration();
			$requestConfig->queryParameters = new \Microsoft\Graph\Generated\Users\Item\UserItemRequestBuilderGetQueryParameters();
			$requestConfig->queryParameters->select = ['mail', 'userPrincipalName'];

			$user = $this->graphServiceClient->users()->byUserId($userGuid)->get($requestConfig)->wait();

			if (empty($user))
			{
				$this->logger->warning('No user data returned for GUID resolution', [
					'bridge' => 'outlook',
					'tenant_id' => $this->config['context_tenant_id'] ?? 'default',
					'user_guid' => $userGuid
				]);
				return null;
			}

			// Prefer mail field, fallback to userPrincipalName
			$email = $user->getMail() ?? $user->getUserPrincipalName() ?? null;

			if (empty($email))
			{
				$this->logger->warning('User found but no email address available', [
					'bridge' => 'outlook',
					'tenant_id' => $this->config['context_tenant_id'] ?? 'default',
					'user_guid' => $userGuid,
					'user_mail' => $user->getMail(),
					'user_principal_name' => $user->getUserPrincipalName()
				]);
				return null;
			}

			$this->logger->debug('Successfully resolved user GUID to email', [
				'bridge' => 'outlook',
				'tenant_id' => $this->config['context_tenant_id'] ?? 'default',
				'user_guid' => $userGuid,
				'email' => $email
			]);

			return $email;
		}
		catch (\Exception $e)
		{
			$this->logger->error('Failed to resolve user GUID to email', [
				'bridge' => 'outlook',
				'tenant_id' => $this->config['context_tenant_id'] ?? 'default',
				'user_guid' => $userGuid,
				'error' => $this->exceptionSummary($e)
			]);
			return null;
		}
	}

	/**
	 * Build a concise, information-rich error summary from Graph/Kiota/HTTP exceptions.
	 * Includes HTTP status, OData error code/message when available, and falls back gracefully.
	 */
	private function exceptionSummary(\Throwable $e): string
	{
		$parts = [];
		$hasHttpStatus = false;
		$hasOData = false;
		$message = trim((string)$e->getMessage());
		if ($message !== '')
		{
			$parts[] = $message;
		}

		// Prefer OData details directly from Kiota exception objects when available.
		if ($odataFromException = $this->extractODataErrorFromException($e))
		{
			$parts[] = $odataFromException;
			$hasOData = true;
		}

		// Include HTTP response details if present
		$response = null;
		if ($e instanceof \GuzzleHttp\Exception\RequestException)
		{
			$response = $e->getResponse();
		}
		elseif (property_exists($e, 'response'))
		{
			$response = $e->response;
		}

		if ($response instanceof ResponseInterface)
		{
			$status = $response->getStatusCode();
			$reason = $response->getReasonPhrase();
			$parts[] = "http={$status} {$reason}";
			$hasHttpStatus = true;
			$body = (string)$response->getBody();
			if (!empty($body))
			{
				if (!$hasOData && ($odata = $this->extractODataErrorFromBody($body)))
				{
					$parts[] = $odata;
					$hasOData = true;
				}
				else
				{
					$parts[] = 'body=' . $this->truncate($body, 400);
				}
			}
		}

		// Kiota ApiException may provide status and response headers even without a PSR response body.
		if ($e instanceof \Microsoft\Kiota\Abstractions\ApiException)
		{
			$status = $e->getResponseStatusCode();
			if ($status !== null && !$hasHttpStatus)
			{
				$parts[] = "http={$status}";
			}

			$requestId = $this->getResponseHeaderValue($e->getResponseHeaders(), ['request-id', 'x-ms-request-id']);
			$clientRequestId = $this->getResponseHeaderValue($e->getResponseHeaders(), ['client-request-id']);
			if ($requestId)
			{
				$parts[] = 'request-id=' . $requestId;
			}
			if ($clientRequestId)
			{
				$parts[] = 'client-request-id=' . $clientRequestId;
			}
		}

		// Include exception class for context
		$parts[] = 'type=' . get_class($e);

		// Previous exception summary (short)
		if ($e->getPrevious())
		{
			$parts[] = 'prev=' . $this->truncate($e->getPrevious()->getMessage() ?: get_class($e->getPrevious()), 200);
		}

		return $this->truncate(implode(' | ', $parts), 1000);
	}

	/**
	 * Extract OData details directly from Kiota ODataError exception objects.
	 * Returns a compact string like: odata=ErrorCode: message | innerError=request-id=...,client-request-id=...
	 */
	private function extractODataErrorFromException(\Throwable $e): ?string
	{
		if (!($e instanceof ODataError))
		{
			return null;
		}

		try
		{
			$main = $e->getError();
			if ($main === null)
			{
				return null;
			}

			$code = $main->getCode();
			$message = $main->getMessage();
			$target = $main->getTarget();

			$odataParts = [];
			if (!empty($code))
			{
				$odataParts[] = (string)$code;
			}
			if (!empty($message))
			{
				$odataParts[] = (string)$message;
			}

			$result = null;
			if (!empty($odataParts))
			{
				$result = 'odata=' . $this->truncate(implode(': ', $odataParts), 400);
			}

			$innerError = $main->getInnerError();
			if ($innerError !== null)
			{
				$innerParts = [];
				if ($innerError->getRequestId())
				{
					$innerParts[] = 'request-id=' . $innerError->getRequestId();
				}
				if ($innerError->getClientRequestId())
				{
					$innerParts[] = 'client-request-id=' . $innerError->getClientRequestId();
				}
				if ($innerError->getDate())
				{
					$innerParts[] = 'date=' . $innerError->getDate()->format('c');
				}
				if (!empty($innerParts))
				{
					$innerSummary = 'innerError=' . implode(',', $innerParts);
					$result = $result ? ($result . ' | ' . $innerSummary) : $innerSummary;
				}
			}

			if (!empty($target))
			{
				$targetSummary = 'target=' . $target;
				$result = $result ? ($result . ' | ' . $targetSummary) : $targetSummary;
			}

			return $result;
		}
		catch (\Throwable $ignored)
		{
			return null;
		}
	}

	/**
	 * Get first matching response header value (case-insensitive) from Kiota header map.
	 *
	 * @param array<string, string[]> $headers
	 * @param array<int, string> $names
	 */
	private function getResponseHeaderValue(array $headers, array $names): ?string
	{
		if (empty($headers) || empty($names))
		{
			return null;
		}

		$normalized = [];
		foreach ($headers as $key => $values)
		{
			$normalized[strtolower((string)$key)] = $values;
		}

		foreach ($names as $name)
		{
			$key = strtolower($name);
			if (!isset($normalized[$key]) || !is_array($normalized[$key]) || empty($normalized[$key]))
			{
				continue;
			}

			$value = $normalized[$key][0] ?? null;
			if ($value !== null && $value !== '')
			{
				return (string)$value;
			}
		}

		return null;
	}

	/**
	 * Extract Microsoft Graph OData error details from an HTTP body if present.
	 * Returns a compact string like: odata=ErrorCode: message
	 */
	private function extractODataErrorFromBody(string $body): ?string
	{
		try
		{
			$data = json_decode($body, true);
			if (!is_array($data))
			{
				return null;
			}
			if (isset($data['error']))
			{
				$err = $data['error'];
				$code = is_array($err) && isset($err['code']) ? (string)$err['code'] : null;
				$msg = null;
				if (is_array($err) && isset($err['message']))
				{
					// message can be string or object with 'value'
					$msg = is_array($err['message']) ? ($err['message']['value'] ?? null) : (string)$err['message'];
				}
				if ($code || $msg)
				{
					$parts = [];
					if ($code) { $parts[] = $code; }
					if ($msg) { $parts[] = $msg; }
					return 'odata=' . $this->truncate(implode(': ', $parts), 400);
				}
			}
		}
		catch (\Throwable $ignored)
		{
			// ignore parse errors
		}
		return null;
	}

	/**
	 * Truncate a string to a maximum length, appending an ellipsis if needed.
	 */
	private function truncate(string $s, int $max = 1000): string
	{
		if (strlen($s) <= $max)
		{
			return $s;
		}
		return substr($s, 0, max(0, $max - 1)) . '…';
	}
}
