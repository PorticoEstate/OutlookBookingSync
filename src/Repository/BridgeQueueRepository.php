<?php

namespace App\Repository;

use PDO;

class BridgeQueueRepository
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function enqueue(string $queueType, string $sourceBridge, ?string $targetBridge, array $payload, int $priority = 5, ?string $tenantId = null): void
    {
        $sql = "INSERT INTO bridge_queue (queue_type, source_bridge, target_bridge, payload, priority, tenant_id) 
                VALUES (:queue_type, :source_bridge, :target_bridge, :payload, :priority, :tenant_id)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':queue_type' => $queueType,
            ':source_bridge' => $sourceBridge,
            ':target_bridge' => $targetBridge,
            ':payload' => json_encode($payload),
            ':priority' => $priority,
            ':tenant_id' => $tenantId
        ]);
    }

    public function findPendingItems(string $queueType, int $limit, ?string $tenantId = null, string $sortOrder = 'ASC'): array
    {
        $sql = "
            SELECT id, tenant_id, source_bridge, target_bridge, payload, attempts, created_at
            FROM bridge_queue 
            WHERE queue_type = :queue_type 
            AND status = 'pending'
        ";
        
        $params = [':queue_type' => $queueType];
        
        if ($tenantId) {
            $sql .= " AND tenant_id = :tenant_id";
            $params[':tenant_id'] = $tenantId;
        }
        
        $sql .= " ORDER BY priority ASC, created_at " . ($sortOrder === 'DESC' ? 'DESC' : 'ASC') . " LIMIT :limit";
        
        $stmt = $this->db->prepare($sql);
        
        // Bind limit as integer for PDO compatibility with LIMIT
        $stmt->bindParam(':queue_type', $queueType);
        if ($tenantId) {
            $stmt->bindParam(':tenant_id', $tenantId);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markProcessing(int $id): void
    {
        $sql = "UPDATE bridge_queue SET status = 'processing', attempts = attempts + 1 WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
    }

    public function markCompleted(int $id): void
    {
        $sql = "UPDATE bridge_queue SET status = 'completed', processed_at = CURRENT_TIMESTAMP WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
    }

    public function updateStatus(int $id, string $status, ?string $errorMessage = null): void
    {
        $sql = "UPDATE bridge_queue SET status = :status, error_message = :error_message WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':error_message' => $errorMessage,
            ':id' => $id
        ]);
    }
}
