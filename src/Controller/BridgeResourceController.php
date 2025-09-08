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
    private $db;
    
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    
    /**
     * List bridge resources with filtering
     */
    public function listResources(Request $request, Response $response)
    {
        try {
            $queryParams = $request->getQueryParams();
            $tenantId = (string)($request->getAttribute('tenant_id') ?? '');
            
            $bridgeName = $queryParams['bridge_name'] ?? null;
            $bridgeType = $queryParams['bridge_type'] ?? null;
            $resourceType = $queryParams['resource_type'] ?? null;
            $activeOnly = filter_var($queryParams['active_only'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
            $search = $queryParams['search'] ?? null;
            $limit = min((int)($queryParams['limit'] ?? 100), 500);
            $offset = max((int)($queryParams['offset'] ?? 0), 0);
            
            $conditions = ['1=1'];
            $params = [];
            
            if ($tenantId !== '') {
                $conditions[] = '(tenant_id IS NOT DISTINCT FROM :tenant_id)';
                $params['tenant_id'] = $tenantId;
            }
            
            if ($bridgeName) {
                $conditions[] = 'bridge_name = :bridge_name';
                $params['bridge_name'] = $bridgeName;
            }
            
            if ($bridgeType) {
                $conditions[] = 'bridge_type = :bridge_type';
                $params['bridge_type'] = $bridgeType;
            }
            
            if ($resourceType) {
                $conditions[] = 'resource_type = :resource_type';
                $params['resource_type'] = $resourceType;
            }
            
            if ($activeOnly) {
                $conditions[] = 'is_active = true';
            }
            
            if ($search) {
                $conditions[] = '(resource_name ILIKE :search OR resource_email ILIKE :search OR location ILIKE :search)';
                $params['search'] = '%' . $search . '%';
            }
            
            $whereClause = implode(' AND ', $conditions);
            
            // Get total count
            $countSql = "SELECT COUNT(*) FROM bridge_resources WHERE $whereClause";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $total = $countStmt->fetchColumn();
            
            // Get resources
            $sql = "SELECT * FROM bridge_resources WHERE $whereClause ORDER BY resource_name LIMIT :limit OFFSET :offset";
            $params['limit'] = $limit;
            $params['offset'] = $offset;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $resources = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'resources' => $resources,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total
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
            
            $sql = "INSERT INTO bridge_resources (
                bridge_name, bridge_type, resource_id, resource_email, resource_name,
                resource_type, capacity, location, description, resource_data, 
                is_active, tenant_id
            ) VALUES (
                :bridge_name, :bridge_type, :resource_id, :resource_email, :resource_name,
                :resource_type, :capacity, :location, :description, :resource_data,
                :is_active, :tenant_id
            )";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'bridge_name' => $data['bridge_name'],
                'bridge_type' => $data['bridge_type'],
                'resource_id' => $data['resource_id'],
                'resource_email' => $data['resource_email'] ?? null,
                'resource_name' => $data['resource_name'],
                'resource_type' => $data['resource_type'] ?? 'room',
                'capacity' => isset($data['capacity']) ? (int)$data['capacity'] : null,
                'location' => $data['location'] ?? null,
                'description' => $data['description'] ?? null,
                'resource_data' => isset($data['resource_data']) ? json_encode($data['resource_data']) : null,
                'is_active' => filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'tenant_id' => $tenantId !== '' ? $tenantId : null
            ]);
            
            $resourceId = $this->db->lastInsertId();
            
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
            
            // Build dynamic update query
            $fields = [];
            $params = ['id' => $id];
            
            $allowedFields = [
                'bridge_name', 'bridge_type', 'resource_id', 'resource_email', 
                'resource_name', 'resource_type', 'capacity', 'location', 
                'description', 'is_active'
            ];
            
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = :$field";
                    if ($field === 'capacity') {
                        $params[$field] = isset($data[$field]) ? (int)$data[$field] : null;
                    } elseif ($field === 'is_active') {
                        $params[$field] = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN);
                    } else {
                        $params[$field] = $data[$field];
                    }
                }
            }
            
            if (isset($data['resource_data'])) {
                $fields[] = 'resource_data = :resource_data';
                $params['resource_data'] = json_encode($data['resource_data']);
            }
            
            if (empty($fields)) {
                throw new \Exception('No fields to update');
            }
            
            $fields[] = 'updated_at = CURRENT_TIMESTAMP';
            
            $sql = "UPDATE bridge_resources SET " . implode(', ', $fields) . " WHERE id = :id";
            
            // Add tenant constraint if tenant is specified
            if ($tenantId !== '') {
                $sql .= " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
                $params['tenant_id'] = $tenantId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            if ($stmt->rowCount() === 0) {
                throw new \Exception('Resource not found or not accessible');
            }
            
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
            
            $sql = "DELETE FROM bridge_resources WHERE id = :id";
            $params = ['id' => $id];
            
            if ($tenantId !== '') {
                $sql .= " AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
                $params['tenant_id'] = $tenantId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            if ($stmt->rowCount() === 0) {
                throw new \Exception('Resource not found or not accessible');
            }
            
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
            $data = json_decode($request->getBody()->getContents(), true);
            $tenantId = (string)($request->getAttribute('tenant_id') ?? '');
            
            if (!isset($data['csv_data']) || !isset($data['bridge_name']) || !isset($data['bridge_type'])) {
                throw new \Exception('csv_data, bridge_name, and bridge_type are required');
            }
            
            $csvData = $data['csv_data'];
            $bridgeName = $data['bridge_name'];
            $bridgeType = $data['bridge_type'];
            $updateExisting = filter_var($data['update_existing'] ?? false, FILTER_VALIDATE_BOOLEAN);
            
            // Parse CSV
            $lines = array_filter(array_map('trim', explode("\n", $csvData)));
            if (empty($lines)) {
                throw new \Exception('No data found in CSV');
            }
            
            // Get header
            $header = str_getcsv(array_shift($lines), ';');
            $header = array_map('trim', $header);
            
            // Validate required columns
            $requiredColumns = ['id', 'displayName'];
            foreach ($requiredColumns as $col) {
                if (!in_array($col, $header)) {
                    throw new \Exception("Required column '$col' not found in CSV header");
                }
            }
            
            $imported = 0;
            $updated = 0;
            $errors = [];
            
            $this->db->beginTransaction();
            
            try {
                foreach ($lines as $lineNum => $line) {
                    if (empty(trim($line))) continue;
                    
                    $row = str_getcsv($line, ';');
                    $record = array_combine($header, $row);
                    
                    if (!$record || empty($record['id']) || empty($record['displayName'])) {
                        $errors[] = "Line " . ($lineNum + 2) . ": Missing required fields";
                        continue;
                    }
                    
                    // Extract capacity from display name if present
                    $capacity = null;
                    if (preg_match('/\((\d+)\s*pers\)/', $record['displayName'], $matches)) {
                        $capacity = (int)$matches[1];
                    }
                    
                    // Extract location from display name (everything before the last hyphen)
                    $location = null;
                    $nameParts = explode(' - ', $record['displayName']);
                    if (count($nameParts) > 1) {
                        array_pop($nameParts); // Remove the last part (room name)
                        $location = implode(' - ', $nameParts);
                    }
                    
                    // Check if resource exists
                    $checkSql = "SELECT id FROM bridge_resources WHERE bridge_name = :bridge_name AND resource_id = :resource_id AND (tenant_id IS NOT DISTINCT FROM :tenant_id)";
                    $checkStmt = $this->db->prepare($checkSql);
                    $checkStmt->execute([
                        'bridge_name' => $bridgeName,
                        'resource_id' => $record['id'],
                        'tenant_id' => $tenantId !== '' ? $tenantId : null
                    ]);
                    
                    $existingId = $checkStmt->fetchColumn();
                    
                    if ($existingId && $updateExisting) {
                        // Update existing
                        $updateSql = "UPDATE bridge_resources SET 
                            resource_email = :resource_email,
                            resource_name = :resource_name,
                            capacity = :capacity,
                            location = :location,
                            updated_at = CURRENT_TIMESTAMP
                            WHERE id = :id";
                        
                        $updateStmt = $this->db->prepare($updateSql);
                        $updateStmt->execute([
                            'id' => $existingId,
                            'resource_email' => $record['userPrincipalName'] ?? null,
                            'resource_name' => $record['displayName'],
                            'capacity' => $capacity,
                            'location' => $location
                        ]);
                        $updated++;
                        
                    } elseif (!$existingId) {
                        // Insert new
                        $insertSql = "INSERT INTO bridge_resources (
                            bridge_name, bridge_type, resource_id, resource_email, resource_name,
                            resource_type, capacity, location, tenant_id
                        ) VALUES (
                            :bridge_name, :bridge_type, :resource_id, :resource_email, :resource_name,
                            :resource_type, :capacity, :location, :tenant_id
                        )";
                        
                        $insertStmt = $this->db->prepare($insertSql);
                        $insertStmt->execute([
                            'bridge_name' => $bridgeName,
                            'bridge_type' => $bridgeType,
                            'resource_id' => $record['id'],
                            'resource_email' => $record['userPrincipalName'] ?? null,
                            'resource_name' => $record['displayName'],
                            'resource_type' => 'room',
                            'capacity' => $capacity,
                            'location' => $location,
                            'tenant_id' => $tenantId !== '' ? $tenantId : null
                        ]);
                        $imported++;
                    }
                }
                
                $this->db->commit();
                
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'imported' => $imported,
                    'updated' => $updated,
                    'errors' => $errors,
                    'message' => "Successfully processed CSV. Imported: $imported, Updated: $updated"
                ]));
                
                return $response->withHeader('Content-Type', 'application/json');
                
            } catch (\Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
            
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
            
            $whereClause = $tenantId !== '' ? 'WHERE (tenant_id IS NOT DISTINCT FROM :tenant_id)' : 'WHERE 1=1';
            $params = $tenantId !== '' ? ['tenant_id' => $tenantId] : [];
            
            // Get stats by bridge and type
            $sql = "SELECT 
                bridge_name,
                bridge_type,
                resource_type,
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE is_active = true) as active,
                AVG(capacity) FILTER (WHERE capacity IS NOT NULL) as avg_capacity
                FROM bridge_resources 
                $whereClause
                GROUP BY bridge_name, bridge_type, resource_type
                ORDER BY bridge_name, resource_type";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
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
