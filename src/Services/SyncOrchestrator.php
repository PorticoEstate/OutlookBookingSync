<?php

namespace App\Services;

use App\Repository\BridgeMappingRepository;
use Psr\Log\LoggerInterface;

class SyncOrchestrator
{
    private $bridgeManager;
    private $mappingRepository;
    private $syncLog;
    private $logger;

    public function __construct(
        BridgeManager $bridgeManager,
        BridgeMappingRepository $mappingRepository,
        SyncLogService $syncLog,
        LoggerInterface $logger
    ) {
        $this->bridgeManager = $bridgeManager;
        $this->mappingRepository = $mappingRepository;
        $this->syncLog = $syncLog;
        $this->logger = $logger;
    }

    /**
     * Core sync logic between two bridges
     * 
     * @param string $sourceBridge Source bridge name
     * @param string $targetBridge Target bridge name
     * @param string $sourceCalendarId Source calendar ID
     * @param string $targetCalendarId Target calendar ID
     * @param string $startDate Start date (Y-m-d)
     * @param string $endDate End date (Y-m-d)
     * @param array $options Additional options (dry_run, force_update, etc.)
     */
    public function syncBetweenBridges(
        string $sourceBridge,
        string $targetBridge,
        string $sourceCalendarId,
        string $targetCalendarId,
        string $startDate,
        string $endDate,
        array $options = []
    ): array {
        $tenantId = $options['tenant_id'] ?? null;
        $source = $this->bridgeManager->getBridgeForTenant($tenantId, $sourceBridge);
        $target = $this->bridgeManager->getBridgeForTenant($tenantId, $targetBridge);

        // Get events from source
        $sourceEvents = $source->getEvents($sourceCalendarId, $startDate, $endDate);

        // Get existing mappings (bounded by sync window) and build an index by source_event_id for O(1) lookups
        $mappings = $this->getBridgeMappings($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $startDate, $endDate, $options);
        $mappingIndex = $this->indexMappingsBySourceId($mappings);

        $results = [
            'source_bridge' => $sourceBridge,
            'target_bridge' => $targetBridge,
            'source_events_found' => count($sourceEvents),
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'recreated' => 0,
            'errors' => [],
            'processed_events' => []
        ];

        // Process each event individually with direction awareness
        $totalEvents = count($sourceEvents);
        $this->logger->info("Starting to process {$totalEvents} events", [
            'source_bridge' => $sourceBridge,
            'target_bridge' => $targetBridge
        ]);

        for ($index = 0; $index < $totalEvents; $index++) {
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
            if ($eventProcessingResult['success']) {
                $results[$eventProcessingResult['action']]++;
                $results['processed_events'][] = $eventProcessingResult;
            } else {
                $results['errors'][] = $eventProcessingResult['error'];
            }

            $this->logger->info("Completed event {$index}/{$totalEvents} - Status: " .
                ($eventProcessingResult['success'] ? 'SUCCESS' : 'FAILED'));
        }

        // Handle deletions if requested (with direction awareness)
        if ($options['handle_deletions'] ?? false) {
            try {
                $deletionResults = $this->handleDeletedEvents($source, $target, $mappings, $sourceEvents, $targetCalendarId, $startDate, $endDate, $options);
                $results['deleted'] += $deletionResults['deleted'];
                $results['errors'] = array_merge($results['errors'], $deletionResults['errors']);
            } catch (\Exception $e) {
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
        try {
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
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to write bridge_sync_logs summary', ['error' => $e->getMessage()]);
        }

        return $results;
    }

    /**
     * Process a single event sync (e.g. from webhook)
     * 
     * @param string $sourceBridgeName Source bridge name
     * @param string $targetBridgeName Target bridge name
     * @param string $sourceCalendarId Source calendar ID
     * @param string $targetCalendarId Target calendar ID
     * @param array $sourceEvent Source event data
     * @param array $options Additional options
     * @return array Sync result
     */
    public function processSingleEventSync(
        string $sourceBridgeName,
        string $targetBridgeName,
        string $sourceCalendarId,
        string $targetCalendarId,
        array $sourceEvent,
        array $options = []
    ): array {
        $tenantId = $options['tenant_id'] ?? null;
        $source = $this->bridgeManager->getBridgeForTenant($tenantId, $sourceBridgeName);
        $target = $this->bridgeManager->getBridgeForTenant($tenantId, $targetBridgeName);
        
        // Find existing mapping
        $mapping = $this->mappingRepository->findMappingBySourceEventId(
            $sourceBridgeName,
            $targetBridgeName,
            $sourceCalendarId,
            $targetCalendarId,
            $sourceEvent['id'],
            $tenantId
        );
        
        $mappingIndex = [];
        if ($mapping) {
            $mappingIndex[$sourceEvent['id']] = $mapping;
        }
        
        // Process the event
        return $this->processSingleEventSafely(
            $source,
            $target,
            $sourceEvent,
            $mappingIndex,
            $sourceCalendarId,
            $targetCalendarId,
            $options,
            $sourceBridgeName,
            $targetBridgeName,
            1,
            1
        );
    }

    /**
     * Wrapper for single event processing with error handling
     */
    private function processSingleEventSafely($source, $target, $sourceEvent, $mappingIndex, $sourceCalendarId, $targetCalendarId, $options, $sourceBridge, $targetBridge, $currentIndex, $totalEvents)
    {
        try {
            return $this->processSingleEvent($source, $target, $sourceEvent, $mappingIndex, $sourceCalendarId, $targetCalendarId, $options);
        } catch (\Throwable $e) {
            $this->logger->error("Critical error processing event {$currentIndex}/{$totalEvents}", [
                'event_id' => $sourceEvent['id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'action' => 'error',
                'event_id' => $sourceEvent['id'] ?? 'unknown',
                'error' => [
                    'event_id' => $sourceEvent['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                    'error_type' => get_class($e)
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
        $sourceEvent['source_calendar_id'] = $sourceCalendarId;

        if ($mapping) {
            $options['mapping_config']['api_call_reversed'] = $mapping['normalized_reversed'];

            // Get sync direction and check permissions
            $syncDirection = $mapping['sync_direction'] ?? 'bidirectional';
            $isReversed = $mapping['normalized_reversed'] ?? false;

            // Get mapping configuration for ownership decisions
            $mappingConfig = $options['mapping_config'] ?? null;

            // Check if this sync direction is allowed by ownership model
            if (!$this->canSyncInDirection($syncDirection, $isReversed, $mappingConfig)) {

                $this->updateMappingSyncStatus($mapping['id'], 'cancelled');

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
                    'target_event_id' => $isReversed ? $mapping['source_event_id'] : $mapping['target_event_id'],
                    'reason' => 'ownership_policy_violation',
                    'sync_direction' => $syncDirection,
                    'is_reversed' => $isReversed
                ];
            }

            // Enforce ownership policy based on sync_direction
            $shouldRecreateDeleted = false;
            $respectDel = (bool)($options['respect_target_deletions'] ?? false);
            
            if ($syncDirection === 'source_to_target' && !$isReversed) {
                $shouldRecreateDeleted = true;
            } elseif ($syncDirection === 'target_to_source' && $isReversed) {
                $shouldRecreateDeleted = true;
            } elseif ($syncDirection === 'bidirectional') {
                $shouldRecreateDeleted = !$respectDel;
            }

            // Fetch target event once for both deletion check and comparison
            $target_bridge = $target->getBridgeType();
            $targetCurrent = null;
            $targetExists = true;
            try {
                $targetCurrent = $target->getEvent($targetCalendarId, $mapping['target_event_id']);
            } catch (\Throwable $e) {
                $targetExists = false;
            }

            if ($shouldRecreateDeleted && !$targetExists) {
                if ($respectDel && $syncDirection === 'bidirectional') {
                    return [
                        'success' => true,
                        'action' => 'skipped',
                        'source_event_id' => $sourceEvent['id'],
                        'reason' => 'target_deleted_respected'
                    ];
                }
                
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
            
            // Handle cancelled events
            if (($mapping['sync_status'] ?? '') === 'cancelled') {
                return $this->handleCancelledEventReactivation($source, $target, $sourceEvent, $mapping, $sourceCalendarId, $targetCalendarId, $options);
            }

            // Update existing event
            if ($options['skip_updates'] ?? false) {
                return [
                    'action' => 'skipped',
                    'source_event_id' => $sourceEvent['id'],
                    'reason' => 'updates_disabled'
                ];
            }

            // Fastest no-op guard using stable hash
            if (!($options['force_update'] ?? false)) {
                try {
                    $newHash = $this->computeEventHash($sourceEvent);
                    if (!empty($mapping['event_hash']) && is_string($mapping['event_hash']) && hash_equals($mapping['event_hash'], $newHash)) {
                        $this->updateMappingTimestamp($mapping['id']);
                        return [
                            'success' => true,
                            'action' => 'skipped',
                            'source_event_id' => $sourceEvent['id'],
                            'target_event_id' => $mapping['target_event_id'],
                            'reason' => 'no_changes_hash'
                        ];
                    }
                } catch (\Throwable $e) {
                    $this->logger->debug('Hash no-op guard failed; falling back', ['error' => $e->getMessage()]);
                }
            }

            // No-op guard: if there are no meaningful changes, skip the update
            if (!($options['force_update'] ?? false) && $targetCurrent !== null) {
                try {
                    if ($this->eventsAreEquivalent($sourceEvent, $targetCurrent)) {
                        $this->updateMappingTimestamp($mapping['id']);
                        // Update hash to ensure fast-path works next time
                        $this->updateMappingEventData($mapping['id'], $sourceEvent);
                        return [
                            'success' => true,
                            'action' => 'skipped',
                            'source_event_id' => $sourceEvent['id'],
                            'target_event_id' => $mapping['target_event_id'],
                            'reason' => 'no_changes'
                        ];
                    }
                } catch (\Throwable $e) {
                    $this->logger->debug('No-op guard: failed to compare target event; proceeding with update', [
                        'target_event_id' => $mapping['target_event_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            try {
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

                if ($success) {
                    $this->updateMappingTimestamp($mapping['id']);
                    $this->updateMappingEventData($mapping['id'], $sourceEvent);

                    $this->updateMappingWithSourceTiming(
                        $mapping['id'],
                        $sourceEvent['start'] ?? null,
                        $sourceEvent['end'] ?? null
                    );

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
                        'action' => 'updated',
                        'source_event_id' => $sourceEvent['id'],
                        'target_event_id' => $mapping['target_event_id']
                    ];
                } else {
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
            } catch (\Exception $e) {
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
        } else {
            // Create new event
            try {
                $targetEventId = $target->createEvent($targetCalendarId, $sourceEvent);

                // Find the newly created mapping and update it with source timing
                $newMappings = $this->getBridgeMappings($source->getBridgeType(), $target->getBridgeType(), $sourceCalendarId, $targetCalendarId, $options['startDate'] ?? null, $options['endDate'] ?? null, $options);
                $newIndex = $this->indexMappingsBySourceId($newMappings);
                $newMapping = $newIndex[$sourceEvent['id']] ?? null;

                if ($newMapping) {
                    $this->updateMappingWithSourceTiming(
                        $newMapping['id'],
                        $sourceEvent['start'] ?? null,
                        $sourceEvent['end'] ?? null
                    );

                    $this->updateMappingEventData($newMapping['id'], $sourceEvent);

                    $this->updateMappingSyncMethod(
                        $source->getBridgeType(),
                        $target->getBridgeType(),
                        $sourceCalendarId,
                        $targetCalendarId,
                        $sourceEvent['id'],
                        $options['sync_method'] ?? 'manual'
                    );
                }

                return [
                    'success' => true,
                    'action' => 'created',
                    'source_event_id' => $sourceEvent['id'],
                    'target_event_id' => $targetEventId
                ];
            } catch (\Exception $e) {
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

    private function handleDeletedEvents($source, $target, $mappings, $sourceEvents, $targetCalendarId, $startDate, $endDate, $options = [])
    {
        $sourceEventIds = array_column($sourceEvents, 'id');
        $sourceEventIdSet = array_fill_keys($sourceEventIds, true);
        $results = ['deleted' => 0, 'errors' => []];

        foreach ($mappings as $mapping) {
            if (($mapping['sync_status'] ?? '') === 'cancelled' || ($mapping['sync_status'] ?? '') === 'deleted') {
                continue;
            }

            $syncDirection = $mapping['sync_direction'] ?? 'bidirectional';
            $mappingConfig = $options['mapping_config'] ?? null;
            $mappingConfig['api_call_reversed'] = $mapping['normalized_reversed'];
            $mappingConfig['sync_direction'] = $syncDirection;

            if (!$this->canDeleteInDirection($syncDirection, $mapping['normalized_reversed'] ?? false, $mappingConfig)) {
                continue;
            }

            if (!$this->isEventWithinTimeframe($mapping, $startDate, $endDate)) {
                continue;
            }

            if (!isset($sourceEventIdSet[$mapping['source_event_id']])) {
                try {
                    $this->updateMappingSyncStatus($mapping['id'], 'deleting');
                    $target->deleteEvent($targetCalendarId, $mapping['target_event_id']);
                    $this->updateMappingSyncStatus($mapping['id'], 'cancelled');

                    $this->updateMappingSyncMethod(
                        $source->getBridgeType(),
                        $target->getBridgeType(),
                        $mapping['source_calendar_id'],
                        $mapping['target_calendar_id'],
                        $mapping['source_event_id'],
                        $options['sync_method'] ?? 'automated'
                    );

                    $results['deleted']++;

                    $this->logger->info('Deleted event from target due to source deletion (cron-triggered)', [
                        'source_event_id' => $mapping['source_event_id'],
                        'target_event_id' => $mapping['target_event_id']
                    ]);
                } catch (\Exception $e) {
                    $this->updateMappingSyncStatus($mapping['id'], 'error', 'Failed to delete from target: ' . $e->getMessage());
                    $results['errors'][] = [
                        'mapping_id' => $mapping['id'],
                        'error' => $e->getMessage()
                    ];
                }
            }
        }

        return $results;
    }

    private function indexMappingsBySourceId(array $mappings): array
    {
        $idx = [];
        foreach ($mappings as $m) {
            if ($m['normalized_reversed'] && isset($m['target_event_id'])) {
                $idx[$m['target_event_id']] = $m;
            } else if (isset($m['source_event_id'])) {
                $idx[$m['source_event_id']] = $m;
            }
        }
        return $idx;
    }

    private function isEventWithinTimeframe($mapping, $startDate, $endDate)
    {
        $windowStart = strtotime($startDate . ' 00:00:00');
        $windowEnd = strtotime($endDate . ' 23:59:59');
        
        if (!empty($mapping['source_event_start']) && !empty($mapping['source_event_end'])) {
            $eventStart = strtotime($mapping['source_event_start']);
            $eventEnd = strtotime($mapping['source_event_end']);
            return $eventStart < $windowEnd && $eventEnd > $windowStart;
        } elseif (!empty($mapping['source_event_start'])) {
            $eventStart = strtotime($mapping['source_event_start']);
            return $eventStart >= $windowStart && $eventStart <= $windowEnd;
        }

        if (!empty($mapping['created_at'])) {
            $createdAt = strtotime($mapping['created_at']);
            $creationWindowEnd = strtotime($endDate . ' +1 day 23:59:59');
            return $createdAt >= $windowStart && $createdAt <= $creationWindowEnd;
        }

        return false;
    }

    private function canSyncInDirection(string $syncDirection, bool $isReversed, array|null $mappingConfig): bool
    {
        if ($mappingConfig) {
            $bridgeFrom = $mappingConfig['bridge_from'] ?? 'unknown';
            $bridgeTo = $mappingConfig['bridge_to'] ?? 'unknown';
            $isApiReversed = $mappingConfig['api_call_reversed'] ?? false;
            
            switch ($syncDirection) {
                case 'source_to_target':
                    return !$isApiReversed;
                case 'target_to_source':
                    return $isApiReversed;
                case 'bidirectional':
                    return true;
                default:
                    return false;
            }
        }
        
        switch ($syncDirection) {
            case 'source_to_target':
                return !$isReversed;
            case 'target_to_source':
                return $isReversed;
            case 'bidirectional':
                return true;
            default:
                return false;
        }
    }

    private function getOwnershipExplanation(string $syncDirection, bool $isReversed, array|null $mappingConfig): string
    {
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
                    return "Unknown ownership model";
            }
        }
        
        switch ($syncDirection) {
            case 'source_to_target':
                return $isReversed 
                    ? 'Target cannot modify source-owned events'
                    : 'Source can modify target';
            case 'target_to_source':
                return $isReversed 
                    ? 'Target can modify source'
                    : 'Source cannot modify target-owned events';
            case 'bidirectional':
                return 'Both sides can modify each other';
            default:
                return 'Unknown ownership model';
        }
    }

    private function canDeleteInDirection(string $syncDirection, bool $isReversed, array|null $mappingConfig): bool
    {
        return $this->canSyncInDirection($syncDirection, $isReversed, $mappingConfig);
    }

    private function computeEventHash(array $event): string
    {
        $data = [
            'subject' => $this->normalizeString($event['subject'] ?? ''),
            'location' => $this->normalizeString($event['location'] ?? ''),
            'description' => $this->normalizeString($event['description'] ?? ''),
            'all_day' => (bool)($event['all_day'] ?? false),
            'start' => $this->normalizeDateToTimestamp($event['start'] ?? null),
            'end' => $this->normalizeDateToTimestamp($event['end'] ?? null),
            'attendees' => $this->normalizeAttendees($event['attendees'] ?? [])
        ];
        return hash('sha256', json_encode($data));
    }

    private function eventsAreEquivalent(array $sourceEvent, array $targetEvent): bool
    {
        // Compare basic string fields
        $stringFields = ['subject', 'location', 'description'];
        foreach ($stringFields as $field) {
            if ($this->normalizeString($sourceEvent[$field] ?? '') !== $this->normalizeString($targetEvent[$field] ?? '')) {
                return false;
            }
        }

        // Compare boolean fields
        if ((bool)($sourceEvent['all_day'] ?? false) !== (bool)($targetEvent['all_day'] ?? false)) {
            return false;
        }

        // Compare date fields
        $dateFields = ['start', 'end'];
        foreach ($dateFields as $field) {
            if ($this->normalizeDateToTimestamp($sourceEvent[$field] ?? null) !== $this->normalizeDateToTimestamp($targetEvent[$field] ?? null)) {
                return false;
            }
        }

        // Compare attendees
        $sourceAttendees = $this->normalizeAttendees($sourceEvent['attendees'] ?? []);
        $targetAttendees = $this->normalizeAttendees($targetEvent['attendees'] ?? []);
        
        return json_encode($sourceAttendees) === json_encode($targetAttendees);
    }

    private function getBridgeMappings($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $startDate, $endDate, $options)
    {
        return $this->mappingRepository->findMappings(
            $sourceBridge,
            $targetBridge,
            $sourceCalendarId,
            $targetCalendarId,
            $startDate,
            $endDate,
            $options
        );
    }

    private function updateMappingSyncStatus($mappingId, $status, $message = null)
    {
        $this->mappingRepository->updateSyncStatus($mappingId, $status, $message);
    }

    private function updateMappingTargetEventId($mappingId, $newTargetEventId)
    {
        $this->mappingRepository->updateTargetEventId($mappingId, $newTargetEventId);
        $this->mappingRepository->updateSyncStatus($mappingId, 'synced');
    }

    private function updateMappingTimestamp($mappingId)
    {
        $this->mappingRepository->updateTimestamp($mappingId);
    }

    private function updateMappingEventData($mappingId, $event)
    {
        $hash = $this->computeEventHash($event);
        $this->mappingRepository->updateEventData($mappingId, json_encode($event), $hash);
    }

    private function updateMappingWithSourceTiming($mappingId, $sourceStart, $sourceEnd)
    {
        $startTimestamp = $this->normalizeTimestampForDatabase($sourceStart);
        $endTimestamp = $this->normalizeTimestampForDatabase($sourceEnd);
        $this->mappingRepository->updateSourceTiming($mappingId, $startTimestamp, $endTimestamp);
    }

    private function updateMappingSyncMethod($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $sourceEventId, $syncMethod)
    {
        $this->mappingRepository->updateSyncMethod(
            $sourceBridge,
            $targetBridge,
            $sourceCalendarId,
            $targetCalendarId,
            $sourceEventId,
            $syncMethod
        );
    }

    private function normalizeTimestampForDatabase($dateTimeString)
    {
        if (empty($dateTimeString)) return null;
        try {
            $dateTime = new \DateTime($dateTimeString);
            $dateTime->setTimezone(new \DateTimeZone('UTC'));
            return $dateTime->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function handleCancelledEventReactivation($source, $target, $sourceEvent, $mapping, $sourceCalendarId, $targetCalendarId, $options)
    {
        $targetEventExists = $this->checkTargetEventExists($target, $targetCalendarId, $mapping['target_event_id']);

        if ($targetEventExists) {
            try {
                $source->updateSyncStatus(
                    $source->getBridgeType(),
                    $target->getBridgeType(),
                    $mapping['source_calendar_id'],
                    $mapping['target_calendar_id'],
                    $sourceEvent['id'],
                    'pending'
                );

                $success = $target->updateEvent($targetCalendarId, $mapping['target_event_id'], $sourceEvent);

                if ($success) {
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
            } catch (\Exception $e) {
                $this->logger->warning('Failed to reactivate existing target event, will recreate', [
                    'target_event_id' => $mapping['target_event_id'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        try {
            $newTargetEventId = $target->createEvent($targetCalendarId, $sourceEvent);
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
        } catch (\Exception $e) {
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

    private function checkTargetEventExists($target, $targetCalendarId, $targetEventId)
    {
        try {
            $event = $target->getEvent($targetCalendarId, $targetEventId);
            return $event !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Process pending syncs for a specific bridge
     * 
     * @param string $bridgeName Bridge name to process pending syncs for
     * @param int $batchSize Maximum number of mappings to process
     * @param array $options Additional options
     * @return array Processing results
     */
    public function processPendingSyncs(string $bridgeName, int $batchSize = 50, array $options = []): array
    {
        $tenantId = $options['tenant_id'] ?? null;
        // Use default max retries of 3
        $mappings = $this->mappingRepository->findPendingSyncsForBridge($bridgeName, $batchSize, 3, $tenantId);
        
        $results = [
            'processed' => 0,
            'errors' => 0,
            'details' => []
        ];

        foreach ($mappings as $mapping) {
            try {
                $sourceBridgeName = $mapping['source_bridge'];
                $targetBridgeName = $mapping['target_bridge'];
                
                $source = $this->bridgeManager->getBridgeForTenant($tenantId, $sourceBridgeName);
                $target = $this->bridgeManager->getBridgeForTenant($tenantId, $targetBridgeName);
                
                // Fetch source event
                $sourceEvent = $source->getEvent($mapping['source_calendar_id'], $mapping['source_event_id']);
                
                if (!$sourceEvent) {
                    $this->logger->warning("Source event not found for pending sync", [
                        'mapping_id' => $mapping['id'],
                        'source_event_id' => $mapping['source_event_id']
                    ]);
                    
                    $this->mappingRepository->updateSyncStatus($mapping['id'], 'cancelled', 'Source event not found');
                    continue;
                }
                
                // Build mapping index for this single event
                $mappingIndex = [$mapping['source_event_id'] => $mapping];
                
                $syncOptions = array_merge($options, [
                    'force_update' => true // Pending usually means we want to force a retry/update
                ]);
                
                $this->processSingleEventSafely(
                    $source,
                    $target,
                    $sourceEvent,
                    $mappingIndex,
                    $mapping['source_calendar_id'],
                    $mapping['target_calendar_id'],
                    $syncOptions,
                    $sourceBridgeName,
                    $targetBridgeName,
                    1,
                    1
                );
                
                $results['processed']++;
                $results['details'][] = ['id' => $mapping['id'], 'status' => 'success'];
                
            } catch (\Exception $e) {
                $results['errors']++;
                $results['details'][] = ['id' => $mapping['id'], 'status' => 'error', 'message' => $e->getMessage()];
                
                $this->logger->error("Failed to process pending sync mapping", [
                    'mapping_id' => $mapping['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $results;
    }

    /**
     * Re-enable failed events for a bridge
     * 
     * @param string $bridgeName Bridge name
     * @param array $eventIds Optional list of event IDs
     * @param array $options Additional options
     * @return int Number of re-enabled events
     */
    public function reEnableFailedEvents(string $bridgeName, array $eventIds = [], array $options = []): int
    {
        $tenantId = $options['tenant_id'] ?? null;
        return $this->mappingRepository->resetSyncStatus($bridgeName, $eventIds, $tenantId);
    }

    private function normalizeString(string $str): string
    {
        return trim($str);
    }

    private function normalizeDateToTimestamp($date): ?int
    {
        if (empty($date)) {
            return null;
        }
        if (is_int($date)) {
            return $date;
        }
        $ts = strtotime($date);
        return $ts === false ? null : $ts;
    }

    private function normalizeAttendees(array $attendees): array
    {
        // Sort by email to ensure order doesn't affect hash
        usort($attendees, function ($a, $b) {
            return strcmp(strtolower($a['email'] ?? ''), strtolower($b['email'] ?? ''));
        });
        
        return array_map(function ($attendee) {
            return [
                'email' => strtolower(trim($attendee['email'] ?? '')),
                'name' => trim($attendee['name'] ?? ''),
                'status' => $attendee['status'] ?? 'unknown'
            ];
        }, $attendees);
    }
}
