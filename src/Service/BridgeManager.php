<?php

namespace App\Service;

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
     * Process a single event sync
     */
    private function processSingleEvent($source, $target, $sourceEvent, $mappings, $targetCalendarId, $options)
    {
        $mapping = $this->findMapping($mappings, $sourceEvent['id']);
        
        if ($mapping) {
            // Update existing event
            if ($options['skip_updates'] ?? false) {
                return [
                    'action' => 'skipped',
                    'source_event_id' => $sourceEvent['id'],
                    'reason' => 'updates_disabled'
                ];
            }
            
            $success = $target->updateEvent($targetCalendarId, $mapping['target_event_id'], $sourceEvent);
            
            if ($success) {
                $this->updateMappingTimestamp($mapping['id']);
                
                return [
                    'action' => 'updated',
                    'source_event_id' => $sourceEvent['id'],
                    'target_event_id' => $mapping['target_event_id']
                ];
            } else {
                throw new \Exception('Failed to update target event');
            }
        } else {
            // Create new event
            $targetEventId = $target->createEvent($targetCalendarId, $sourceEvent);
            
            $this->createBridgeMapping(
                $source->getBridgeType(),
                $target->getBridgeType(),
                $sourceEvent['id'],
                $targetEventId,
                $targetCalendarId,
                $sourceEvent
            );
            
            return [
                'action' => 'created',
                'source_event_id' => $sourceEvent['id'],
                'target_event_id' => $targetEventId
            ];
        }
    }
    
    /**
     * Handle events that were deleted from source
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
                    $this->deleteBridgeMapping($mapping['id']);
                    $results['deleted']++;
                    
                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'mapping_id' => $mapping['id'],
                        'target_event_id' => $mapping['target_event_id'],
                        'error' => $e->getMessage()
                    ];
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
}
