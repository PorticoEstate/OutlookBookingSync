<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Repository\BridgeMappingRepository;
use App\Repository\BridgeQueueRepository;

/**
 * ResourceMappingController handles resource mapping between booking system and calendar systems.
 */
class ResourceMappingController
{
	private BridgeMappingRepository $repository;
	private BridgeQueueRepository $queueRepository;

	public function __construct(BridgeMappingRepository $repository, BridgeQueueRepository $queueRepository)
	{
		$this->repository = $repository;
		$this->queueRepository = $queueRepository;
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
			$filters = [
				'bridge_from' => $queryParams['bridge_from'] ?? null,
				'bridge_to' => $queryParams['bridge_to'] ?? null,
				'source_calendar_id' => $queryParams['source_calendar_id'] ?? null,
				'target_calendar_id' => $queryParams['target_calendar_id'] ?? null,
				'active_only' => ($queryParams['active_only'] ?? 'true') === 'true',
				'tenant_id' => $request->getAttribute('tenant_id')
			];

			$mappings = $this->repository->findAllResourceMappings($filters);

			foreach ($mappings as &$mapping)
			{
				if (isset($mapping['source_calendar_name']))
				{
					$mapping['source_calendar_name'] = html_entity_decode($mapping['source_calendar_name']);
				}
				if (isset($mapping['target_calendar_name']))
				{
					$mapping['target_calendar_name'] = html_entity_decode($mapping['target_calendar_name']);
				}
			}


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

			// Validate email format for Outlook bridge calendar IDs
			if ($data['bridge_from'] === 'outlook' && !filter_var($data['source_calendar_id'], FILTER_VALIDATE_EMAIL))
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => "Outlook bridge requires source_calendar_id to be in email format. Got: {$data['source_calendar_id']}"
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			if ($data['bridge_to'] === 'outlook' && !filter_var($data['target_calendar_id'], FILTER_VALIDATE_EMAIL))
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => "Outlook bridge requires target_calendar_id to be in email format. Got: {$data['target_calendar_id']}"
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Check if mapping already exists (active or inactive)
			$existingMapping = $this->repository->findResourceMapping(
				$data['bridge_from'],
				$data['bridge_to'],
				$data['source_calendar_id'],
				$data['target_calendar_id'],
				$request->getAttribute('tenant_id')
			);

			if ($existingMapping)
			{
				if ($existingMapping['is_active'])
				{
					$response->getBody()->write(json_encode([
						'success' => false,
						'error' => 'Resource mapping already exists and is active'
					]));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
				}
				else
				{
					// Reactivate the existing mapping
					$this->repository->reactivateResourceMapping(
						$existingMapping['id'],
						$data['sync_direction'] ?? 'bidirectional'
					);

					$response->getBody()->write(json_encode([
						'success' => true,
						'mapping_id' => $existingMapping['id'],
						'message' => 'Resource mapping reactivated successfully',
						'reactivated' => true
					]));

					return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
				}
			}

			// Create new mapping
			$mappingId = $this->repository->createResourceMapping([
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
			$existing = $this->repository->findResourceMappingById($mappingId);

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
			$params = [];

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

			// Validate email format for Outlook bridge calendar IDs being updated
			$bridgeFrom = $data['bridge_from'] ?? $existing['bridge_from'];
			$bridgeTo = $data['bridge_to'] ?? $existing['bridge_to'];

			if ($bridgeFrom === 'outlook' && isset($data['source_calendar_id']) && 
				!filter_var($data['source_calendar_id'], FILTER_VALIDATE_EMAIL))
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => "Outlook bridge requires source_calendar_id to be in email format. Got: {$data['source_calendar_id']}"
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			if ($bridgeTo === 'outlook' && isset($data['target_calendar_id']) && 
				!filter_var($data['target_calendar_id'], FILTER_VALIDATE_EMAIL))
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => "Outlook bridge requires target_calendar_id to be in email format. Got: {$data['target_calendar_id']}"
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			if (empty($updateFields))
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => 'No valid fields to update'
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			if ($this->repository->updateResourceMapping($mappingId, $updateFields, $params))
			{
				$response->getBody()->write(json_encode([
					'success' => true,
					'mapping_id' => $mappingId,
					'message' => 'Resource mapping updated successfully'
				]));
			}
			else
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => 'Failed to update resource mapping'
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

			// Check if mapping exists before deletion (try both directions for backward compatibility), scoped by tenant when provided
			$tenantId = $request->getAttribute('tenant_id');
			$existingMapping = $this->repository->findResourceMappingByCompositeKey(
				$bridgeFrom,
				$sourceCalendarId,
				$targetCalendarId,
				$tenantId
			);

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

			// Determine if there are dependent event rows in bridge_mappings for this calendar pair (either direction)
			$bridgeTo = $existingMapping['bridge_to'] ?? null;
			$dependents = 0;
			if ($bridgeTo !== null)
			{
				$dependents = $this->repository->countDependentMappings(
					$bridgeFrom,
					$bridgeTo,
					$existingMapping['source_calendar_id'],
					$existingMapping['target_calendar_id'],
					$tenantId
				);
			}

			// If there are no dependent rows, hard delete; otherwise, soft delete
			if ($dependents === 0)
			{
				$result = $this->repository->deleteResourceMapping($existingMapping['id']);
			}
			else
			{
				$result = $this->repository->softDeleteResourceMapping($existingMapping['id']);
			}

			if ($result)
			{
				$response->getBody()->write(json_encode([
					'success' => true,
					'message' => 'Resource mapping deleted successfully',
					'deleted_mapping' => [
						'id' => $existingMapping['id'],
						'bridge_from' => $existingMapping['bridge_from'] ?? $bridgeFrom,
						'bridge_to' => $existingMapping['bridge_to'] ?? $bridgeTo,
						'source_calendar_id' => $existingMapping['source_calendar_id'],
						'target_calendar_id' => $existingMapping['target_calendar_id'],
						'source_calendar_name' => $existingMapping['source_calendar_name'] ?? null,
						'target_calendar_name' => $existingMapping['target_calendar_name'] ?? null,
						'soft_deleted' => ($dependents > 0)
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

			$mappings = $this->repository->findResourceMappingsByResource($sourceCalendarId, $bridgeFrom);

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
			$mapping = $this->repository->findResourceMappingById($mappingId);

			if (!$mapping || !$mapping['is_active'])
			{
				$response->getBody()->write(json_encode([
					'success' => false,
					'error' => 'Resource mapping not found or inactive'
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			// Add sync job to queue
			$this->queueRepository->enqueue(
				'resource_sync',
				$mapping['bridge_from'],
				$mapping['bridge_to'],
				[
					'mapping_id' => $mappingId,
					'source_calendar_id' => $mapping['source_calendar_id'],
					'target_calendar_id' => $mapping['target_calendar_id'],
					'sync_direction' => $mapping['sync_direction']
				],
				1,
				$request->getAttribute('tenant_id')
			);

			// Note: last_synced_at will be updated by the queue worker upon successful completion

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

}
