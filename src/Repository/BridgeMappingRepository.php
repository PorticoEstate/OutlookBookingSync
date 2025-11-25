<?php

namespace App\Repository;

use PDO;

class BridgeMappingRepository
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findMappings(
        string $sourceBridge,
        string $targetBridge,
        string $sourceCalendarId,
        string $targetCalendarId,
        string $startDate,
        string $endDate,
        array $options = []
    ): array {
        $tenantId = $options['tenant_id'] ?? null;

        $baseWhere = "(source_bridge = :source_bridge AND target_bridge = :target_bridge AND source_calendar_id = :source_calendar_id AND target_calendar_id = :target_calendar_id)";
        $reverseWhere = "(source_bridge = :target_bridge AND target_bridge = :source_bridge AND source_calendar_id = :target_calendar_id AND target_calendar_id = :source_calendar_id)";
        $tenantPredicate = $tenantId !== null ? " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)" : "";
        
        // Time predicate for event overlap detection:
        // An event overlaps the window if: event_start < window_end AND event_end > window_start
        // Date strings are converted to full day ranges (00:00:00 to 23:59:59)
        // This catches events that start before, during, or after the window but have any time overlap
        $timePredicate = ($startDate && $endDate)
            ? " AND ((created_at BETWEEN :wstart AND :wend) OR (source_event_start < :wend AND source_event_end > :wstart))"
            : "";

        $sql = "SELECT * FROM bridge_mappings WHERE $baseWhere$tenantPredicate$timePredicate
                UNION ALL
                SELECT * FROM bridge_mappings WHERE $reverseWhere$tenantPredicate$timePredicate
                ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);
        $params = [
            ':source_bridge'      => $sourceBridge,
            ':target_bridge'      => $targetBridge,
            ':source_calendar_id' => $sourceCalendarId,
            ':target_calendar_id' => $targetCalendarId,
        ];
        if ($tenantId !== null)
        {
            $params[':tenant_id'] = (string)$tenantId;
        }
        if ($startDate && $endDate)
        {
            // Convert date strings to full datetime ranges for proper comparison
            // windowStart becomes start of day (00:00:00), windowEnd becomes end of day (23:59:59)
            $params[':wstart'] = date('Y-m-d H:i:s', strtotime($startDate . ' 00:00:00'));
            $params[':wend'] = date('Y-m-d H:i:s', strtotime($endDate . ' 23:59:59'));
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row)
        {
            $isCurrentDirection =
                $row['source_bridge'] === $sourceBridge &&
                $row['target_bridge'] === $targetBridge &&
                $row['source_calendar_id'] === $sourceCalendarId &&
                $row['target_calendar_id'] === $targetCalendarId;

            if ($isCurrentDirection)
            {
                $row['normalized_reversed'] = false;
            }
            else
            {
                $row['normalized_reversed'] = true;
            }
        }

        return $rows;
    }

    public function updateTargetEventId(int $mappingId, string $newEventId): void
    {
        $sql = "UPDATE bridge_mappings SET target_event_id = :target_event_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $mappingId, ':target_event_id' => $newEventId]);
    }

    public function updateTimestamp(int $mappingId): void
    {
        $sql = "UPDATE bridge_mappings SET last_synced_at = CURRENT_TIMESTAMP WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $mappingId]);
    }

    public function updateEventData(int $mappingId, string $eventData, string $eventHash): void
    {
        $sql = "UPDATE bridge_mappings SET event_data = :event_data, event_hash = :event_hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $mappingId, ':event_data' => $eventData, ':event_hash' => $eventHash]);
    }

    public function updateSyncStatus(int $mappingId, string $status, ?string $errorMessage = null): void
    {
        $sql = "UPDATE bridge_mappings 
                SET sync_status = :status, 
                    error_message = :error_message,
                    updated_at = CURRENT_TIMESTAMP 
                WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $mappingId,
            ':status' => $status,
            ':error_message' => $errorMessage
        ]);
    }

    public function updateSourceTiming(int $mappingId, ?string $start, ?string $end): void
    {
        $sql = "UPDATE bridge_mappings 
                SET source_event_start = :start, 
                    source_event_end = :end 
                WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $mappingId,
            ':start' => $start,
            ':end' => $end
        ]);
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO bridge_mappings 
                (source_bridge, target_bridge, source_calendar_id, target_calendar_id, source_event_id, target_event_id, event_hash, event_data, last_synced_at, sync_status, tenant_id, sync_direction)
                VALUES 
                (:source_bridge, :target_bridge, :source_calendar_id, :target_calendar_id, :source_event_id, :target_event_id, :event_hash, :event_data, CURRENT_TIMESTAMP, :sync_status, :tenant_id, :sync_direction)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':source_bridge' => $data['source_bridge'],
            ':target_bridge' => $data['target_bridge'],
            ':source_calendar_id' => $data['source_calendar_id'],
            ':target_calendar_id' => $data['target_calendar_id'],
            ':source_event_id' => $data['source_event_id'],
            ':target_event_id' => $data['target_event_id'],
            ':event_hash' => $data['event_hash'] ?? null,
            ':event_data' => $data['event_data'] ?? null,
            ':sync_status' => $data['sync_status'] ?? 'synced',
            ':tenant_id' => $data['tenant_id'] ?? null,
            ':sync_direction' => $data['sync_direction'] ?? 'source_to_target'
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    public function createOrUpdate(array $data): int
    {
        $sql = "INSERT INTO bridge_mappings 
                (source_bridge, target_bridge, source_calendar_id, target_calendar_id, source_event_id, target_event_id, sync_direction, sync_status, event_data, tenant_id, created_at, updated_at)
                VALUES 
                (:source_bridge, :target_bridge, :source_calendar_id, :target_calendar_id, :source_event_id, :target_event_id, :sync_direction, 'synced', :event_data, :tenant_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON CONFLICT (source_bridge, target_bridge, source_calendar_id, target_calendar_id, source_event_id, tenant_id)
                DO UPDATE SET
                    target_event_id = EXCLUDED.target_event_id,
                    sync_status = 'synced',
                    event_data = EXCLUDED.event_data,
                    updated_at = CURRENT_TIMESTAMP,
                    last_synced_at = CURRENT_TIMESTAMP,
                    retry_count = 0,
                    error_message = NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':source_bridge' => $data['source_bridge'],
            ':target_bridge' => $data['target_bridge'],
            ':source_calendar_id' => $data['source_calendar_id'],
            ':target_calendar_id' => $data['target_calendar_id'],
            ':source_event_id' => $data['source_event_id'],
            ':target_event_id' => $data['target_event_id'],
            ':sync_direction' => $data['sync_direction'] ?? 'source_to_target',
            ':event_data' => $data['event_data'] ?? null,
            ':tenant_id' => $data['tenant_id'] ?? null
        ]);
        
        // Return ID if possible, but ON CONFLICT might not return it easily without RETURNING clause which might not be supported by all drivers or requires fetch.
        // For now, we just return 0 or true/false if we change return type.
        // But AbstractCalendarBridge expects bool.
        return 1; 
    }

    public function createOrUpdateWithTargetEventId(array $data): void
    {
        $sql = "
            INSERT INTO bridge_mappings 
            (source_bridge, target_bridge, source_calendar_id, target_calendar_id, source_event_id, sync_status, tenant_id, created_at, updated_at, target_event_id)
            VALUES (:source_bridge, :target_bridge, :source_calendar_id, :target_calendar_id, :source_event_id, :sync_status, :tenant_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, :target_event_id)
            ON CONFLICT (source_bridge, target_bridge, source_calendar_id, target_calendar_id, source_event_id, tenant_id)
            DO UPDATE SET 
                sync_status = :sync_status_update,
                updated_at = CURRENT_TIMESTAMP,
                retry_count = 0,
                error_message = NULL,
                target_event_id = :target_event_id_update
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':source_bridge' => $data['source_bridge'],
            ':target_bridge' => $data['target_bridge'],
            ':source_calendar_id' => $data['source_calendar_id'],
            ':target_calendar_id' => $data['target_calendar_id'],
            ':source_event_id' => $data['source_event_id'],
            ':sync_status' => $data['sync_status'],
            ':tenant_id' => $data['tenant_id'],
            ':target_event_id' => $data['target_event_id'],
            ':sync_status_update' => $data['sync_status'],
            ':target_event_id_update' => $data['target_event_id']
        ]);
    }

    public function updateStatusAndTargetEventId(array $data): int
    {
        $sql = "
            UPDATE bridge_mappings 
            SET sync_status = :sync_status,
                updated_at = CURRENT_TIMESTAMP,
                retry_count = 0,
                error_message = NULL,
                target_event_id = :target_event_id
            WHERE source_bridge = :source_bridge
            AND target_bridge = :target_bridge
            AND source_calendar_id = :source_calendar_id
            AND target_calendar_id = :target_calendar_id
            AND source_event_id = :source_event_id
            AND tenant_id IS NOT DISTINCT FROM :tenant_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':sync_status' => $data['sync_status'],
            ':target_event_id' => $data['target_event_id'],
            ':source_bridge' => $data['source_bridge'],
            ':target_bridge' => $data['target_bridge'],
            ':source_calendar_id' => $data['source_calendar_id'],
            ':target_calendar_id' => $data['target_calendar_id'],
            ':source_event_id' => $data['source_event_id'],
            ':tenant_id' => $data['tenant_id']
        ]);
        
        return $stmt->rowCount();
    }

    public function markAsCancelled(string $sourceBridge, string $targetBridge, string $sourceCalendarId, string $sourceEventId, ?string $tenantId): int
    {
        $sql = "
            UPDATE bridge_mappings 
            SET sync_status = 'cancelled', 
                updated_at = CURRENT_TIMESTAMP
            WHERE source_bridge = :source_bridge 
            AND target_bridge = :target_bridge
            AND source_calendar_id = :source_calendar_id 
            AND source_event_id = :source_event_id
        ";
        
        $params = [
            ':source_bridge' => $sourceBridge,
            ':target_bridge' => $targetBridge,
            ':source_calendar_id' => $sourceCalendarId,
            ':source_event_id' => $sourceEventId
        ];
        
        if ($tenantId) {
            $sql .= " AND tenant_id = :tenant_id";
            $params[':tenant_id'] = $tenantId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->rowCount();
    }

    public function findByEventIdAndBridge(string $eventId, string $bridgeName, ?string $tenantId): ?array
    {
        $sql = "SELECT source_calendar_id, target_calendar_id, source_bridge, target_bridge 
                FROM bridge_mappings 
                WHERE (source_event_id = :event_id OR target_event_id = :event_id)
                AND (source_bridge = :bridge_name OR target_bridge = :bridge_name)
                AND (tenant_id = :tenant_id OR tenant_id IS NULL)
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':event_id' => $eventId,
            ':bridge_name' => $bridgeName,
            ':tenant_id' => $tenantId
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function updateSyncStatusByCompositeKey(
        string $sourceBridge,
        string $targetBridge,
        string $sourceCalendarId,
        string $targetCalendarId,
        string $sourceEventId,
        string $status,
        ?string $errorMessage = null,
        ?string $tenantId = null
    ): bool {
        $sql = "UPDATE bridge_mappings 
                SET sync_status = :status, 
                    error_message = :error_message,
                    updated_at = CURRENT_TIMESTAMP 
                WHERE source_bridge = :source_bridge
                AND target_bridge = :target_bridge
                AND source_calendar_id = :source_calendar_id
                AND target_calendar_id = :target_calendar_id
                AND source_event_id = :source_event_id";
        
        $params = [
            ':status' => $status,
            ':error_message' => $errorMessage,
            ':source_bridge' => $sourceBridge,
            ':target_bridge' => $targetBridge,
            ':source_calendar_id' => $sourceCalendarId,
            ':target_calendar_id' => $targetCalendarId,
            ':source_event_id' => $sourceEventId
        ];

        if ($tenantId !== null) {
            $sql .= " AND tenant_id IS NOT DISTINCT FROM :tenant_id";
            $params[':tenant_id'] = $tenantId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function markAsPending(
        string $sourceBridge,
        string $targetBridge,
        string $sourceCalendarId,
        string $targetCalendarId,
        string $sourceEventId,
        ?string $tenantId = null
    ): bool {
        return $this->updateSyncStatusByCompositeKey(
            $sourceBridge,
            $targetBridge,
            $sourceCalendarId,
            $targetCalendarId,
            $sourceEventId,
            'pending',
            null,
            $tenantId
        );
    }

    public function getEventsToSync(
        string $sourceBridge,
        string $targetBridge,
        int $maxRetries = 3,
        ?string $tenantId = null
    ): array {
        $sql = "SELECT * FROM bridge_mappings 
                WHERE source_bridge = :source_bridge 
                AND target_bridge = :target_bridge 
                AND sync_status IN ('pending', 'failed') 
                AND retry_count < :max_retries";
        
        $params = [
            ':source_bridge' => $sourceBridge,
            ':target_bridge' => $targetBridge,
            ':max_retries' => $maxRetries
        ];

        if ($tenantId !== null) {
            $sql .= " AND tenant_id IS NOT DISTINCT FROM :tenant_id";
            $params[':tenant_id'] = $tenantId;
        }

        $sql .= " ORDER BY created_at ASC LIMIT 50";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCancelledEvents(
        string $sourceBridge,
        string $targetBridge,
        ?string $tenantId = null
    ): array {
        $sql = "SELECT * FROM bridge_mappings 
                WHERE source_bridge = :source_bridge 
                AND target_bridge = :target_bridge 
                AND sync_status = 'cancelled'";
        
        $params = [
            ':source_bridge' => $sourceBridge,
            ':target_bridge' => $targetBridge
        ];

        if ($tenantId !== null) {
            $sql .= " AND tenant_id IS NOT DISTINCT FROM :tenant_id";
            $params[':tenant_id'] = $tenantId;
        }

        $sql .= " ORDER BY updated_at DESC LIMIT 50";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSyncStats(
        ?string $sourceBridge = null,
        ?string $targetBridge = null,
        ?string $tenantId = null
    ): array {
        $where = ["1=1"];
        $params = [];

        if ($sourceBridge) {
            $where[] = "source_bridge = :source_bridge";
            $params[':source_bridge'] = $sourceBridge;
        }
        if ($targetBridge) {
            $where[] = "target_bridge = :target_bridge";
            $params[':target_bridge'] = $targetBridge;
        }
        if ($tenantId) {
            $where[] = "tenant_id IS NOT DISTINCT FROM :tenant_id";
            $params[':tenant_id'] = $tenantId;
        }

        $whereClause = implode(" AND ", $where);
        
        $sql = "SELECT sync_status, COUNT(*) as count 
                FROM bridge_mappings 
                WHERE $whereClause 
                GROUP BY sync_status";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function resetSyncStatus(
        string $bridgeType,
        array $eventIds = [],
        ?string $tenantId = null
    ): int {
        $sql = "UPDATE bridge_mappings 
                SET sync_status = 'pending', 
                    retry_count = 0, 
                    error_message = NULL, 
                    updated_at = CURRENT_TIMESTAMP 
                WHERE (source_bridge = :bridge_type OR target_bridge = :bridge_type) 
                AND sync_status = 'failed'";
        
        $params = [':bridge_type' => $bridgeType];

        if (!empty($eventIds)) {
            // Create placeholders for IN clause
            $placeholders = [];
            foreach ($eventIds as $i => $id) {
                $key = ":event_id_$i";
                $placeholders[] = $key;
                $params[$key] = $id;
            }
            $sql .= " AND (source_event_id IN (" . implode(',', $placeholders) . ") OR target_event_id IN (" . implode(',', $placeholders) . "))";
        }

        if ($tenantId !== null) {
            $sql .= " AND tenant_id IS NOT DISTINCT FROM :tenant_id";
            $params[':tenant_id'] = $tenantId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->rowCount();
    }

    public function updateSyncMethod(
        string $sourceBridge,
        string $targetBridge,
        string $sourceCalendarId,
        string $targetCalendarId,
        string $sourceEventId,
        string $syncMethod
    ): void {
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
    }

    public function findPendingSyncsForBridge(
        string $bridgeName,
        int $limit = 50,
        int $maxRetries = 3,
        ?string $tenantId = null
    ): array {
        $sql = "SELECT * FROM bridge_mappings 
                WHERE (source_bridge = :bridge_name OR target_bridge = :bridge_name)
                AND sync_status IN ('pending', 'failed') 
                AND retry_count < :max_retries";
        
        $params = [
            ':bridge_name' => $bridgeName,
            ':max_retries' => $maxRetries
        ];

        if ($tenantId !== null) {
            $sql .= " AND tenant_id IS NOT DISTINCT FROM :tenant_id";
            $params[':tenant_id'] = $tenantId;
        }

        $sql .= " ORDER BY created_at ASC LIMIT " . (int)$limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findMappingBySourceEventId(
        string $sourceBridge,
        string $targetBridge,
        string $sourceCalendarId,
        string $targetCalendarId,
        string $sourceEventId,
        ?string $tenantId = null
    ): ?array {
        $sql = "SELECT * FROM bridge_mappings 
                WHERE source_bridge = :source_bridge 
                AND target_bridge = :target_bridge 
                AND source_calendar_id = :source_calendar_id 
                AND target_calendar_id = :target_calendar_id
                AND source_event_id = :source_event_id";
        
        $params = [
            ':source_bridge' => $sourceBridge,
            ':target_bridge' => $targetBridge,
            ':source_calendar_id' => $sourceCalendarId,
            ':target_calendar_id' => $targetCalendarId,
            ':source_event_id' => $sourceEventId
        ];

        if ($tenantId !== null) {
            $sql .= " AND tenant_id IS NOT DISTINCT FROM :tenant_id";
            $params[':tenant_id'] = $tenantId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $mapping = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($mapping) {
            $mapping['normalized_reversed'] = false; // Direct lookup implies direct direction
        }
        
        return $mapping ?: null;
    }

    /**
     * Find all resource mappings based on filters.
     *
     * @param array $filters
     * @return array
     */
    public function findAllResourceMappings(array $filters = []): array
    {
        $sql = "SELECT * FROM v_active_resource_mappings WHERE 1=1";
        $params = [];

        // Optional tenant scoping
        if (!empty($filters['tenant_id'])) {
            $sql .= " AND (tenant_id = :tenant_id OR tenant_id IS NULL)";
            $params['tenant_id'] = $filters['tenant_id'];
        }

        if (!empty($filters['bridge_from'])) {
            $sql .= " AND bridge_from = :bridge_from";
            $params['bridge_from'] = $filters['bridge_from'];
        }

        if (!empty($filters['bridge_to'])) {
            $sql .= " AND bridge_to = :bridge_to";
            $params['bridge_to'] = $filters['bridge_to'];
        }

        // Handle legacy source_calendar_id parameter - search both source and target
        if (!empty($filters['source_calendar_id'])) {
            $sql .= " AND (source_calendar_id = :source_calendar_id OR target_calendar_id = :source_calendar_id)";
            $params['source_calendar_id'] = $filters['source_calendar_id'];
        }

        if (!empty($filters['target_calendar_id'])) {
            $sql .= " AND target_calendar_id = :target_calendar_id";
            $params['target_calendar_id'] = $filters['target_calendar_id'];
        }

        if (isset($filters['active_only']) && $filters['active_only'] === true) {
            $sql .= " AND is_active = true AND sync_enabled = true";
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
