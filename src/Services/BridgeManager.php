<?php

namespace App\Services;

use App\Bridge\AbstractCalendarBridge;
use Psr\Log\LoggerInterface;
use PDO;

class BridgeManager
{
    private $bridges = [];
    private $logger;
    private $db;
    
    public function __construct(LoggerInterface $logger, PDO $db)
    {
        $this->logger = $logger;
        $this->db = $db;
    }
    
    /**
     * Register a calendar bridge
     */
    public function registerBridge($name, $bridgeClass, $config)
    {
        if (!is_subclass_of($bridgeClass, AbstractCalendarBridge::class)) {
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
     * Get a bridge instance
     */
    public function getBridge($name): AbstractCalendarBridge
    {
        if (!isset($this->bridges[$name])) {
            throw new \Exception("Bridge '{$name}' not found");
        }
        
        if (!$this->bridges[$name]['instance']) {
            $class = $this->bridges[$name]['class'];
            $config = $this->bridges[$name]['config'];
            
            $this->bridges[$name]['instance'] = new $class($config, $this->logger, $this->db);
        }
        
        return $this->bridges[$name]['instance'];
    }
    
    /**
     * Get bridge information
     */
    public function getBridgeInfo($name): array
    {
        if (!isset($this->bridges[$name])) {
            throw new \Exception("Bridge '{$name}' not found");
        }
        
        $bridge = $this->getBridge($name);
        
        return [
            'name' => $name,
            'type' => $bridge->getBridgeType(),
            'class' => $this->bridges[$name]['class'],
            'capabilities' => $bridge->getCapabilities(),
            'health' => $bridge->healthCheck()
        ];
    }
    
    /**
     * Get information about all bridges
     */
    public function getAllBridgesInfo(): array
    {
        $info = [];
        
        foreach (array_keys($this->bridges) as $name) {
            try {
                $info[$name] = $this->getBridgeInfo($name);
            } catch (\Exception $e) {
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
     * Sync events between two bridges
     */
    public function syncBetweenBridges(
        $sourceBridge, 
        $targetBridge, 
        $sourceCalendarId, 
        $targetCalendarId, 
        $startDate, 
        $endDate,
        $options = []
    ): array {
        $source = $this->getBridge($sourceBridge);
        $target = $this->getBridge($targetBridge);
        
        $this->logger->info('Starting bridge sync', [
            'source_bridge' => $sourceBridge,
            'target_bridge' => $targetBridge,
            'source_calendar' => $sourceCalendarId,
            'target_calendar' => $targetCalendarId,
            'date_range' => [$startDate, $endDate]
        ]);
        
        // Get events from source
        $sourceEvents = $source->getEvents($sourceCalendarId, $startDate, $endDate);
        
        // Get existing mappings
        $mappings = $this->getBridgeMappings($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId);
        
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
        
        // Process each event individually with maximum fault tolerance
        $totalEvents = count($sourceEvents);
        $this->logger->info("Starting to process {$totalEvents} events individually", [
            'source_bridge' => $sourceBridge,
            'target_bridge' => $targetBridge
        ]);
        
        for ($index = 0; $index < $totalEvents; $index++) {
            $sourceEvent = $sourceEvents[$index];
            
            $this->logger->info("Processing event {$index}/{$totalEvents}", [
                'event_id' => $sourceEvent['id'] ?? 'unknown',
                'event_subject' => $sourceEvent['subject'] ?? 'N/A'
            ]);
            
            // Process this single event in complete isolation
            $eventProcessingResult = $this->processSingleEventSafely(
                $source, 
                $target, 
                $sourceEvent, 
                $mappings, 
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
        
        // Handle deletions if requested
        if ($options['handle_deletions'] ?? false) {
            try {
                $deletionResults = $this->handleDeletedEvents($source, $target, $mappings, $sourceEvents, $targetCalendarId, $startDate, $endDate, $options);
                $results['deleted'] += $deletionResults['deleted'];
                $results['errors'] = array_merge($results['errors'], $deletionResults['errors']);
            } catch (\Exception $e) {
                $this->logger->error('Failed to handle deletions - continuing without deletion processing', [
                    'error' => $e->getMessage()
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
        
        return $results;
    }
    
    /**
     * Process a single event with complete isolation and maximum error protection
     */
    private function processSingleEventSafely($source, $target, $sourceEvent, $mappings, $sourceCalendarId, $targetCalendarId, $options, $sourceBridge, $targetBridge, $eventIndex, $totalEvents)
    {
        // Set error reporting to catch everything
        $originalErrorReporting = error_reporting(E_ALL);
        
        try {
            $this->logger->debug('Processing event with safety wrapper', [
                'event_index' => $eventIndex,
                'total_events' => $totalEvents,
                'event_id' => $sourceEvent['id'] ?? 'unknown',
                'event_subject' => $sourceEvent['subject'] ?? 'N/A'
            ]);
            
            // Call the original processing method
            $eventResult = $this->processSingleEvent($source, $target, $sourceEvent, $mappings, $sourceCalendarId, $targetCalendarId, $options);
            
            $this->logger->debug('Event processed successfully with safety wrapper', [
                'event_id' => $sourceEvent['id'] ?? 'unknown',
                'action' => $eventResult['action']
            ]);
            
            // Restore error reporting
            error_reporting($originalErrorReporting);
            
            return [
                'success' => true,
                'action' => $eventResult['action'],
                'source_event_id' => $eventResult['source_event_id'],
                'target_event_id' => $eventResult['target_event_id'] ?? null,
                'reason' => $eventResult['reason'] ?? null
            ];
            
        } catch (\Throwable $e) {
            // Restore error reporting
            error_reporting($originalErrorReporting);
            
            $errorInfo = [
                'event_id' => $sourceEvent['id'] ?? 'unknown',
                'event_subject' => $sourceEvent['subject'] ?? 'N/A',
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
                'event_data' => $sourceEvent,
                'stack_trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];
            
            $this->logger->error('Event sync failed in safety wrapper - isolated and continuing', [
                'source_bridge' => $sourceBridge,
                'target_bridge' => $targetBridge,
                'event_index' => $eventIndex,
                'total_events' => $totalEvents,
                'events_remaining' => $totalEvents - $eventIndex,
                'error_info' => $errorInfo
            ]);
            
            return [
                'success' => false,
                'error' => $errorInfo
            ];
        }
    }

    /**
     * Process a single event sync with sync_status tracking
     */
    private function processSingleEvent($source, $target, $sourceEvent, $mappings, $sourceCalendarId, $targetCalendarId, $options)
    {
        $mapping = $this->findMapping($mappings, $sourceEvent['id']);
        
        // Add source bridge information to event data
        $sourceEvent['source_bridge'] = $source->getBridgeType();
        $sourceEvent['source_event_id'] = $sourceEvent['id'];
        $sourceEvent['source_calendar_id'] = $sourceCalendarId; // Correct: source calendar ID
        
        if ($mapping) {
            // Handle cancelled events - check if target event still exists
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
                        'action' => 'updated',
                        'source_event_id' => $sourceEvent['id'],
                        'target_event_id' => $mapping['target_event_id']
                    ];
                } else {
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
                
            } catch (\Exception $e) {
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
            
        } else {
            // Create new event
            try {
                $targetEventId = $target->createEvent($targetCalendarId, $sourceEvent);
                
                // Find the newly created mapping and update it with source timing
                $newMappings = $this->getBridgeMappings($source->getBridgeType(), $target->getBridgeType(), $sourceCalendarId, $targetCalendarId);
                $newMapping = $this->findMapping($newMappings, $sourceEvent['id']);
                
                if ($newMapping) {
                    $this->updateMappingWithSourceTiming(
                        $newMapping['id'],
                        $sourceEvent['start'] ?? null,
                        $sourceEvent['end'] ?? null
                    );
                    
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
                    'action' => 'created',
                    'source_event_id' => $sourceEvent['id'],
                    'target_event_id' => $targetEventId
                ];
                
            } catch (\Exception $e) {
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
        $results = ['deleted' => 0, 'errors' => []];
        
        foreach ($mappings as $mapping) {
            // Only consider events that were created within the sync timeframe
            if (!$this->isEventWithinTimeframe($mapping, $startDate, $endDate)) {
                $this->logger->debug('Skipping deletion check for event outside timeframe', [
                    'source_event_id' => $mapping['source_event_id'],
                    'event_created_at' => $mapping['created_at'] ?? 'unknown',
                    'sync_window' => [$startDate, $endDate]
                ]);
                continue;
            }
            
            if (!in_array($mapping['source_event_id'], $sourceEventIds)) {
                try {
                    // Event was deleted from source, delete from target
                    $target->deleteEvent($targetCalendarId, $mapping['target_event_id']);
                    
                    // Record sync method for deletion tracking
                    $this->updateMappingSyncMethod(
                        $source->getBridgeType(),
                        $target->getBridgeType(),
                        $mapping['source_calendar_id'],
                        $mapping['target_calendar_id'],
                        $mapping['source_event_id'],
                        $options['sync_method'] ?? 'automated'
                    );
                    
                    // Mark as cancelled in sync_status (handled by bridge's deleteEvent method)
                    $results['deleted']++;
                    
                    $this->logger->info('Deleted event from target due to source deletion', [
                        'source_event_id' => $mapping['source_event_id'],
                        'target_event_id' => $mapping['target_event_id'],
                        'event_created_at' => $mapping['created_at'] ?? 'unknown'
                    ]);
                    
                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'mapping_id' => $mapping['id'],
                        'source_event_id' => $mapping['source_event_id'],
                        'target_event_id' => $mapping['target_event_id'],
                        'error' => $e->getMessage()
                    ];
                    
                    // Mark as error
                    try {
                        $source->updateSyncStatus(
                            $source->getBridgeType(),
                            $target->getBridgeType(),
                            $mapping['source_calendar_id'],
                            $mapping['target_calendar_id'],
                            $mapping['source_event_id'],
                            'error',
                            'Failed to delete from target: ' . $e->getMessage()
                        );
                    } catch (\Exception $statusUpdateError) {
                        $this->logger->error('Failed to update sync status for deletion error', [
                            'error' => $statusUpdateError->getMessage()
                        ]);
                    }
                    
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
        foreach ($mappings as $mapping) {
            if ($mapping['source_event_id'] === $sourceEventId) {
                return $mapping;
            }
        }
        return null;
    }
    
    /**
     * Check if a mapping's event is within the specified timeframe
     * This checks if the event was created/originated within the sync window
     */
    private function isEventWithinTimeframe($mapping, $startDate, $endDate)
    {
        // Check if we have source event start/end times stored in the mapping
        if (!empty($mapping['source_event_start'])) {
            $eventStart = strtotime($mapping['source_event_start']);
            $windowStart = strtotime($startDate);
            $windowEnd = strtotime($endDate);
            
            // Event start falls within the sync window
            return $eventStart >= $windowStart && $eventStart <= $windowEnd;
        }
        
        // Fallback: check mapping creation time if source event times not available
        if (!empty($mapping['created_at'])) {
            $createdAt = strtotime($mapping['created_at']);
            $windowStart = strtotime($startDate);
            $windowEnd = strtotime($endDate . ' +1 day'); // Give some buffer for creation time
            
            return $createdAt >= $windowStart && $createdAt <= $windowEnd;
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
     */
    private function getBridgeMappings($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId): array
    {
        $sql = "
            SELECT * FROM bridge_mappings 
            WHERE source_bridge = :source_bridge 
            AND target_bridge = :target_bridge
            AND source_calendar_id = :source_calendar_id
            AND target_calendar_id = :target_calendar_id
            ORDER BY created_at DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':source_bridge' => $sourceBridge,
            ':target_bridge' => $targetBridge,
            ':source_calendar_id' => $sourceCalendarId,
            ':target_calendar_id' => $targetCalendarId
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
     * Update mapping with source event timing information
     */
    private function updateMappingWithSourceTiming($mappingId, $sourceStart, $sourceEnd)
    {
        // Only update if we have timing information
        if (empty($sourceStart)) {
            return;
        }
        
        try {
            $sql = "UPDATE bridge_mappings 
                    SET source_event_start = :start, 
                        source_event_end = :end,
                        updated_at = CURRENT_TIMESTAMP 
                    WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':id' => $mappingId,
                ':start' => $sourceStart,
                ':end' => $sourceEnd
            ]);
            
            $this->logger->debug('Updated mapping with source event timing', [
                'mapping_id' => $mappingId,
                'source_start' => $sourceStart,
                'source_end' => $sourceEnd
            ]);
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to update mapping with source timing - continuing', [
                'mapping_id' => $mappingId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Update mapping with sync method information
     */
    private function updateMappingSyncMethod($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $sourceEventId, $syncMethod)
    {
        try {
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
            
        } catch (\Exception $e) {
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
        
        if ($targetEventExists) {
            // Target event exists - try to reactivate/update it
            try {
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
                
                if ($success) {
                    $this->updateMappingTimestamp($mapping['id']);
                    $this->updateMappingWithSourceTiming($mapping['id'], $sourceEvent['start'] ?? null, $sourceEvent['end'] ?? null);
                    $this->updateMappingSyncMethod(
                        $source->getBridgeType(),
                        $target->getBridgeType(),
                        $mapping['source_calendar_id'],
                        $mapping['target_calendar_id'],
                        $sourceEvent['id'],
                        $options['sync_method'] ?? 'manual'
                    );
                    
                    return [
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
        
        // Target event doesn't exist or reactivation failed - create new event
        try {
            $this->logger->info('Creating new target event for cancelled mapping', [
                'source_event_id' => $sourceEvent['id'],
                'old_target_event_id' => $mapping['target_event_id']
            ]);
            
            $newTargetEventId = $target->createEvent($targetCalendarId, $sourceEvent);
            
            // Update the mapping with new target event ID
            $this->updateMappingTargetEventId($mapping['id'], $newTargetEventId);
            $this->updateMappingTimestamp($mapping['id']);
            $this->updateMappingWithSourceTiming($mapping['id'], $sourceEvent['start'] ?? null, $sourceEvent['end'] ?? null);
            $this->updateMappingSyncMethod(
                $source->getBridgeType(),
                $target->getBridgeType(),
                $mapping['source_calendar_id'],
                $mapping['target_calendar_id'],
                $sourceEvent['id'],
                $options['sync_method'] ?? 'manual'
            );
            
            return [
                'action' => 'recreated',
                'source_event_id' => $sourceEvent['id'],
                'target_event_id' => $newTargetEventId,
                'previous_target_event_id' => $mapping['target_event_id']
            ];
            
        } catch (\Exception $e) {
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
        try {
            // Try to get the event - if it exists, this won't throw
            $event = $target->getEvent($targetCalendarId, $targetEventId);
            return $event !== null;
        } catch (\Exception $e) {
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
        try {
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
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to update mapping with new target event ID', [
                'mapping_id' => $mappingId,
                'new_target_event_id' => $newTargetEventId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Process pending syncs across all bridges
     */
    public function processPendingSyncs($bridgeName = null, $batchSize = 50): array
    {
        $results = [];
        
        if ($bridgeName) {
            // Process pending syncs for specific bridge
            $bridge = $this->getBridge($bridgeName);
            if (method_exists($bridge, 'processPendingSyncs')) {
                $results[$bridgeName] = $bridge->processPendingSyncs($batchSize);
            }
        } else {
            // Process pending syncs for all bridges
            foreach (array_keys($this->bridges) as $name) {
                try {
                    $bridge = $this->getBridge($name);
                    if (method_exists($bridge, 'processPendingSyncs')) {
                        $results[$name] = $bridge->processPendingSyncs($batchSize);
                    }
                } catch (\Exception $e) {
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
        
        if ($bridgeName) {
            // Re-enable for specific bridge
            $bridge = $this->getBridge($bridgeName);
            if (method_exists($bridge, 'reEnableFailedEvents')) {
                $results[$bridgeName] = $bridge->reEnableFailedEvents($eventIds);
            }
        } else {
            // Re-enable for all bridges
            foreach (array_keys($this->bridges) as $name) {
                try {
                    $bridge = $this->getBridge($name);
                    if (method_exists($bridge, 'reEnableFailedEvents')) {
                        $results[$name] = $bridge->reEnableFailedEvents($eventIds);
                    }
                } catch (\Exception $e) {
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
        
        foreach (array_keys($this->bridges) as $name) {
            try {
                $bridge = $this->getBridge($name);
                if (method_exists($bridge, 'getSyncStats')) {
                    $allStats[$name] = $bridge->getSyncStats();
                } else {
                    // Fallback to basic stats
                    $allStats[$name] = [
                        'bridge_name' => $name,
                        'bridge_type' => $bridge->getBridgeType(),
                        'sync_stats_available' => false
                    ];
                }
            } catch (\Exception $e) {
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
        
        foreach (array_keys($this->bridges) as $name) {
            try {
                $bridge = $this->getBridge($name);
                if (method_exists($bridge, 'getCancelledEvents')) {
                    $cancelled = $bridge->getCancelledEvents($name, null); // Get for this bridge
                    if (!empty($cancelled)) {
                        $allCancelled[$name] = $cancelled;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to get cancelled events for bridge', [
                    'bridge' => $name,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $allCancelled;
    }
}
