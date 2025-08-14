<?php

namespace App\Services;

use PDO;
use Psr\Log\LoggerInterface;

/**
 * DeletionSyncService handles detecting and syncing deleted events between bridges.
 */
class DeletionSyncService
{
	 /** @var PDO Database connection */
	 private PDO $db;
	 /** @var LoggerInterface Logger instance */
	 private LoggerInterface $logger;
	 /** @var mixed BridgeManager orchestrator */
	 private $bridgeManager;

	 /**
	  * Constructor.
	  *
	  * @param PDO $db Database connection
	  * @param LoggerInterface $logger Logger
	  * @param mixed $bridgeManager BridgeManager instance
	  */
	public function __construct(PDO $db, LoggerInterface $logger, $bridgeManager)
	{
		$this->db = $db;
		$this->logger = $logger;
		$this->bridgeManager = $bridgeManager;
	}

	/**
	  * Process deletion check queue.
	  *
	  * @param string|null $tenantId Tenant scope to process, or null for all
	  * @return array{processed:int,deletions_found:int,errors:array}
	 */
	public function processDeletionChecks(?string $tenantId = null): array
	{
		$results = [
			'processed' => 0,
			'deletions_found' => 0,
			'errors' => []
		];

		try
		{
			// Get pending deletion checks from queue
			$checks = $this->getDeletionChecks($tenantId);

			foreach ($checks as $check)
			{
				try
				{
					$checkData = json_decode($check['payload'], true);

					if ($this->processOutlookDeletionCheck($checkData, $tenantId))
					{
						$results['deletions_found']++;
					}

					$results['processed']++;

					// Mark queue item as processed
					$this->markQueueItemProcessed($check['id']);
				}
				catch (\Exception $e)
				{
					$results['errors'][] = [
						'check_id' => $check['id'],
						'error' => $e->getMessage()
					];

					$this->markQueueItemFailed($check['id'], $e->getMessage());
				}
			}
		}
		catch (\Exception $e)
		{
			$this->logger->error('Failed to process deletion checks', [
				'error' => $e->getMessage()
			]);

			$results['errors'][] = $e->getMessage();
		}

		return $results;
	}

	/**
	 * Process a single Outlook deletion check.
	 *
	 * @param array $checkData Must contain keys: calendar_id, event_id
	 * @param string|null $tenantId Tenant identifier
	 * @return bool True if deletion detected and processed, false otherwise
	 */
	private function processOutlookDeletionCheck($checkData, ?string $tenantId = null): bool
	{
		$calendarId = $checkData['calendar_id'];
		$eventId = $checkData['event_id'];

		$this->logger->info('Processing deletion check', [
			'calendar_id' => $calendarId,
			'event_id' => $eventId
		]);

		// Try to fetch the event from Outlook to see if it still exists
	$outlookBridge = $tenantId ? $this->bridgeManager->getBridgeForTenant($tenantId, 'outlook') : $this->bridgeManager->getBridge('outlook');

		try
		{
			// Attempt to get the specific event
			$event = $this->getOutlookEvent($outlookBridge, $calendarId, $eventId);

			if ($event === null)
			{
				// Event doesn't exist in Outlook anymore - it was deleted
				$this->handleDeletedOutlookEvent($calendarId, $eventId, $tenantId);
				return true;
			}

			// Event still exists, no deletion detected
			return false;
		}
		catch (\Exception $e)
		{
			// If we get a 404 or similar error, the event was likely deleted
			if (
				strpos($e->getMessage(), '404') !== false ||
				strpos($e->getMessage(), 'not found') !== false
			)
			{

				$this->handleDeletedOutlookEvent($calendarId, $eventId, $tenantId);
				return true;
			}

			// Other errors should be re-thrown
			throw $e;
		}
	}

	/**
	 * Get a specific event from Outlook.
	 *
	 * @param mixed $outlookBridge Outlook bridge instance
	 * @param string $calendarId Outlook user or calendar ID
	 * @param string $eventId Outlook event ID
	 * @return array|null Event data if found, null if 404/not found
	 * @throws \Exception On non-404 errors from Graph
	 */
	private function getOutlookEvent($outlookBridge, $calendarId, $eventId)
	{
		$graphBaseUrl = 'https://graph.microsoft.com/v1.0';
		$url = "{$graphBaseUrl}/users/{$calendarId}/calendar/events/{$eventId}";

		try
		{
			// Use reflection to access the private makeGraphRequest method
			$reflection = new \ReflectionClass($outlookBridge);
			$method = $reflection->getMethod('makeGraphRequest');
			$method->setAccessible(true);

			return $method->invoke($outlookBridge, 'GET', $url);
		}
		catch (\Exception $e)
		{
			if (strpos($e->getMessage(), '404') !== false)
			{
				return null; // Event not found
			}
			throw $e;
		}
	}

	/**
	 * Handle a deleted Outlook event by syncing the deletion to the booking system and cleaning mappings.
	 *
	 * @param string $calendarId Outlook calendar ID
	 * @param string $eventId Outlook event ID
	 * @param string|null $tenantId Tenant identifier
	 * @return void
	 */
	private function handleDeletedOutlookEvent($calendarId, $eventId, ?string $tenantId = null)
	{
		$this->logger->info('Outlook event deleted, syncing to booking system', [
			'calendar_id' => $calendarId,
			'event_id' => $eventId
		]);

		// Find bridge mappings for this Outlook event
	$mappings = $this->findMappingsForOutlookEvent($calendarId, $eventId, $tenantId);

		foreach ($mappings as $mapping)
		{
			try
			{
				// Get the target bridge (booking system)
				$targetBridge = $tenantId ? $this->bridgeManager->getBridgeForTenant($tenantId, $mapping['target_bridge']) : $this->bridgeManager->getBridge($mapping['target_bridge']);

				// Delete the event in the booking system
				$success = $targetBridge->deleteEvent(
					$mapping['target_calendar_id'],
					$mapping['target_event_id']
				);

				if ($success)
				{
					// Remove the bridge mapping since both events are now deleted
					$this->deleteBridgeMapping($mapping['id'], $tenantId);

					// Log the successful deletion sync
					$this->logSyncOperation(
						'delete',
						'outlook',
						$mapping['target_bridge'],
						'success',
						['calendar_id' => $calendarId, 'event_id' => $eventId],
						$tenantId
					);

					$this->logger->info('Successfully synced deletion to booking system', [
						'outlook_calendar' => $calendarId,
						'outlook_event' => $eventId,
						'booking_resource' => $mapping['target_calendar_id'],
						'booking_event' => $mapping['target_event_id']
					]);
				}
				else
				{
					throw new \Exception("Failed to delete event in booking system");
				}
			}
			catch (\Exception $e)
			{
				$this->logger->error('Failed to sync deletion to booking system', [
					'outlook_calendar' => $calendarId,
					'outlook_event' => $eventId,
					'mapping_id' => $mapping['id'],
					'error' => $e->getMessage()
				]);

				// Log the failed deletion sync
				$this->logSyncOperation(
					'delete',
					'outlook',
					$mapping['target_bridge'],
					'error',
					['error' => $e->getMessage()],
					$tenantId
				);
			}
		}
	}

	/**
	 * Find bridge mappings for an Outlook event.
	 *
	 * @param string $calendarId Outlook calendar ID
	 * @param string $eventId Outlook event ID
	 * @param string|null $tenantId Tenant identifier
	 * @return array<int,array<string,mixed>> Matching mapping rows
	 */
	private function findMappingsForOutlookEvent($calendarId, $eventId, ?string $tenantId = null): array
	{
		$sql = "SELECT * FROM bridge_mappings 
				WHERE source_bridge = 'outlook' 
				AND source_calendar_id = :calendar_id 
				AND source_event_id = :event_id" . ($tenantId !== null ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "");

		$stmt = $this->db->prepare($sql);
		$params = [ ':calendar_id' => $calendarId, ':event_id' => $eventId ];
		if ($tenantId !== null) { $params[':tenant_id'] = (string)$tenantId; }
		$stmt->execute($params);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Delete a bridge mapping.
	 *
	 * @param int $mappingId Mapping primary key ID
	 * @param string|null $tenantId Tenant identifier
	 * @return void
	 */
	private function deleteBridgeMapping($mappingId, ?string $tenantId = null)
	{
		$sql = "DELETE FROM bridge_mappings WHERE id = :id" . ($tenantId !== null ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "");
		$stmt = $this->db->prepare($sql);
		$params = [':id' => $mappingId];
		if ($tenantId !== null) { $params[':tenant_id'] = (string)$tenantId; }
		$stmt->execute($params);
	}

	/**
	 * Log sync operation.
	 *
	 * @param string $operation Operation type (e.g., delete, sync)
	 * @param string $sourceBridge Source bridge name
	 * @param string $targetBridge Target bridge name
	 * @param string $status Status string (success|error|pending)
	 * @param array $details Arbitrary details to persist
	 * @param string|null $tenantId Tenant identifier
	 * @return void
	 */
	private function logSyncOperation($operation, $sourceBridge, $targetBridge, $status, $details = [], ?string $tenantId = null)
	{
		$sql = "INSERT INTO bridge_sync_logs 
				(source_bridge, target_bridge, operation, status, details, tenant_id) 
				VALUES (:source, :target, :operation, :status, :details, :tenant_id)";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':source' => $sourceBridge,
			':target' => $targetBridge,
			':operation' => $operation,
			':status' => $status,
			':details' => json_encode($details),
			':tenant_id' => $tenantId
		]);
	}

	/**
	 * Get pending deletion checks from queue.
	 *
	 * @param string|null $tenantId Tenant identifier to filter by
	 * @return array<int,array<string,mixed>> Queue rows
	 */
	private function getDeletionChecks(?string $tenantId = null): array
	{
		$sql = "SELECT * FROM bridge_queue 
				WHERE queue_type = 'deletion_check' 
				AND status = 'pending' " . ($tenantId !== null ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "") . "
				ORDER BY scheduled_at ASC 
				LIMIT 50";
		$stmt = $this->db->prepare($sql);
		$params = [];
		if ($tenantId !== null) { $params[':tenant_id'] = (string)$tenantId; }
		$stmt->execute($params);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Mark queue item as processed.
	 *
	 * @param int $queueId Queue row ID
	 * @return void
	 */
	private function markQueueItemProcessed($queueId)
	{
		$sql = "UPDATE bridge_queue 
                SET status = 'completed', processed_at = CURRENT_TIMESTAMP 
                WHERE id = :id";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => $queueId]);
	}

	/**
	 * Mark queue item as failed.
	 *
	 * @param int $queueId Queue row ID
	 * @param string $errorMessage Error message
	 * @return void
	 */
	private function markQueueItemFailed($queueId, $errorMessage)
	{
		$sql = "UPDATE bridge_queue 
                SET status = 'failed', error_message = :error, processed_at = CURRENT_TIMESTAMP 
                WHERE id = :id";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':id' => $queueId,
			':error' => $errorMessage
		]);
	}

	/**
	 * Manual deletion sync - check all recent mappings for deleted Outlook events.
	 *
	 * @param string|null $tenantId Tenant identifier
	 * @return array{checked:int,deleted:int,errors:array}
	 */
	public function syncDeletedEvents(?string $tenantId = null): array
	{
		$results = [
			'checked' => 0,
			'deleted' => 0,
			'errors' => []
		];

		// Get all recent Outlook to booking system mappings
	$sql = "SELECT DISTINCT source_calendar_id, source_event_id 
                FROM bridge_mappings 
                WHERE source_bridge = 'outlook' 
		AND last_synced_at > NOW() - INTERVAL '7 days'" . ($tenantId !== null ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "");
	$stmt = $this->db->prepare($sql);
	$params = [];
	if ($tenantId !== null) { $params[':tenant_id'] = (string)$tenantId; }
	$stmt->execute($params);
	$mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

		foreach ($mappings as $mapping)
		{
			try
			{
				$checkData = [
					'calendar_id' => $mapping['source_calendar_id'],
					'event_id' => $mapping['source_event_id']
				];

				if ($this->processOutlookDeletionCheck($checkData, $tenantId))
				{
					$results['deleted']++;
				}

				$results['checked']++;
			}
			catch (\Exception $e)
			{
				$results['errors'][] = [
					'calendar_id' => $mapping['source_calendar_id'],
					'event_id' => $mapping['source_event_id'],
					'error' => $e->getMessage()
				];
			}
		}

		return $results;
	}
}
