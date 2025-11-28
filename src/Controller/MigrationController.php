<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MigrationService;
use Exception;

/**
 * MigrationController manages database migrations through a web interface
 */
class MigrationController
{
    private $migrationService;
    
    public function __construct(MigrationService $migrationService)
    {
        $this->migrationService = $migrationService;
    }
    
    /**
     * Get migration status and history
     */
    public function getStatus(Request $request, Response $response)
    {
        try {
            $status = $this->migrationService->getMigrationStatus();
            
            $response->getBody()->write(json_encode(array_merge(
                ['success' => true],
                $status
            )));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (Exception $e) {
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
            
            $result = $this->migrationService->runMigration($version, $dryRun);
            
            $response->getBody()->write(json_encode($result));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (Exception $e) {
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
            
            $result = $this->migrationService->runAllMigrations($dryRun);
            
            $response->getBody()->write(json_encode($result));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (Exception $e) {
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
            
            $result = $this->migrationService->getMigrationContent($version);
            
            $response->getBody()->write(json_encode(array_merge(
                ['success' => true],
                $result
            )));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (Exception $e) {
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
            
            $result = $this->migrationService->createMigration($description, $sqlContent);
            
            $response->getBody()->write(json_encode(array_merge(
                ['success' => true],
                $result
            )));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }
}
