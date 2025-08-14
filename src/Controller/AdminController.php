<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\TenantService;
use PDO;

/**
 * AdminController: CRUD tenants, rotate API keys, and manage per-tenant bridge configs.
 */
class AdminController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function listTenants(Request $request, Response $response): Response
    {
        $service = new TenantService($this->db);
        $includeInactive = filter_var($request->getQueryParams()['include_inactive'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
        $result = $service->listTenants($includeInactive);
        $response->getBody()->write(json_encode(['tenants' => $result], JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getTenant(Request $request, Response $response, array $args): Response
    {
        $service = new TenantService($this->db);
        $tenant = $service->getTenant($args['tenantId']);
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

        $service = new TenantService($this->db);
        $result = $service->createTenant($id, $name, $active);
        $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function updateTenant(Request $request, Response $response, array $args): Response
    {
        $payload = json_decode($request->getBody()->getContents(), true) ?: [];
        $name = $payload['name'] ?? null;
        $active = array_key_exists('active', $payload) ? (bool)$payload['active'] : null;

        $service = new TenantService($this->db);
        $result = $service->updateTenant($args['tenantId'], $name, $active);
        if (!$result) {
            $response->getBody()->write(json_encode(['error' => 'Not Found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function deleteTenant(Request $request, Response $response, array $args): Response
    {
        $service = new TenantService($this->db);
        $ok = $service->deleteTenant($args['tenantId']);
        if (!$ok) {
            $response->getBody()->write(json_encode(['error' => 'Not Found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        return $response->withStatus(204);
    }

    public function rotateApiKey(Request $request, Response $response, array $args): Response
    {
        $service = new TenantService($this->db);
        $result = $service->rotateApiKey($args['tenantId']);
        $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getKeyMetadata(Request $request, Response $response, array $args): Response
    {
        $service = new TenantService($this->db);
        $meta = $service->getKeyMetadata($args['tenantId']);
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
        $service = new TenantService($this->db);
        $result = $service->upsertBridgeConfig($args['tenantId'], $args['bridgeName'], $payload);
        $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getBridgeConfig(Request $request, Response $response, array $args): Response
    {
        $service = new TenantService($this->db);
        $result = $service->getBridgeConfig($args['tenantId'], $args['bridgeName']);
        if (!$result) {
            $response->getBody()->write(json_encode(['error' => 'Not Found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
