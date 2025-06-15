<?php

namespace App\Services;

class CalendarMappingService
{
	private $db;
	private $logger;

	public function __construct($database, $logger)
	{
		$this->db = $database;
		$this->logger = $logger;
	}

	/**
	 * Create a unified view of all calendar items with priority
	 */
	public function getCalendarItemsForSync($resourceId = null, $fromDate = null, $toDate = null)
	{
		$sql = "
        WITH unified_calendar_items AS (
            -- Events (Priority 1)
            SELECT 
                'event' as item_type,
                e.id as item_id,
                1 as priority_level,
                e.from_ as start_time,
                e.to_ as end_time,
                COALESCE(e.name, e.description) as title,
                e.contact_name as organizer,
                e.contact_email as organizer_email,
                e.description,
                e.active,
                er.resource_id,
                e.id_string,
                e.customer_organization_name as organization_name
            FROM bb_event e
            JOIN bb_event_resource er ON e.id = er.event_id
            WHERE e.active = 1
            
            UNION ALL
            
            -- Bookings (Priority 2)
            SELECT 
                'booking' as item_type,
                b.id as item_id,
                2 as priority_level,
                b.from_ as start_time,
                b.to_ as end_time,
                CONCAT('Booking - ', g.name) as title,
                g.name as organizer,
                '' as organizer_email,
                CONCAT('Booking for ', g.name) as description,
                b.active,
                br.resource_id,
                CAST(b.id AS VARCHAR) as id_string,
                org.name as organization_name
            FROM bb_booking b
            JOIN bb_booking_resource br ON b.id = br.booking_id
            LEFT JOIN bb_group g ON b.group_id = g.id
            LEFT JOIN bb_organization org ON g.organization_id = org.id
            WHERE b.active = 1
            
            UNION ALL
            
            -- Allocations (Priority 3)
            SELECT 
                'allocation' as item_type,
                a.id as item_id,
                3 as priority_level,
                a.from_ as start_time,
                a.to_ as end_time,
                CONCAT('Allocation - ', org.name) as title,
                org.name as organizer,
                org.email as organizer_email,
                CONCAT('Allocation for ', org.name) as description,
                a.active,
                ar.resource_id,
                a.id_string,
                org.name as organization_name
            FROM bb_allocation a
            JOIN bb_allocation_resource ar ON a.id = ar.allocation_id
            LEFT JOIN bb_organization org ON a.organization_id = org.id
            WHERE a.active = 1
        )
        SELECT 
            uci.*,
            bro.outlook_item_id,
            bro.outlook_item_name,
            scm.outlook_event_id,
            scm.sync_status,
            scm.last_sync_at
        FROM unified_calendar_items uci
        JOIN bb_resource_outlook_item bro ON uci.resource_id = bro.resource_id AND bro.active = 1
        LEFT JOIN outlook_calendar_mapping scm ON (
            scm.reservation_type = uci.item_type 
            AND scm.reservation_id = uci.item_id 
            AND scm.resource_id = uci.resource_id
        )
        WHERE 1=1
        " . ($resourceId ? " AND uci.resource_id = :resource_id" : "") . "
        " . ($fromDate ? " AND uci.start_time >= :from_date" : "") . "
        " . ($toDate ? " AND uci.end_time <= :to_date" : "") . "
        ORDER BY uci.resource_id, uci.start_time, uci.priority_level
        ";

		$params = [];
		if ($resourceId) $params['resource_id'] = $resourceId;
		if ($fromDate) $params['from_date'] = $fromDate;
		if ($toDate) $params['to_date'] = $toDate;

		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchAll();
	}

	/**
	 * Resolve conflicts when multiple items occupy the same time slot
	 */
	public function resolveTimeConflicts($calendarItems)
	{
		$resolved = [];
		$grouped = [];

		// Group by resource and time overlaps
		foreach ($calendarItems as $item)
		{
			$key = $item['resource_id'] . '_' . $item['start_time'] . '_' . $item['end_time'];
			$grouped[$key][] = $item;
		}

		foreach ($grouped as $group)
		{
			if (count($group) === 1)
			{
				$resolved[] = $group[0];
			}
			else
			{
				// Sort by priority (1 = highest priority)
				usort($group, function ($a, $b)
				{
					return $a['priority_level'] <=> $b['priority_level'];
				});

				// Take the highest priority item
				$resolved[] = $group[0];

				// Log the conflict
				$this->logger->info('Resolved time conflict', [
					'resource_id' => $group[0]['resource_id'],
					'time_slot' => $group[0]['start_time'] . ' - ' . $group[0]['end_time'],
					'winner' => $group[0]['item_type'] . ':' . $group[0]['item_id'],
					'conflicts' => array_map(function ($item)
					{
						return $item['item_type'] . ':' . $item['item_id'];
					}, array_slice($group, 1))
				]);
			}
		}

		return $resolved;
	}

	/**
	 * Map booking system item to Outlook event format
	 */
	public function mapToOutlookEvent($calendarItem)
	{
		return [
			'subject' => $this->sanitizeTitle($calendarItem['title']),
			'start' => [
				'dateTime' => date('c', strtotime($calendarItem['start_time'])),
				'timeZone' => 'Europe/Oslo' // Configure as needed
			],
			'end' => [
				'dateTime' => date('c', strtotime($calendarItem['end_time'])),
				'timeZone' => 'Europe/Oslo'
			],
			'body' => [
				'contentType' => 'text',
				'content' => $calendarItem['description'] ?: $calendarItem['title']
			],
			'organizer' => [
				'emailAddress' => [
					'name' => $calendarItem['organizer'],
					'address' => $calendarItem['organizer_email'] ?: 'noreply@yourorg.com'
				]
			],
			'isReminderOn' => false,
			'showAs' => 'busy',
			// Custom properties to track source
			'singleValueExtendedProperties' => [
				[
					'id' => 'String {66f5a359-4659-4830-9070-00047ec6ac6e} Name BookingSystemType',
					'value' => $calendarItem['item_type']
				],
				[
					'id' => 'String {66f5a359-4659-4830-9070-00047ec6ac6f} Name BookingSystemId',
					'value' => (string)$calendarItem['item_id']
				]
			]
		];
	}

	private function sanitizeTitle($title)
	{
		return substr(trim($title), 0, 255) ?: 'Unnamed Event';
	}

	/**
	 * Create or update calendar mapping entry
	 */
	public function createOrUpdateMapping($reservationType, $reservationId, $resourceId, $outlookItemId, $outlookEventId = null, $syncStatus = 'pending')
	{
		$sql = "
		INSERT INTO outlook_calendar_mapping 
		(reservation_type, reservation_id, resource_id, outlook_item_id, outlook_event_id, sync_status, priority_level, created_at, updated_at)
		VALUES (:reservation_type, :reservation_id, :resource_id, :outlook_item_id, :outlook_event_id, :sync_status, :priority_level, NOW(), NOW())
		ON CONFLICT (reservation_type, reservation_id, resource_id)
		DO UPDATE SET
			outlook_event_id = EXCLUDED.outlook_event_id,
			sync_status = EXCLUDED.sync_status,
			last_sync_at = NOW(),
			updated_at = NOW()
		RETURNING id
		";

		$priorityLevel = $this->getPriorityLevel($reservationType);

		$params = [
			'reservation_type' => $reservationType,
			'reservation_id' => $reservationId,
			'resource_id' => $resourceId,
			'outlook_item_id' => $outlookItemId,
			'outlook_event_id' => $outlookEventId,
			'sync_status' => $syncStatus,
			'priority_level' => $priorityLevel
		];

		$result = $this->db->prepare($sql);
		$result->execute($params);
		$row = $result->fetch();
		return $row['id'] ?? null;
	}

	/**
	 * Bulk populate mapping table from existing calendar items
	 */
	public function populateMappingTable($resourceId = null)
	{
		$calendarItems = $this->getCalendarItemsForSync($resourceId);
		$created = 0;
		$errors = [];

		foreach ($calendarItems as $item) {
			try {
				// Only create mapping if it doesn't exist and we have an outlook_item_id
				if (empty($item['outlook_event_id']) && !empty($item['outlook_item_id'])) {
					$this->createOrUpdateMapping(
						$item['item_type'],
						$item['item_id'],
						$item['resource_id'],
						$item['outlook_item_id'],
						null, // No Outlook event yet
						'pending'
					);
					$created++;
				}
			} catch (\Exception $e) {
				$errors[] = [
					'item' => $item['item_type'] . ':' . $item['item_id'],
					'error' => $e->getMessage()
				];
			}
		}

		$this->logger->info('Bulk populated calendar mapping', [
			'created' => $created,
			'errors_count' => count($errors),
			'resource_id' => $resourceId
		]);

		return ['created' => $created, 'errors' => $errors];
	}

	/**
	 * Update mapping when Outlook event is created/updated
	 */
	public function updateMappingWithOutlookEvent($reservationType, $reservationId, $resourceId, $outlookEventId, $syncStatus = 'synced')
	{
		$sql = "
		UPDATE outlook_calendar_mapping 
		SET outlook_event_id = :outlook_event_id,
			sync_status = :sync_status,
			last_sync_at = NOW(),
			last_modified_outlook = NOW(),
			updated_at = NOW()
		WHERE reservation_type = :reservation_type 
			AND reservation_id = :reservation_id 
			AND resource_id = :resource_id
		";

		$params = [
			'outlook_event_id' => $outlookEventId,
			'sync_status' => $syncStatus,
			'reservation_type' => $reservationType,
			'reservation_id' => $reservationId,
			'resource_id' => $resourceId
		];

		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
		return $stmt->rowCount() > 0;
	}

	/**
	 * Mark mapping as error with error message
	 */
	public function markMappingError($reservationType, $reservationId, $resourceId, $errorMessage)
	{
		$sql = "
		UPDATE outlook_calendar_mapping 
		SET sync_status = 'error',
			error_message = :error_message,
			updated_at = NOW()
		WHERE reservation_type = :reservation_type 
			AND reservation_id = :reservation_id 
			AND resource_id = :resource_id
		";

		$params = [
			'error_message' => $errorMessage,
			'reservation_type' => $reservationType,
			'reservation_id' => $reservationId,
			'resource_id' => $resourceId
		];

		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
		return $stmt->rowCount() > 0;
	}

	/**
	 * Get priority level based on reservation type
	 */
	private function getPriorityLevel($reservationType)
	{
		$priorities = [
			'event' => 1,
			'booking' => 2,
			'allocation' => 3
		];

		return $priorities[$reservationType] ?? 3;
	}

	/**
	 * Get pending sync items
	 */
	public function getPendingSyncItems($limit = 50)
	{
		$sql = "
		SELECT 
			ocm.*,
			bro.outlook_item_id,
			bro.outlook_item_name
		FROM outlook_calendar_mapping ocm
		JOIN bb_resource_outlook_item bro ON ocm.resource_id = bro.resource_id AND bro.active = 1
		WHERE ocm.sync_status IN ('pending', 'error')
		ORDER BY ocm.priority_level ASC, ocm.created_at ASC
		LIMIT :limit
		";

		$stmt = $this->db->prepare($sql);
		$stmt->execute(['limit' => $limit]);
		return $stmt->fetchAll();
	}

	/**
	 * Clean up orphaned mappings (items that no longer exist in source tables)
	 */
	public function cleanupOrphanedMappings()
	{
		$sql = "
		DELETE FROM outlook_calendar_mapping ocm
		WHERE NOT EXISTS (
			SELECT 1 FROM bb_event e 
			WHERE ocm.reservation_type = 'event' 
				AND ocm.reservation_id = e.id 
				AND e.active = 1
		)
		AND NOT EXISTS (
			SELECT 1 FROM bb_booking b 
			WHERE ocm.reservation_type = 'booking' 
				AND ocm.reservation_id = b.id 
				AND b.active = 1
		)
		AND NOT EXISTS (
			SELECT 1 FROM bb_allocation a 
			WHERE ocm.reservation_type = 'allocation' 
				AND ocm.reservation_id = a.id 
				AND a.active = 1
		)
		";

		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$deletedCount = $stmt->rowCount();
		
		$this->logger->info('Cleaned up orphaned mappings', [
			'deleted_count' => $deletedCount
		]);

		return $deletedCount;
	}

	/**
     * Get all resource mappings
     */
    public function getResourceMappings()
    {
        $sql = "
        SELECT 
            resource_id,
            outlook_item_id,
            outlook_item_name,
            active
        FROM bb_resource_outlook_item 
        WHERE active = 1
        ORDER BY resource_id
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Find mapping by Outlook event ID
     */
    public function findMappingByOutlookEvent($outlookEventId)
    {
        $sql = "
        SELECT * FROM outlook_calendar_mapping 
        WHERE outlook_event_id = :outlook_event_id
        LIMIT 1
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['outlook_event_id' => $outlookEventId]);
        return $stmt->fetch();
    }
    
    /**
     * Create mapping for Outlook-originated event
     */
    public function createOutlookOriginatedMapping($outlookEventId, $resourceId, $calendarId, $subject, $startTime, $endTime, $organizer, $organizerEmail, $description)
    {
        // For Outlook-originated events, we don't have a booking system reservation yet
        // So reservation_type and reservation_id will be NULL initially
        $sql = "
        INSERT INTO outlook_calendar_mapping 
        (reservation_type, reservation_id, resource_id, outlook_item_id, outlook_event_id, sync_status, sync_direction, priority_level, created_at, updated_at, last_modified_outlook)
        VALUES (NULL, NULL, :resource_id, :outlook_item_id, :outlook_event_id, 'imported', 'outlook_to_booking', 1, NOW(), NOW(), NOW())
        ON CONFLICT (outlook_event_id)
        DO UPDATE SET
            resource_id = EXCLUDED.resource_id,
            outlook_item_id = EXCLUDED.outlook_item_id,
            sync_status = 'imported',
            sync_direction = 'outlook_to_booking',
            last_modified_outlook = NOW(),
            updated_at = NOW()
        RETURNING id
        ";
        
        $params = [
            'outlook_event_id' => $outlookEventId,
            'resource_id' => $resourceId,
            'outlook_item_id' => $calendarId
        ];
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        
        $this->logger->info('Created Outlook-originated mapping', [
            'outlook_event_id' => $outlookEventId,
            'resource_id' => $resourceId,
            'calendar_id' => $calendarId,
            'subject' => $subject,
            'organizer' => $organizer
        ]);
        
        return $row['id'] ?? null;
    }
}
