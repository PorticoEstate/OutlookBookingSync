<?php

namespace App\Repository;

use PDO;

class BridgeConfigRepository
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByTenantAndName(string $tenantId, string $bridgeName): ?array
    {
        $sql = "SELECT config_data FROM bridge_configs WHERE bridge_name = :name AND tenant_id = :tid LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':name' => $bridgeName, ':tid' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row && isset($row['config_data'])) {
            $data = json_decode($row['config_data'], true);
            return is_array($data) ? $data : null;
        }
        
        return null;
    }

    public function findAllActive(): array
    {
        $sql = "SELECT tenant_id, bridge_name, config_data
                FROM bridge_configs WHERE is_active = TRUE
                ORDER BY tenant_id, bridge_name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function upsert(string $tenantId, string $bridgeName, array $config): void
    {
        $json = json_encode($config);
        $sql = "INSERT INTO bridge_configs (tenant_id, bridge_name, config_data, updated_at)
                VALUES (:tid, :name, :config, CURRENT_TIMESTAMP)
                ON CONFLICT (tenant_id, bridge_name) 
                DO UPDATE SET config_data = EXCLUDED.config_data, updated_at = CURRENT_TIMESTAMP";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':tid' => $tenantId,
            ':name' => $bridgeName,
            ':config' => $json
        ]);
    }
}
