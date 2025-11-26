<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\TenantService;
use App\Repository\BridgeConfigRepository;

/**
 * AdminController: CRUD tenants, rotate API keys, and manage per-tenant bridge configs.
 */
class AdminController
{
    private TenantService $tenantService;
    private BridgeConfigRepository $configRepository;

    public function __construct(TenantService $tenantService, BridgeConfigRepository $configRepository)
    {
        $this->tenantService = $tenantService;
        $this->configRepository = $configRepository;
    }

    public function listTenants(Request $request, Response $response): Response
    {
        $includeInactive = filter_var($request->getQueryParams()['include_inactive'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
        $result = $this->tenantService->listTenants($includeInactive);
        $response->getBody()->write(json_encode(['tenants' => $result], JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getTenant(Request $request, Response $response, array $args): Response
    {
        $tenant = $this->tenantService->getTenant($args['tenantId']);
        if (!$tenant) {
            $response->getBody()->write(json_encode(['error' => 'Not Found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write(json_encode($tenant, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function createTenant(Request $request, Response $response): Response
    {
        $payload = json_decode($request->getBody()->getContents(), true) ?: [];
        $id = $payload['id'] ?? null;
        $name = $payload['name'] ?? null;
        $active = isset($payload['active']) ? (bool)$payload['active'] : true;

        if (!$id || !$name) {
            $response->getBody()->write(json_encode(['error' => 'id and name are required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $result = $this->tenantService->createTenant($id, $name, $active);
        $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function updateTenant(Request $request, Response $response, array $args): Response
    {
        $payload = json_decode($request->getBody()->getContents(), true) ?: [];
        $name = $payload['name'] ?? null;
        $active = array_key_exists('active', $payload) ? (bool)$payload['active'] : null;

        $result = $this->tenantService->updateTenant($args['tenantId'], $name, $active);
        if (!$result) {
            $response->getBody()->write(json_encode(['error' => 'Not Found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function deleteTenant(Request $request, Response $response, array $args): Response
    {
        $ok = $this->tenantService->deleteTenant($args['tenantId']);
        if (!$ok) {
            $response->getBody()->write(json_encode(['error' => 'Not Found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        return $response->withStatus(204);
    }

    public function rotateApiKey(Request $request, Response $response, array $args): Response
    {
        $result = $this->tenantService->rotateApiKey($args['tenantId']);
        $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getKeyMetadata(Request $request, Response $response, array $args): Response
    {
        $meta = $this->tenantService->getKeyMetadata($args['tenantId']);
        if (!$meta) {
            $response->getBody()->write(json_encode(['error' => 'Not Found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write(json_encode($meta, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function upsertBridgeConfig(Request $request, Response $response, array $args): Response
    {
        $payload = json_decode($request->getBody()->getContents(), true) ?: [];
        if (!is_array($payload)) { $payload = []; }
        
        $this->configRepository->upsert($args['tenantId'], $args['bridgeName'], $payload);
        
        $response->getBody()->write(json_encode(['success' => true], JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getBridgeConfig(Request $request, Response $response, array $args): Response
    {
        $result = $this->configRepository->findByTenantAndName($args['tenantId'], $args['bridgeName']);
        if (!$result) {
            $response->getBody()->write(json_encode(['error' => 'Not Found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getLogs(Request $request, Response $response): Response
    {
        $lines = (int)($request->getQueryParams()['lines'] ?? 100);
        $logType = $request->getQueryParams()['type'] ?? 'application';
        $date = $request->getQueryParams()['date'] ?? null; // For application logs: YYYY-MM-DD
        
        // Determine log file based on type
        switch ($logType) {
            case 'application':
                // Handle rotating file pattern (application-Y-m-d.log)
                $logDir = __DIR__ . '/../../storage/logs/';
                
                if ($date) {
                    // Specific date requested
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                        $response->getBody()->write(json_encode([
                            'error' => 'Invalid date format. Use YYYY-MM-DD format.'
                        ]));
                        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
                    }
                    $logFile = $logDir . "application-{$date}.log";
                } else {
                    // Today's log by default
                    $today = date('Y-m-d');
                    $logFile = $logDir . "application-{$today}.log";
                    
                    // If today's log doesn't exist, find the most recent one
                    if (!file_exists($logFile)) {
                        $files = glob($logDir . 'application-*.log');
                        if (!empty($files)) {
                            // Sort by modification time, newest first
                            usort($files, function($a, $b) {
                                return filemtime($b) - filemtime($a);
                            });
                            $logFile = $files[0];
                        }
                    }
                }
                break;
            case 'bridge-cron':
                $logFile = __DIR__ . '/../../storage/logs/bridge-cron.log';
                break;
            case 'bridge-stats':
                $logFile = __DIR__ . '/../../storage/logs/bridge-stats.log';
                break;
            case 'cron':
                $logFile = __DIR__ . '/../../storage/logs/cron.log';
                break;
            default:
                $response->getBody()->write(json_encode([
                    'error' => 'Invalid log type',
                    'available_types' => ['application', 'bridge-cron', 'bridge-stats', 'cron'],
                    'note' => 'For application logs, add ?date=YYYY-MM-DD to view specific date'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        if (!file_exists($logFile)) {
            // For application logs, show available dates
            $availableDates = [];
            if ($logType === 'application') {
                $logDir = __DIR__ . '/../../storage/logs/';
                $files = glob($logDir . 'application-*.log');
                foreach ($files as $file) {
                    if (preg_match('/application-(\d{4}-\d{2}-\d{2})\.log$/', basename($file), $matches)) {
                        $availableDates[] = $matches[1];
                    }
                }
                rsort($availableDates); // Most recent first
            }
            
            $response->getBody()->write(json_encode([
                'error' => 'Log file not found',
                'logs' => [],
                'log_file' => basename($logFile),
                'log_path' => $logFile,
                'available_dates' => $availableDates
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        
        // Read last N lines efficiently
        $logs = $this->readLastLines($logFile, $lines);
        
        $response->getBody()->write(json_encode([
            'logs' => $logs,
            'total_lines' => count($logs),
            'log_file' => basename($logFile),
            'log_type' => $logType,
            'log_path' => $logFile,
            'file_size' => filesize($logFile),
            'last_modified' => date('c', filemtime($logFile))
        ], JSON_PRETTY_PRINT));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function readLastLines(string $file, int $lines): array
    {
        if (!file_exists($file)) {
            return [];
        }
        
        $handle = fopen($file, "r");
        if (!$handle) {
            return [];
        }
        
        $linecounter = $lines;
        $pos = -2;
        $beginning = false;
        $text = [];
        
        // Get file size
        fseek($handle, 0, SEEK_END);
        $filesize = ftell($handle);
        
        if ($filesize == 0) {
            fclose($handle);
            return [];
        }
        
        while ($linecounter > 0) {
            $t = " ";
            while ($t != "\n") {
                if (fseek($handle, $pos, SEEK_END) == -1) {
                    $beginning = true;
                    break;
                }
                $t = fgetc($handle);
                $pos--;
            }
            $linecounter--;
            if ($beginning) {
                rewind($handle);
            }
            $line = fgets($handle);
            if ($line !== false) {
                $text[$lines-$linecounter-1] = rtrim($line);
            }
            if ($beginning) break;
        }
        fclose($handle);
        
        return array_filter(array_reverse($text));
    }
}
