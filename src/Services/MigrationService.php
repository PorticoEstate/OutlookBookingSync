<?php

namespace App\Services;

use App\Repository\MigrationRepository;
use Exception;

class MigrationService
{
    private $repository;
    private $migrationDir;

    public function __construct(MigrationRepository $repository)
    {
        $this->repository = $repository;
        $this->migrationDir = __DIR__ . '/../../database/migrations';
    }

    public function getMigrationStatus()
    {
        $appliedMigrations = $this->repository->getAppliedMigrations();
        $migrationFiles = $this->getAvailableMigrationFiles();
        
        $migrations = [];
        foreach ($migrationFiles as $file) {
            $version = $this->extractVersionFromFile($file);
            if (!$version) continue;
            
            $isApplied = false;
            $appliedAt = null;
            $description = $this->extractDescriptionFromFile($file);
            
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
        
        usort($migrations, function($a, $b) {
            return version_compare($a['version'], $b['version']);
        });

        return [
            'migrations' => $migrations,
            'total_files' => count($migrationFiles),
            'total_applied' => count($appliedMigrations)
        ];
    }

    public function runMigration($version, $dryRun = false)
    {
        $migrationFile = $this->findMigrationFile($version);
        if (!$migrationFile) {
            throw new Exception("Migration file for version {$version} not found");
        }

        if ($this->repository->isMigrationApplied($version)) {
            throw new Exception("Migration {$version} has already been applied");
        }

        $content = file_get_contents($migrationFile);

        if ($dryRun) {
            return [
                'success' => true,
                'dry_run' => true,
                'version' => $version,
                'filename' => basename($migrationFile),
                'content' => $content,
                'message' => 'Dry run completed - no changes made'
            ];
        }

        $this->repository->executeMigration($content);

        return [
            'success' => true,
            'version' => $version,
            'filename' => basename($migrationFile),
            'message' => "Migration {$version} executed successfully"
        ];
    }

    public function runAllMigrations($dryRun = false)
    {
        $migrationFiles = $this->getAvailableMigrationFiles();
        $pendingMigrations = [];
        
        foreach ($migrationFiles as $file) {
            $version = $this->extractVersionFromFile($file);
            if ($version && !$this->repository->isMigrationApplied($version)) {
                $pendingMigrations[] = [
                    'version' => $version,
                    'file' => $file
                ];
            }
        }
        
        usort($pendingMigrations, function($a, $b) {
            return version_compare($a['version'], $b['version']);
        });
        
        if (empty($pendingMigrations)) {
            return [
                'success' => true,
                'message' => 'No pending migrations found',
                'executed' => []
            ];
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
                    $content = file_get_contents($migration['file']);
                    $this->repository->executeMigration($content);
                    $executed[] = [
                        'version' => $migration['version'],
                        'filename' => basename($migration['file']),
                        'status' => 'success'
                    ];
                }
            } catch (Exception $e) {
                $errors[] = [
                    'version' => $migration['version'],
                    'filename' => basename($migration['file']),
                    'error' => $e->getMessage()
                ];
                break;
            }
        }
        
        return [
            'success' => empty($errors),
            'dry_run' => $dryRun,
            'executed' => $executed,
            'errors' => $errors,
            'message' => $dryRun 
                ? 'Dry run completed - no changes made'
                : (empty($errors) ? 'All migrations executed successfully' : 'Some migrations failed')
        ];
    }

    public function getMigrationContent($version)
    {
        $migrationFile = $this->findMigrationFile($version);
        if (!$migrationFile) {
            throw new Exception("Migration file for version {$version} not found");
        }
        
        return [
            'version' => $version,
            'filename' => basename($migrationFile),
            'content' => file_get_contents($migrationFile)
        ];
    }

    public function createMigration($description, $sqlContent)
    {
        $migrationFiles = $this->getAvailableMigrationFiles();
        $maxVersion = 0;
        
        foreach ($migrationFiles as $file) {
            $version = $this->extractVersionFromFile($file);
            if ($version && is_numeric($version)) {
                $maxVersion = max($maxVersion, intval($version));
            }
        }
        
        $nextVersion = str_pad($maxVersion + 1, 3, '0', STR_PAD_LEFT);
        
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $description));
        $slug = trim($slug, '_');
        $filename = "{$nextVersion}_{$slug}.sql";
        
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
        
        if (!is_dir($this->migrationDir)) {
            mkdir($this->migrationDir, 0755, true);
        }
        
        $filePath = $this->migrationDir . '/' . $filename;
        
        if (file_exists($filePath)) {
            throw new Exception("Migration file already exists: {$filename}");
        }
        
        if (file_put_contents($filePath, $formattedSql) === false) {
            throw new Exception("Failed to create migration file: {$filename}");
        }
        
        return [
            'version' => $nextVersion,
            'filename' => $filename,
            'file_path' => $filePath,
            'message' => "Migration {$nextVersion} created successfully"
        ];
    }

    private function getAvailableMigrationFiles()
    {
        if (!is_dir($this->migrationDir)) {
            return [];
        }
        
        $files = glob($this->migrationDir . '/*.sql');
        return $files ?: [];
    }

    private function extractVersionFromFile($filePath)
    {
        $filename = basename($filePath);
        if (preg_match('/^(\d+)_/', $filename, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function extractDescriptionFromFile($filePath)
    {
        $content = file_get_contents($filePath);
        if (preg_match('/-- Description: (.+)/i', $content, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

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
}
