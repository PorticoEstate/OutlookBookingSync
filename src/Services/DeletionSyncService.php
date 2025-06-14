<?php

namespace App\Services;

use PDO;
use Psr\Log\LoggerInterface;

/**
 * DeletionSyncService handles detecting and syncing deleted events between bridges
 */
class DeletionSyncService
{
    private PDO $db;
    private LoggerInterface $logger;
    private $bridgeManager;

    public function __construct(PDO $db, LoggerInterface $logger, $bridgeManager)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->bridgeManager = $bridgeManager;
    }

    /**
     * Process deletion check queue
     */
    public function processDeletionChecks(): array
    {
        $results = [
            'processed' => 0,
            'deletions_found' => 0,
            'errors' => []
        ];

        try {
            // Get pending deletion checks from queue
            $checks = $this->getDeletionChecks();
            
            foreach ($checks as $check) {
                try {
                    $checkData = json_decode($check['payload'], true);
                    
                    if ($this->processOutlookDeletionCheck($checkData)) {
                        $results['deletions_found']++;
                    }
                    
                    $results['processed']++;
                    
                    // Mark queue item as processed
                    $this->markQueueItemProcessed($check['id']);
                    
                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'check_id' => $check['id'],
                        'error' => $e->getMessage()
                    ];
                    
                    $this->markQueueItemFailed($check['id'], $e->getMessage());
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to process deletion checks', [
                'error' => $e->getMessage()
            ]);
            
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Process a single Outlook deletion check
     */
    private function processOutlookDeletionCheck($checkData): bool
    {
        $calendarId = $checkData['calendar_id'];
        $eventId = $checkData['event_id'];
        
        $this->logger->info('Processing deletion check', [
            'calendar_id' => $calendarId,
            'event_id' => $eventId
        ]);

        // Try to fetch the event from Outlook to see if it still exists
        $outlookBridge = $this->bridgeManager->getBridge('outlook');
        
        try {
            // Attempt to get the specific event
            $event = $this->getOutlookEvent($outlookBridge, $calendarId, $eventId);
            
            if ($event === null) {
                // Event doesn't exist in Outlook anymore - it was deleted
                $this->handleDeletedOutlookEvent($calendarId, $eventId);
                return true;
            }
            
            // Event still exists, no deletion detected
            return false;
            
        } catch (\Exception $e) {
            // If we get a 404 or similar error, the event was likely deleted
            if (strpos($e->getMessage(), '404') !== false || 
                strpos($e->getMessage(), 'not found') !== false) {
                
                $this->handleDeletedOutlookEvent($calendarId, $eventId);
                return true;
            }
            
            // Other errors should be re-thrown
            throw $e;
        }
    }

    /**
     * Get a specific event from Outlook
     */
    private function getOutlookEvent($outlookBridge, $calendarId, $eventId)
    {
        $graphBaseUrl = 'https://graph.microsoft.com/v1.0';
        $url = "{$graphBaseUrl}/users/{$calendarId}/calendar/events/{$eventId}";
        
        try {
            // Use reflection to access the private makeGraphRequest method
            $reflection = new \ReflectionClass($outlookBridge);
            $method = $reflection->getMethod('makeGraphRequest');
            $method->setAccessible(true);
            
            return $method->invoke($outlookBridge, 'GET', $url);
            
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), '404') !== false) {
                return null; // Event not found
            }
            throw $e;
        }
    }

    /**
     * Handle a deleted Outlook event by syncing the deletion to booking system
     */
    private function handleDeletedOutlookEvent($calendarId, $eventId)
    {
        $this->logger->info('Outlook event deleted, syncing to booking system', [
            'calendar_id' => $calendarId,
            'event_id' => $eventId
        ]);

        // Find bridge mappings for this Outlook event
        $mappings = $this->findMappingsForOutlookEvent($calendarId, $eventId);
        
        foreach ($mappings as $mapping) {
            try {
                // Get the target bridge (booking system)
                $targetBridge = $this->bridgeManager->getBridge($mapping['target_bridge']);
                
                // Delete the event in the booking system
                $success = $targetBridge->deleteEvent(
                    $mapping['target_calendar_id'], 
                    $mapping['target_event_id']
                );
                
                if ($success) {
                    // Remove the bridge mapping since both events are now deleted
                    $this->deleteBridgeMapping($mapping['id']);
                    
                    // Log the successful deletion sync
                    $this->logSyncOperation('delete', 'outlook', $mapping['target_bridge'], 
                        'success', ['calendar_id' => $calendarId, 'event_id' => $eventId]);
                    
                    $this->logger->info('Successfully synced deletion to booking system', [
                        'outlook_calendar' => $calendarId,
                        'outlook_event' => $eventId,
                        'booking_resource' => $mapping['target_calendar_id'],
                        'booking_event' => $mapping['target_event_id']
                    ]);
                } else {
                    throw new \Exception("Failed to delete event in booking system");
                }
                
            } catch (\Exception $e) {
                $this->logger->error('Failed to sync deletion to booking system', [
                    'outlook_calendar' => $calendarId,
                    'outlook_event' => $eventId,
                    'mapping_id' => $mapping['id'],
                    'error' => $e->getMessage()
                ]);
                
                // Log the failed deletion sync
                $this->logSyncOperation('delete', 'outlook', $mapping['target_bridge'], 
                    'error', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Find bridge mappings for an Outlook event
     */
    private function findMappingsForOutlookEvent($calendarId, $eventId): array
    {
        $sql = "SELECT * FROM bridge_mappings 
                WHERE source_bridge = 'outlook' 
                AND source_calendar_id = :calendar_id 
                AND source_event_id = :event_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':calendar_id' => $calendarId,
            ':event_id' => $eventId
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete a bridge mapping
     */
    private function deleteBridgeMapping($mappingId)
    {
        $sql = "DELETE FROM bridge_mappings WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $mappingId]);
    }

    /**
     * Log sync operation
     */
    private function logSyncOperation($operation, $sourceBridge, $targetBridge, $status, $details = [])
    {
        $sql = "INSERT INTO bridge_sync_logs 
                (source_bridge, target_bridge, operation, status, details) 
                VALUES (:source, :target, :operation, :status, :details)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':source' => $sourceBridge,
            ':target' => $targetBridge,
            ':operation' => $operation,
            ':status' => $status,
            ':details' => json_encode($details)
        ]);
    }

    /**
     * Get pending deletion checks from queue
     */
    private function getDeletionChecks(): array
    {
        $sql = "SELECT * FROM bridge_queue 
                WHERE queue_type = 'deletion_check' 
                AND status = 'pending' 
                ORDER BY scheduled_at ASC 
                LIMIT 50";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark queue item as processed
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
     * Mark queue item as failed
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
     * Manual deletion sync - check all recent mappings for deleted Outlook events
     */
    public function syncDeletedEvents(): array
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
                AND last_synced_at > NOW() - INTERVAL '7 days'";
        
        $stmt = $this->db->query($sql);
        $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($mappings as $mapping) {
            try {
                $checkData = [
                    'calendar_id' => $mapping['source_calendar_id'],
                    'event_id' => $mapping['source_event_id']
                ];
                
                if ($this->processOutlookDeletionCheck($checkData)) {
                    $results['deleted']++;
                }
                
                $results['checked']++;
                
            } catch (\Exception $e) {
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
