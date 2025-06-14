<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

/**
 * ResourceMappingController handles resource mapping between booking system and calendar systems
 */
class ResourceMappingController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get all resource mappings
     * GET /mappings/resources
     */
    public function getResourceMappings(Request $request, Response $response): Response
    {
        try {
            $queryParams = $request->getQueryParams();
            $bridgeFrom = $queryParams['bridge_from'] ?? null;
            $bridgeTo = $queryParams['bridge_to'] ?? null;
            $resourceId = $queryParams['resource_id'] ?? null;
            $activeOnly = ($queryParams['active_only'] ?? 'true') === 'true';

            $sql = "SELECT * FROM v_active_resource_mappings WHERE 1=1";
            $params = [];

            if ($bridgeFrom) {
                $sql .= " AND bridge_from = :bridge_from";
                $params['bridge_from'] = $bridgeFrom;
            }

            if ($bridgeTo) {
                $sql .= " AND bridge_to = :bridge_to";
                $params['bridge_to'] = $bridgeTo;
            }

            if ($resourceId) {
                $sql .= " AND resource_id = :resource_id";
                $params['resource_id'] = $resourceId;
            }

            if ($activeOnly) {
                $sql .= " AND is_active = true AND sync_enabled = true";
            }

            $sql .= " ORDER BY created_at DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response->getBody()->write(json_encode([
                'success' => true,
                'mappings' => $mappings,
                'count' => count($mappings)
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to retrieve resource mappings',
                'message' => $e->getMessage()
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Create new resource mapping
     * POST /mappings/resources
     */
    public function createResourceMapping(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);

            // Validate required fields
            $required = ['bridge_from', 'bridge_to', 'resource_id', 'calendar_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => "Missing required field: {$field}"
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }
            }

            // Check if mapping already exists
            $checkSql = "SELECT id FROM bridge_resource_mappings 
                        WHERE bridge_from = :bridge_from 
                        AND bridge_to = :bridge_to 
                        AND resource_id = :resource_id 
                        AND calendar_id = :calendar_id";
            
            $checkStmt = $this->db->prepare($checkSql);
            $checkStmt->execute([
                'bridge_from' => $data['bridge_from'],
                'bridge_to' => $data['bridge_to'],
                'resource_id' => $data['resource_id'],
                'calendar_id' => $data['calendar_id']
            ]);

            if ($checkStmt->fetch()) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Resource mapping already exists'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
            }

            // Create new mapping
            $sql = "INSERT INTO bridge_resource_mappings 
                    (bridge_from, bridge_to, resource_id, calendar_id, calendar_name, 
                     sync_direction, is_active, sync_enabled) 
                    VALUES (:bridge_from, :bridge_to, :resource_id, :calendar_id, 
                            :calendar_name, :sync_direction, :is_active, :sync_enabled)
                    RETURNING id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'bridge_from' => $data['bridge_from'],
                'bridge_to' => $data['bridge_to'],
                'resource_id' => $data['resource_id'],
                'calendar_id' => $data['calendar_id'],
                'calendar_name' => $data['calendar_name'] ?? null,
                'sync_direction' => $data['sync_direction'] ?? 'bidirectional',
                'is_active' => $data['is_active'] ?? true,
                'sync_enabled' => $data['sync_enabled'] ?? true
            ]);

            $mappingId = $stmt->fetchColumn();

            $response->getBody()->write(json_encode([
                'success' => true,
                'mapping_id' => $mappingId,
                'message' => 'Resource mapping created successfully'
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to create resource mapping',
                'message' => $e->getMessage()
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Update existing resource mapping
     * PUT /mappings/resources/{id}
     */
    public function updateResourceMapping(Request $request, Response $response, array $args): Response
    {
        try {
            $mappingId = $args['id'];
            $data = json_decode($request->getBody()->getContents(), true);

            // Check if mapping exists
            $checkSql = "SELECT * FROM bridge_resource_mappings WHERE id = :id";
            $checkStmt = $this->db->prepare($checkSql);
            $checkStmt->execute(['id' => $mappingId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Resource mapping not found'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            // Build update query dynamically
            $updateFields = [];
            $params = ['id' => $mappingId];

            $allowedFields = ['calendar_name', 'sync_direction', 'is_active', 'sync_enabled'];
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "{$field} = :{$field}";
                    $params[$field] = $data[$field];
                }
            }

            if (empty($updateFields)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'No valid fields to update'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
            
            $sql = "UPDATE bridge_resource_mappings SET " . implode(', ', $updateFields) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            $response->getBody()->write(json_encode([
                'success' => true,
                'mapping_id' => $mappingId,
                'message' => 'Resource mapping updated successfully'
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to update resource mapping',
                'message' => $e->getMessage()
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Delete resource mapping
     * DELETE /mappings/resources/{id}
     */
    public function deleteResourceMapping(Request $request, Response $response, array $args): Response
    {
        try {
            $mappingId = $args['id'];

            // Check if mapping exists
            $checkSql = "SELECT id FROM bridge_resource_mappings WHERE id = :id";
            $checkStmt = $this->db->prepare($checkSql);
            $checkStmt->execute(['id' => $mappingId]);

            if (!$checkStmt->fetch()) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Resource mapping not found'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            // Soft delete - set is_active to false
            $sql = "UPDATE bridge_resource_mappings SET is_active = false, updated_at = CURRENT_TIMESTAMP WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $mappingId]);

            $response->getBody()->write(json_encode([
                'success' => true,
                'mapping_id' => $mappingId,
                'message' => 'Resource mapping deleted successfully'
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to delete resource mapping',
                'message' => $e->getMessage()
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Get resource mapping by booking system resource ID
     * GET /mappings/resources/by-resource/{resourceId}
     */
    public function getResourceMappingByResource(Request $request, Response $response, array $args): Response
    {
        try {
            $resourceId = $args['resourceId'];
            $queryParams = $request->getQueryParams();
            $bridgeFrom = $queryParams['bridge_from'] ?? 'booking_system';

            $sql = "SELECT * FROM bridge_resource_mappings 
                    WHERE resource_id = :resource_id 
                    AND bridge_from = :bridge_from 
                    AND is_active = true 
                    ORDER BY created_at DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'resource_id' => $resourceId,
                'bridge_from' => $bridgeFrom
            ]);

            $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response->getBody()->write(json_encode([
                'success' => true,
                'resource_id' => $resourceId,
                'mappings' => $mappings,
                'count' => count($mappings)
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to retrieve resource mapping',
                'message' => $e->getMessage()
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Sync resource mapping - trigger sync for specific resource
     * POST /mappings/resources/{id}/sync
     */
    public function syncResourceMapping(Request $request, Response $response, array $args): Response
    {
        try {
            $mappingId = $args['id'];

            // Get mapping details
            $sql = "SELECT * FROM bridge_resource_mappings WHERE id = :id AND is_active = true";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $mappingId]);
            $mapping = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$mapping) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Resource mapping not found or inactive'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            // Add sync job to queue
            $queueSql = "INSERT INTO bridge_queue (queue_type, source_bridge, target_bridge, payload, priority)
                        VALUES ('resource_sync', :source_bridge, :target_bridge, :payload, 1)";
            
            $queueStmt = $this->db->prepare($queueSql);
            $queueStmt->execute([
                'source_bridge' => $mapping['bridge_from'],
                'target_bridge' => $mapping['bridge_to'],
                'payload' => json_encode([
                    'mapping_id' => $mappingId,
                    'resource_id' => $mapping['resource_id'],
                    'calendar_id' => $mapping['calendar_id'],
                    'sync_direction' => $mapping['sync_direction']
                ])
            ]);

            // Update last sync timestamp
            $updateSql = "UPDATE bridge_resource_mappings SET last_synced_at = CURRENT_TIMESTAMP WHERE id = :id";
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->execute(['id' => $mappingId]);

            $response->getBody()->write(json_encode([
                'success' => true,
                'mapping_id' => $mappingId,
                'message' => 'Resource sync queued successfully'
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to queue resource sync',
                'message' => $e->getMessage()
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Legacy method for backward compatibility
     * GET /mappings (redirects to /mappings/resources)
     */
    public function getMapping(Request $request, Response $response, array $args): Response
    {
        return $this->getResourceMappings($request, $response);
    }
}
