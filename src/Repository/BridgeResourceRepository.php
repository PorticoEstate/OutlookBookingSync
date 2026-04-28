<?php

namespace App\Repository;

use PDO;

class BridgeResourceRepository
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findActiveMappings(string $sourceBridge, string $targetBridge, ?string $tenantId): array
    {
        $hasTenantFilter = $tenantId !== null && $tenantId !== '';
        $tenantClause = $hasTenantFilter ? " AND (tenant_id = :tenant_id OR tenant_id IS NULL)" : "";
        $sql = "SELECT 
                    tenant_id,
                    source_calendar_id,
                    target_calendar_id,
                    sync_direction, 
                    horizon,
                    id,
                    bridge_from,
                    bridge_to
                FROM bridge_resource_mappings 
                WHERE (
                    (bridge_from = :bf AND bridge_to = :bt) OR 
                    (bridge_from = :bt AND bridge_to = :bf)
                )
                AND is_active = TRUE AND sync_enabled = TRUE" . $tenantClause;

        $stmt = $this->db->prepare($sql);
        $params = [
            ':bf' => $sourceBridge,
            ':bt' => $targetBridge,
        ];
        if ($hasTenantFilter) {
            $params[':tenant_id'] = $tenantId;
        }
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateLastSyncedAt(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE bridge_resource_mappings SET last_synced_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    public function findMappingForWebhook(string $sourceBridge, string $targetBridge, string $resourceId, ?string $tenantId): ?array
    {
        $sql = "
            SELECT 
                tenant_id, 
                source_calendar_id,
                target_calendar_id, 
                id as mapping_id,
                bridge_from,
                bridge_to,
                sync_direction,
                horizon
            FROM bridge_resource_mappings
            WHERE (
                (bridge_from = ? AND bridge_to = ? AND source_calendar_id = ?) OR
                (bridge_from = ? AND bridge_to = ? AND target_calendar_id = ?)
            )
            AND is_active = true
        ";

        $params = [
            $sourceBridge, $targetBridge, $resourceId,  // Forward direction
            $targetBridge, $sourceBridge, $resourceId   // Reverse direction
        ];
        if ($tenantId) {
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findMappingForDeletion(string $sourceBridge, string $targetBridge, string $resourceId, string $eventId, ?string $tenantId): ?array
    {
        $sql = "
            SELECT 
                brm.tenant_id, 
                brm.source_calendar_id,
                brm.target_calendar_id,
                brm.bridge_from,
                brm.bridge_to,
                brm.sync_direction,
                bm.target_event_id,
                bm.target_calendar_id as mapped_target_calendar
            FROM bridge_resource_mappings brm
            LEFT JOIN bridge_mappings bm ON (
                bm.source_bridge = brm.bridge_from
                AND bm.target_bridge = brm.bridge_to
                AND bm.source_calendar_id = brm.source_calendar_id
                AND bm.target_calendar_id = brm.target_calendar_id
                AND bm.source_event_id = ?
            )
            WHERE (
                (brm.bridge_from = ? AND brm.bridge_to = ? AND brm.source_calendar_id = ?) OR
                (brm.bridge_from = ? AND brm.bridge_to = ? AND brm.target_calendar_id = ?)
            )
            AND brm.is_active = true
        ";
        
        $params = [
            $eventId,  // For bridge_mappings JOIN
            $sourceBridge, $targetBridge, $resourceId,  // Forward direction
            $targetBridge, $sourceBridge, $resourceId   // Reverse direction
        ];
        
        if ($tenantId) {
            $sql .= " AND brm.tenant_id = ?";
            $params[] = $tenantId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find a resource by its ID.
     */
    public function find(int $id, ?string $tenantId = null): ?array
    {
        $sql = "SELECT * FROM bridge_resources WHERE id = :id";
        $params = ['id' => $id];

        if ($tenantId !== null && $tenantId !== '') {
            $sql .= " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
            $params['tenant_id'] = $tenantId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find a resource by bridge name and resource ID.
     */
    public function findByResourceId(string $bridgeName, string $resourceId, ?string $tenantId = null): ?array
    {
        $sql = "SELECT * FROM bridge_resources WHERE bridge_name = :bridge_name AND resource_id = :resource_id";
        $params = [
            'bridge_name' => $bridgeName,
            'resource_id' => $resourceId
        ];

        if ($tenantId !== null && $tenantId !== '') {
            $sql .= " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
            $params['tenant_id'] = $tenantId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Create a new resource.
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO bridge_resources (
            bridge_name, bridge_type, resource_id, resource_email, resource_name,
            resource_type, capacity, location, description, resource_data,
            is_active, tenant_id
        ) VALUES (
            :bridge_name, :bridge_type, :resource_id, :resource_email, :resource_name,
            :resource_type, :capacity, :location, :description, :resource_data,
            :is_active, :tenant_id
        )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'bridge_name' => $data['bridge_name'],
            'bridge_type' => $data['bridge_type'],
            'resource_id' => $data['resource_id'],
            'resource_email' => $data['resource_email'] ?? null,
            'resource_name' => $data['resource_name'],
            'resource_type' => $data['resource_type'] ?? 'room',
            'capacity' => isset($data['capacity']) ? (int)$data['capacity'] : null,
            'location' => $data['location'] ?? null,
            'description' => $data['description'] ?? null,
            'resource_data' => isset($data['resource_data']) ? json_encode($data['resource_data']) : null,
            'is_active' => filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'tenant_id' => !empty($data['tenant_id']) ? $data['tenant_id'] : null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update an existing resource.
     */
    public function update(int $id, array $data, ?string $tenantId = null): bool
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = [
            'bridge_name', 'bridge_type', 'resource_id', 'resource_email',
            'resource_name', 'resource_type', 'capacity', 'location',
            'description', 'is_active'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                if ($field === 'capacity') {
                    $params[$field] = isset($data[$field]) ? (int)$data[$field] : null;
                } elseif ($field === 'is_active') {
                    $params[$field] = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN);
                } else {
                    $params[$field] = $data[$field];
                }
            }
        }

        if (isset($data['resource_data'])) {
            $fields[] = 'resource_data = :resource_data';
            $params['resource_data'] = json_encode($data['resource_data']);
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = CURRENT_TIMESTAMP';

        $sql = "UPDATE bridge_resources SET " . implode(', ', $fields) . " WHERE id = :id";

        if ($tenantId !== null && $tenantId !== '') {
            $sql .= " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
            $params['tenant_id'] = $tenantId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a resource.
     */
    public function delete(int $id, ?string $tenantId = null): bool
    {
        $sql = "DELETE FROM bridge_resources WHERE id = :id";
        $params = ['id' => $id];

        if ($tenantId !== null && $tenantId !== '') {
            $sql .= " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
            $params['tenant_id'] = $tenantId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * List resources with filtering.
     */
    public function findAll(array $filters = [], ?string $tenantId = null): array
    {
        $conditions = ['1=1'];
        $params = [];

        if ($tenantId !== null && $tenantId !== '') {
            $conditions[] = '(tenant_id IS NOT DISTINCT FROM :tenant_id)';
            $params['tenant_id'] = $tenantId;
        }

        if (!empty($filters['bridge_name'])) {
            $conditions[] = 'bridge_name = :bridge_name';
            $params['bridge_name'] = $filters['bridge_name'];
        }

        if (!empty($filters['bridge_type'])) {
            $conditions[] = 'bridge_type = :bridge_type';
            $params['bridge_type'] = $filters['bridge_type'];
        }

        if (!empty($filters['resource_type'])) {
            $conditions[] = 'resource_type = :resource_type';
            $params['resource_type'] = $filters['resource_type'];
        }

        if (isset($filters['active_only']) && $filters['active_only']) {
            $conditions[] = 'is_active = true';
        }

        if (!empty($filters['search'])) {
            $conditions[] = '(resource_name ILIKE :search OR resource_email ILIKE :search OR location ILIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = implode(' AND ', $conditions);
        $limit = min((int)($filters['limit'] ?? 100), 500);
        $offset = max((int)($filters['offset'] ?? 0), 0);

        $sql = "SELECT * FROM bridge_resources WHERE $whereClause ORDER BY resource_name LIMIT :limit OFFSET :offset";
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count resources with filtering.
     */
    public function count(array $filters = [], ?string $tenantId = null): int
    {
        $conditions = ['1=1'];
        $params = [];

        if ($tenantId !== null && $tenantId !== '') {
            $conditions[] = '(tenant_id IS NOT DISTINCT FROM :tenant_id)';
            $params['tenant_id'] = $tenantId;
        }

        if (!empty($filters['bridge_name'])) {
            $conditions[] = 'bridge_name = :bridge_name';
            $params['bridge_name'] = $filters['bridge_name'];
        }

        if (!empty($filters['bridge_type'])) {
            $conditions[] = 'bridge_type = :bridge_type';
            $params['bridge_type'] = $filters['bridge_type'];
        }

        if (!empty($filters['resource_type'])) {
            $conditions[] = 'resource_type = :resource_type';
            $params['resource_type'] = $filters['resource_type'];
        }

        if (isset($filters['active_only']) && $filters['active_only']) {
            $conditions[] = 'is_active = true';
        }

        if (!empty($filters['search'])) {
            $conditions[] = '(resource_name ILIKE :search OR resource_email ILIKE :search OR location ILIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = implode(' AND ', $conditions);

        $sql = "SELECT COUNT(*) FROM bridge_resources WHERE $whereClause";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get resource statistics.
     */
    public function getStats(?string $tenantId): array
    {
        $whereClause = $tenantId !== null && $tenantId !== '' ? 'WHERE (tenant_id IS NOT DISTINCT FROM :tenant_id)' : 'WHERE 1=1';
        $params = $tenantId !== null && $tenantId !== '' ? ['tenant_id' => $tenantId] : [];
        
        $sql = "SELECT 
            bridge_name,
            bridge_type,
            resource_type,
            COUNT(*) as total,
            COUNT(*) FILTER (WHERE is_active = true) as active,
            AVG(capacity) FILTER (WHERE capacity IS NOT NULL) as avg_capacity
            FROM bridge_resources 
            $whereClause
            GROUP BY bridge_name, bridge_type, resource_type
            ORDER BY bridge_name, resource_type";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
