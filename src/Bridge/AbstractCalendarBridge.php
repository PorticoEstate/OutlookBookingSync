<?php

namespace App\Bridge;

use Psr\Log\LoggerInterface;
use PDO;

/**
 * Base class for calendar bridge implementations, providing common utilities
 * for event normalization, logging, session handling, and tenant-aware helpers.
 */
abstract class AbstractCalendarBridge
{
    protected $config;
    protected $logger;
    protected $db;

    // Simple session storage helpers
    protected $sessionData = null; // for CLI file-based sessions
    protected $sessionFile = null;
    protected $isCliMode = null;

    /**
     * @param array $config Bridge configuration; may include context_tenant_id for scoping
     * @param LoggerInterface $logger
     * @param PDO $db
     */
    public function __construct($config, LoggerInterface $logger, PDO $db)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->db = $db;

        $this->validateConfig();
        $this->initialize();
    }

    // Abstract methods that each bridge must implement
    /** @param string $calendarId @param string $startDate @param string $endDate @return array<int,array<string,mixed>> */
    abstract public function getEvents($calendarId, $startDate, $endDate): array;
    /** @param string $calendarId @param array $event @return string Newly created event ID */
    abstract public function createEvent($calendarId, $event): string;
    /** @param string $calendarId @param string $eventId @param array $event @return bool */
    abstract public function updateEvent($calendarId, $eventId, $event): bool;
    /** @param string $calendarId @param string $eventId @return bool */
    abstract public function deleteEvent($calendarId, $eventId): bool;
    /** @return array<int,array<string,mixed>> */
    abstract public function getCalendars(): array;
    /** @param string $calendarId @param string $webhookUrl @return string Subscription ID */
    abstract public function subscribeToChanges($calendarId, $webhookUrl): string;
    /** @param string $subscriptionId @return bool */
    abstract public function unsubscribeFromChanges($subscriptionId): bool;
    /** @return string Bridge name/type identifier */
    abstract public function getBridgeType(): string;

    // Resource discovery methods
    /** @param string|null $nameFilter @param int $limit @param int $offset @return array */
    abstract public function getAvailableResources($nameFilter = null, $limit = 0, $offset = 0): array;
    /** @param string|null $nameFilter @param int $limit @param int $offset @return array */
    abstract public function getAvailableGroups($nameFilter = null, $limit = 0, $offset = 0): array;
    /** @param string $resourceId @param string|null $startDate @param string|null $endDate @return array */
    abstract public function getResourceCalendarItems($resourceId, $startDate = null, $endDate = null): array;

    // Optional helpers
    public function validateEvent($event): bool
    {
        $required = ['subject', 'start', 'end'];
        foreach ($required as $field)
        {
            if (!isset($event[$field]) || empty($event[$field]))
            {
                return false;
            }
        }
        try
        {
            $startDateTime = new \DateTime($event['start']);
            $endDateTime = new \DateTime($event['end']);
            if ($startDateTime >= $endDateTime)
            {
                return false;
            }
        }
        catch (\Exception $e)
        {
            return false;
        }
        return true;
    }

    public function formatEventForBridge($genericEvent): array
    {
        return $genericEvent;
    }
    public function formatEventFromBridge($bridgeEvent): array
    {
        return $bridgeEvent;
    }

    /**
     * @return array{supports_webhooks:bool,supports_recurring:bool,supports_all_day:bool,supports_attendees:bool,supports_attachments:bool,max_events_per_request:int,rate_limit_per_minute:int}
     */
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

    /** @param string $operation @param array $data */
    protected function logOperation($operation, $data = [])
    {
        $this->logger->info("Bridge operation: {$operation}", [
            'bridge_type' => $this->getBridgeType(),
            'operation' => $operation,
            'data' => $data
        ]);
    }

    /** @param string $operation @param string|array $error @param array $data */
    protected function logError($operation, $error, $data = [])
    {
        $this->logger->error("Bridge operation failed: {$operation}", [
            'bridge_type' => $this->getBridgeType(),
            'operation' => $operation,
            'error' => $error,
            'data' => $data
        ]);
    }

    /** @param string $dateString */
    protected function isValidDateTime($dateString): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d H:i:s', $dateString);
        if ($date !== false)
        {
            return true;
        }
        $date = \DateTime::createFromFormat('c', $dateString);
        if ($date !== false)
        {
            return true;
        }
        try
        {
            new \DateTime($dateString);
            return true;
        }
        catch (\Exception $e)
        {
            return false;
        }
    }

    /** @param string $dateString */
    protected function normalizeDateTime($dateString): string
    {
        if (empty($dateString))
        {
            return '';
        }

        try
        {
            // First try to parse with specific format
            $date = \DateTime::createFromFormat('Y-m-d H:i:s', $dateString);
            if ($date === false)
            {
                // Fallback to generic parsing which handles various formats including ISO8601
                $date = new \DateTime($dateString);
            }

            // Always convert to UTC for consistent storage and comparison
            $date->setTimezone(new \DateTimeZone('UTC'));

            // Return in ISO8601 format with UTC timezone
            return $date->format('c');
        }
        catch (\Exception $e)
        {
            // Log the error and return empty string as fallback
            $this->logger->warning('Failed to normalize datetime', [
                'input' => $dateString,
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }

    /** Hook for bridge-specific initialization. */
    protected function initialize()
    {
    }
    /** Hook for bridge-specific validation. */
    protected function validateConfig()
    {
    }

    /** Basic health probe for the bridge. */
    public function healthCheck(): array
    {
        try
        {
            $start = microtime(true);
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
        }
        catch (\Exception $e)
        {
            return [
                'status' => 'unhealthy',
                'bridge_type' => $this->getBridgeType(),
                'error' => $e->getMessage(),
                'timestamp' => date('c')
            ];
        }
    }

    /** @param array $data @return array Generic normalized event */
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

    // ----------------------
    // Session management
    // ----------------------

    protected function isCliMode(): bool
    {
        if ($this->isCliMode === null)
        {
            $forceFileSession = $this->config['force_file_session'] ?? false;
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

    protected function isApiRequest(): bool
    {
        if (isset($_SERVER['REQUEST_URI']))
        {
            $uri = $_SERVER['REQUEST_URI'];
            foreach (['/api/', '/bridges/', '/webhook/', '/sync/'] as $pattern)
            {
                if (strpos($uri, $pattern) !== false)
                {
                    return true;
                }
            }
        }
        foreach (['HTTP_X_API_KEY', 'HTTP_AUTHORIZATION', 'HTTP_X_REQUESTED_WITH'] as $header)
        {
            if (isset($_SERVER[$header]))
            {
                return true;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
        {
            return true;
        }
        return false;
    }

    protected function initializeSessionStorage(): void
    {
        if ($this->isCliMode())
        {
            $this->initializeFileBasedSession();
        }
        else
        {
            $this->initializeWebSession();
        }
    }

    protected function initializeFileBasedSession(): void
    {
        if ($this->sessionData !== null)
        {
            return;
        }
        $projectRoot = dirname(dirname(__DIR__));
        $sessionDir = $projectRoot . '/storage/sessions';
        if (!is_dir($sessionDir))
        {
            mkdir($sessionDir, 0755, true);
        }
        $sessionId = $this->generateConsistentSessionId();
        $this->sessionFile = $sessionDir . '/session_' . $sessionId . '.json';
        $this->sessionData = [];
        if (file_exists($this->sessionFile))
        {
            $loadedData = json_decode(file_get_contents($this->sessionFile), true);
            if ($loadedData && is_array($loadedData))
            {
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

    protected function saveSessionToFile(): void
    {
        if (!$this->isCliMode() || $this->sessionFile === null || $this->sessionData === null)
        {
            return;
        }
        file_put_contents($this->sessionFile, json_encode($this->sessionData, JSON_PRETTY_PRINT));
        $this->logOperation('session_saved_to_file', ['session_file' => basename($this->sessionFile), 'data_count' => count($this->sessionData)]);
    }

    protected function initializeWebSession(): void
    {
        if (session_status() === PHP_SESSION_NONE)
        {
            $sessionName = $this->config['session_name'] ?? 'BRIDGE_SESSION';
            $sessionLifetime = $this->config['session_lifetime'] ?? 3600;
            ini_set('session.name', $sessionName);
            ini_set('session.gc_maxlifetime', $sessionLifetime);
            ini_set('session.cookie_lifetime', $sessionLifetime);
            session_start();
            $this->logOperation('web_session_initialized', [
                'session_name' => $sessionName,
                'session_id' => substr(session_id(), 0, 8) . '...',
                'sapi' => php_sapi_name(),
                'lifetime' => $sessionLifetime
            ]);
        }
    }

    protected function generateConsistentSessionId(): string
    {
        $identifier = $this->getBridgeType() . '_' . ($this->config['api_base_url'] ?? 'default') . '_' . ($this->config['system_login'] ?? 'anonymous');
        return 'bridge_' . substr(md5($identifier), 0, 24);
    }

    protected function getSessionPrefix(): string
    {
        $prefix = $this->config['session_prefix'] ?? 'bridge_';
        return $prefix . $this->getBridgeType() . '_';
    }

    protected function setSession(string $key, $data, int $ttl = 0): void
    {
        $this->initializeSessionStorage();
        $sessionKey = $this->getSessionPrefix() . $key;
        $sessionData = ['data' => $data, 'created_at' => time(), 'ttl' => $ttl, 'expires_at' => $ttl > 0 ? time() + $ttl : 0];
        if ($this->isCliMode())
        {
            $this->sessionData[$sessionKey] = $sessionData;
            $this->saveSessionToFile();
        }
        else
        {
            $_SESSION[$sessionKey] = $sessionData;
        }
        $this->logOperation('session_set', ['key' => $key, 'ttl' => $ttl, 'expires_at' => $sessionData['expires_at'], 'mode' => $this->isCliMode() ? 'file' : 'web']);
    }

    protected function getSession(string $key, $default = null)
    {
        $this->initializeSessionStorage();
        $sessionKey = $this->getSessionPrefix() . $key;
        $sessionData = $this->isCliMode() ? ($this->sessionData[$sessionKey] ?? null) : ($_SESSION[$sessionKey] ?? null);
        if ($sessionData === null)
        {
            return $default;
        }
        if ($sessionData['expires_at'] > 0 && time() > $sessionData['expires_at'])
        {
            $this->clearSession($key);
            $this->logOperation('session_expired', ['key' => $key, 'expired_at' => $sessionData['expires_at']]);
            return $default;
        }
        return $sessionData['data'];
    }

    protected function hasValidSession(string $key): bool
    {
        $this->initializeSessionStorage();
        $sessionKey = $this->getSessionPrefix() . $key;
        $sessionData = $this->isCliMode() ? ($this->sessionData[$sessionKey] ?? null) : ($_SESSION[$sessionKey] ?? null);
        if ($sessionData === null)
        {
            return false;
        }
        if ($sessionData['expires_at'] > 0 && time() > $sessionData['expires_at'])
        {
            $this->clearSession($key);
            return false;
        }
        return true;
    }

    protected function clearSession(string $key): void
    {
        $this->initializeSessionStorage();
        $sessionKey = $this->getSessionPrefix() . $key;
        if ($this->isCliMode())
        {
            if (isset($this->sessionData[$sessionKey]))
            {
                unset($this->sessionData[$sessionKey]);
                $this->saveSessionToFile();
                $this->logOperation('session_cleared', ['key' => $key, 'mode' => 'file']);
            }
        }
        else
        {
            if (isset($_SESSION[$sessionKey]))
            {
                unset($_SESSION[$sessionKey]);
                $this->logOperation('session_cleared', ['key' => $key, 'mode' => 'web']);
            }
        }
    }

    protected function clearAllSessions(): void
    {
        $this->initializeSessionStorage();
        $prefix = $this->getSessionPrefix();
        $clearedKeys = [];
        if ($this->isCliMode())
        {
            foreach ($this->sessionData as $sessionKey => $sessionData)
            {
                if (strpos($sessionKey, $prefix) === 0)
                {
                    unset($this->sessionData[$sessionKey]);
                    $clearedKeys[] = str_replace($prefix, '', $sessionKey);
                }
            }
            if (!empty($clearedKeys))
            {
                $this->saveSessionToFile();
            }
        }
        else
        {
            foreach ($_SESSION as $sessionKey => $sessionData)
            {
                if (strpos($sessionKey, $prefix) === 0)
                {
                    unset($_SESSION[$sessionKey]);
                    $clearedKeys[] = str_replace($prefix, '', $sessionKey);
                }
            }
        }
        if (!empty($clearedKeys))
        {
            $this->logOperation('session_cleared_all', ['keys' => $clearedKeys, 'mode' => $this->isCliMode() ? 'file' : 'web']);
        }
    }

    protected function updateSessionTTL(string $key, int $ttl): bool
    {
        $this->initializeSessionStorage();
        $sessionKey = $this->getSessionPrefix() . $key;
        $sessionData = $this->isCliMode() ? ($this->sessionData[$sessionKey] ?? null) : ($_SESSION[$sessionKey] ?? null);
        if ($sessionData === null)
        {
            return false;
        }
        $sessionData['ttl'] = $ttl;
        $sessionData['expires_at'] = $ttl > 0 ? time() + $ttl : 0;
        if ($this->isCliMode())
        {
            $this->sessionData[$sessionKey] = $sessionData;
            $this->saveSessionToFile();
        }
        else
        {
            $_SESSION[$sessionKey] = $sessionData;
        }
        $this->logOperation('session_ttl_updated', ['key' => $key, 'ttl' => $ttl, 'expires_at' => $sessionData['expires_at'], 'mode' => $this->isCliMode() ? 'file' : 'web']);
        return true;
    }

    protected function getSessionStats(): array
    {
        $this->initializeSessionStorage();
        $prefix = $this->getSessionPrefix();
        $stats = ['total_sessions' => 0, 'active_sessions' => 0, 'expired_sessions' => 0, 'sessions' => [], 'mode' => $this->isCliMode() ? 'file' : 'web'];
        $sessionStore = $this->isCliMode() ? $this->sessionData : $_SESSION;
        foreach ($sessionStore as $sessionKey => $sessionData)
        {
            if (strpos($sessionKey, $prefix) === 0)
            {
                $stats['total_sessions']++;
                $key = str_replace($prefix, '', $sessionKey);
                $isExpired = $sessionData['expires_at'] > 0 && time() > $sessionData['expires_at'];
                if ($isExpired)
                {
                    $stats['expired_sessions']++;
                }
                else
                {
                    $stats['active_sessions']++;
                }
                $stats['sessions'][$key] = ['created_at' => $sessionData['created_at'], 'ttl' => $sessionData['ttl'], 'expires_at' => $sessionData['expires_at'], 'expired' => $isExpired, 'age_seconds' => time() - $sessionData['created_at']];
            }
        }
        return $stats;
    }

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
        if ($this->isCliMode())
        {
            $debug['session_file'] = $this->sessionFile;
            $debug['session_file_exists'] = file_exists($this->sessionFile);
            $debug['session_data_count'] = count($this->sessionData);
            $debug['session_data_keys'] = array_keys($this->sessionData);
            $debug['bridge_sessions'] = array_filter(array_keys($this->sessionData), function ($key)
            {
                return strpos($key, $this->getSessionPrefix()) === 0;
            });
        }
        else
        {
            $debug['session_status'] = session_status();
            $debug['session_id'] = session_id();
            $debug['session_name'] = session_name();
            $debug['session_data_count'] = count($_SESSION);
            $debug['session_data_keys'] = array_keys($_SESSION);
            $debug['bridge_sessions'] = array_filter(array_keys($_SESSION), function ($key)
            {
                return strpos($key, $this->getSessionPrefix()) === 0;
            });
            $debug['session_cookie_params'] = session_get_cookie_params();
        }
        return $debug;
    }

    // ----------------------
    // Tenant-aware mapping helpers
    // ----------------------

    public function updateSyncStatus($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $sourceEventId, $status, $errorMessage = null): bool
    {
        try
        {
            $tenantId = $this->config['context_tenant_id'] ?? null;
            $sql = "
                UPDATE bridge_mappings 
                SET sync_status = ?, 
                    error_message = ?,
                    retry_count = CASE 
                        WHEN ? = 'error' THEN retry_count + 1 
                        WHEN ? = 'synced' THEN 0 
                        ELSE retry_count 
                    END,
                    updated_at = CURRENT_TIMESTAMP,
                    last_synced_at = CASE WHEN ? = 'synced' THEN CURRENT_TIMESTAMP ELSE last_synced_at END
                WHERE source_bridge = ? 
                    AND target_bridge = ? 
                    AND source_calendar_id = ? 
                    AND target_calendar_id = ? 
                    AND source_event_id = ?
            ";
            if ($tenantId !== null)
            {
                $sql .= " AND (tenant_id IS NOT DISTINCT FROM ? )";
            }
            $stmt = $this->db->prepare($sql);
            $params = [$status, $errorMessage, $status, $status, $status, $sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $sourceEventId];
            if ($tenantId !== null)
            {
                $params[] = (string)$tenantId;
            }
            return $stmt->execute($params);
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to update sync status', ['error' => $e->getMessage(), 'source_bridge' => $sourceBridge, 'target_bridge' => $targetBridge, 'source_event_id' => $sourceEventId, 'status' => $status]);
            return false;
        }
    }

    protected function createEventMapping($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $sourceEventId, $targetEventId, $eventData = null, $syncDirection = 'source_to_target'): bool
    {
        try
        {
            $tenantId = $this->config['context_tenant_id'] ?? null;
            $stmt = $this->db->prepare("INSERT INTO bridge_mappings
            (source_bridge, target_bridge, source_calendar_id, target_calendar_id,
            source_event_id, target_event_id, sync_direction, sync_status, event_data, tenant_id,
            created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (source_bridge, target_bridge, source_calendar_id, target_calendar_id, source_event_id, tenant_id)
            DO UPDATE SET
                target_event_id = EXCLUDED.target_event_id,
                sync_status = 'synced',
                event_data = EXCLUDED.event_data,
                updated_at = CURRENT_TIMESTAMP,
                last_synced_at = CURRENT_TIMESTAMP,
                retry_count = 0,
                error_message = NULL
            ");
            return $stmt->execute([$sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $sourceEventId, $targetEventId, $syncDirection, json_encode($eventData), $tenantId]);
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to create event mapping', ['error' => $e->getMessage(), 'source_bridge' => $sourceBridge, 'target_bridge' => $targetBridge, 'source_event_id' => $sourceEventId, 'target_event_id' => $targetEventId]);
            return false;
        }
    }

    protected function markEventCancelled($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $sourceEventId): bool
    {
        return $this->updateSyncStatus($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $sourceEventId, 'cancelled');
    }

    protected function markEventPending($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $sourceEventId): bool
    {
        try
        {
            $tenantId = $this->config['context_tenant_id'] ?? null;
            $sql = "\n                UPDATE bridge_mappings \n                SET sync_status = 'pending', target_event_id = '', error_message = NULL, retry_count = 0, updated_at = CURRENT_TIMESTAMP\n                WHERE source_bridge = ? AND target_bridge = ? AND source_calendar_id = ? AND target_calendar_id = ? AND source_event_id = ?\n            ";
            if ($tenantId !== null)
            {
                $sql .= " AND (tenant_id IS NOT DISTINCT FROM ? )";
            }
            $stmt = $this->db->prepare($sql);
            $params = [$sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId, $sourceEventId];
            if ($tenantId !== null)
            {
                $params[] = (string)$tenantId;
            }
            return $stmt->execute($params);
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to mark event as pending', ['error' => $e->getMessage(), 'source_bridge' => $sourceBridge, 'target_bridge' => $targetBridge, 'source_event_id' => $sourceEventId]);
            return false;
        }
    }

    public function getEventsToSync($sourceBridge, $targetBridge, $maxRetries = 3): array
    {
        try
        {
            $tenantId = $this->config['context_tenant_id'] ?? null;
            $sql = "\n                SELECT * FROM bridge_mappings \n                WHERE source_bridge = ? AND target_bridge = ? AND (sync_status = 'pending' OR (sync_status = 'error' AND retry_count < ?))\n            ";
            $params = [$sourceBridge, $targetBridge, $maxRetries];
            if ($tenantId !== null)
            {
                $sql .= " AND (tenant_id IS NOT DISTINCT FROM ? )";
                $params[] = (string)$tenantId;
            }
            $sql .= " ORDER BY CASE sync_status WHEN 'pending' THEN 1 WHEN 'error' THEN 2 ELSE 3 END, created_at ASC LIMIT 100";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to get events to sync', ['error' => $e->getMessage(), 'source_bridge' => $sourceBridge, 'target_bridge' => $targetBridge]);
            return [];
        }
    }

    public function getCancelledEvents($sourceBridge, $targetBridge): array
    {
        try
        {
            $tenantId = $this->config['context_tenant_id'] ?? null;
            $sql = "\n                SELECT * FROM bridge_mappings \n                WHERE source_bridge = ? AND target_bridge = ? AND sync_status = 'cancelled' AND target_event_id != ''\n            ";
            $params = [$sourceBridge, $targetBridge];
            if ($tenantId !== null)
            {
                $sql .= " AND (tenant_id IS NOT DISTINCT FROM ? )";
                $params[] = (string)$tenantId;
            }
            $sql .= " ORDER BY updated_at ASC LIMIT 50";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to get cancelled events', ['error' => $e->getMessage(), 'source_bridge' => $sourceBridge, 'target_bridge' => $targetBridge]);
            return [];
        }
    }

    public function getSyncStats($sourceBridge = null, $targetBridge = null): array
    {
        try
        {
            $tenantId = $this->config['context_tenant_id'] ?? null;
            $where = 'WHERE 1=1';
            $params = [];
            if ($sourceBridge)
            {
                $where .= ' AND source_bridge = ?';
                $params[] = $sourceBridge;
            }
            if ($targetBridge)
            {
                $where .= ' AND target_bridge = ?';
                $params[] = $targetBridge;
            }
            if ($tenantId !== null)
            {
                $where .= ' AND (tenant_id IS NOT DISTINCT FROM ? )';
                $params[] = (string)$tenantId;
            }
            $stmt = $this->db->prepare("\n                SELECT sync_status, COUNT(*) as count, AVG(retry_count) as avg_retries, MAX(retry_count) as max_retries\n                FROM bridge_mappings $where GROUP BY sync_status\n            ");
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to get sync stats', ['error' => $e->getMessage(), 'source_bridge' => $sourceBridge, 'target_bridge' => $targetBridge]);
            return [];
        }
    }

    // Optional diagnostic
    public function getSessionDiagnostics(): array
    {
        $diagnostics = ['bridge_type' => $this->getBridgeType(), 'session_mode' => $this->isCliMode() ? 'cli_file' : 'web_session', 'timestamp' => date('Y-m-d H:i:s'), 'php_sapi' => php_sapi_name()];
        if ($this->isCliMode())
        {
            $diagnostics['cli_session'] = [
                'session_file' => $this->getSessionFilePath(),
                'file_exists' => file_exists($this->getSessionFilePath()),
                'file_readable' => is_readable($this->getSessionFilePath()),
                'file_writable' => is_writable(dirname($this->getSessionFilePath())),
                'session_data_loaded' => $this->sessionData !== null,
                'session_keys' => $this->sessionData ? array_keys($this->sessionData) : []
            ];
            if (file_exists($this->getSessionFilePath()))
            {
                $diagnostics['cli_session']['file_size'] = filesize($this->getSessionFilePath());
                $diagnostics['cli_session']['file_modified'] = date('Y-m-d H:i:s', filemtime($this->getSessionFilePath()));
            }
        }
        else
        {
            $diagnostics['web_session'] = ['session_status' => session_status(), 'session_id' => session_id() ? substr(session_id(), 0, 8) . '...' : 'none', 'session_name' => session_name(), 'session_keys' => array_keys($_SESSION ?? [])];
        }
        return $diagnostics;
    }

    protected function getSessionFilePath(): string
    {
        if ($this->sessionFile)
        {
            return $this->sessionFile;
        }
        $projectRoot = dirname(dirname(__DIR__));
        $sessionDir = $projectRoot . '/storage/sessions';
        $sessionId = $this->generateConsistentSessionId();
        return $sessionDir . '/session_' . $sessionId . '.json';
    }

    protected function getResourceMappingSyncDirection($sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId): string
    {
        try
        {
            $stmt = $this->db->prepare("SELECT sync_direction FROM bridge_resource_mappings WHERE bridge_from = ? AND bridge_to = ? AND source_calendar_id = ? AND target_calendar_id = ? AND is_active = TRUE AND sync_enabled = TRUE LIMIT 1");
            $stmt->execute([$sourceBridge, $targetBridge, $sourceCalendarId, $targetCalendarId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && isset($result['sync_direction']))
            {
                return $result['sync_direction'];
            }
            return 'source_to_target';
        }
        catch (\Exception $e)
        {
            $this->logger->error('Failed to get resource mapping sync direction', ['error' => $e->getMessage(), 'source_bridge' => $sourceBridge, 'target_bridge' => $targetBridge, 'source_calendar_id' => $sourceCalendarId, 'target_calendar_id' => $targetCalendarId]);
            return 'source_to_target';
        }
    }

    // Default implementation; bridges may override
    public function reEnableFailedEvents(array $eventIds = []): array
    {
        $results = ['re_enabled_count' => 0, 'errors' => 0, 'error_details' => []];
        try
        {
            $whereClause = "sync_status = 'error' AND (source_bridge = ? OR target_bridge = ?)";
            $params = [$this->getBridgeType(), $this->getBridgeType()];
            if (!empty($eventIds))
            {
                $placeholders = str_repeat('?,', count($eventIds) - 1) . '?';
                $whereClause .= " AND source_event_id IN ($placeholders)";
                $params = array_merge($params, $eventIds);
            }
            $tenantId = $this->config['context_tenant_id'] ?? null;
            if ($tenantId !== null)
            {
                $whereClause .= " AND (tenant_id IS NOT DISTINCT FROM ?)";
                $params[] = (string)$tenantId;
            }
            $stmt = $this->db->prepare("UPDATE bridge_mappings SET sync_status = 'pending', error_message = NULL, retry_count = 0, updated_at = CURRENT_TIMESTAMP WHERE $whereClause");
            $stmt->execute($params);
            $results['re_enabled_count'] = $stmt->rowCount();
            $this->logOperation('re_enable_failed_events', ['bridge_type' => $this->getBridgeType(), 're_enabled_count' => $results['re_enabled_count'], 'event_ids_filter' => $eventIds]);
        }
        catch (\Exception $e)
        {
            $results['errors']++;
            $results['error_details'][] = ['error' => 'Failed to re-enable failed events: ' . $e->getMessage()];
        }
        return $results;
    }
}
