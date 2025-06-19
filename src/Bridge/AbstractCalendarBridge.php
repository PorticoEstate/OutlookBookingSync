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
    
    /** @var array Session data cache for CLI mode */
    protected $sessionData = null;
    
    /** @var string Session file path for CLI mode */
    protected $sessionFile = null;
    
    /** @var bool Whether we're in CLI mode */
    protected $isCliMode = null;
    
    /**
     * Check if we're running in CLI mode or should use file-based sessions
     */
    protected function isCliMode(): bool
    {
        if ($this->isCliMode === null) {
            // Check if explicitly configured to use file-based sessions
            $forceFileSession = $this->config['force_file_session'] ?? false;
            
            // Use file-based sessions if:
            // 1. Running in actual CLI mode
            // 2. Running as CLI server (php -S)
            // 3. Explicitly configured to use file-based sessions
            // 4. API requests (detected by certain headers or paths)
            $isApiRequest = $this->isApiRequest();
            
            $this->isCliMode = (
                php_sapi_name() === 'cli' || 
                php_sapi_name() === 'cli-server' ||
                $forceFileSession ||
                $isApiRequest
            );
        }
        return $this->isCliMode;
    }
    
    /**
     * Detect if this is an API request that should use file-based sessions
     */
    protected function isApiRequest(): bool
    {
        // Check for API request indicators
        if (isset($_SERVER['REQUEST_URI'])) {
            $uri = $_SERVER['REQUEST_URI'];
            
            // API endpoints that should use file-based sessions
            $apiPatterns = [
                '/api/',
                '/bridges/',
                '/webhook/',
                '/sync/'
            ];
            
            foreach ($apiPatterns as $pattern) {
                if (strpos($uri, $pattern) !== false) {
                    return true;
                }
            }
        }
        
        // Check for API-style headers
        $apiHeaders = [
            'HTTP_X_API_KEY',
            'HTTP_AUTHORIZATION',
            'HTTP_X_REQUESTED_WITH'
        ];
        
        foreach ($apiHeaders as $header) {
            if (isset($_SERVER[$header])) {
                return true;
            }
        }
        
        // Check Content-Type for API requests
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $contentType = $_SERVER['CONTENT_TYPE'];
            if (strpos($contentType, 'application/json') !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Initialize session storage based on environment
     */
    protected function initializeSessionStorage(): void
    {
        if ($this->isCliMode()) {
            $this->initializeFileBasedSession();
        } else {
            $this->initializeWebSession();
        }
    }

    /**
     * Initialize file-based session storage for CLI/API usage
     */
    protected function initializeFileBasedSession(): void
    {
        if ($this->sessionData !== null) {
            return; // Already initialized
        }
        
        // Create session directory in the project root (persistent across container restarts)
        $projectRoot = dirname(dirname(__DIR__)); // Go up from src/Bridge to project root
        $sessionDir = $projectRoot . '/storage/sessions';
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0755, true);
        }

        // Generate session file path
        $sessionId = $this->generateConsistentSessionId();
        $this->sessionFile = $sessionDir . '/session_' . $sessionId . '.json';

        // Load existing session data from file
        $this->sessionData = [];
        if (file_exists($this->sessionFile)) {
            $sessionContent = file_get_contents($this->sessionFile);
            $loadedData = json_decode($sessionContent, true);
            
            if ($loadedData && is_array($loadedData)) {
                $this->sessionData = $loadedData;
            }
        }

        $this->logOperation('file_session_initialized', [
            'session_file' => basename($this->sessionFile),
            'session_dir' => $sessionDir,
            'session_exists' => file_exists($this->sessionFile),
            'session_data_count' => count($this->sessionData),
            'sapi' => php_sapi_name()
        ]);
    }

    /**
     * Save session data to file (CLI mode only)
     */
    protected function saveSessionToFile(): void
    {
        if (!$this->isCliMode() || $this->sessionFile === null || $this->sessionData === null) {
            return;
        }
        
        // Clean expired sessions before saving
        $this->cleanExpiredSessions();
        
        $jsonData = json_encode($this->sessionData, JSON_PRETTY_PRINT);
        file_put_contents($this->sessionFile, $jsonData);
        
        $this->logOperation('session_saved_to_file', [
            'session_file' => basename($this->sessionFile),
            'data_count' => count($this->sessionData)
        ]);
    }

    /**
     * Initialize web-based session storage
     */
    protected function initializeWebSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Set session configuration before starting
            $sessionName = $this->config['session_name'] ?? 'BRIDGE_SESSION';
            $sessionLifetime = $this->config['session_lifetime'] ?? 3600; // 1 hour default
            
            // Configure session settings
            ini_set('session.name', $sessionName);
            ini_set('session.gc_maxlifetime', $sessionLifetime);
            ini_set('session.cookie_lifetime', $sessionLifetime);
            
            session_start();
            
            // Log session initialization for debugging
            $this->logOperation('web_session_initialized', [
                'session_name' => $sessionName,
                'session_id' => substr(session_id(), 0, 8) . '...',
                'sapi' => php_sapi_name(),
                'lifetime' => $sessionLifetime
            ]);
        }
    }
    
    /**
     * Generate a consistent session ID based on bridge configuration
     * This ensures the same session is used across API calls for the same bridge
     */
    protected function generateConsistentSessionId(): string
    {
        // Create session ID based on bridge type and configuration
        $identifier = $this->getBridgeType() . '_' . 
                     ($this->config['api_base_url'] ?? 'default') . '_' .
                     ($this->config['system_login'] ?? 'anonymous');
        
        // Generate a consistent hash that will be the same across requests
        return 'bridge_' . substr(md5($identifier), 0, 24);
    }
    /**
     * Clean expired sessions from storage
     */
    protected function cleanExpiredSessions(): void
    {
        if ($this->isCliMode()) {
            if ($this->sessionData === null) {
                return;
            }
            
            $cleaned = [];
            foreach ($this->sessionData as $key => $sessionData) {
                if (isset($sessionData['expires_at']) && $sessionData['expires_at'] > 0 && time() > $sessionData['expires_at']) {
                    unset($this->sessionData[$key]);
                    $cleaned[] = $key;
                }
            }
            
            if (!empty($cleaned)) {
                $this->logOperation('expired_sessions_cleaned', ['keys' => $cleaned]);
            }
        } else {
            // For web mode, PHP handles garbage collection automatically
            // but we can clean up manually if needed
            $prefix = $this->getSessionPrefix();
            $cleaned = [];
            
            foreach ($_SESSION as $sessionKey => $sessionData) {
                if (strpos($sessionKey, $prefix) === 0 && 
                    isset($sessionData['expires_at']) && 
                    $sessionData['expires_at'] > 0 && 
                    time() > $sessionData['expires_at']) {
                    unset($_SESSION[$sessionKey]);
                    $cleaned[] = str_replace($prefix, '', $sessionKey);
                }
            }
            
            if (!empty($cleaned)) {
                $this->logOperation('expired_sessions_cleaned', ['keys' => $cleaned]);
            }
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
        
        if ($this->isCliMode()) {
            $this->sessionData[$sessionKey] = $sessionData;
            $this->saveSessionToFile();
        } else {
            $_SESSION[$sessionKey] = $sessionData;
        }
        
        $this->logOperation('session_set', [
            'key' => $key,
            'ttl' => $ttl,
            'expires_at' => $sessionData['expires_at'],
            'mode' => $this->isCliMode() ? 'file' : 'web'
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
        $sessionData = null;
        
        if ($this->isCliMode()) {
            $sessionData = $this->sessionData[$sessionKey] ?? null;
        } else {
            $sessionData = $_SESSION[$sessionKey] ?? null;
        }
        
        if ($sessionData === null) {
            return $default;
        }
        
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
        $sessionData = null;
        
        if ($this->isCliMode()) {
            $sessionData = $this->sessionData[$sessionKey] ?? null;
        } else {
            $sessionData = $_SESSION[$sessionKey] ?? null;
        }
        
        if ($sessionData === null) {
            return false;
        }
        
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
        
        if ($this->isCliMode()) {
            if (isset($this->sessionData[$sessionKey])) {
                unset($this->sessionData[$sessionKey]);
                $this->saveSessionToFile();
                $this->logOperation('session_cleared', ['key' => $key, 'mode' => 'file']);
            }
        } else {
            if (isset($_SESSION[$sessionKey])) {
                unset($_SESSION[$sessionKey]);
                $this->logOperation('session_cleared', ['key' => $key, 'mode' => 'web']);
            }
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
        
        if ($this->isCliMode()) {
            foreach ($this->sessionData as $sessionKey => $sessionData) {
                if (strpos($sessionKey, $prefix) === 0) {
                    unset($this->sessionData[$sessionKey]);
                    $clearedKeys[] = str_replace($prefix, '', $sessionKey);
                }
            }
            if (!empty($clearedKeys)) {
                $this->saveSessionToFile();
            }
        } else {
            foreach ($_SESSION as $sessionKey => $sessionData) {
                if (strpos($sessionKey, $prefix) === 0) {
                    unset($_SESSION[$sessionKey]);
                    $clearedKeys[] = str_replace($prefix, '', $sessionKey);
                }
            }
        }
        
        if (!empty($clearedKeys)) {
            $this->logOperation('session_cleared_all', [
                'keys' => $clearedKeys,
                'mode' => $this->isCliMode() ? 'file' : 'web'
            ]);
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
        $sessionData = null;
        
        if ($this->isCliMode()) {
            $sessionData = $this->sessionData[$sessionKey] ?? null;
        } else {
            $sessionData = $_SESSION[$sessionKey] ?? null;
        }
        
        if ($sessionData === null) {
            return false;
        }
        
        $sessionData['ttl'] = $ttl;
        $sessionData['expires_at'] = $ttl > 0 ? time() + $ttl : 0;
        
        if ($this->isCliMode()) {
            $this->sessionData[$sessionKey] = $sessionData;
            $this->saveSessionToFile();
        } else {
            $_SESSION[$sessionKey] = $sessionData;
        }
        
        $this->logOperation('session_ttl_updated', [
            'key' => $key,
            'ttl' => $ttl,
            'expires_at' => $sessionData['expires_at'],
            'mode' => $this->isCliMode() ? 'file' : 'web'
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
            'sessions' => [],
            'mode' => $this->isCliMode() ? 'file' : 'web'
        ];
        
        $sessionStore = $this->isCliMode() ? $this->sessionData : $_SESSION;
        
        foreach ($sessionStore as $sessionKey => $sessionData) {
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
    
    /**
     * Debug session information
     */
    protected function debugSession(): array
    {
        $this->initializeSessionStorage();
        
        $debug = [
            'php_sapi' => php_sapi_name(),
            'mode' => $this->isCliMode() ? 'file' : 'web',
            'bridge_type' => $this->getBridgeType(),
            'session_prefix' => $this->getSessionPrefix(),
            'detection_info' => [
                'is_cli_sapi' => php_sapi_name() === 'cli',
                'is_cli_server' => php_sapi_name() === 'cli-server',
                'force_file_session' => $this->config['force_file_session'] ?? false,
                'is_api_request' => $this->isApiRequest(),
                'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
                'http_method' => $_SERVER['REQUEST_METHOD'] ?? null
            ]
        ];
        
        if ($this->isCliMode()) {
            $debug['session_file'] = $this->sessionFile;
            $debug['session_file_exists'] = file_exists($this->sessionFile);
            $debug['session_data_count'] = count($this->sessionData);
            $debug['session_data_keys'] = array_keys($this->sessionData);
            $debug['bridge_sessions'] = array_filter(array_keys($this->sessionData), function($key) {
                return strpos($key, $this->getSessionPrefix()) === 0;
            });
        } else {
            $debug['session_status'] = session_status();
            $debug['session_id'] = session_id();
            $debug['session_name'] = session_name();
            $debug['session_data_count'] = count($_SESSION);
            $debug['session_data_keys'] = array_keys($_SESSION);
            $debug['bridge_sessions'] = array_filter(array_keys($_SESSION), function($key) {
                return strpos($key, $this->getSessionPrefix()) === 0;
            });
            $debug['session_cookie_params'] = session_get_cookie_params();
        }
        
        return $debug;
    }
}
