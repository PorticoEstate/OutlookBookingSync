<?php

namespace App\Services;

use App\Repository\BridgeMappingRepository;
use App\Repository\BridgeResourceRepository;
use App\Repository\BridgeQueueRepository;
use Psr\Log\LoggerInterface;

class SyncOrchestrator
{
    private $bridgeManager;
    private $mappingRepository;
    private $resourceRepository;
    private $queueRepository;
    private $syncLog;
    private $logger;

    public function __construct(
        BridgeManager $bridgeManager,
        BridgeMappingRepository $mappingRepository,
        BridgeResourceRepository $resourceRepository,
        BridgeQueueRepository $queueRepository,
        SyncLogService $syncLog,
        LoggerInterface $logger
    ) {
        $this->bridgeManager = $bridgeManager;
        $this->mappingRepository = $mappingRepository;
        $this->resourceRepository = $resourceRepository;
        $this->queueRepository = $queueRepository;
        $this->syncLog = $syncLog;
        $this->logger = $logger;
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
        $options['tenant_id'] = $tenantId;
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
                'tenant_id' => $options['tenant_id'] ?? null,
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


            if ($isReversed) {
                $target_event_id = $mapping['source_event_id'];
                $source_event_id = $mapping['target_event_id'];
            }
            else
            {
                $target_event_id = $mapping['target_event_id'];
                $source_event_id = $mapping['source_event_id'];
            }

            // Get mapping configuration for ownership decisions

            $mappingConfig = $options['mapping_config'] ?? null;
            // Fetch target event once for both deletion check and comparison
            $target_bridge = $target->getBridgeType();
            $targetCurrent = null;
            $targetExists = true;
            try
            {
                $targetCurrent = $target->getEvent($targetCalendarId, $target_event_id);
            }
            catch (\Throwable $e)
            {
                $targetExists = false;
            }

            $targetIsInactive = $targetExists && isset($targetCurrent['active']) && $targetCurrent['active'] === false;

            // Check if this sync direction is allowed by ownership model
            if (!$this->canSyncInDirection($syncDirection, $isReversed, $mappingConfig)) {


                $action_text = 'skipped';

                if(!$targetExists || $targetIsInactive) {
                    $action_text = 'deleted';
                    // If target event doesn't exist or is inactive, we can consider the mapping cancelled
                    $this->updateMappingSyncStatus($mapping['id'], 'cancelled');

                    //if the sync is reversed, and original event is cancelled, we should mark the mapping as cancelled
                    //and the target event which is the source in this case, should be cancelled / deleted as well

                    $source->deleteEvent($sourceCalendarId, $source_event_id);
                }
 
                $ownershipReason = $this->getOwnershipExplanation($syncDirection, $isReversed, $mappingConfig);
                $this->logger->debug('Skipping sync due to ownership policy', [
                    'source_event_id' => $source_event_id,
                    'sync_direction' => $syncDirection,
                    'is_reversed' => $isReversed,
                    'ownership_reason' => $ownershipReason,
                    'mapping_config' => $mappingConfig
                ]);
                
                return [
                    'success' => true,
                    'action' => $action_text,
                    'source_event_id' => $source_event_id,
                    'target_event_id' => $target_event_id,
                    'reason' => 'deleted due to ownership policy',
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


            if ($shouldRecreateDeleted && (!$targetExists || $targetIsInactive)) {
                if ($respectDel && $syncDirection === 'bidirectional') {
                    return [
                        'success' => true,
                        'action' => 'skipped',
                        'source_event_id' => $source_event_id,
                        'reason' => 'target_deleted_respected'
                    ];
                }
                
                $this->logger->info('Recreating/Reactivating deleted target event due to ownership policy', [
                    'source_event_id' => $source_event_id,
                    'target_event_id' => $target_event_id,
                    'sync_direction' => $syncDirection,
                    'is_reversed' => $isReversed,
                    'ownership_reason' => $syncDirection === 'bidirectional' ? 'bidirectional_consistency' : 'owner_enforcement',
                    'is_reactivation' => $targetIsInactive
                ]);

                if ($targetIsInactive) {
                    // Try to reactivate via update
                    $eventToUpdate = $sourceEvent;
                    $eventToUpdate['active'] = true;
                    
                    try {
                        $success = $target->updateEvent($targetCalendarId, $target_event_id, $eventToUpdate);
                        if ($success) {
                            $this->updateMappingTimestamp($mapping['id']);
                            $this->updateMappingEventData($mapping['id'], $sourceEvent);
                            return [
                                'success' => true,
                                'action' => 'reactivated',
                                'source_event_id' => $source_event_id,
                                'target_event_id' => $target_event_id,
                                'reason' => 'ownership_enforcement_reactivation'
                            ];
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to reactivate target event via update, falling back to create', ['error' => $e->getMessage()]);
                    }
                }
                
                $newId = $target->createEvent($targetCalendarId, $sourceEvent);
                $this->updateMappingTargetEventId($mapping['id'], $newId);
                $this->updateMappingTimestamp($mapping['id']);
                $this->updateMappingEventData($mapping['id'], $sourceEvent);
                return [
                    'success' => true,
                    'action' => 'recreated',
                    'source_event_id' => $source_event_id,
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
                    'source_event_id' => $source_event_id,
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
                            'source_event_id' => $source_event_id,
                            'target_event_id' => $target_event_id,
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
                            'source_event_id' => $source_event_id,
                            'target_event_id' => $target_event_id,
                            'reason' => 'no_changes'
                        ];
                    }
                } catch (\Throwable $e) {
                    $this->logger->debug('No-op guard: failed to compare target event; proceeding with update', [
                        'target_event_id' => $target_event_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            try {
                // Mark as pending before update
                $source->updateSyncStatus(
                    $mapping['source_bridge'],
                    $mapping['target_bridge'],
                    $mapping['source_calendar_id'],
                    $mapping['target_calendar_id'],
                    $mapping['source_event_id'],
                    'pending'
                );

                $success = $target->updateEvent($targetCalendarId, $target_event_id, $sourceEvent);

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
                        $source_event_id,
                        $options['sync_method'] ?? 'manual'
                    );

                    return [
                        'success' => true,
                        'action' => 'updated',
                        'source_event_id' => $source_event_id,
                        'target_event_id' => $target_event_id
                    ];
                } else {
                    $source->updateSyncStatus(
                        $mapping['source_bridge'],
                        $mapping['target_bridge'],
                        $mapping['source_calendar_id'],
                        $mapping['target_calendar_id'],
                        $mapping['source_event_id'],
                        'error',
                        'Failed to update target event'
                    );
                    throw new \Exception('Failed to update target event');
                }
            } catch (\Exception $e) {
                $source->updateSyncStatus(
                    $mapping['source_bridge'],
                    $mapping['target_bridge'],
                    $mapping['source_calendar_id'],
                    $mapping['target_calendar_id'],
                    $mapping['source_event_id'],
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
                    'tenant_id' => $options['tenant_id'] ?? null,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }
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
        // Provide default date range if not specified (used for single event syncs)
        if ($startDate === null) {
            $startDate = date('Y-m-d', strtotime('-30 days'));
        }
        if ($endDate === null) {
            $endDate = date('Y-m-d', strtotime('+90 days'));
        }
        
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
     * Orchestrate sync request between two bridges
     */
    public function processSyncRequest(
        string $sourceBridge,
        string $targetBridge,
        string $startDate,
        string $endDate,
        array $options = [],
        ?string $tenantId = null
    ): array {
        $this->logger->info('Bridge sync requested', [
            'source_bridge' => $sourceBridge,
            'target_bridge' => $targetBridge,
            'date_range' => [$startDate, $endDate],
            'options' => $options
        ]);

        // Get all active mappings between these bridges
        $resourceMappings = $this->resourceRepository->findActiveMappings($sourceBridge, $targetBridge, $tenantId);

        if (empty($resourceMappings)) {
            return [
                'success' => false,
                'error' => "No active mappings found between {$sourceBridge} and {$targetBridge}",
                'suggestion' => "Create resource mappings first using the /mappings/resources endpoint"
            ];
        }

        $jobsQueued = 0;
        $jobsSkipped = 0;
        $eventsFound = 0;
        $allResults = [];

        foreach ($resourceMappings as $resourceMapping) {
            // Use tenant_id from the resource mapping record for proper isolation
            $mappingTenantId = $resourceMapping['tenant_id'];

            // Determine the correct source and target calendar IDs based on sync direction
            if ($sourceBridge === $resourceMapping['bridge_from'] && $targetBridge === $resourceMapping['bridge_to']) {
                // Forward direction: booking_system → outlook
                $sourceCalendarId = $resourceMapping['source_calendar_id'];
                $targetCalendarId = $resourceMapping['target_calendar_id'];
            } else {
                // Reverse direction: outlook → booking_system
                $sourceCalendarId = $resourceMapping['target_calendar_id'];
                $targetCalendarId = $resourceMapping['source_calendar_id'];
            }

            try {
                $effectiveEndDate = $this->resolveMappingEndDate(
                    $endDate,
                    $resourceMapping,
                    (bool)($options['end_date_explicit'] ?? false)
                );

                $this->logger->info('Processing mapping for sync', [
                    'mapping_id' => $resourceMapping['id'],
                    'mapping_tenant_id' => $mappingTenantId,
                    'request_tenant_id' => $tenantId,
                    'source_calendar' => $sourceCalendarId,
                    'target_calendar' => $targetCalendarId,
                    'horizon' => $resourceMapping['horizon'] ?? null,
                    'effective_end_date' => $effectiveEndDate
                ]);

                // Get source bridge instance and fetch events
                $sourceBridgeInstance = $this->bridgeManager->getBridgeForTenant($mappingTenantId ?: 'default', $sourceBridge);
                $sourceEvents = $sourceBridgeInstance->getEvents($sourceCalendarId, $startDate, $effectiveEndDate);

                if (empty($sourceEvents)) {
                    $allResults[] = [
                        'mapping_id' => $resourceMapping['id'],
                        'source_calendar' => $sourceCalendarId,
                        'target_calendar' => $targetCalendarId,
                        'events_found' => 0,
                        'events_queued' => 0,
                        'status' => 'no_events'
                    ];
                    $this->syncLog->write(
                        'sync',
                        $sourceBridge,
                        $targetBridge,
                        'no_events',
                        0,
                        [
                            'mapping_id' => $resourceMapping['id'],
                            'source_calendar' => $sourceCalendarId,
                            'target_calendar' => $targetCalendarId,
                            'events_found' => 0,
                            'events_queued' => 0,
                            'start_date' => $startDate,
                            'end_date' => $effectiveEndDate
                        ],
                        null,
                        null,
                        $mappingTenantId
                    );

                    continue;
                }

                $eventsFound += count($sourceEvents);
                $mappingEventsQueued = 0;
                $mappingEventsSkipped = 0;

                if ($options['dry_run'] ?? false) {
                    $results = $this->performDryRun($mappingTenantId ?: 'default', $sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $startDate, $effectiveEndDate);
                    
                    $allResults[] = [
                        'mapping_id' => $resourceMapping['id'],
                        'source_calendar' => $sourceCalendarId,
                        'target_calendar' => $targetCalendarId,
                        'results' => $results
                    ];
                } else {
                    // Queue each event individually
                    foreach ($sourceEvents as $event) {
                        $eventId = $event['id'] ?? $event['event_id'] ?? null;
                        if (!$eventId) {
                            continue;
                        }

                        $queuePayload = [
                            'mapping_id' => $resourceMapping['id'],
                            'source_bridge' => $sourceBridge,
                            'target_bridge' => $targetBridge,
                            'source_calendar_id' => $sourceCalendarId,
                            'target_calendar_id' => $targetCalendarId,
                            'source_event_id' => $eventId,
                            'event_data' => $event,
                            'options' => $options,
                            'tenant_id' => $mappingTenantId
                        ];

                        $queued = $this->queueRepository->enqueueIfNotExists(
                            'sync',
                            $sourceBridge,
                            $targetBridge,
                            $queuePayload,
                            3,
                            $mappingTenantId
                        );

                        if ($queued) {
                            $jobsQueued++;
                            $mappingEventsQueued++;
                        } else {
                            $jobsSkipped++;
                            $mappingEventsSkipped++;
                        }
                    }

                    // Log the sync operation for this mapping
                    $this->syncLog->write(
                        'sync',
                        $sourceBridge,
                        $targetBridge,
                        'success',
                        $mappingEventsQueued,
                        [
                            'mapping_id' => $resourceMapping['id'],
                            'source_calendar' => $sourceCalendarId,
                            'target_calendar' => $targetCalendarId,
                            'events_found' => count($sourceEvents),
                            'events_queued' => $mappingEventsQueued,
                            'events_skipped' => $mappingEventsSkipped,
                            'start_date' => $startDate,
                            'end_date' => $effectiveEndDate
                        ],
                        null,
                        null,
                        $mappingTenantId
                    );

                    $allResults[] = [
                        'mapping_id' => $resourceMapping['id'],
                        'source_calendar' => $sourceCalendarId,
                        'target_calendar' => $targetCalendarId,
                        'events_found' => count($sourceEvents),
                        'events_queued' => $mappingEventsQueued,
                        'events_skipped' => $mappingEventsSkipped,
                        'status' => $mappingEventsQueued > 0 ? 'queued' : 'all_duplicates'
                    ];
                }
            } catch (\Exception $e) {
                $allResults[] = [
                    'mapping_id' => $resourceMapping['id'],
                    'source_calendar' => $sourceCalendarId ?? null,
                    'target_calendar' => $targetCalendarId ?? null,
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];

                $this->logger->error('Failed to process mapping sync', [
                    'mapping_id' => $resourceMapping['id'],
                    'tenant_id' => $mappingTenantId,
                    'request_tenant_id' => $tenantId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return [
            'success' => true,
            'jobs_queued' => $jobsQueued,
            'jobs_skipped' => $jobsSkipped,
            'events_found' => $eventsFound,
            'mappings_processed' => count($resourceMappings),
            'sync_results' => $allResults
        ];
    }

    private function resolveMappingEndDate(string $requestedEndDate, array $resourceMapping, bool $endDateExplicit): string
    {
        if ($endDateExplicit)
        {
            return $requestedEndDate;
        }

        $horizon = $resourceMapping['horizon'] ?? null;
        if ($horizon === null || $horizon === '' || !is_numeric($horizon))
        {
            return $requestedEndDate;
        }

        $days = (int)$horizon;
        if ($days < 0)
        {
            return $requestedEndDate;
        }

        try
        {
            $endDate = new \DateTime('today');
            $endDate->modify('+' . $days . ' days');
            return $endDate->format('Y-m-d');
        }
        catch (\Exception $e)
        {
            return $requestedEndDate;
        }
    }

    private function performDryRun(
        string $tenantId,
        string $sourceBridge,
        string $targetBridge,
        string $sourceCalendarId,
        string $targetCalendarId,
        string $startDate,
        string $endDate
    ): array {
        $source = $this->bridgeManager->getBridgeForTenant($tenantId, $sourceBridge);
        $sourceEvents = $source->getEvents($sourceCalendarId, $startDate, $endDate);

        return [
            'dry_run' => true,
            'source_bridge' => $sourceBridge,
            'target_bridge' => $targetBridge,
            'source_events_found' => count($sourceEvents),
            'events_to_process' => $sourceEvents,
            'note' => 'This is a dry run - no actual changes were made'
        ];
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


    /**
     * Re-enable failed events for a bridge
     * 
     * @param string $bridgeName Bridge name
     * @param array $eventIds Specific event IDs to retry (optional)
     * @param array $options Additional options
     * @return int Number of events re-enabled
     */
    public function reEnableFailedEvents(string $bridgeName, array $eventIds = [], array $options = []): int
    {
        $tenantId = $options['tenant_id'] ?? null;
        $count = 0;
        
        if (empty($eventIds)) {
            // If no IDs provided, retry all failed items for this bridge
            // We need to fetch them first
            // Assuming getFailedItems returns all failed items, we filter by bridge
            $failedItems = $this->queueRepository->getFailedItems($tenantId, 500); // Limit 500 for safety
            foreach ($failedItems as $item) {
                if ($item['source_bridge'] === $bridgeName || $item['target_bridge'] === $bridgeName) {
                    if ($this->queueRepository->retryFailedItem($item['id'])) {
                        $count++;
                    }
                }
            }
        } else {
            foreach ($eventIds as $id) {
                if ($this->queueRepository->retryFailedItem($id)) {
                    $count++;
                }
            }
        }
        
        return $count;
    }
}
