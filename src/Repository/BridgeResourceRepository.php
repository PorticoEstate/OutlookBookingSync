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
                sync_direction
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
}
