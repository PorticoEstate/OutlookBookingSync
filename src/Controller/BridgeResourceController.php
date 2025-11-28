<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

/**
 * BridgeResourceController manages bridge resources (rooms, equipment, etc.)
 * Used when bridge APIs don't provide direct resource listing
 */
class BridgeResourceController
{
    private $resourceRepository;
    private $importService;
    
    public function __construct(
        \App\Repository\BridgeResourceRepository $resourceRepository,
        \App\Services\ResourceImportService $importService
    ) {
        $this->resourceRepository = $resourceRepository;
        $this->importService = $importService;
    }
    
    /**
     * List bridge resources with filtering
     */
    public function listResources(Request $request, Response $response)
    {
        try {
            $queryParams = $request->getQueryParams();
            $tenantId = (string)($request->getAttribute('tenant_id') ?? '');
            
            $filters = [
                'bridge_name' => $queryParams['bridge_name'] ?? null,
                'bridge_type' => $queryParams['bridge_type'] ?? null,
                'resource_type' => $queryParams['resource_type'] ?? null,
                'active_only' => filter_var($queryParams['active_only'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
                'search' => $queryParams['search'] ?? null,
                'limit' => min((int)($queryParams['limit'] ?? 100), 500),
                'offset' => max((int)($queryParams['offset'] ?? 0), 0)
            ];
            
            $total = $this->resourceRepository->count($filters, $tenantId !== '' ? $tenantId : null);
            $resources = $this->resourceRepository->findAll($filters, $tenantId !== '' ? $tenantId : null);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'resources' => $resources,
                'pagination' => [
                    'total' => $total,
                    'limit' => $filters['limit'],
                    'offset' => $filters['offset'],
                    'has_more' => ($filters['offset'] + $filters['limit']) < $total
                ]
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Create a new bridge resource
     */
    public function createResource(Request $request, Response $response)
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            $tenantId = (string)($request->getAttribute('tenant_id') ?? '');
            
            $required = ['bridge_name', 'bridge_type', 'resource_id', 'resource_name'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new \Exception("Field '$field' is required");
                }
            }
            
            // Add tenant_id to data if not present
            if (!isset($data['tenant_id']) && $tenantId !== '') {
                $data['tenant_id'] = $tenantId;
            }
            
            $resourceId = $this->resourceRepository->create($data);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'resource_id' => $resourceId,
                'message' => 'Resource created successfully'
            ]));
            
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Update an existing bridge resource
     */
    public function updateResource(Request $request, Response $response, $args)
    {
        try {
            $id = (int)$args['id'];
            $data = json_decode($request->getBody()->getContents(), true);
            $tenantId = (string)($request->getAttribute('tenant_id') ?? '');
            
            // Verify ownership if tenant_id is present
            $existing = $this->resourceRepository->find($id);
            if (!$existing) {
                throw new \Exception('Resource not found');
            }
            
            if ($tenantId !== '' && ($existing['tenant_id'] ?? '') !== $tenantId) {
                throw new \Exception('Resource not accessible');
            }
            
            $this->resourceRepository->update($id, $data);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Resource updated successfully'
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Delete a bridge resource
     */
    public function deleteResource(Request $request, Response $response, $args)
    {
        try {
            $id = (int)$args['id'];
            $tenantId = (string)($request->getAttribute('tenant_id') ?? '');
            
            // Verify ownership if tenant_id is present
            $existing = $this->resourceRepository->find($id);
            if (!$existing) {
                throw new \Exception('Resource not found');
            }
            
            if ($tenantId !== '' && ($existing['tenant_id'] ?? '') !== $tenantId) {
                throw new \Exception('Resource not accessible');
            }
            
            $this->resourceRepository->delete($id);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Resource deleted successfully'
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Import resources from CSV
     */
    public function importFromCSV(Request $request, Response $response)
    {
        try {
            $uploadedFiles = $request->getUploadedFiles();
            $tenantId = (string)($request->getAttribute('tenant_id') ?? '');
            
            if (empty($uploadedFiles['csv_file'])) {
                throw new \Exception('No CSV file uploaded');
            }
            
            $csvFile = $uploadedFiles['csv_file'];
            if ($csvFile->getError() !== UPLOAD_ERR_OK) {
                throw new \Exception('File upload error');
            }
            
            $params = $request->getParsedBody();
            $bridgeName = $params['bridge_name'] ?? null;
            $bridgeType = $params['bridge_type'] ?? 'generic';
            $updateExisting = filter_var($params['update_existing'] ?? false, FILTER_VALIDATE_BOOLEAN);
            
            if (empty($bridgeName)) {
                throw new \Exception('Bridge name is required');
            }
            
            $csvData = $csvFile->getStream()->getContents();
            
            $result = $this->importService->importFromCSV(
                $csvData,
                $bridgeName,
                $bridgeType,
                $updateExisting,
                $tenantId !== '' ? $tenantId : null
            );
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => "Import completed: {$result['imported']} imported, {$result['updated']} updated, {$result['failed']} failed",
                'details' => $result
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }
    
    /**
     * Get resource types and bridge statistics
     */
    public function getStats(Request $request, Response $response)
    {
        try {
            $tenantId = (string)($request->getAttribute('tenant_id') ?? '');
            
            $stats = $this->resourceRepository->getStats($tenantId !== '' ? $tenantId : null);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'stats' => $stats
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}
