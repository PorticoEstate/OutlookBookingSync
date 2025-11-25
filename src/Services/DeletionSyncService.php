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

	 /** @var \App\Repository\BridgeQueueRepository Queue repository */
	 private $queueRepository;

	 /**
	  * Constructor.
	  *
	  * @param PDO $db Database connection
	  * @param LoggerInterface $logger Logger
	  * @param mixed $bridgeManager BridgeManager instance
	  * @param \App\Repository\BridgeQueueRepository $queueRepository Queue repository
	  */
	public function __construct(PDO $db, LoggerInterface $logger, $bridgeManager, $queueRepository)
	{
		$this->db = $db;
		$this->logger = $logger;
		$this->bridgeManager = $bridgeManager;
		$this->queueRepository = $queueRepository;
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
			$checks = $this->queueRepository->findPendingItems('deletion_check', 50, $tenantId);

			foreach ($checks as $check)
			{
				try
				{
                    // Mark as processing
                    $this->queueRepository->markProcessing($check['id']);

					$checkData = json_decode($check['payload'], true);
					$mappingTenantId = $check['tenant_id'];

					if ($this->processOutlookDeletionCheck($checkData, $mappingTenantId))
					{
						$results['deletions_found']++;
					}

					$results['processed']++;

					// Mark queue item as processed
					$this->queueRepository->markCompleted($check['id']);
				}
				catch (\Exception $e)
				{
					$results['errors'][] = [
						'check_id' => $check['id'],
						'error' => $e->getMessage()
					];

					$this->queueRepository->updateStatus($check['id'], 'failed', $e->getMessage());
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
		$outlookBridge = $this->bridgeManager->getBridgeForTenant($tenantId, 'outlook');

		try
		{
			// Attempt to get the specific event using the public bridge interface
			// This will throw an exception if the event is not found (404)
			$event = $outlookBridge->getEvent($calendarId, $eventId);
			
			// If we get here, the event exists
			return false;
		}
		catch (\Exception $e)
		{
			// Check if it's a "not found" error
			if (
				strpos($e->getMessage(), '404') !== false ||
				strpos($e->getMessage(), 'not found') !== false ||
				strpos($e->getMessage(), 'Event not found') !== false
			)
			{
				// Event doesn't exist in Outlook anymore - it was deleted
				$this->handleDeletedOutlookEvent($calendarId, $eventId, $tenantId);
				return true;
			}

			// Other errors should be re-thrown
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
				$targetBridge = $this->bridgeManager->getBridgeForTenant($tenantId, $mapping['target_bridge']);

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
	 * Manual deletion sync - check all recent mappings for deleted Outlook events.
	 *
	 * @param string|null $tenantId Tenant identifier
	 * @return array{checked:int,deleted:int,errors:array}
	 */
	public function syncDeletedEvents(?string $tenantId = null): array
	{
		$results = [
			'checked' => 0,
			'queued' => 0,
			'errors' => []
		];

		// Get all recent Outlook to booking system mappings
		$sql = "SELECT DISTINCT tenant_id, source_calendar_id, source_event_id 
					FROM bridge_mappings 
					WHERE source_bridge = 'outlook' 
			AND last_synced_at > NOW() - INTERVAL '7 days'" . ($tenantId !== null ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "");
		$stmt = $this->db->prepare($sql);
		$params = [];
		if ($tenantId !== null)
		{
			$params[':tenant_id'] = (string)$tenantId;
		}
		$stmt->execute($params);
		$mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

		foreach ($mappings as $mapping)
		{
			try
			{
				$mappingTenantId = $mapping['tenant_id'];
				$checkData = [
					'calendar_id' => $mapping['source_calendar_id'],
					'event_id' => $mapping['source_event_id']
				];

                $this->queueRepository->enqueue(
                    'deletion_check',
                    'outlook',
                    null,
                    $checkData,
                    5,
                    $mappingTenantId
                );

				$results['queued']++;
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
