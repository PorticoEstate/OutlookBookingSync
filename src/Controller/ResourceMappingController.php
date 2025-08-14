<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

/**
 * ResourceMappingController handles resource mapping between booking system and calendar systems.
 */
class ResourceMappingController
{
	private PDO $db;

	public function __construct(PDO $db)
	{
		$this->db = $db;
	}

	/**
	 * Get all resource mappings.
	 * GET /mappings/resources
	 *
	 * @param Request $request
	 * @param Response $response
	 * @return Response
	 */
	public function getResourceMappings(Request $request, Response $response): Response
	{
		try
		{
			$queryParams = $request->getQueryParams();
			$bridgeFrom = $queryParams['bridge_from'] ?? null;
			$bridgeTo = $queryParams['bridge_to'] ?? null;
			$sourceCalendarId = $queryParams['source_calendar_id'] ?? null;
			$targetCalendarId = $queryParams['target_calendar_id'] ?? null;
			$activeOnly = ($queryParams['active_only'] ?? 'true') === 'true';

			$sql = "SELECT * FROM v_active_resource_mappings WHERE 1=1";
			$params = [];

			// Optional tenant scoping
			$tenantId = $request->getAttribute('tenant_id');
			if ($tenantId) {
				$sql .= " AND (tenant_id = :tenant_id OR tenant_id IS NULL)";
				$params['tenant_id'] = $tenantId;
			}

			if ($bridgeFrom)
			{
				$sql .= " AND bridge_from = :bridge_from";
				$params['bridge_from'] = $bridgeFrom;
			}

			if ($bridgeTo)
			{
				$sql .= " AND bridge_to = :bridge_to";
				$params['bridge_to'] = $bridgeTo;
			}

			// Handle legacy source_calendar_id parameter - search both source and target
			if ($sourceCalendarId)
			{
				$sql .= " AND (source_calendar_id = :source_calendar_id OR target_calendar_id = :source_calendar_id)";
				$params['source_calendar_id'] = $sourceCalendarId;
			}

			// New semantic parameters
			if ($sourceCalendarId)
			{
				$sql .= " AND source_calendar_id = :source_calendar_id";
				$params['source_calendar_id'] = $sourceCalendarId;
			}

			if ($targetCalendarId)
			{
				$sql .= " AND target_calendar_id = :target_calendar_id";
				$params['target_calendar_id'] = $targetCalendarId;
			}

			if ($activeOnly)
			{
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
		}
		catch (\Exception $e)
		{
			$response->getBody()->write(json_encode([
				'success' => false,
				'error' => 'Failed to retrieve resource mappings',
				'message' => $e->getMessage()
			]));

			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * Create new resource mapping.
	 * POST /mappings/resources
	 *
	 * @param Request $request
	 * @param Response $response
	 * @return Response
	 */
	public function createResourceMapping(Request $request, Response $response): Response
	{
		try
		{
			$data = json_decode($request->getBody()->getContents(), true);

			// Validate required fields with new semantic names
			$required = ['bridge_from', 'bridge_to', 'source_calendar_id', 'target_calendar_id'];
			foreach ($required as $field)
			{
				if (empty($data[$field]))
				{
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
                        AND source_calendar_id = :source_calendar_id 
						AND target_calendar_id = :target_calendar_id
						AND (tenant_id = :tenant_id OR (tenant_id IS NULL AND :tenant_id IS NULL))";

			$checkStmt = $this->db->prepare($checkSql);
			$checkStmt->execute([
				'bridge_from' => $data['bridge_from'],
				'bridge_to' => $data['bridge_to'],
				'source_calendar_id' => $data['source_calendar_id'],
				'target_calendar_id' => $data['target_calendar_id'],
				'tenant_id' => $request->getAttribute('tenant_id')
			]);

			if ($checkStmt->fetch())
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => 'Resource mapping already exists'
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
			}

			// Create new mapping
	     $sql = "INSERT INTO bridge_resource_mappings 
                    (bridge_from, bridge_to, source_calendar_id, target_calendar_id, 
			source_calendar_name, target_calendar_name, sync_direction, is_active, sync_enabled, tenant_id) 
                    VALUES (:bridge_from, :bridge_to, :source_calendar_id, :target_calendar_id, 
				:source_calendar_name, :target_calendar_name, :sync_direction, :is_active, :sync_enabled, :tenant_id)
                    RETURNING id";

			$stmt = $this->db->prepare($sql);
			$stmt->execute([
				'bridge_from' => $data['bridge_from'],
				'bridge_to' => $data['bridge_to'],
				'source_calendar_id' => $data['source_calendar_id'],
				'target_calendar_id' => $data['target_calendar_id'],
				'source_calendar_name' => $data['source_calendar_name'] ?? null,
				'target_calendar_name' => $data['target_calendar_name'] ?? null,
				'sync_direction' => $data['sync_direction'] ?? 'bidirectional',
				'is_active' => $data['is_active'] ?? true,
				'sync_enabled' => $data['sync_enabled'] ?? true,
				'tenant_id' => $request->getAttribute('tenant_id')
			]);

			$mappingId = $stmt->fetchColumn();

			$response->getBody()->write(json_encode([
				'success' => true,
				'mapping_id' => $mappingId,
				'message' => 'Resource mapping created successfully'
			]));

			return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
		}
		catch (\Exception $e)
		{
			$response->getBody()->write(json_encode([
				'success' => false,
				'error' => 'Failed to create resource mapping',
				'message' => $e->getMessage()
			]));

			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * Update existing resource mapping.
	 * PUT /mappings/resources/{id}
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args Must include id
	 * @return Response
	 */
	public function updateResourceMapping(Request $request, Response $response, array $args): Response
	{
		try
		{
			$mappingId = $args['id'];
			$data = json_decode($request->getBody()->getContents(), true);

			// Check if mapping exists
			$checkSql = "SELECT * FROM bridge_resource_mappings WHERE id = :id";
			$checkStmt = $this->db->prepare($checkSql);
			$checkStmt->execute(['id' => $mappingId]);
			$existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

			if (!$existing)
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => 'Resource mapping not found'
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			// Build update query dynamically
			$updateFields = [];
			$params = ['id' => $mappingId];

			$allowedFields = [
				'source_calendar_name',
				'target_calendar_name',
				'sync_direction',
				'is_active',
				'sync_enabled',
				'bridge_to',
				'bridge_from',
				'source_calendar_id',
				'target_calendar_id'
			];


			foreach ($allowedFields as $field)
			{
				if (isset($data[$field]))
				{
					$updateFields[] = "{$field} = :{$field}";
					$params[$field] = $data[$field];
				}
			}

			if (empty($updateFields))
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => 'No valid fields to update'
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			$updateFields[] = "updated_at = CURRENT_TIMESTAMP";

			$sql = "UPDATE bridge_resource_mappings SET " . implode(', ', $updateFields) . " WHERE id = :id";
			$stmt = $this->db->prepare($sql);
			if ($stmt->execute($params))
			{
				$response->getBody()->write(json_encode([
					'success' => true,
					'mapping_id' => $mappingId,
					'message' => 'Resource mapping updated successfully'
				]));
			}
			else
			{
				//write the actual error message to the response
				$errorInfo = $stmt->errorInfo();
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => 'Failed to update resource mapping',
					'details' => $errorInfo
				]));
			}

			return $response->withHeader('Content-Type', 'application/json');
		}
		catch (\Exception $e)
		{
			$response->getBody()->write(json_encode([
				'success' => false,
				'error' => 'Failed to update resource mapping',
				'message' => $e->getMessage()
			]));

			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}



	/**
	 * Delete resource mapping by composite key (bridge_from, source_calendar_id, target_calendar_id).
	 * DELETE /mappings/resources/by-key/{bridge_from}/{source_calendar_id}/{target_calendar_id}
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args Must include bridge_from, source_calendar_id, target_calendar_id
	 * @return Response
	 */
	public function deleteResourceMappingByKey(Request $request, Response $response, array $args): Response
	{
		try
		{
			$bridgeFrom = $args['bridge_from'] ?? null;
			$sourceCalendarId = $args['source_calendar_id'] ?? null;
			$targetCalendarId = $args['target_calendar_id'] ?? null;

			// Validate required parameters
			if (!$bridgeFrom || !$sourceCalendarId || !$targetCalendarId)
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => 'Missing required parameters: bridge_from, source_calendar_id, target_calendar_id'
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// URL decode the parameters in case they contain special characters
			$bridgeFrom = urldecode($bridgeFrom);
			$sourceCalendarId = urldecode($sourceCalendarId);
			$targetCalendarId = urldecode($targetCalendarId);

			// Check if mapping exists before deletion (try both directions for backward compatibility)
			$checkSql = "SELECT id, source_calendar_id, target_calendar_id, source_calendar_name, target_calendar_name FROM bridge_resource_mappings 
                        WHERE bridge_from = :bridge_from 
                        AND ((source_calendar_id = :source_calendar_id AND target_calendar_id = :target_calendar_id)
                             OR (source_calendar_id = :target_calendar_id AND target_calendar_id = :source_calendar_id))
                        AND is_active = true";

			$checkStmt = $this->db->prepare($checkSql);
			$checkStmt->execute([
				'bridge_from' => $bridgeFrom,
				'source_calendar_id' => $sourceCalendarId,
				'target_calendar_id' => $targetCalendarId
			]);

			$existingMapping = $checkStmt->fetch(PDO::FETCH_ASSOC);

			if (!$existingMapping)
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => 'Resource mapping not found or already inactive',
					'searched_for' => [
						'bridge_from' => $bridgeFrom,
						'source_calendar_id' => $sourceCalendarId,
						'target_calendar_id' => $targetCalendarId
					]
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			// Soft delete - set is_active to false (consistent with existing delete method)
			$deleteSql = "UPDATE bridge_resource_mappings 
                         SET is_active = false, updated_at = CURRENT_TIMESTAMP
                         WHERE bridge_from = :bridge_from 
                         AND source_calendar_id = :source_calendar_id 
                         AND target_calendar_id = :target_calendar_id";

			$deleteStmt = $this->db->prepare($deleteSql);
			$result = $deleteStmt->execute([
				'bridge_from' => $bridgeFrom,
				'source_calendar_id' => $sourceCalendarId,
				'target_calendar_id' => $targetCalendarId
			]);

			if ($result && $deleteStmt->rowCount() > 0)
			{
				$response->getBody()->write(json_encode([
					'success' => true,
					'message' => 'Resource mapping deleted successfully',
					'deleted_mapping' => [
						'id' => $existingMapping['id'],
						'bridge_from' => $bridgeFrom,
						'source_calendar_id' => $sourceCalendarId,
						'target_calendar_id' => $targetCalendarId,
						'calendar_name' => $existingMapping['calendar_name']
					]
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
			}
			else
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => 'Failed to delete resource mapping'
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
			}
		}
		catch (\Exception $e)
		{
			$response->getBody()->write(json_encode([
				'success' => false,
				'error' => 'Database error occurred',
				'message' => $e->getMessage()
			]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * Get resource mapping by booking system resource ID.
	 * GET /mappings/resources/by-resource/{source_calendar_id}
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args Must include source_calendar_id
	 * @return Response
	 */
	public function getResourceMappingByResource(Request $request, Response $response, array $args): Response
	{
		try
		{
			$sourceCalendarId = $args['source_calendar_id'];
			$queryParams = $request->getQueryParams();
			$bridgeFrom = $queryParams['bridge_from'] ?? 'booking_system';

			$sql = "SELECT * FROM bridge_resource_mappings
                    WHERE source_calendar_id = :source_calendar_id
                    AND bridge_from = :bridge_from
                    AND is_active = true 
                    ORDER BY created_at DESC";

			$stmt = $this->db->prepare($sql);
			$stmt->execute([
				'source_calendar_id' => $sourceCalendarId,
				'bridge_from' => $bridgeFrom
			]);

			$mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

			$response->getBody()->write(json_encode([
				'success' => true,
				'source_calendar_id' => $sourceCalendarId,
				'mappings' => $mappings,
				'count' => count($mappings)
			]));

			return $response->withHeader('Content-Type', 'application/json');
		}
		catch (\Exception $e)
		{
			$response->getBody()->write(json_encode([
				'success' => false,
				'error' => 'Failed to retrieve resource mapping',
				'message' => $e->getMessage()
			]));

			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * Sync resource mapping - trigger sync for specific resource.
	 * POST /mappings/resources/{id}/sync
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args Must include id
	 * @return Response
	 */
	public function syncResourceMapping(Request $request, Response $response, array $args): Response
	{
		try
		{
			$mappingId = $args['id'];

			// Get mapping details
			$sql = "SELECT * FROM bridge_resource_mappings WHERE id = :id AND is_active = true";
			$stmt = $this->db->prepare($sql);
			$stmt->execute(['id' => $mappingId]);
			$mapping = $stmt->fetch(PDO::FETCH_ASSOC);

			if (!$mapping)
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => 'Resource mapping not found or inactive'
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			// Add sync job to queue
			$queueSql = "INSERT INTO bridge_queue (queue_type, source_bridge, target_bridge, payload, priority, tenant_id)
						VALUES ('resource_sync', :source_bridge, :target_bridge, :payload, 1, :tenant_id)";

			$queueStmt = $this->db->prepare($queueSql);
			$queueStmt->execute([
				'source_bridge' => $mapping['bridge_from'],
				'target_bridge' => $mapping['bridge_to'],
				'payload' => json_encode([
					'mapping_id' => $mappingId,
					'source_calendar_id' => $mapping['source_calendar_id'],
					'target_calendar_id' => $mapping['target_calendar_id'],
					'sync_direction' => $mapping['sync_direction']
				]),
				'tenant_id' => $request->getAttribute('tenant_id')
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
		}
		catch (\Exception $e)
		{
			$response->getBody()->write(json_encode([
				'success' => false,
				'error' => 'Failed to queue resource sync',
				'message' => $e->getMessage()
			]));

			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * Legacy method for backward compatibility.
	 * GET /mappings (redirects to /mappings/resources)
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args
	 * @return Response
	 */
	public function getMapping(Request $request, Response $response, array $args): Response
	{
		return $this->getResourceMappings($request, $response);
	}
}
