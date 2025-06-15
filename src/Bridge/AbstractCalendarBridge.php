<?php

namespace App\Bridge;

use Psr\Log\LoggerInterface;
use PDO;

abstract class AbstractCalendarBridge
{
    protected $config;
    protected $logger;
    protected $db;
    
    public function __construct($config, LoggerInterface $logger, PDO $db)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->db = $db;
        
        $this->validateConfig();
        $this->initialize();
    }
    
    // Abstract methods that each bridge must implement
    abstract public function getEvents($calendarId, $startDate, $endDate): array;
    abstract public function createEvent($calendarId, $event): string;
    abstract public function updateEvent($calendarId, $eventId, $event): bool;
    abstract public function deleteEvent($calendarId, $eventId): bool;
    abstract public function getCalendars(): array;
    abstract public function subscribeToChanges($calendarId, $webhookUrl): string;
    abstract public function unsubscribeFromChanges($subscriptionId): bool;
    abstract public function getBridgeType(): string;
    
    // Resource discovery methods (added for generic bridge pattern)
    abstract public function getAvailableResources(): array;
    abstract public function getAvailableGroups(): array;
    abstract public function getUserCalendarItems($userId, $startDate = null, $endDate = null): array;
    
    // Optional methods with default implementations
    public function validateEvent($event): bool
    {
        $required = ['subject', 'start', 'end'];
        
        foreach ($required as $field) {
            if (!isset($event[$field]) || empty($event[$field])) {
                return false;
            }
        }
        
        // Validate date format
        if (!$this->isValidDateTime($event['start']) || !$this->isValidDateTime($event['end'])) {
            return false;
        }
        
        // Validate start is before end
        if (strtotime($event['start']) >= strtotime($event['end'])) {
            return false;
        }
        
        return true;
    }
    
    public function formatEventForBridge($genericEvent): array
    {
        // Default implementation - bridges can override
        return $genericEvent;
    }
    
    public function formatEventFromBridge($bridgeEvent): array
    {
        // Default implementation - bridges can override
        return $bridgeEvent;
    }
    
    public function getCapabilities(): array
    {
        return [
            'supports_webhooks' => false,
            'supports_recurring' => false,
            'supports_all_day' => false,
            'supports_attendees' => false,
            'supports_attachments' => false,
            'max_events_per_request' => 100,
            'rate_limit_per_minute' => 60
        ];
    }
    
    protected function logOperation($operation, $data = [])
    {
        $this->logger->info("Bridge operation: {$operation}", [
            'bridge_type' => $this->getBridgeType(),
            'operation' => $operation,
            'data' => $data
        ]);
    }
    
    protected function logError($operation, $error, $data = [])
    {
        $this->logger->error("Bridge operation failed: {$operation}", [
            'bridge_type' => $this->getBridgeType(),
            'operation' => $operation,
            'error' => $error,
            'data' => $data
        ]);
    }
    
    protected function isValidDateTime($dateString): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d H:i:s', $dateString);
        if ($date === false) {
            $date = \DateTime::createFromFormat('c', $dateString); // ISO 8601
        }
        
        return $date !== false;
    }
    
    protected function normalizeDateTime($dateString): string
    {
        $date = \DateTime::createFromFormat('Y-m-d H:i:s', $dateString);
        if ($date === false) {
            $date = new \DateTime($dateString);
        }
        
        return $date->format('c'); // ISO 8601 format
    }
    
    // Template method pattern for initialization
    protected function initialize()
    {
        // Override in concrete bridges if needed
    }
    
    protected function validateConfig()
    {
        // Override in concrete bridges to validate specific config requirements
    }
    
    // Health check method
    public function healthCheck(): array
    {
        try {
            $start = microtime(true);
            
            // Basic connectivity test - try to get calendars
            $calendars = $this->getCalendars();
            
            $responseTime = round((microtime(true) - $start) * 1000, 2);
            
            return [
                'status' => 'healthy',
                'bridge_type' => $this->getBridgeType(),
                'response_time_ms' => $responseTime,
                'calendars_count' => count($calendars),
                'capabilities' => $this->getCapabilities(),
                'timestamp' => date('c')
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'bridge_type' => $this->getBridgeType(),
                'error' => $e->getMessage(),
                'timestamp' => date('c')
            ];
        }
    }
    
    // Generic event format for internal use
    protected function createGenericEvent($data): array
    {
        return [
            'id' => $data['id'] ?? null,
            'subject' => $data['subject'] ?? $data['title'] ?? '',
            'start' => $this->normalizeDateTime($data['start']),
            'end' => $this->normalizeDateTime($data['end']),
            'location' => $data['location'] ?? '',
            'description' => $data['description'] ?? $data['body'] ?? '',
            'attendees' => $data['attendees'] ?? [],
            'organizer' => $data['organizer'] ?? '',
            'all_day' => $data['all_day'] ?? false,
            'timezone' => $data['timezone'] ?? 'UTC',
            'bridge_type' => $this->getBridgeType(),
            'external_id' => $data['id'] ?? null,
            'last_modified' => $data['last_modified'] ?? date('c'),
            'created' => $data['created'] ?? date('c'),
            'raw_data' => $data
        ];
    }
}
