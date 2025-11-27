<?php

namespace App\Repository;

use PDO;

class BridgeSubscriptionRepository
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * List webhook subscriptions with optional filtering.
     *
     * @param string|null $bridgeName Filter by bridge type/name
     * @param string|null $tenantId Filter by tenant ID
     * @param array $filters Additional filters (search, status)
     * @return array List of subscriptions
     */
    public function findAll(?string $bridgeName = null, ?string $tenantId = null, array $filters = []): array
    {
        $sql = "SELECT id, subscription_id, calendar_id, bridge_type, webhook_url, 
                      is_active, expires_at, last_renewed_at, created_at 
               FROM bridge_subscriptions";
        $params = [];
        
        $whereClauses = [];
        
        // Add bridge filter if specified
        if ($bridgeName !== null && $bridgeName !== '')
        {
            $whereClauses[] = "bridge_type = :bridge_type";
            $params[':bridge_type'] = $bridgeName;
        }

        // Add tenant filter if specified
        if ($tenantId !== null && $tenantId !== '')
        {
            $whereClauses[] = "(tenant_id IS NOT DISTINCT FROM :tenant_id)";
            $params[':tenant_id'] = $tenantId;
        }
        
        // Add search filter
        if (!empty($filters['search']))
        {
            $whereClauses[] = "calendar_id ILIKE :search";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        // Add status filter
        if (!empty($filters['status']))
        {
            $status = $filters['status'];
            if ($status === 'active')
            {
                $whereClauses[] = "is_active = TRUE AND (expires_at IS NULL OR expires_at > NOW())";
            }
            elseif ($status === 'inactive')
            {
                $whereClauses[] = "(is_active = FALSE OR expires_at <= NOW())";
            }
            elseif ($status === 'expired')
            {
                $whereClauses[] = "expires_at <= NOW()";
            }
        }

        if (!empty($whereClauses))
        {
            $sql .= " WHERE " . implode(' AND ', $whereClauses);
        }

        $sql .= " ORDER BY created_at DESC";

        // Add pagination
        if (isset($filters['limit']) && (int)$filters['limit'] > 0)
        {
            $sql .= " LIMIT :limit";
            $params[':limit'] = (int)$filters['limit'];
        }

        if (isset($filters['offset']) && (int)$filters['offset'] > 0)
        {
            $sql .= " OFFSET :offset";
            $params[':offset'] = (int)$filters['offset'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get subscription statistics.
     *
     * @param string|null $bridgeName Filter by bridge type/name
     * @param string|null $tenantId Filter by tenant ID
     * @return array Statistics
     */
    public function getStats(?string $bridgeName = null, ?string $tenantId = null): array
    {
        $sql = "SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN is_active = TRUE THEN 1 END) as active,
            COUNT(CASE WHEN expires_at IS NOT NULL AND expires_at <= NOW() THEN 1 END) as expired,
            COUNT(CASE WHEN expires_at IS NOT NULL AND expires_at > NOW() AND expires_at <= (NOW() + interval '24 hours') THEN 1 END) as expiring_24h
        FROM bridge_subscriptions";
        
        $whereClauses = [];
        $params = [];
        
        if ($bridgeName !== null && $bridgeName !== '')
        {
            $whereClauses[] = "bridge_type = :bridge_type";
            $params[':bridge_type'] = $bridgeName;
        }
        
        if ($tenantId !== null && $tenantId !== '')
        {
            $whereClauses[] = "(tenant_id IS NOT DISTINCT FROM :tenant_id)";
            $params[':tenant_id'] = $tenantId;
        }
        
        if (!empty($whereClauses))
        {
            $sql .= " WHERE " . implode(' AND ', $whereClauses);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Find a subscription by ID and bridge type.
     * 
     * @param string $subscriptionId
     * @param string $bridgeType
     * @param string|null $tenantId
     * @return array|null
     */
    public function find(string $subscriptionId, string $bridgeType, ?string $tenantId = null): ?array
    {
        $sql = "SELECT * FROM bridge_subscriptions 
               WHERE subscription_id = :subscription_id AND bridge_type = :bridge_type";
        $params = [
            ':subscription_id' => $subscriptionId,
            ':bridge_type' => $bridgeType
        ];

        if ($tenantId !== null && $tenantId !== '')
        {
            $sql .= " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
            $params[':tenant_id'] = $tenantId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }

    /**
     * Delete a subscription.
     * 
     * @param string $subscriptionId
     * @param string $bridgeType
     * @param string|null $tenantId
     * @return bool True if deleted
     */
    public function delete(string $subscriptionId, string $bridgeType, ?string $tenantId = null): bool
    {
        $sql = "DELETE FROM bridge_subscriptions 
               WHERE subscription_id = :subscription_id AND bridge_type = :bridge_type";
        $params = [
            ':subscription_id' => $subscriptionId,
            ':bridge_type' => $bridgeType
        ];

        if ($tenantId !== null && $tenantId !== '')
        {
            $sql .= " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
            $params[':tenant_id'] = $tenantId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->rowCount() > 0;
    }

    /**
     * Find expiring subscriptions.
     *
     * @param int $minutes Number of minutes to check for expiration
     * @param int $limit Maximum number of records to return
     * @param string|null $bridgeName Filter by bridge type/name
     * @param string|null $tenantId Filter by tenant ID
     * @param string|null $subscriptionId Filter by subscription ID
     * @return array List of expiring subscriptions
     */
    public function findExpiring(int $minutes, int $limit, ?string $bridgeName = null, ?string $tenantId = null, ?string $subscriptionId = null): array
    {
        $sql = "SELECT tenant_id, bridge_type, subscription_id, calendar_id, expires_at, webhook_url 
              FROM bridge_subscriptions 
              WHERE is_active = TRUE AND expires_at IS NOT NULL 
              AND expires_at < (NOW() + (:minutes || ' minutes')::interval)";
        
        $params = [':minutes' => $minutes, ':limit' => $limit];

        if ($tenantId) {
            $sql .= " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
            $params[':tenant_id'] = $tenantId;
        }
        if ($subscriptionId) {
            $sql .= " AND subscription_id = :subscription_id";
            $params[':subscription_id'] = $subscriptionId;
        }
        if ($bridgeName) {
            $sql .= " AND bridge_type = :bridge";
            $params[':bridge'] = $bridgeName;
        }

        $sql .= " ORDER BY tenant_id, expires_at ASC LIMIT :limit";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Deactivate a subscription.
     *
     * @param string $subscriptionId
     * @param string|null $tenantId
     */
    public function deactivate(string $subscriptionId, ?string $tenantId = null): void
    {
        $sql = "UPDATE bridge_subscriptions SET is_active = FALSE WHERE subscription_id = :sub_id";
        $params = [':sub_id' => $subscriptionId];
        
        if ($tenantId) {
            $sql .= " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
            $params[':tenant_id'] = $tenantId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Update subscription expiration time.
     *
     * @param string $subscriptionId
     * @param string $expirationDateTime
     * @return void
     */
    public function updateExpiration(string $subscriptionId, string $expirationDateTime): void
    {
        $sql = "UPDATE bridge_subscriptions SET expires_at = :expires_at, last_renewed_at = NOW() WHERE subscription_id = :subscription_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':expires_at' => $expirationDateTime,
            ':subscription_id' => $subscriptionId
        ]);
    }
}
