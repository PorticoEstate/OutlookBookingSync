<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

/**
 * MigrationController manages database migrations through a web interface
 */
class MigrationController
{
    private $db;
    
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    
    /**
     * Get migration status and history
     */
    public function getStatus(Request $request, Response $response)
    {
        try {
            // Ensure schema_migrations table exists
            $this->ensureSchemaMigrationsTable();
            
            // Get applied migrations
            $appliedStmt = $this->db->prepare("
                SELECT version, description, applied_at 
                FROM schema_migrations 
                ORDER BY version ASC
            ");
            $appliedStmt->execute();
            $appliedMigrations = $appliedStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get available migration files
            $migrationFiles = $this->getAvailableMigrationFiles();
            
            // Build status array
            $migrations = [];
            foreach ($migrationFiles as $file) {
                $version = $this->extractVersionFromFile($file);
                if (!$version) continue;
                
                $isApplied = false;
                $appliedAt = null;
                $description = $this->extractDescriptionFromFile($file);
                
                // Check if this migration has been applied
                foreach ($appliedMigrations as $applied) {
                    if ($applied['version'] === $version) {
                        $isApplied = true;
                        $appliedAt = $applied['applied_at'];
                        if (!$description) {
                            $description = $applied['description'];
                        }
                        break;
                    }
                }
                
                $migrations[] = [
                    'version' => $version,
                    'filename' => basename($file),
                    'description' => $description ?: 'No description available',
                    'is_applied' => $isApplied,
                    'applied_at' => $appliedAt,
                    'file_path' => $file
                ];
            }
            
            // Sort by version
            usort($migrations, function($a, $b) {
                return version_compare($a['version'], $b['version']);
            });
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'migrations' => $migrations,
                'total_files' => count($migrationFiles),
                'total_applied' => count($appliedMigrations)
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
     * Execute a specific migration
     */
    public function runMigration(Request $request, Response $response)
    {
        try {
            $body = json_decode($request->getBody()->getContents(), true);
            $version = $body['version'] ?? null;
            $dryRun = $body['dry_run'] ?? false;
            
            if (!$version) {
                throw new \InvalidArgumentException('Version is required');
            }
            
            // Find the migration file
            $migrationFile = $this->findMigrationFile($version);
            if (!$migrationFile) {
                throw new \Exception("Migration file for version {$version} not found");
            }
            
            // Check if already applied
            if ($this->isMigrationApplied($version)) {
                throw new \Exception("Migration {$version} has already been applied");
            }
            
            if ($dryRun) {
                // Read and return the migration content for preview
                $content = file_get_contents($migrationFile);
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'dry_run' => true,
                    'version' => $version,
                    'filename' => basename($migrationFile),
                    'content' => $content,
                    'message' => 'Dry run completed - no changes made'
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }
            
            // Execute the migration
            $result = $this->executeMigrationFile($migrationFile);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'version' => $version,
                'filename' => basename($migrationFile),
                'message' => "Migration {$version} executed successfully",
                'output' => $result['output'] ?? null
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
     * Run all pending migrations
     */
    public function runAllMigrations(Request $request, Response $response)
    {
        try {
            $body = json_decode($request->getBody()->getContents(), true);
            $dryRun = $body['dry_run'] ?? false;
            
            $migrationFiles = $this->getAvailableMigrationFiles();
            $pendingMigrations = [];
            
            foreach ($migrationFiles as $file) {
                $version = $this->extractVersionFromFile($file);
                if ($version && !$this->isMigrationApplied($version)) {
                    $pendingMigrations[] = [
                        'version' => $version,
                        'file' => $file
                    ];
                }
            }
            
            // Sort by version
            usort($pendingMigrations, function($a, $b) {
                return version_compare($a['version'], $b['version']);
            });
            
            if (empty($pendingMigrations)) {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'message' => 'No pending migrations found',
                    'executed' => []
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }
            
            $executed = [];
            $errors = [];
            
            foreach ($pendingMigrations as $migration) {
                try {
                    if ($dryRun) {
                        $executed[] = [
                            'version' => $migration['version'],
                            'filename' => basename($migration['file']),
                            'status' => 'would_execute',
                            'dry_run' => true
                        ];
                    } else {
                        $result = $this->executeMigrationFile($migration['file']);
                        $executed[] = [
                            'version' => $migration['version'],
                            'filename' => basename($migration['file']),
                            'status' => 'success',
                            'output' => $result['output'] ?? null
                        ];
                    }
                } catch (\Exception $e) {
                    $errors[] = [
                        'version' => $migration['version'],
                        'filename' => basename($migration['file']),
                        'error' => $e->getMessage()
                    ];
                    
                    // Stop on first error to maintain consistency
                    break;
                }
            }
            
            $response->getBody()->write(json_encode([
                'success' => empty($errors),
                'dry_run' => $dryRun,
                'executed' => $executed,
                'errors' => $errors,
                'message' => $dryRun 
                    ? 'Dry run completed - no changes made'
                    : (empty($errors) ? 'All migrations executed successfully' : 'Some migrations failed')
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
     * Get migration file content for preview
     */
    public function getMigrationContent(Request $request, Response $response, $args)
    {
        try {
            $version = $args['version'] ?? null;
            
            if (!$version) {
                throw new \InvalidArgumentException('Version is required');
            }
            
            $migrationFile = $this->findMigrationFile($version);
            if (!$migrationFile) {
                throw new \Exception("Migration file for version {$version} not found");
            }
            
            $content = file_get_contents($migrationFile);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'version' => $version,
                'filename' => basename($migrationFile),
                'content' => $content
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
     * Create a new migration file
     */
    public function createMigration(Request $request, Response $response)
    {
        try {
            $body = json_decode($request->getBody()->getContents(), true);
            $description = trim($body['description'] ?? '');
            $sqlContent = trim($body['sql_content'] ?? '');
            
            if (empty($description)) {
                throw new \InvalidArgumentException('Description is required');
            }
            
            if (empty($sqlContent)) {
                throw new \InvalidArgumentException('SQL content is required');
            }
            
            // Generate next version number
            $migrationFiles = $this->getAvailableMigrationFiles();
            $maxVersion = 0;
            
            foreach ($migrationFiles as $file) {
                $version = $this->extractVersionFromFile($file);
                if ($version && is_numeric($version)) {
                    $maxVersion = max($maxVersion, intval($version));
                }
            }
            
            $nextVersion = str_pad($maxVersion + 1, 3, '0', STR_PAD_LEFT);
            
            // Generate filename
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $description));
            $slug = trim($slug, '_');
            $filename = "{$nextVersion}_{$slug}.sql";
            
            // Format SQL content with proper header
            $date = date('Y-m-d');
            $formattedSql = "-- Migration: {$description}\n";
            $formattedSql .= "-- Description: {$description}\n";
            $formattedSql .= "-- Version: {$nextVersion}\n";
            $formattedSql .= "-- Date: {$date}\n\n";
            $formattedSql .= $sqlContent . "\n\n";
            $formattedSql .= "-- Record migration completion\n";
            $formattedSql .= "INSERT INTO schema_migrations (version, description, applied_at) \n";
            $formattedSql .= "VALUES ('{$nextVersion}', '{$description}', NOW())\n";
            $formattedSql .= "ON CONFLICT (version) DO NOTHING;\n\n";
            $formattedSql .= "-- Display completion message\n";
            $formattedSql .= "SELECT 'Migration {$nextVersion}: {$description} completed successfully' AS result;\n";
            
            // Create migration file
            $migrationDir = __DIR__ . '/../../database/migrations';
            if (!is_dir($migrationDir)) {
                mkdir($migrationDir, 0755, true);
            }
            
            $filePath = $migrationDir . '/' . $filename;
            
            if (file_exists($filePath)) {
                throw new \Exception("Migration file already exists: {$filename}");
            }
            
            if (file_put_contents($filePath, $formattedSql) === false) {
                throw new \Exception("Failed to create migration file: {$filename}");
            }
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'version' => $nextVersion,
                'filename' => $filename,
                'file_path' => $filePath,
                'message' => "Migration {$nextVersion} created successfully"
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
     * Ensure schema_migrations table exists
     */
    private function ensureSchemaMigrationsTable()
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(50) PRIMARY KEY,
                description TEXT,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
    
    /**
     * Get all available migration files
     */
    private function getAvailableMigrationFiles()
    {
        $migrationDir = __DIR__ . '/../../database/migrations';
        if (!is_dir($migrationDir)) {
            return [];
        }
        
        $files = glob($migrationDir . '/*.sql');
        return $files ?: [];
    }
    
    /**
     * Extract version number from migration filename
     */
    private function extractVersionFromFile($filePath)
    {
        $filename = basename($filePath);
        if (preg_match('/^(\d+)_/', $filename, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    /**
     * Extract description from migration file
     */
    private function extractDescriptionFromFile($filePath)
    {
        $content = file_get_contents($filePath);
        if (preg_match('/-- Description: (.+)/i', $content, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
    
    /**
     * Check if a migration has been applied
     */
    private function isMigrationApplied($version)
    {
        $this->ensureSchemaMigrationsTable();
        
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM schema_migrations WHERE version = ?");
        $stmt->execute([$version]);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Find migration file by version
     */
    private function findMigrationFile($version)
    {
        $migrationFiles = $this->getAvailableMigrationFiles();
        
        foreach ($migrationFiles as $file) {
            if ($this->extractVersionFromFile($file) === $version) {
                return $file;
            }
        }
        
        return null;
    }
    
    /**
     * Execute a migration file
     */
    private function executeMigrationFile($filePath)
    {
        $content = file_get_contents($filePath);
        
        if (!$content) {
            throw new \Exception("Failed to read migration file: " . basename($filePath));
        }
        
        $version = $this->extractVersionFromFile($filePath);
        $description = $this->extractDescriptionFromFile($filePath);
        
        // Begin transaction
        $this->db->beginTransaction();
        
        try {
            // Execute the migration SQL
            $this->db->exec($content);
            
            // Commit transaction
            $this->db->commit();
            
            return [
                'success' => true,
                'output' => "Migration {$version} executed successfully",
                'version' => $version,
                'description' => $description
            ];
            
        } catch (\Exception $e) {
            // Rollback transaction
            $this->db->rollBack();
            throw new \Exception("Migration {$version} failed: " . $e->getMessage());
        }
    }
}
