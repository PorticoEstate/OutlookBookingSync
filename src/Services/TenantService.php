<?php

namespace App\Services;

use PDO;

/**
 * TenantService provides CRUD for tenants and secure API key rotation (hashed).
 */
class TenantService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * List tenants.
     *
     * @param bool $includeInactive
     * @return array
     */
    public function listTenants(bool $includeInactive = true): array
    {
        $sql = 'SELECT id, name, active, created_at FROM tenants ' . ($includeInactive ? '' : 'WHERE active = TRUE ') . 'ORDER BY created_at DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get a single tenant by id.
     */
    public function getTenant(string $tenantId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, name, active, created_at FROM tenants WHERE id = :id');
        $stmt->execute([':id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create a tenant.
     */
    public function createTenant(string $id, string $name, bool $active = true): array
    {
        $stmt = $this->db->prepare('INSERT INTO tenants (id, name, active) VALUES (:id, :name, :active)');
        $stmt->execute([':id' => $id, ':name' => $name, ':active' => $active]);
        return $this->getTenant($id) ?? ['id' => $id, 'name' => $name, 'active' => $active];
    }

    /**
     * Update a tenant's name/active status.
     */
    public function updateTenant(string $id, ?string $name = null, ?bool $active = null): array
    {
        $fields = [];
        $params = [':id' => $id];
        if ($name !== null) { $fields[] = 'name = :name'; $params[':name'] = $name; }
        if ($active !== null) { $fields[] = 'active = :active'; $params[':active'] = $active; }
        if (!$fields) { return $this->getTenant($id) ?? []; }
        $sql = 'UPDATE tenants SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $this->getTenant($id) ?? [];
    }

    /**
     * Delete a tenant (cascades to keys via FK).
     */
    public function deleteTenant(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM tenants WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Rotate API key: generate new secret, store hash, return plaintext once.
     */
    public function rotateApiKey(string $tenantId): array
    {
        // Generate random 32-byte key as hex string
        $plainKey = bin2hex(random_bytes(32));
        $hash = password_hash($plainKey, PASSWORD_DEFAULT);

        $sql = 'INSERT INTO tenant_api_keys (tenant_id, api_key_hash, created_at)
                VALUES (:tenant_id, :hash, CURRENT_TIMESTAMP)
                ON CONFLICT (tenant_id) DO UPDATE SET api_key_hash = EXCLUDED.api_key_hash, created_at = CURRENT_TIMESTAMP';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tenant_id' => $tenantId, ':hash' => $hash]);

        return [
            'tenant_id' => $tenantId,
            'X-API-Key' => $plainKey,
            'created_at' => date('c')
        ];
    }

    /**
     * Check whether a tenant has a key (no secret leak).
     */
    public function getKeyMetadata(string $tenantId): ?array
    {
        $stmt = $this->db->prepare('SELECT tenant_id, created_at FROM tenant_api_keys WHERE tenant_id = :id');
        $stmt->execute([':id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Upsert tenant-specific bridge config JSON.
     */
    public function upsertBridgeConfig(string $tenantId, string $bridgeName, array $config): array
    {
        $sql = 'INSERT INTO bridge_configs (bridge_name, bridge_type, config_data, tenant_id, updated_at)
                VALUES (:name, :type, :data, :tenant, CURRENT_TIMESTAMP)
                ON CONFLICT (bridge_name, tenant_id) DO UPDATE SET config_data = EXCLUDED.config_data, updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':name' => $bridgeName,
            ':type' => $bridgeName,
            ':data' => json_encode($config),
            ':tenant' => $tenantId,
        ]);
        return $this->getBridgeConfig($tenantId, $bridgeName) ?? [];
    }

    /**
     * Get tenant-specific bridge config JSON.
     */
    public function getBridgeConfig(string $tenantId, string $bridgeName): ?array
    {
        $stmt = $this->db->prepare('SELECT bridge_name, bridge_type, config_data, tenant_id, updated_at FROM bridge_configs WHERE tenant_id IS NOT DISTINCT FROM :tenant AND bridge_name = :name');
        $stmt->execute([':tenant' => $tenantId, ':name' => $bridgeName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return null; }
        $row['config_data'] = is_array($row['config_data']) ? $row['config_data'] : json_decode($row['config_data'], true);
        return $row;
    }
}
