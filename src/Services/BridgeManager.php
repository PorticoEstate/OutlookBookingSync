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
     * Get all available bridge names
     */
    public function getAvailableBridges(): array
    {
        return array_keys($this->bridges);
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
        
        foreach ($sourceEvents as $sourceEvent) {
            try {
                $eventResult = $this->processSingleEvent($source, $target, $sourceEvent, $mappings, $targetCalendarId, $options);
                
                $results[$eventResult['action']]++;
                $results['processed_events'][] = $eventResult;
                
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'event_id' => $sourceEvent['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                    'event_data' => $sourceEvent
                ];
                
                $this->logger->error('Event sync failed', [
                    'source_bridge' => $sourceBridge,
                    'target_bridge' => $targetBridge,
                    'event' => $sourceEvent,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Handle deletions if requested
        if ($options['handle_deletions'] ?? false) {
            $deletionResults = $this->handleDeletedEvents($source, $target, $mappings, $sourceEvents, $targetCalendarId);
            $results['deleted'] += $deletionResults['deleted'];
            $results['errors'] = array_merge($results['errors'], $deletionResults['errors']);
        }
        
        $this->logger->info('Bridge sync completed', $results);
        
        return $results;
    }
    
    /**
     * Process a single event sync with sync_status tracking
     */
    private function processSingleEvent($source, $target, $sourceEvent, $mappings, $targetCalendarId, $options)
    {
        $mapping = $this->findMapping($mappings, $sourceEvent['id']);
        
        // Add source bridge information to event data
        $sourceEvent['source_bridge'] = $source->getBridgeType();
        $sourceEvent['source_event_id'] = $sourceEvent['id'];
        $sourceEvent['source_calendar_id'] = $targetCalendarId; // Source calendar context
        
        if ($mapping) {
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
     */
    private function handleDeletedEvents($source, $target, $mappings, $sourceEvents, $targetCalendarId)
    {
        $sourceEventIds = array_column($sourceEvents, 'id');
        $results = ['deleted' => 0, 'errors' => []];
        
        foreach ($mappings as $mapping) {
            if (!in_array($mapping['source_event_id'], $sourceEventIds)) {
                try {
                    // Event was deleted from source, delete from target
                    $target->deleteEvent($targetCalendarId, $mapping['target_event_id']);
                    
                    // Mark as cancelled in sync_status (handled by bridge's deleteEvent method)
                    $results['deleted']++;
                    
                    $this->logger->info('Deleted event from target due to source deletion', [
                        'source_event_id' => $mapping['source_event_id'],
                        'target_event_id' => $mapping['target_event_id']
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
     * Create bridge mapping
     */
    private function createBridgeMapping($sourceBridge, $targetBridge, $sourceEventId, $targetEventId, $targetCalendarId, $eventData)
    {
        $sql = "
            INSERT INTO bridge_mappings (
                source_bridge, target_bridge, source_calendar_id, target_calendar_id,
                source_event_id, target_event_id, event_data, created_at, last_synced_at
            ) VALUES (
                :source_bridge, :target_bridge, :source_calendar_id, :target_calendar_id,
                :source_event_id, :target_event_id, :event_data, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':source_bridge' => $sourceBridge,
            ':target_bridge' => $targetBridge,
            ':source_calendar_id' => $eventData['external_id'] ?? 'unknown',
            ':target_calendar_id' => $targetCalendarId,
            ':source_event_id' => $sourceEventId,
            ':target_event_id' => $targetEventId,
            ':event_data' => json_encode($eventData)
        ]);
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
     * Delete bridge mapping
     */
    private function deleteBridgeMapping($mappingId)
    {
        $sql = "DELETE FROM bridge_mappings WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $mappingId]);
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
            foreach ($this->bridges as $name => $bridge) {
                if (method_exists($bridge, 'processPendingSyncs')) {
                    try {
                        $results[$name] = $bridge->processPendingSyncs($batchSize);
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
            foreach ($this->bridges as $name => $bridge) {
                if (method_exists($bridge, 'reEnableFailedEvents')) {
                    try {
                        $results[$name] = $bridge->reEnableFailedEvents($eventIds);
                    } catch (\Exception $e) {
                        $results[$name] = 0;
                        
                        $this->logger->error('Failed to re-enable failed events for bridge', [
                            'bridge' => $name,
                            'error' => $e->getMessage()
                        ]);
                    }
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
        
        foreach ($this->bridges as $name => $bridge) {
            try {
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
        
        foreach ($this->bridges as $name => $bridge) {
            try {
                if (method_exists($bridge, 'getCancelledEvents')) {
                    $cancelled = $bridge->getCancelledEvents();
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
