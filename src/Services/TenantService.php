<?php

namespace App\Services;

use App\Repository\TenantRepository;

/**
 * TenantService provides CRUD for tenants and secure API key rotation (hashed).
 */
class TenantService
{
    private TenantRepository $repository;

    public function __construct(TenantRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * List tenants.
     *
     * @param bool $includeInactive
     * @return array
     */
    public function listTenants(bool $includeInactive = true): array
    {
        return $this->repository->findAll($includeInactive);
    }

    /**
     * Get a single tenant by id.
     */
    public function getTenant(string $tenantId): ?array
    {
        return $this->repository->find($tenantId);
    }

    /**
     * Create a tenant.
     */
    public function createTenant(string $id, string $name, bool $active = true): array
    {
        $this->repository->create($id, $name, $active);
        return $this->getTenant($id) ?? ['id' => $id, 'name' => $name, 'active' => $active];
    }

    /**
     * Update a tenant's name/active status.
     */
    public function updateTenant(string $id, ?string $name = null, ?bool $active = null): array
    {
        $fields = [];
        if ($name !== null) { $fields['name'] = $name; }
        if ($active !== null) { $fields['active'] = $active; }
        
        $this->repository->update($id, $fields);
        return $this->getTenant($id) ?? [];
    }

    /**
     * Delete a tenant (cascades to keys via FK).
     */
    public function deleteTenant(string $id): bool
    {
        return $this->repository->delete($id);
    }

    /**
     * Rotate API key: generate new secret, store hash, return plaintext once.
     */
    public function rotateApiKey(string $tenantId): array
    {
        // Generate random 32-byte key as hex string
        $plainKey = bin2hex(random_bytes(32));
        $hash = password_hash($plainKey, PASSWORD_DEFAULT);

        $this->repository->rotateApiKey($tenantId, $hash);

        return [
            'tenant_id' => $tenantId,
            'X-API-Key' => $plainKey,
            'created_at' => date('c')
        ];
    }

    /**
     * Get key metadata (e.g. created_at).
     */
    public function getKeyMetadata(string $tenantId): ?array
    {
        return $this->repository->getKeyMetadata($tenantId);
    }
}
