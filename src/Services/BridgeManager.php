<?php

namespace App\Services;

use App\Bridge\AbstractCalendarBridge;
use App\Services\SyncLogService;
use Psr\Log\LoggerInterface;
use PDO;

/**
 * BridgeManager coordinates bridge registration, instantiation, and sync orchestration.
 */
class BridgeManager
{
	private $bridges = [];
	private $logger;
	private $db;
	private $syncLog;
	/** @var array<string, array<string, AbstractCalendarBridge>> */
	private $tenantBridgeCache = [];

	/**
	 * @param LoggerInterface $logger
	 * @param PDO $db
	 * @param SyncLogService $syncLog
	 */
	public function __construct(LoggerInterface $logger, PDO $db, SyncLogService $syncLog)
	{
		$this->logger = $logger;
		$this->db = $db;
		$this->syncLog = $syncLog;
	}

	/**
	 * Register a calendar bridge.
	 *
	 * @param string $name Bridge name
	 * @param string $bridgeClass FQCN extending AbstractCalendarBridge
	 * @param array $config Default configuration
	 */
	public function registerBridge($name, $bridgeClass, $config)
	{
		if (!is_subclass_of($bridgeClass, AbstractCalendarBridge::class))
		{
			throw new \InvalidArgumentException("Bridge class must extend AbstractCalendarBridge");
		}

		$this->bridges[$name] = [
			'class' => $bridgeClass,
			'config' => $config,
			'instance' => null
		];

		$this->logger->info('Bridge registered', [
			'bridge_name' => $name,
			'bridge_class' => $bridgeClass
		]);
	}

	/**
	 * Get a bridge instance.
	 *
	 * @param string $name Bridge name
	 * @return AbstractCalendarBridge
	 */
	public function getBridge($name): AbstractCalendarBridge
	{
		if (!isset($this->bridges[$name]))
		{
			throw new \Exception("Bridge '{$name}' not found");
		}

		if (!$this->bridges[$name]['instance'])
		{
			$class = $this->bridges[$name]['class'];
			$config = $this->bridges[$name]['config'];

			$this->bridges[$name]['instance'] = new $class($config, $this->logger, $this->db);
		}

		return $this->bridges[$name]['instance'];
	}

	/**
	 * Get a bridge instance configured for a specific tenant.
	 * Falls back to globally registered config when tenant-specific config is absent.
	 *
	 * @param string $tenantId Tenant identifier
	 * @param string $name Bridge name
	 * @return AbstractCalendarBridge
	 */
	public function getBridgeForTenant(string $tenantId, string $name): AbstractCalendarBridge
	{
		// Use cached per-tenant instance if available
		if (isset($this->tenantBridgeCache[$tenantId][$name]))
		{
			return $this->tenantBridgeCache[$tenantId][$name];
		}

		// Resolve base registration
		if (!isset($this->bridges[$name]))
		{
			throw new \Exception("Bridge '{$name}' not found");
		}

		$class = $this->bridges[$name]['class'];
		$baseConfig = $this->bridges[$name]['config'] ?? [];

		// Attempt to load tenant-specific override from DB bridge_configs
		$tenantConfig = $this->loadTenantBridgeConfig($tenantId, $name);
		$config = $tenantConfig ? array_replace_recursive($baseConfig, $tenantConfig) : $baseConfig;
		// Inject context tenant id without colliding with bridge-specific config keys
		$config['context_tenant_id'] = $tenantId;

		$instance = new $class($config, $this->logger, $this->db);
		$this->tenantBridgeCache[$tenantId][$name] = $instance;
		return $instance;
	}

	private function loadTenantBridgeConfig(string $tenantId, string $bridgeName): ?array
	{
		try
		{
			$stmt = $this->db->prepare("SELECT config_data FROM bridge_configs WHERE bridge_name = :name AND tenant_id = :tid LIMIT 1");
			$stmt->execute(['name' => $bridgeName, 'tid' => $tenantId]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if ($row && isset($row['config_data']))
			{
				$data = json_decode($row['config_data'], true);
				return is_array($data) ? $data : null;
			}
		}
		catch (\Throwable $e)
		{
			$this->logger->warning('Failed to load tenant bridge config', ['tenant_id' => $tenantId, 'bridge' => $bridgeName, 'error' => $e->getMessage()]);
		}
		return null;
	}

	/**
	 * Get bridge information.
	 *
	 * @param string $name
	 * @return array
	 */
	public function getBridgeInfo($name): array
	{
		if (!isset($this->bridges[$name]))
		{
			throw new \Exception("Bridge '{$name}' not found");
		}

		// Prefer tenant-aware config if a tenant id is available (header or DEFAULT_TENANT_ID)
		$tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? $_ENV['DEFAULT_TENANT_ID'] ?? null;
		if ($tenantId !== null)
		{
			$bridge = $this->getBridgeForTenant((string)$tenantId, $name);
		}
		else
		{
			$bridge = $this->getBridge($name);
		}

		return [
			'name' => $name,
			'type' => $bridge->getBridgeType(),
			'class' => $this->bridges[$name]['class'],
			'capabilities' => $bridge->getCapabilities(),
			'health' => $bridge->healthCheck()
		];
	}

	/**
	 * Get information about all bridges.
	 *
	 * @return array
	 */
	public function getAllBridgesInfo(): array
	{
		$info = [];

		foreach (array_keys($this->bridges) as $name)
		{
			try
			{
				$info[$name] = $this->getBridgeInfo($name);
			}
			catch (\Exception $e)
			{
				$info[$name] = [
					'name' => $name,
					'error' => $e->getMessage(),
					'status' => 'error'
				];
			}
		}

		return $info;
	}

	/**
	 * Get bridge information for a specific tenant.
	 *
	 * @param string $tenantId
	 * @param string $name
	 * @return array
	 */
	public function getBridgeInfoForTenant(string $tenantId, string $name): array
	{
		if (!isset($this->bridges[$name]))
		{
			throw new \Exception("Bridge '{$name}' not found");
		}

		$bridge = $this->getBridgeForTenant($tenantId, $name);

		return [
			'name' => $name,
			'type' => $bridge->getBridgeType(),
			'class' => $this->bridges[$name]['class'],
			'capabilities' => $bridge->getCapabilities(),
			'health' => $bridge->healthCheck()
		];
	}

	/**
	 * Get information about all bridges for a specific tenant.
	 *
	 * @param string $tenantId
	 * @return array
	 */
	public function getAllBridgesInfoForTenant(string $tenantId): array
	{
		$info = [];

		foreach (array_keys($this->bridges) as $name)
		{
			try
			{
				$info[$name] = $this->getBridgeInfoForTenant($tenantId, $name);
			}
			catch (\Exception $e)
			{
				$info[$name] = [
					'name' => $name,
					'error' => $e->getMessage(),
					'status' => 'error'
				];
			}
		}

		return $info;
	}

	/**
	 * Sync events between two bridges with ownership policy enforcement.
	 * 
	 * The sync operation is always unidirectional (source -> target) based on the API call.
	 * The ownership policy (sync_direction) determines which bridge can create/modify/delete events:
	 * - 'source_to_target': Only source bridge owns events (can create/modify target events)
	 * - 'target_to_source': Only target bridge owns events (can create/modify source events)  
	 * - 'bidirectional': Both bridges can own events (mutual create/modify permissions)
	 *
	 * @param string $sourceBridge The bridge initiating the sync (API caller)
	 * @param string $targetBridge The bridge receiving the sync  
	 * @param string $sourceCalendarId
	 * @param string $targetCalendarId
	 * @param string $startDate
	 * @param string $endDate
	 * @param array $options ['handle_deletions'=>bool,'skip_updates'=>bool,'dry_run'=>bool,'sync_method'=>string,'tenant_id'=>string|null,'sync_direction'=>string]
	 * @return array
	 */
	public function syncBetweenBridges(
		$sourceBridge,
		$targetBridge,
		$sourceCalendarId,
		$targetCalendarId,
		$startDate,
		$endDate,
		$options = []
	): array
	{
		// Get sync direction from options or determine from resource mapping
		
		$tenantId = $options['tenant_id'] ?? null;
		if ($tenantId !== null)
		{
			$source = $this->getBridgeForTenant((string)$tenantId, $sourceBridge);
			$target = $this->getBridgeForTenant((string)$tenantId, $targetBridge);
		}
		else
		{
			$source = $this->getBridge($sourceBridge);
			$target = $this->getBridge($targetBridge);
		}


		$this->logger->info('Starting bridge sync', [
			'source_bridge' => $sourceBridge,
			'target_bridge' => $targetBridge,
			'source_calendar' => $sourceCalendarId,
			'target_calendar' => $targetCalendarId,
			'date_range' => [$startDate, $endDate],
		]);

		return $this->performSync(
			$source, $target, $sourceBridge, $targetBridge,
			$sourceCalendarId, $targetCalendarId,
			$startDate, $endDate, $options
		);
	}

	/**
	 * Perform sync from source to target with ownership policy enforcement
	 */
	private function performSync($source, $target, $sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $startDate, $endDate, $options)
	{
		// Get events from source
		$sourceEvents = $source->getEvents($sourceCalendarId, $startDate, $endDate);

		// Get existing mappings (bounded by sync window) and build an index by source_event_id for O(1) lookups
		$mappings = $this->getBridgeMappings($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $startDate, $endDate);
		$mappingIndex = $this->indexMappingsBySourceId($mappings);

		$results = [
			'source_bridge' => $sourceBridge,
			'target_bridge' => $targetBridge,
			'source_events_found' => count($sourceEvents),
			'created' => 0,
			'updated' => 0,
			'deleted' => 0,
			'skipped' => 0,
			'errors' => [],
			'processed_events' => []
		];

		// Process each event individually with direction awareness
		$totalEvents = count($sourceEvents);
		$this->logger->info("Starting to process {$totalEvents} events", [
			'source_bridge' => $sourceBridge,
			'target_bridge' => $targetBridge
		]);

		for ($index = 0; $index < $totalEvents; $index++)
		{
			$sourceEvent = $sourceEvents[$index];

			$this->logger->info("Processing event {$index}/{$totalEvents}", [
				'event_id' => $sourceEvent['id'] ?? 'unknown',
				'event_subject' => $sourceEvent['subject'] ?? 'N/A',
			]);


			// Process this single event in complete isolation
			$eventProcessingResult = $this->processSingleEventSafely(
				$source,
				$target,
				$sourceEvent,
				$mappingIndex,
				$sourceCalendarId,
				$targetCalendarId,
				$options,
				$sourceBridge,
				$targetBridge,
				$index + 1,
				$totalEvents
			);

			// Add result to our collection
			if ($eventProcessingResult['success'])
			{
				$results[$eventProcessingResult['action']]++;
				$results['processed_events'][] = $eventProcessingResult;
			}
			else
			{
				$results['errors'][] = $eventProcessingResult['error'];
			}

			$this->logger->info("Completed event {$index}/{$totalEvents} - Status: " .
				($eventProcessingResult['success'] ? 'SUCCESS' : 'FAILED'));
		}

		// Handle deletions if requested (with direction awareness)
		if ($options['handle_deletions'] ?? false)
		{
			try
			{
				$deletionResults = $this->handleDeletedEvents($source, $target, $mappings, $sourceEvents, $targetCalendarId, $startDate, $endDate, $options);
				$results['deleted'] += $deletionResults['deleted'];
				$results['errors'] = array_merge($results['errors'], $deletionResults['errors']);
			}
			catch (\Exception $e)
			{
				$this->logger->error('Failed to handle deletions - continuing without deletion processing', [
					'error' => $e->getMessage(),
				]);
				$results['errors'][] = [
					'event_id' => 'deletion_process',
					'error' => 'Failed to handle deletions: ' . $e->getMessage(),
					'error_type' => get_class($e)
				];
			}
		}

		// Calculate success rate and add summary
		$totalProcessed = $results['created'] + $results['updated'] + $results['skipped'];
		$successRate = count($sourceEvents) > 0 ? ($totalProcessed / count($sourceEvents)) * 100 : 100;

		$results['summary'] = [
			'total_source_events' => count($sourceEvents),
			'successfully_processed' => $totalProcessed,
			'failed_events' => count($results['errors']),
			'success_rate_percent' => round($successRate, 2)
		];

		$this->logger->info('Bridge sync completed', array_merge($results['summary'], [
			'source_bridge' => $sourceBridge,
			'target_bridge' => $targetBridge,
			'details' => [
				'created' => $results['created'],
				'updated' => $results['updated'],
				'deleted' => $results['deleted'],
				'skipped' => $results['skipped'],
				'errors' => count($results['errors'])
			]
		]));

		// Persist sync summary to bridge_sync_logs for health metrics
		try
		{
			$processedCount = (int)(($results['created'] ?? 0) + ($results['updated'] ?? 0));
			$status = (count($results['errors'] ?? []) > 0) ? 'error' : 'success';
			$this->syncLog->write(
				($options['dry_run'] ?? false) ? 'dry_run' : 'sync',
				(string)$sourceBridge,
				(string)$targetBridge,
				$status,
				$processedCount,
				[
					'source_calendar_id' => $sourceCalendarId,
					'target_calendar_id' => $targetCalendarId,
					'date_range' => [$startDate, $endDate],
					'created' => $results['created'] ?? 0,
					'updated' => $results['updated'] ?? 0,
					'deleted' => $results['deleted'] ?? 0,
					'skipped' => $results['skipped'] ?? 0,
					'failed_events' => count($results['errors'] ?? [])
				],
				null,
				null,
				$options['tenant_id'] ?? null
			);
		}
		catch (\Throwable $e)
		{
			$this->logger->warning('Failed to write bridge_sync_logs summary', ['error' => $e->getMessage()]);
		}

		return $results;
	}

	/**
	 * Wrapper for single event processing with error handling
	 */
	private function processSingleEventSafely($source, $target, $sourceEvent, $mappingIndex, $sourceCalendarId, $targetCalendarId, $options, $sourceBridge, $targetBridge, $currentIndex, $totalEvents)
	{
		try
		{
			return $this->processSingleEvent($source, $target, $sourceEvent, $mappingIndex, $sourceCalendarId, $targetCalendarId, $options);
		}
		catch (\Throwable $e)
		{
			$this->logger->error("Critical error processing event {$currentIndex}/{$totalEvents}", [
				'event_id' => $sourceEvent['id'] ?? 'unknown',
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);

			return [
				'success' => false,
				'action' => 'error',
				'event_id' => $sourceEvent['id'] ?? 'unknown',
				'error' => [
					'event_id' => $sourceEvent['id'] ?? 'unknown',
					'error' => $e->getMessage(),
					'error_type' => get_class($e),
					'trace' => $e->getTraceAsString()
				]
			];
		}
	}

	/**
	 * Process a single event sync with sync_status tracking
	 */
	private function processSingleEvent($source, $target, $sourceEvent, $mappingIndex, $sourceCalendarId, $targetCalendarId, $options)
	{
		$mapping = $mappingIndex[$sourceEvent['id']] ?? null;

		// Add source bridge information to event data
		$sourceEvent['source_bridge'] = $source->getBridgeType();
		$sourceEvent['source_event_id'] = $sourceEvent['id'];
		$sourceEvent['source_calendar_id'] = $sourceCalendarId; // Correct: source calendar ID

		if ($mapping)
		{
			$options['mapping_config']['api_call_reversed'] = $mapping['normalized_reversed'];

			// Get sync direction and check permissions
			$syncDirection = $mapping['sync_direction'] ?? 'bidirectional';
			$isReversed = $mapping['normalized_reversed'] ?? false;

			// Get mapping configuration for ownership decisions
			$mappingConfig = $options['mapping_config'] ?? null;

			// Check if this sync direction is allowed by ownership model
			if (!$this->canSyncInDirection($syncDirection, $isReversed, $mappingConfig))
			{
				$ownershipReason = $this->getOwnershipExplanation($syncDirection, $isReversed, $mappingConfig);
				$this->logger->debug('Skipping sync due to ownership policy', [
					'source_event_id' => $sourceEvent['id'],
					'sync_direction' => $syncDirection,
					'is_reversed' => $isReversed,
					'ownership_reason' => $ownershipReason,
					'mapping_config' => $mappingConfig
				]);
				
				return [
					'success' => true,
					'action' => 'skipped',
					'source_event_id' => $sourceEvent['id'],
					'target_event_id' => $mapping['target_event_id'] ?? null,
					'reason' => 'ownership_policy_violation',
					'sync_direction' => $syncDirection,
					'is_reversed' => $isReversed
				];
			}

			// Enforce ownership policy based on sync_direction
			// - source_to_target: Source owns, must recreate if target deleted
			// - target_to_source: Target owns, respect target deletions
			// - bidirectional: Shared ownership, configurable behavior
			$shouldRecreateDeleted = false;
			$respectDel = (bool)($options['respect_target_deletions'] ?? false);
			
			if ($syncDirection === 'source_to_target' && !$isReversed)
			{
				// Source owns the event, should recreate if deleted on target side
				$shouldRecreateDeleted = true;
			}
			elseif ($syncDirection === 'target_to_source' && $isReversed)
			{
				// Target owns the event, should recreate if deleted on source side  
				$shouldRecreateDeleted = true;
			}
			elseif ($syncDirection === 'bidirectional')
			{
				// Bidirectional: use configuration or default to recreation for consistency
				$shouldRecreateDeleted = !$respectDel;
			}
			
			if ($shouldRecreateDeleted)
			{
				// Check if target was deleted; if so, recreate/overwrite unless respecting deletions
				$targetExists = true;
				try
				{
					$target->getEvent($targetCalendarId, $mapping['target_event_id']);
				}
				catch (\Throwable $e)
				{
					$targetExists = false;
				}

				if (!$targetExists)
				{
					if ($respectDel && $syncDirection === 'bidirectional')
					{
						// Only respect deletions in bidirectional mode if explicitly configured
						return [
							'success' => true,
							'action' => 'skipped',
							'source_event_id' => $sourceEvent['id'],
							'reason' => 'target_deleted_respected'
						];
					}
					
					// Owner recreates the event on the target side
					$this->logger->info('Recreating deleted target event due to ownership policy', [
						'source_event_id' => $sourceEvent['id'],
						'target_event_id' => $mapping['target_event_id'],
						'sync_direction' => $syncDirection,
						'is_reversed' => $isReversed,
						'ownership_reason' => $syncDirection === 'bidirectional' ? 'bidirectional_consistency' : 'owner_enforcement'
					]);
					
					$newId = $target->createEvent($targetCalendarId, $sourceEvent);
					$this->updateMappingTargetEventId($mapping['id'], $newId);
					$this->updateMappingTimestamp($mapping['id']);
					$this->updateMappingEventData($mapping['id'], $sourceEvent);
					return [
						'success' => true,
						'action' => 'recreated',
						'source_event_id' => $sourceEvent['id'],
						'target_event_id' => $newId,
						'reason' => 'ownership_enforcement'
					];
				}
			}
			// Handle cancelled events - check if target event still exists
			if (($mapping['sync_status'] ?? '') === 'cancelled')
			{
				return $this->handleCancelledEventReactivation($source, $target, $sourceEvent, $mapping, $sourceCalendarId, $targetCalendarId, $options);
			}

			// Update existing event
			if ($options['skip_updates'] ?? false)
			{
				return [
					'action' => 'skipped',
					'source_event_id' => $sourceEvent['id'],
					'reason' => 'updates_disabled'
				];
			}

			// Fastest no-op guard using stable hash if present
			if (!($options['force_update'] ?? false))
			{
				try
				{
					$newHash = $this->computeEventHash($sourceEvent);
					if (!empty($mapping['event_hash']) && is_string($mapping['event_hash']) && hash_equals($mapping['event_hash'], $newHash))
					{
						$this->updateMappingTimestamp($mapping['id']);
						return [
							'success' => true,
							'action' => 'skipped',
							'source_event_id' => $sourceEvent['id'],
							'target_event_id' => $mapping['target_event_id'],
							'reason' => 'no_changes_hash'
						];
					}
				}
				catch (\Throwable $e)
				{
					$this->logger->debug('Hash no-op guard failed; falling back', ['error' => $e->getMessage()]);
				}
			}

			// Fast no-op guard using cached last-synced payload from mapping.event_data
			if (!($options['force_update'] ?? false))
			{
				try
				{
					if (isset($mapping['event_data']) && !empty($mapping['event_data']))
					{
						$cached = is_array($mapping['event_data']) ? $mapping['event_data'] : json_decode((string)$mapping['event_data'], true);
						if (is_array($cached) && $this->eventsAreEquivalent($sourceEvent, $cached))
						{
							$this->updateMappingTimestamp($mapping['id']);
							return [
								'success' => true,
								'action' => 'skipped',
								'source_event_id' => $sourceEvent['id'],
								'target_event_id' => $mapping['target_event_id'],
								'reason' => 'no_changes_cached'
							];
						}
					}
				}
				catch (\Throwable $e)
				{
					$this->logger->debug('Cached no-op guard failed; will attempt live comparison or proceed with update', ['error' => $e->getMessage()]);
				}
			}

			// No-op guard: if there are no meaningful changes, skip the update
			if (!($options['force_update'] ?? false))
			{
				try
				{
					$targetCurrent = $target->getEvent($targetCalendarId, $mapping['target_event_id']);
					if ($this->eventsAreEquivalent($sourceEvent, $targetCurrent))
					{
						// Optionally bump timestamp to reflect check without write
						$this->updateMappingTimestamp($mapping['id']);
						return [
							'success' => true,
							'action' => 'skipped',
							'source_event_id' => $sourceEvent['id'],
							'target_event_id' => $mapping['target_event_id'],
							'reason' => 'no_changes'
						];
					}
				}
				catch (\Throwable $e)
				{
					$this->logger->debug('No-op guard: failed to fetch/compare target event; proceeding with update', [
						'target_event_id' => $mapping['target_event_id'],
						'error' => $e->getMessage()
					]);
				}
			}

			try
			{
				// Mark as pending before update
				$source->updateSyncStatus(
					$source->getBridgeType(),
					$target->getBridgeType(),
					$mapping['source_calendar_id'],
					$mapping['target_calendar_id'],
					$sourceEvent['id'],
					'pending'
				);

				$success = $target->updateEvent($targetCalendarId, $mapping['target_event_id'], $sourceEvent);

				if ($success)
				{
					$this->updateMappingTimestamp($mapping['id']);
					$this->updateMappingEventData($mapping['id'], $sourceEvent);

					// Store source event timing for safer deletion checks
					$this->updateMappingWithSourceTiming(
						$mapping['id'],
						$sourceEvent['start'] ?? null,
						$sourceEvent['end'] ?? null
					);

					// Record sync method for cron activity monitoring
					$this->updateMappingSyncMethod(
						$source->getBridgeType(),
						$target->getBridgeType(),
						$mapping['source_calendar_id'],
						$mapping['target_calendar_id'],
						$sourceEvent['id'],
						$options['sync_method'] ?? 'manual'
					);

					// Update handled by target bridge's updateEvent method
					return [
						'success' => true,
						'action' => 'updated',
						'source_event_id' => $sourceEvent['id'],
						'target_event_id' => $mapping['target_event_id']
					];
				}
				else
				{
					// Mark as error
					$source->updateSyncStatus(
						$source->getBridgeType(),
						$target->getBridgeType(),
						$mapping['source_calendar_id'],
						$mapping['target_calendar_id'],
						$sourceEvent['id'],
						'error',
						'Failed to update target event'
					);
					throw new \Exception('Failed to update target event');
				}
			}
			catch (\Exception $e)
			{
				// Mark as error
				$source->updateSyncStatus(
					$source->getBridgeType(),
					$target->getBridgeType(),
					$mapping['source_calendar_id'],
					$mapping['target_calendar_id'],
					$sourceEvent['id'],
					'error',
					$e->getMessage()
				);
				throw $e;
			}
		}
		else
		{
			// Create new event
			try
			{
				$targetEventId = $target->createEvent($targetCalendarId, $sourceEvent);

				// Find the newly created mapping and update it with source timing
				$newMappings = $this->getBridgeMappings($source->getBridgeType(), $target->getBridgeType(), $sourceCalendarId, $targetCalendarId, $options['startDate'] ?? null, $options['endDate'] ?? null);
				$newIndex = $this->indexMappingsBySourceId($newMappings);
				$newMapping = $newIndex[$sourceEvent['id']] ?? null;

				if ($newMapping)
				{
					$this->updateMappingWithSourceTiming(
						$newMapping['id'],
						$sourceEvent['start'] ?? null,
						$sourceEvent['end'] ?? null
					);

					// Cache last-synced payload and hash
					$this->updateMappingEventData($newMapping['id'], $sourceEvent);

					// Record sync method for newly created mapping
					$this->updateMappingSyncMethod(
						$source->getBridgeType(),
						$target->getBridgeType(),
						$sourceCalendarId,
						$targetCalendarId,
						$sourceEvent['id'],
						$options['sync_method'] ?? 'manual'
					);
				}

				// Create mapping handled by target bridge's createEvent method
				// No need to create mapping here as it's handled in the bridge

				return [
					'success' => true,
					'action' => 'created',
					'source_event_id' => $sourceEvent['id'],
					'target_event_id' => $targetEventId
				];
			}
			catch (\Exception $e)
			{
				// Log error for unmapped event creation failure
				$this->logger->error('Failed to create new event in target bridge', [
					'source_bridge' => $source->getBridgeType(),
					'target_bridge' => $target->getBridgeType(),
					'source_event_id' => $sourceEvent['id'],
					'error' => $e->getMessage()
				]);
				throw $e;
			}
		}
	}

	/**
	 * Handle events that were deleted from source with sync_status tracking
	 * Only considers events that originated within the specified timeframe
	 */
	private function handleDeletedEvents($source, $target, $mappings, $sourceEvents, $targetCalendarId, $startDate, $endDate, $options = [])
	{
		$sourceEventIds = array_column($sourceEvents, 'id');
		$sourceEventIdSet = array_fill_keys($sourceEventIds, true);
		$results = ['deleted' => 0, 'errors' => []];

		foreach ($mappings as $mapping)
		{
			// Skip if already marked as cancelled/deleted
			if (($mapping['sync_status'] ?? '') === 'cancelled' || ($mapping['sync_status'] ?? '') === 'deleted')
			{
				$this->logger->debug('Skipping already deleted/cancelled event', [
					'source_event_id' => $mapping['source_event_id'],
					'sync_status' => $mapping['sync_status']
				]);
				continue;
			}

			// Check sync direction permissions for deletion
			$syncDirection = $mapping['sync_direction'] ?? 'bidirectional';
			$mappingConfig = $options['mapping_config'] ?? null;
			if (!$this->canDeleteInDirection($syncDirection, $mapping['normalized_reversed'] ?? false, $mappingConfig))
			{
				$this->logger->debug('Deletion not allowed for this sync direction', [
					'source_event_id' => $mapping['source_event_id'],
					'sync_direction' => $syncDirection,
					'normalized_reversed' => $mapping['normalized_reversed'] ?? false,
					'mapping_config' => $mappingConfig
				]);
				continue;
			}

			// Only consider events that were created within the sync timeframe
			if (!$this->isEventWithinTimeframe($mapping, $startDate, $endDate))
			{
				$this->logger->debug('Skipping deletion check for event outside timeframe', [
					'source_event_id' => $mapping['source_event_id'],
					'event_created_at' => $mapping['created_at'] ?? 'unknown',
					'sync_window' => [$startDate, $endDate]
				]);
				continue;
			}

			if (!isset($sourceEventIdSet[$mapping['source_event_id']]))
			{
				try
				{
					// Mark as pending deletion first
					$this->updateMappingSyncStatus($mapping['id'], 'deleting');

					// Event was deleted from source, delete from target
					$target->deleteEvent($targetCalendarId, $mapping['target_event_id']);

					// Mark as cancelled/deleted in our mapping
					$this->updateMappingSyncStatus($mapping['id'], 'cancelled');

					// Record sync method for deletion tracking
					$this->updateMappingSyncMethod(
						$source->getBridgeType(),
						$target->getBridgeType(),
						$mapping['source_calendar_id'],
						$mapping['target_calendar_id'],
						$mapping['source_event_id'],
						$options['sync_method'] ?? 'automated'
					);

					$results['deleted']++;

					$this->logger->info('Deleted event from target due to source deletion', [
						'source_event_id' => $mapping['source_event_id'],
						'target_event_id' => $mapping['target_event_id'],
						'event_created_at' => $mapping['created_at'] ?? 'unknown'
					]);
				}
				catch (\Exception $e)
				{
					// Mark as error instead of cancelled
					$this->updateMappingSyncStatus($mapping['id'], 'error', 'Failed to delete from target: ' . $e->getMessage());

					$results['errors'][] = [
						'mapping_id' => $mapping['id'],
						'source_event_id' => $mapping['source_event_id'],
						'target_event_id' => $mapping['target_event_id'],
						'error' => $e->getMessage()
					];

					$this->logger->error('Failed to delete event from target', [
						'mapping' => $mapping,
						'error' => $e->getMessage()
					]);
				}
			}
		}

		return $results;
	}

	/**
	 * Find mapping for a source event
	 */
	private function findMapping($mappings, $sourceEventId)
	{
		foreach ($mappings as $mapping)
		{
			if ($mapping['source_event_id'] === $sourceEventId)
			{
				return $mapping;
			}
		}
		return null;
	}

	private function indexMappingsBySourceId(array $mappings): array
	{
		$idx = [];
		foreach ($mappings as $m)
		{
			if($m['normalized_reversed'] && isset($m['target_event_id']))
			{
				$idx[$m['target_event_id']] = $m;
			}
			else if (isset($m['source_event_id']))
			{
				$idx[$m['source_event_id']] = $m;
			}
		}
		return $idx;
	}

	/**
	 * Check if a mapping's event overlaps with the specified timeframe
	 * Uses proper overlap detection: event_start < window_end AND event_end > window_start
	 */
	private function isEventWithinTimeframe($mapping, $startDate, $endDate)
	{
		// Convert date strings to full datetime ranges for proper comparison
		$windowStart = strtotime($startDate . ' 00:00:00');
		$windowEnd = strtotime($endDate . ' 23:59:59');
		
		// Check if we have source event start/end times stored in the mapping
		if (!empty($mapping['source_event_start']) && !empty($mapping['source_event_end']))
		{
			$eventStart = strtotime($mapping['source_event_start']);
			$eventEnd = strtotime($mapping['source_event_end']);

			// Event overlaps window if: event_start < window_end AND event_end > window_start
			return $eventStart < $windowEnd && $eventEnd > $windowStart;
		}
		// Fallback: if only start time available, check if it's within the window
		elseif (!empty($mapping['source_event_start']))
		{
			$eventStart = strtotime($mapping['source_event_start']);

			// Event start falls within the sync window (old logic as fallback)
			return $eventStart >= $windowStart && $eventStart <= $windowEnd;
		}

		// Fallback: check mapping creation time if source event times not available
		if (!empty($mapping['created_at']))
		{
			$createdAt = strtotime($mapping['created_at']);
			// Use same window start but extend end by one day for creation time buffer
			$creationWindowEnd = strtotime($endDate . ' +1 day 23:59:59');

			return $createdAt >= $windowStart && $createdAt <= $creationWindowEnd;
		}

		// If we don't have timing information, be conservative and don't delete
		$this->logger->warning('No timing information available for mapping - skipping deletion', [
			'mapping_id' => $mapping['id'] ?? 'unknown',
			'source_event_id' => $mapping['source_event_id'] ?? 'unknown'
		]);

		return false;
	}

	/**
	 * Get bridge mappings from database
	 * Normalizes rows so that source_* always refers to the current $sourceBridge/$sourceCalendarId
	 * Optionally restricts rows to a date range using proper overlap detection:
	 * - windowStart/windowEnd are date strings (e.g., "2025-09-15") converted to full day ranges
	 * - Events are included if they overlap the window (event_start < window_end AND event_end > window_start)
	 * - Also includes events created during the window period
	 */
	private function getBridgeMappings($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, ?string $windowStart = null, ?string $windowEnd = null): array
	{
		// Build a UNION query to get mappings in either orientation for the pair,
		// then normalize so that source_* refers to the provided source/target.
		$tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? $_ENV['DEFAULT_TENANT_ID'] ?? null;

		$baseWhere = "(source_bridge = :source_bridge AND target_bridge = :target_bridge AND source_calendar_id = :source_calendar_id AND target_calendar_id = :target_calendar_id)";
		$reverseWhere = "(source_bridge = :target_bridge AND target_bridge = :source_bridge AND source_calendar_id = :target_calendar_id AND target_calendar_id = :source_calendar_id)";
		$tenantPredicate = $tenantId !== null ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "";
		
		// Time predicate for event overlap detection:
		// An event overlaps the window if: event_start < window_end AND event_end > window_start
		// Date strings are converted to full day ranges (00:00:00 to 23:59:59)
		// This catches events that start before, during, or after the window but have any time overlap
		$timePredicate = ($windowStart && $windowEnd)
			? " AND ((created_at BETWEEN :wstart AND :wend) OR (source_event_start < :wend AND source_event_end > :wstart))"
			: "";

		$sql = "SELECT * FROM bridge_mappings WHERE $baseWhere$tenantPredicate$timePredicate
				UNION ALL
				SELECT * FROM bridge_mappings WHERE $reverseWhere$tenantPredicate$timePredicate
				ORDER BY created_at DESC";

		$stmt = $this->db->prepare($sql);
		$params = [
			':source_bridge'      => $sourceBridge,
			':target_bridge'      => $targetBridge,
			':source_calendar_id' => $sourceCalendarId,
			':target_calendar_id' => $targetCalendarId,
		];
		if ($tenantId !== null)
		{
			$params[':tenant_id'] = (string)$tenantId;
		}
		if ($windowStart && $windowEnd)
		{
			// Convert date strings to full datetime ranges for proper comparison
			// windowStart becomes start of day (00:00:00), windowEnd becomes end of day (23:59:59)
			$params[':wstart'] = date('Y-m-d H:i:s', strtotime($windowStart . ' 00:00:00'));
			$params[':wend'] = date('Y-m-d H:i:s', strtotime($windowEnd . ' 23:59:59'));
		}
		$stmt->execute($params);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		foreach ($rows as &$row)
		{
			$isCurrentDirection =
				$row['source_bridge'] === $sourceBridge &&
				$row['target_bridge'] === $targetBridge &&
				$row['source_calendar_id'] === $sourceCalendarId &&
				$row['target_calendar_id'] === $targetCalendarId;

			if ($isCurrentDirection)
			{
				$row['normalized_reversed'] = false;
			}
			else
			{
				$row['normalized_reversed'] = true;
			}

		}

		return $rows;
	}

	/**
	 * Determine if two generic events are effectively equivalent (no meaningful changes).
	 * Compares key fields with normalization to avoid spurious updates.
	 */
	private function eventsAreEquivalent(array $a, array $b): bool
	{
		$fields = ['subject', 'location', 'description'];
		foreach ($fields as $f)
		{
			$av = isset($a[$f]) ? $this->normalizeString((string)$a[$f]) : '';
			$bv = isset($b[$f]) ? $this->normalizeString((string)$b[$f]) : '';
			if ($av !== $bv)
			{
				return false;
			}
		}

		// All-day flag
		$allDayA = (bool)($a['all_day'] ?? false);
		$allDayB = (bool)($b['all_day'] ?? false);
		if ($allDayA !== $allDayB)
		{
			return false;
		}

		// Start/End: compare as timestamps (UTC-equivalent)
		if ($this->normalizeDateToTimestamp($a['start'] ?? null) !== $this->normalizeDateToTimestamp($b['start'] ?? null))
		{
			return false;
		}
		if ($this->normalizeDateToTimestamp($a['end'] ?? null) !== $this->normalizeDateToTimestamp($b['end'] ?? null))
		{
			return false;
		}

		// Attendees (case-insensitive, order-insensitive)
		$attA = $this->normalizeAttendees($a['attendees'] ?? []);
		$attB = $this->normalizeAttendees($b['attendees'] ?? []);
		if ($attA !== $attB)
		{
			return false;
		}

		return true;
	}

	private function normalizeString(string $s): string
	{
		return trim(preg_replace('/\s+/', ' ', $s));
	}

	private function normalizeDateToTimestamp($val): ?int
	{
		if (empty($val))
		{
			return null;
		}
		try
		{
			$dt = new \DateTime((string)$val);
			return $dt->getTimestamp();
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}

	private function normalizeAttendees($val): array
	{
		if (!is_array($val))
		{
			return [];
		}
		$norm = array_map(function ($x)
		{
			return strtolower(trim((string)$x));
		}, $val);
		$norm = array_values(array_unique(array_filter($norm, function ($x)
		{
			return $x !== '';
		})));
		sort($norm);
		return $norm;
	}

	/**
	 * Compute a stable hash for a generic event using normalized fields.
	 */
	private function computeEventHash(array $event): string
	{
		$payload = [
			'subject'   => $this->normalizeString((string)($event['subject'] ?? '')),
			'location'  => $this->normalizeString((string)($event['location'] ?? '')),
			'description' => $this->normalizeString((string)($event['description'] ?? '')),
			'all_day'   => (bool)($event['all_day'] ?? false),
			'start_ts'  => $this->normalizeDateToTimestamp($event['start'] ?? null),
			'end_ts'    => $this->normalizeDateToTimestamp($event['end'] ?? null),
			'attendees' => $this->normalizeAttendees($event['attendees'] ?? []),
		];
		return hash('sha256', json_encode($payload));
	}

	/**
	 * Update cached last-synced payload on mapping
	 */
	private function updateMappingEventData($mappingId, array $event): void
	{
		try
		{
			$hash = $this->computeEventHash($event);
			$sql = "UPDATE bridge_mappings SET event_data = :event_data, event_hash = :event_hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([':id' => $mappingId, ':event_data' => json_encode($event), ':event_hash' => $hash]);
		}
		catch (\Throwable $e)
		{
			$this->logger->debug('Failed to update mapping event_data/hash cache - continuing', ['mapping_id' => $mappingId, 'error' => $e->getMessage()]);
		}
	}

	/**
	 * Update mapping timestamp
	 */
	private function updateMappingTimestamp($mappingId)
	{
		$sql = "UPDATE bridge_mappings SET last_synced_at = CURRENT_TIMESTAMP WHERE id = :id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => $mappingId]);
	}

	/**
	 * Update mapping sync status with optional error message
	 */
	private function updateMappingSyncStatus($mappingId, $status, $errorMessage = null): void
	{
		try
		{
			$sql = "UPDATE bridge_mappings 
					SET sync_status = :status, 
						error_message = :error_message,
						updated_at = CURRENT_TIMESTAMP 
					WHERE id = :id";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([
				':id' => $mappingId,
				':status' => $status,
				':error_message' => $errorMessage
			]);

			$this->logger->debug('Updated mapping sync status', [
				'mapping_id' => $mappingId,
				'status' => $status,
				'error_message' => $errorMessage
			]);
		}
		catch (\Exception $e)
		{
			$this->logger->warning('Failed to update mapping sync status', [
				'mapping_id' => $mappingId,
				'status' => $status,
				'error' => $e->getMessage()
			]);
		}
	}

	/**
	 * Check if deletion is allowed based on ownership model
	 * Uses mapping configuration, not API call direction, to determine ownership
	 * - source_to_target: Only source bridge can delete (source owns the events)
	 * - target_to_source: Only target bridge can delete (target owns the events)
	 * - bidirectional: Both bridges can delete
	 */
	private function canDeleteInDirection(string $syncDirection, bool $isReversed, array $mappingConfig = null): bool
	{
		// If we have mapping config, use the proper ownership logic
		if ($mappingConfig !== null) {
			return $this->canDeleteWithOwnership($mappingConfig);
		}
		
		// Fallback to old logic for backward compatibility
		switch ($syncDirection) {
			case 'source_to_target':
				// Source owns the event, only source can delete
				// If this is the reverse direction, source is actually the "target" in terms of method params
				return !$isReversed;
			
			case 'target_to_source':
				// Target owns the event, only target can delete
				// If this is the reverse direction, target is actually the "source" in terms of method params
				return $isReversed;
			
			case 'bidirectional':
				// Both sides can delete in bidirectional mode
				return true;
			
			default:
				// Unknown direction, be conservative
				return false;
		}
	}

	/**
	 * Check deletion permission using mapping configuration
	 */
	private function canDeleteWithOwnership(array $mappingConfig): bool
	{
		$syncDirection = $mappingConfig['sync_direction'];
		$bridgeFrom = $mappingConfig['bridge_from'];
		$bridgeTo = $mappingConfig['bridge_to'];
		$apiCallReversed = $mappingConfig['api_call_reversed'];
		
		// Determine who is currently syncing (based on API call direction)
		$currentSyncingBridge = $apiCallReversed ? $bridgeTo : $bridgeFrom;
		
		switch ($syncDirection) {
			case 'source_to_target':
				// Bridge_from owns events, only bridge_from can delete
				return $currentSyncingBridge === $bridgeFrom;
			
			case 'target_to_source':
				// Bridge_to owns events, only bridge_to can delete
				return $currentSyncingBridge === $bridgeTo;
			
			case 'bidirectional':
				// Both sides can delete in bidirectional mode
				return true;
			
			default:
				// Unknown direction, be conservative
				return false;
		}
	}

	/**
	 * Check if sync (create/update) is allowed based on ownership model
	 * Uses mapping configuration, not API call direction, to determine ownership
	 * - source_to_target: Only source bridge can modify target (source owns)
	 * - target_to_source: Only target bridge can modify source (target owns)
	 * - bidirectional: Both bridges can modify each other
	 */
	private function canSyncInDirection(string $syncDirection, bool $isReversed, array|null $mappingConfig): bool
	{
		// If we have mapping config, use the proper ownership logic
		if ($mappingConfig !== null) {
			return $this->canSyncWithOwnership($mappingConfig, $syncDirection);
		}
		
		// Fallback to old logic for backward compatibility
		switch ($syncDirection) {
			case 'source_to_target':
				// Source owns, can only sync TO target (not reversed)
				return !$isReversed;
			
			case 'target_to_source':
				// Target owns, can only sync TO source (reversed)
				return $isReversed;
			
			case 'bidirectional':
				// Both directions allowed in bidirectional mode
				return true;
			
			default:
				// Unknown direction, allow for backward compatibility
				return true;
		}
	}

	/**
	 * New ownership-based sync permission check using mapping configuration
	 */
	private function canSyncWithOwnership(array $mappingConfig, string $syncDirection): bool
	{
		$bridgeFrom = $mappingConfig['bridge_from'];
		$bridgeTo = $mappingConfig['bridge_to'];
		$apiCallReversed = $mappingConfig['api_call_reversed'];
		
		// Determine who is currently syncing (based on API call direction)
		$currentSyncingBridge = $apiCallReversed ? $bridgeTo : $bridgeFrom;
		
		switch ($syncDirection) {
			case 'source_to_target':
				// Bridge_from owns events, only bridge_from can sync
				return $currentSyncingBridge === $bridgeFrom;
			
			case 'target_to_source':
				// Bridge_to owns events, only bridge_to can sync
				return $currentSyncingBridge === $bridgeTo;
			
			case 'bidirectional':
				// Both bridges can sync
				return true;
			
			default:
				// Unknown direction, allow for backward compatibility
				return true;
		}
	}

	/**
	 * Check if the current sync operation is from the owner bridge
	 * Uses mapping configuration, not API call direction, to determine ownership
	 * - source_to_target: Source bridge owns the events
	 * - target_to_source: Target bridge owns the events  
	 * - bidirectional: Both bridges have ownership
	 */
	private function isOwnerSync(string $syncDirection, bool $isReversed, array $mappingConfig = null): bool
	{
		// If we have mapping config, use the proper ownership logic
		if ($mappingConfig !== null) {
			return $this->isOwnerWithMapping($mappingConfig);
		}
		
		// Fallback to old logic for backward compatibility
		switch ($syncDirection) {
			case 'source_to_target':
				// Source owns, so owner sync when not reversed (source -> target)
				return !$isReversed;
				
			case 'target_to_source':
				// Target owns, so owner sync when reversed (target -> source in method terms)
				return $isReversed;
				
			case 'bidirectional':
				// Both sides have ownership in bidirectional mode
				return true;
				
			default:
				// Unknown direction, assume no ownership
				return false;
		}
	}

	/**
	 * Check ownership using mapping configuration
	 */
	private function isOwnerWithMapping(array $mappingConfig): bool
	{
		$syncDirection = $mappingConfig['sync_direction'];
		$bridgeFrom = $mappingConfig['bridge_from'];
		$bridgeTo = $mappingConfig['bridge_to'];
		$apiCallReversed = $mappingConfig['api_call_reversed'];
		
		// Determine who is currently syncing (based on API call direction)
		$currentSyncingBridge = $apiCallReversed ? $bridgeTo : $bridgeFrom;
		
		switch ($syncDirection) {
			case 'source_to_target':
				// Bridge_from owns events
				return $currentSyncingBridge === $bridgeFrom;
				
			case 'target_to_source':
				// Bridge_to owns events
				return $currentSyncingBridge === $bridgeTo;
				
			case 'bidirectional':
				// Both sides have ownership in bidirectional mode
				return true;
				
			default:
				// Unknown direction, assume no ownership
				return false;
		}
	}

	/**
	 * Get human-readable explanation of ownership policy for logging
	 */
	private function getOwnershipExplanation(string $syncDirection, bool $isReversed, array $mappingConfig = null): string
	{
		// If we have mapping configuration, use it for more accurate ownership explanation
		if ($mappingConfig) {
			$bridgeFrom = $mappingConfig['bridge_from'] ?? 'unknown';
			$bridgeTo = $mappingConfig['bridge_to'] ?? 'unknown';
			$isApiReversed = $mappingConfig['api_call_reversed'] ?? false;
			
			switch ($syncDirection) {
				case 'source_to_target':
					return $isApiReversed 
						? "Bridge {$bridgeFrom} cannot modify {$bridgeTo}-owned events (source_to_target ownership)"
						: "Bridge {$bridgeFrom} can modify {$bridgeTo} in source_to_target ownership";
						
				case 'target_to_source':
					return $isApiReversed 
						? "Bridge {$bridgeFrom} can modify {$bridgeTo} in target_to_source ownership"
						: "Bridge {$bridgeFrom} cannot modify {$bridgeTo}-owned events (target_to_source ownership)";
						
				case 'bidirectional':
					return "Both {$bridgeFrom} and {$bridgeTo} can modify each other (bidirectional ownership)";
					
				default:
					return "Unknown ownership model for direction: {$syncDirection} between {$bridgeFrom} and {$bridgeTo}";
			}
		}
		
		// Fallback to original logic for backward compatibility
		switch ($syncDirection) {
			case 'source_to_target':
				return $isReversed 
					? 'Target cannot modify source-owned events (source_to_target ownership)'
					: 'Source can modify target in source_to_target ownership';
					
			case 'target_to_source':
				return $isReversed 
					? 'Target can modify source in target_to_source ownership'
					: 'Source cannot modify target-owned events (target_to_source ownership)';
					
			case 'bidirectional':
				return 'Both sides can modify each other (bidirectional ownership)';
				
			default:
				return 'Unknown ownership model for direction: ' . $syncDirection;
		}
	}

	/**
	 * Update mapping with source event timing information
	 * All datetime values are normalized to UTC for consistent cross-bridge comparison
	 */
	private function updateMappingWithSourceTiming($mappingId, $sourceStart, $sourceEnd)
	{
		// Only update if we have timing information
		if (empty($sourceStart))
		{
			return;
		}

		try
		{
			// Convert datetime strings to proper UTC format for TIMESTAMPTZ fields
			$startTimestamp = $this->normalizeTimestampForDatabase($sourceStart);
			$endTimestamp = $this->normalizeTimestampForDatabase($sourceEnd);

			$sql = "UPDATE bridge_mappings 
                    SET source_event_start = :start, 
                        source_event_end = :end,
                        updated_at = CURRENT_TIMESTAMP 
                    WHERE id = :id";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([
				':id' => $mappingId,
				':start' => $startTimestamp,
				':end' => $endTimestamp
			]);

			$this->logger->debug('Updated mapping with source event timing (UTC)', [
				'mapping_id' => $mappingId,
				'source_start_utc' => $startTimestamp,
				'source_end_utc' => $endTimestamp
			]);
		}
		catch (\Exception $e)
		{
			$this->logger->warning('Failed to update mapping with source timing - continuing', [
				'mapping_id' => $mappingId,
				'error' => $e->getMessage()
			]);
		}
	}

	/**
	 * Normalize datetime string for database TIMESTAMP storage
	 * Ensures all datetime values are stored in UTC for consistent cross-bridge comparison
	 */
	private function normalizeTimestampForDatabase($dateTimeString)
	{
		if (empty($dateTimeString))
		{
			return null;
		}

		try
		{
			// Handle various datetime formats and convert to database-compatible format
			$dateTime = new \DateTime($dateTimeString);

			// Always convert to UTC to ensure consistent storage and comparison across bridges
			$dateTime->setTimezone(new \DateTimeZone('UTC'));

			// Format for PostgreSQL TIMESTAMP (without timezone info since we're storing in UTC)
			return $dateTime->format('Y-m-d H:i:s');
		}
		catch (\Exception $e)
		{
			$this->logger->warning('Failed to normalize datetime for database', [
				'input' => $dateTimeString,
				'error' => $e->getMessage()
			]);
			return null;
		}
	}

	/**
	 * Update mapping with sync method information
	 */
	private function updateMappingSyncMethod($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $sourceEventId, $syncMethod)
	{
		try
		{
			$sql = "UPDATE bridge_mappings 
                    SET sync_method = :sync_method,
                        updated_at = CURRENT_TIMESTAMP 
                    WHERE source_bridge = :source_bridge
                    AND target_bridge = :target_bridge
                    AND source_calendar_id = :source_calendar_id
                    AND target_calendar_id = :target_calendar_id
                    AND source_event_id = :source_event_id";

			$stmt = $this->db->prepare($sql);
			$stmt->execute([
				':sync_method' => $syncMethod,
				':source_bridge' => $sourceBridge,
				':target_bridge' => $targetBridge,
				':source_calendar_id' => $sourceCalendarId,
				':target_calendar_id' => $targetCalendarId,
				':source_event_id' => $sourceEventId
			]);

			$this->logger->debug('Updated mapping with sync method', [
				'source_event_id' => $sourceEventId,
				'sync_method' => $syncMethod
			]);
		}
		catch (\Exception $e)
		{
			$this->logger->debug('Failed to update mapping sync method - continuing', [
				'source_event_id' => $sourceEventId,
				'sync_method' => $syncMethod,
				'error' => $e->getMessage()
			]);
		}
	}

	/**
	 * Handle reactivation of cancelled events
	 * Checks if target event still exists and either reactivates or recreates it
	 */
	private function handleCancelledEventReactivation($source, $target, $sourceEvent, $mapping, $sourceCalendarId, $targetCalendarId, $options)
	{
		$this->logger->info('Handling cancelled event reactivation', [
			'source_event_id' => $sourceEvent['id'],
			'target_event_id' => $mapping['target_event_id'],
			'mapping_id' => $mapping['id']
		]);

		// Check if target event still exists
		$targetEventExists = $this->checkTargetEventExists($target, $targetCalendarId, $mapping['target_event_id']);

		if ($targetEventExists)
		{
			// Target event exists - try to reactivate/update it
			try
			{
				$this->logger->info('Target event exists - attempting reactivation', [
					'target_event_id' => $mapping['target_event_id']
				]);

				// Mark as pending
				$source->updateSyncStatus(
					$source->getBridgeType(),
					$target->getBridgeType(),
					$mapping['source_calendar_id'],
					$mapping['target_calendar_id'],
					$sourceEvent['id'],
					'pending'
				);

				$success = $target->updateEvent($targetCalendarId, $mapping['target_event_id'], $sourceEvent);

				if ($success)
				{
					$this->updateMappingTimestamp($mapping['id']);
					$this->updateMappingWithSourceTiming($mapping['id'], $sourceEvent['start'] ?? null, $sourceEvent['end'] ?? null);
					$this->updateMappingEventData($mapping['id'], $sourceEvent);
					$this->updateMappingSyncMethod(
						$source->getBridgeType(),
						$target->getBridgeType(),
						$mapping['source_calendar_id'],
						$mapping['target_calendar_id'],
						$sourceEvent['id'],
						$options['sync_method'] ?? 'manual'
					);

					return [
						'success' => true,
						'action' => 'reactivated',
						'source_event_id' => $sourceEvent['id'],
						'target_event_id' => $mapping['target_event_id']
					];
				}
			}
			catch (\Exception $e)
			{
				$this->logger->warning('Failed to reactivate existing target event, will recreate', [
					'target_event_id' => $mapping['target_event_id'],
					'error' => $e->getMessage()
				]);
			}
		}

		// Target event doesn't exist or reactivation failed - create new event
		try
		{
			$this->logger->info('Creating new target event for cancelled mapping', [
				'source_event_id' => $sourceEvent['id'],
				'old_target_event_id' => $mapping['target_event_id']
			]);

			$newTargetEventId = $target->createEvent($targetCalendarId, $sourceEvent);

			// Update the mapping with new target event ID
			$this->updateMappingTargetEventId($mapping['id'], $newTargetEventId);
			$this->updateMappingTimestamp($mapping['id']);
			$this->updateMappingWithSourceTiming($mapping['id'], $sourceEvent['start'] ?? null, $sourceEvent['end'] ?? null);
			$this->updateMappingEventData($mapping['id'], $sourceEvent);
			$this->updateMappingSyncMethod(
				$source->getBridgeType(),
				$target->getBridgeType(),
				$mapping['source_calendar_id'],
				$mapping['target_calendar_id'],
				$sourceEvent['id'],
				$options['sync_method'] ?? 'manual'
			);

			return [
				'success' => true,
				'action' => 'recreated',
				'source_event_id' => $sourceEvent['id'],
				'target_event_id' => $newTargetEventId,
				'previous_target_event_id' => $mapping['target_event_id']
			];
		}
		catch (\Exception $e)
		{
			$this->logger->error('Failed to recreate cancelled event', [
				'source_event_id' => $sourceEvent['id'],
				'mapping_id' => $mapping['id'],
				'error' => $e->getMessage()
			]);

			// Mark as error
			$source->updateSyncStatus(
				$source->getBridgeType(),
				$target->getBridgeType(),
				$mapping['source_calendar_id'],
				$mapping['target_calendar_id'],
				$sourceEvent['id'],
				'error',
				'Failed to recreate cancelled event: ' . $e->getMessage()
			);

			throw $e;
		}
	}

	/**
	 * Check if target event exists
	 */
	private function checkTargetEventExists($target, $targetCalendarId, $targetEventId)
	{
		try
		{
			// Try to get the event - if it exists, this won't throw
			$event = $target->getEvent($targetCalendarId, $targetEventId);
			return $event !== null;
		}
		catch (\Exception $e)
		{
			// Event doesn't exist or can't be accessed
			$this->logger->debug('Target event does not exist or cannot be accessed', [
				'target_event_id' => $targetEventId,
				'error' => $e->getMessage()
			]);
			return false;
		}
	}

	/**
	 * Update mapping with new target event ID
	 */
	private function updateMappingTargetEventId($mappingId, $newTargetEventId)
	{
		try
		{
			$sql = "UPDATE bridge_mappings 
                    SET target_event_id = :target_event_id,
                        sync_status = 'synced',
                        updated_at = CURRENT_TIMESTAMP 
                    WHERE id = :id";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([
				':id' => $mappingId,
				':target_event_id' => $newTargetEventId
			]);

			$this->logger->debug('Updated mapping with new target event ID', [
				'mapping_id' => $mappingId,
				'new_target_event_id' => $newTargetEventId
			]);
		}
		catch (\Exception $e)
		{
			$this->logger->error('Failed to update mapping with new target event ID', [
				'mapping_id' => $mappingId,
				'new_target_event_id' => $newTargetEventId,
				'error' => $e->getMessage()
			]);
			throw $e;
		}
	}

	/**
	 * Process a single event sync directly (for webhook processing)
	 */
	public function processSingleEventSync($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $sourceEvent, $options = []): array
	{
		try {
			// Get bridge instances
			$tenantId = $options['tenant_id'] ?? null;
			$source = $tenantId 
				? $this->getBridgeForTenant($tenantId, $sourceBridge)
				: $this->getBridge($sourceBridge);
			$target = $tenantId
				? $this->getBridgeForTenant($tenantId, $targetBridge)
				: $this->getBridge($targetBridge);

		// Get any existing mappings for ownership tracking
		$eventDate = $sourceEvent['start'] ?? date('Y-m-d');
		if (strlen($eventDate) > 10) {
			$eventDate = substr($eventDate, 0, 10); // Extract YYYY-MM-DD
		}
		
		$rawMappings = $this->getBridgeMappings($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $eventDate, $eventDate);
		$mappings = $this->indexMappingsBySourceId($rawMappings);			// Set sync method to webhook if not specified
			$options['sync_method'] = $options['sync_method'] ?? 'webhook';

			// Process the single event directly
			$eventResult = $this->processSingleEventSafely(
				$source,
				$target,
				$sourceEvent,
				$mappings,
				$sourceCalendarId,
				$targetCalendarId,
				$options,
				$sourceBridge,
				$targetBridge,
				1, // currentIndex
				1  // totalEvents
			);

			if ($eventResult['success']) {
				return [
					'success' => true,
					'action' => $eventResult['action'],
					'source_event_id' => $eventResult['source_event_id'],
					'target_event_id' => $eventResult['target_event_id'] ?? null,
					'created' => $eventResult['action'] === 'created' ? 1 : 0,
					'updated' => $eventResult['action'] === 'updated' ? 1 : 0,
					'skipped' => $eventResult['action'] === 'skipped' ? 1 : 0,
					'errors' => 0,
					'summary' => [
						'total_source_events' => 1,
						'successfully_processed' => 1,
						'failed_events' => 0,
						'success_rate_percent' => 100
					]
				];
			} else {
				return [
					'success' => false,
					'error' => $eventResult['error'] ?? 'Unknown error',
					'created' => 0,
					'updated' => 0,
					'skipped' => 0,
					'errors' => 1,
					'summary' => [
						'total_source_events' => 1,
						'successfully_processed' => 0,
						'failed_events' => 1,
						'success_rate_percent' => 0
					]
				];
			}

		} catch (\Exception $e) {
			$this->logger->error('Single event sync failed', [
				'source_bridge' => $sourceBridge,
				'target_bridge' => $targetBridge,
				'source_event_id' => $sourceEvent['id'] ?? 'unknown',
				'error' => $e->getMessage()
			]);

			return [
				'success' => false,
				'error' => $e->getMessage(),
				'created' => 0,
				'updated' => 0,
				'skipped' => 0,
				'errors' => 1,
				'summary' => [
					'total_source_events' => 1,
					'successfully_processed' => 0,
					'failed_events' => 1,
					'success_rate_percent' => 0
				]
			];
		}
	}

	/**
	 * Process pending syncs across all bridges
	 */
	public function processPendingSyncs($bridgeName = null, $batchSize = 50): array
	{
		$results = [];
		$tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? $_ENV['DEFAULT_TENANT_ID'] ?? null;
		// Prefer tenant-aware instances if a tenant id is available (header or DEFAULT_TENANT_ID)
		if ($bridgeName)
		{
			// Process pending syncs for specific bridge
			$bridge = $tenantId !== null
				? $this->getBridgeForTenant((string)$tenantId, $bridgeName)
				: $this->getBridge($bridgeName);

			if (method_exists($bridge, 'processPendingSyncs'))
			{
				$results[$bridgeName] = call_user_func([$bridge, 'processPendingSyncs'], $batchSize);
			}
		}
		else
		{
			// Process pending syncs for all bridges
			foreach (array_keys($this->bridges) as $name)
			{
				try
				{
					$bridge = $tenantId !== null
						? $this->getBridgeForTenant((string)$tenantId, $name)
						: $this->getBridge($name);

					if (method_exists($bridge, 'processPendingSyncs'))
					{
						$results[$name] = call_user_func([$bridge, 'processPendingSyncs'], $batchSize);
					}
				}
				catch (\Exception $e)
				{
					$results[$name] = [
						'processed' => 0,
						'errors' => 1,
						'error_details' => [['error' => $e->getMessage()]]
					];

					$this->logger->error('Failed to process pending syncs for bridge', [
						'bridge' => $name,
						'error' => $e->getMessage()
					]);
				}
			}
		}

		return $results;
	}

	/**
	 * Re-enable failed events across bridges
	 */
	public function reEnableFailedEvents($bridgeName = null, $eventIds = []): array
	{
		$results = [];

		if ($bridgeName)
		{
			// Re-enable for specific bridge
			$bridge = $this->getBridge($bridgeName);
			if (method_exists($bridge, 'reEnableFailedEvents'))
			{
				$results[$bridgeName] = $bridge->reEnableFailedEvents($eventIds);
			}
		}
		else
		{
			// Re-enable for all bridges
			foreach (array_keys($this->bridges) as $name)
			{
				try
				{
					$bridge = $this->getBridge($name);
					if (method_exists($bridge, 'reEnableFailedEvents'))
					{
						$results[$name] = $bridge->reEnableFailedEvents($eventIds);
					}
				}
				catch (\Exception $e)
				{
					$results[$name] = 0;

					$this->logger->error('Failed to re-enable failed events for bridge', [
						'bridge' => $name,
						'error' => $e->getMessage()
					]);
				}
			}
		}

		return $results;
	}

	/**
	 * Get sync statistics for all bridges
	 */
	public function getAllSyncStats(): array
	{
		$allStats = [];
		// Prefer tenant-aware instances if a tenant id is available (header or DEFAULT_TENANT_ID)
		$tenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? $_ENV['DEFAULT_TENANT_ID'] ?? null;

		foreach (array_keys($this->bridges) as $name)
		{
			try
			{
				$bridge = $tenantId !== null
					? $this->getBridgeForTenant((string)$tenantId, $name)
					: $this->getBridge($name);
				if (method_exists($bridge, 'getSyncStats'))
				{
					$allStats[$name] = $bridge->getSyncStats();
				}
				else
				{
					// Fallback to basic stats
					$allStats[$name] = [
						'bridge_name' => $name,
						'bridge_type' => $bridge->getBridgeType(),
						'sync_stats_available' => false
					];
				}
			}
			catch (\Exception $e)
			{
				$allStats[$name] = [
					'bridge_name' => $name,
					'error' => $e->getMessage()
				];

				$this->logger->error('Failed to get sync stats for bridge', [
					'bridge' => $name,
					'error' => $e->getMessage()
				]);
			}
		}

		return $allStats;
	}

	/**
	 * Get cancelled events for cleanup across all bridges
	 */
	public function getAllCancelledEvents(): array
	{
		$allCancelled = [];

		foreach (array_keys($this->bridges) as $name)
		{
			try
			{
				$bridge = $this->getBridge($name);
				if (method_exists($bridge, 'getCancelledEvents'))
				{
					$cancelled = $bridge->getCancelledEvents($name, null); // Get for this bridge
					if (!empty($cancelled))
					{
						$allCancelled[$name] = $cancelled;
					}
				}
			}
			catch (\Exception $e)
			{
				$this->logger->error('Failed to get cancelled events for bridge', [
					'bridge' => $name,
					'error' => $e->getMessage()
				]);
			}
		}

		return $allCancelled;
	}

}