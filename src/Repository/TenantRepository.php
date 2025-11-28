<?php

namespace App\Repository;

use PDO;

class TenantRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findAll(bool $includeInactive = true): array
    {
        $sql = 'SELECT id, name, active, created_at FROM tenants ' . ($includeInactive ? '' : 'WHERE active = TRUE ') . 'ORDER BY created_at DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function find(string $tenantId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, name, active, created_at FROM tenants WHERE id = :id');
        $stmt->execute([':id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(string $id, string $name, bool $active = true): void
    {
        $stmt = $this->db->prepare('INSERT INTO tenants (id, name, active) VALUES (:id, :name, :active)');
        $stmt->execute([':id' => $id, ':name' => $name, ':active' => $active]);
    }

    public function update(string $id, array $fields): void
    {
        if (empty($fields)) {
            return;
        }

        $setClauses = [];
        $params = [':id' => $id];

        foreach ($fields as $key => $value) {
            $setClauses[] = "$key = :$key";
            $params[":$key"] = $value;
        }

        $sql = 'UPDATE tenants SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function delete(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM tenants WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function rotateApiKey(string $tenantId, string $hash): void
    {
        $sql = 'INSERT INTO tenant_api_keys (tenant_id, api_key_hash, created_at)
                VALUES (:tenant_id, :hash, CURRENT_TIMESTAMP)
                ON CONFLICT (tenant_id) DO UPDATE SET api_key_hash = EXCLUDED.api_key_hash, created_at = CURRENT_TIMESTAMP';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tenant_id' => $tenantId, ':hash' => $hash]);
    }

    public function getKeyMetadata(string $tenantId): ?array
    {
        $stmt = $this->db->prepare('SELECT created_at FROM tenant_api_keys WHERE tenant_id = :id');
        $stmt->execute([':id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
