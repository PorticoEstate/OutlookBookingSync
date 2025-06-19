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
    /**
     * Get available resources/calendars
     * 
     * @param string|null $nameFilter Filter resources by name
     * @param int $limit Maximum number of resources to return (0 = no limit)
     * @param int $offset Number of resources to skip
     * @return array
     */
    abstract public function getAvailableResources($nameFilter = null, $limit = 0, $offset = 0): array;
    /**
     * Get available groups/collections
     * 
     * @param string|null $nameFilter Filter groups by name
     * @param int $limit Maximum number of groups to return (0 = no limit)
     * @param int $offset Number of groups to skip
     * @return array
     */
    abstract public function getAvailableGroups($nameFilter = null, $limit = 0, $offset = 0): array;
    /**
     * Get calendar items for a specific resource
     * 
     * @param string $resourceId The resource/calendar ID
     * @param string|null $startDate Start date filter
     * @param string|null $endDate End date filter
     * @return array
     */
    abstract public function getResourceCalendarItems($resourceId, $startDate = null, $endDate = null): array;
    
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
            
            // Basic connectivity test - try to get available resources
            $resources = $this->getAvailableResources();
            
            $responseTime = round((microtime(true) - $start) * 1000, 2);
            
            return [
                'status' => 'healthy',
                'bridge_type' => $this->getBridgeType(),
                'response_time_ms' => $responseTime,
                'calendars_count' => is_array($resources) ? count($resources) : (isset($resources['count']) ? $resources['count'] : 0),
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
    
    // Global Session Management System
    // =================================
    
    /**
     * Initialize session if not already started
     */
    protected function initializeSessionStorage(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * Get session storage prefix for this bridge
     */
    protected function getSessionPrefix(): string
    {
        $prefix = $this->config['session_prefix'] ?? 'bridge_';
        return $prefix . $this->getBridgeType() . '_';
    }
    
    /**
     * Store data in session with optional TTL
     * 
     * @param string $key Session key
     * @param mixed $data Data to store
     * @param int $ttl Time to live in seconds (0 = no expiration)
     */
    protected function setSession(string $key, $data, int $ttl = 0): void
    {
        $this->initializeSessionStorage();
        
        $sessionKey = $this->getSessionPrefix() . $key;
        $sessionData = [
            'data' => $data,
            'created_at' => time(),
            'ttl' => $ttl,
            'expires_at' => $ttl > 0 ? time() + $ttl : 0
        ];
        
        $_SESSION[$sessionKey] = $sessionData;
        
        $this->logOperation('session_set', [
            'key' => $key,
            'ttl' => $ttl,
            'expires_at' => $sessionData['expires_at']
        ]);
    }
    
    /**
     * Retrieve data from session
     * 
     * @param string $key Session key
     * @param mixed $default Default value if key doesn't exist or expired
     * @return mixed
     */
    protected function getSession(string $key, $default = null)
    {
        $this->initializeSessionStorage();
        
        $sessionKey = $this->getSessionPrefix() . $key;
        
        if (!isset($_SESSION[$sessionKey])) {
            return $default;
        }
        
        $sessionData = $_SESSION[$sessionKey];
        
        // Check if session data has expired
        if ($sessionData['expires_at'] > 0 && time() > $sessionData['expires_at']) {
            $this->clearSession($key);
            $this->logOperation('session_expired', [
                'key' => $key,
                'expired_at' => $sessionData['expires_at']
            ]);
            return $default;
        }
        
        return $sessionData['data'];
    }
    
    /**
     * Check if session key exists and is valid
     * 
     * @param string $key Session key
     * @return bool
     */
    protected function hasValidSession(string $key): bool
    {
        $this->initializeSessionStorage();
        
        $sessionKey = $this->getSessionPrefix() . $key;
        
        if (!isset($_SESSION[$sessionKey])) {
            return false;
        }
        
        $sessionData = $_SESSION[$sessionKey];
        
        // Check if session data has expired
        if ($sessionData['expires_at'] > 0 && time() > $sessionData['expires_at']) {
            $this->clearSession($key);
            return false;
        }
        
        return true;
    }
    
    /**
     * Remove data from session
     * 
     * @param string $key Session key
     */
    protected function clearSession(string $key): void
    {
        $this->initializeSessionStorage();
        
        $sessionKey = $this->getSessionPrefix() . $key;
        
        if (isset($_SESSION[$sessionKey])) {
            unset($_SESSION[$sessionKey]);
            $this->logOperation('session_cleared', ['key' => $key]);
        }
    }
    
    /**
     * Clear all sessions for this bridge
     */
    protected function clearAllSessions(): void
    {
        $this->initializeSessionStorage();
        
        $prefix = $this->getSessionPrefix();
        $clearedKeys = [];
        
        foreach ($_SESSION as $sessionKey => $sessionData) {
            if (strpos($sessionKey, $prefix) === 0) {
                unset($_SESSION[$sessionKey]);
                $clearedKeys[] = str_replace($prefix, '', $sessionKey);
            }
        }
        
        if (!empty($clearedKeys)) {
            $this->logOperation('session_cleared_all', ['keys' => $clearedKeys]);
        }
    }
    
    /**
     * Update session TTL for existing key
     * 
     * @param string $key Session key
     * @param int $ttl New TTL in seconds
     * @return bool True if updated, false if key doesn't exist
     */
    protected function updateSessionTTL(string $key, int $ttl): bool
    {
        $this->initializeSessionStorage();
        
        $sessionKey = $this->getSessionPrefix() . $key;
        
        if (!isset($_SESSION[$sessionKey])) {
            return false;
        }
        
        $_SESSION[$sessionKey]['ttl'] = $ttl;
        $_SESSION[$sessionKey]['expires_at'] = $ttl > 0 ? time() + $ttl : 0;
        
        $this->logOperation('session_ttl_updated', [
            'key' => $key,
            'ttl' => $ttl,
            'expires_at' => $_SESSION[$sessionKey]['expires_at']
        ]);
        
        return true;
    }
    
    /**
     * Get session statistics for this bridge
     * 
     * @return array
     */
    protected function getSessionStats(): array
    {
        $this->initializeSessionStorage();
        
        $prefix = $this->getSessionPrefix();
        $stats = [
            'total_sessions' => 0,
            'active_sessions' => 0,
            'expired_sessions' => 0,
            'sessions' => []
        ];
        
        foreach ($_SESSION as $sessionKey => $sessionData) {
            if (strpos($sessionKey, $prefix) === 0) {
                $stats['total_sessions']++;
                $key = str_replace($prefix, '', $sessionKey);
                
                $isExpired = $sessionData['expires_at'] > 0 && time() > $sessionData['expires_at'];
                
                if ($isExpired) {
                    $stats['expired_sessions']++;
                } else {
                    $stats['active_sessions']++;
                }
                
                $stats['sessions'][$key] = [
                    'created_at' => $sessionData['created_at'],
                    'ttl' => $sessionData['ttl'],
                    'expires_at' => $sessionData['expires_at'],
                    'expired' => $isExpired,
                    'age_seconds' => time() - $sessionData['created_at']
                ];
            }
        }
        
        return $stats;
    }
}
