<?php

namespace App\Services;

use App\Repository\BridgeResourceRepository;
use Psr\Log\LoggerInterface;
use PDO;

class ResourceImportService
{
    private $resourceRepository;
    private $logger;
    private $db;

    public function __construct(
        BridgeResourceRepository $resourceRepository,
        LoggerInterface $logger,
        PDO $db
    ) {
        $this->resourceRepository = $resourceRepository;
        $this->logger = $logger;
        $this->db = $db;
    }

    /**
     * Import resources from CSV data.
     *
     * @param string $csvData
     * @param string $bridgeName
     * @param string $bridgeType
     * @param bool $updateExisting
     * @param string|null $tenantId
     * @return array Import statistics
     */
    public function importFromCSV(string $csvData, string $bridgeName, string $bridgeType, bool $updateExisting, ?string $tenantId): array
    {
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
                if (count($row) !== count($header)) {
                    $errors[] = "Line " . ($lineNum + 2) . ": Column count mismatch";
                    continue;
                }
                
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
                $existingResource = $this->resourceRepository->findByResourceId($bridgeName, $record['id'], $tenantId);

                if ($existingResource && $updateExisting) {
                    // Update existing
                    $updateData = [
                        'resource_email' => $record['userPrincipalName'] ?? null,
                        'resource_name' => $record['displayName'],
                        'capacity' => $capacity,
                        'location' => $location
                    ];
                    
                    $this->resourceRepository->update($existingResource['id'], $updateData, $tenantId);
                    $updated++;

                } elseif (!$existingResource) {
                    // Insert new
                    $createData = [
                        'bridge_name' => $bridgeName,
                        'bridge_type' => $bridgeType,
                        'resource_id' => $record['id'],
                        'resource_email' => $record['userPrincipalName'] ?? null,
                        'resource_name' => $record['displayName'],
                        'resource_type' => 'room',
                        'capacity' => $capacity,
                        'location' => $location,
                        'tenant_id' => $tenantId
                    ];
                    
                    $this->resourceRepository->create($createData);
                    $imported++;
                }
            }

            $this->db->commit();

            return [
                'success' => true,
                'imported' => $imported,
                'updated' => $updated,
                'errors' => $errors,
                'message' => "Successfully processed CSV. Imported: $imported, Updated: $updated"
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('CSV import failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
