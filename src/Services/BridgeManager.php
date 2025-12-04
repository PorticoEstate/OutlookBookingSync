<?php

namespace App\Services;

use App\Bridge\AbstractCalendarBridge;
use App\Services\SyncLogService;
use App\Services\SessionManager;
use App\Repository\BridgeMappingRepository;
use App\Repository\BridgeConfigRepository;
use Psr\Log\LoggerInterface;
use PDO;

/**
 * BridgeManager coordinates bridge registration, instantiation, and sync orchestration.
 */
class BridgeManager
{
	private $bridges = [];
	private $logger;
	private $db;
	private $syncLog;
	private $mappingRepository;
	private $configRepository;
    private $sessionManager;
	/** @var array<string, array<string, AbstractCalendarBridge>> */
	private $tenantBridgeCache = [];

	/**
	 * @param LoggerInterface $logger
	 * @param PDO $db
	 * @param SyncLogService $syncLog
	 * @param BridgeMappingRepository|null $mappingRepository
	 * @param BridgeConfigRepository|null $configRepository
     * @param SessionManager|null $sessionManager
	 */
	public function __construct(
        LoggerInterface $logger, 
        PDO $db, 
        SyncLogService $syncLog, 
        ?BridgeMappingRepository $mappingRepository = null, 
        ?BridgeConfigRepository $configRepository = null,
        ?SessionManager $sessionManager = null
    ) {
		$this->logger = $logger;
		$this->db = $db;
		$this->syncLog = $syncLog;
		$this->mappingRepository = $mappingRepository ?: new BridgeMappingRepository($db);
		$this->configRepository = $configRepository ?: new BridgeConfigRepository($db);
        $this->sessionManager = $sessionManager ?: new SessionManager($logger);
	}

	/**
	 * Register a calendar bridge.
	 *
	 * @param string $name Bridge name
	 * @param string $bridgeClass FQCN extending AbstractCalendarBridge
	 * @param array $config Default configuration
	 */
	public function registerBridge($name, $bridgeClass, $config)
	{
		if (!is_subclass_of($bridgeClass, AbstractCalendarBridge::class))
		{
			throw new \InvalidArgumentException("Bridge class must extend AbstractCalendarBridge");
		}

		$this->bridges[$name] = [
			'class' => $bridgeClass,
			'config' => $config,
			'instance' => null
		];

		$this->logger->info('Bridge registered', [
			'bridge_name' => $name,
			'bridge_class' => $bridgeClass
		]);
	}


	/**
	 * Get a bridge instance configured for a specific tenant.
	 * Falls back to globally registered config when tenant-specific config is absent.
	 *
	 * @param string $tenantId Tenant identifier
	 * @param string $name Bridge name
	 * @return AbstractCalendarBridge
	 */
	public function getBridgeForTenant(string $tenantId, string $name): AbstractCalendarBridge
	{
		// Use cached per-tenant instance if available
		if (isset($this->tenantBridgeCache[$tenantId][$name]))
		{
			return $this->tenantBridgeCache[$tenantId][$name];
		}

		// Resolve base registration
		if (!isset($this->bridges[$name]))
		{
			throw new \Exception("Bridge '{$name}' not found");
		}

		$class = $this->bridges[$name]['class'];
		$baseConfig = $this->bridges[$name]['config'] ?? [];

		// Attempt to load tenant-specific override from DB bridge_configs
		$tenantConfig = $this->loadTenantBridgeConfig($tenantId, $name);
		$config = $tenantConfig ? array_replace_recursive($baseConfig, $tenantConfig) : $baseConfig;
		// Inject context tenant id without colliding with bridge-specific config keys
		$config['context_tenant_id'] = $tenantId;

		$instance = new $class($config, $this->logger, $this->db, $this->mappingRepository, $this->sessionManager);

		// Cache the instance for this tenant
		$this->tenantBridgeCache[$tenantId][$name] = $instance;
		return $instance;
	}

	private function loadTenantBridgeConfig(string $tenantId, string $bridgeName): ?array
	{
		try
		{
			return $this->configRepository->findByTenantAndName($tenantId, $bridgeName);
		}
		catch (\Throwable $e)
		{
			$this->logger->warning('Failed to load tenant bridge config', ['tenant_id' => $tenantId, 'bridge' => $bridgeName, 'error' => $e->getMessage()]);
		}
		return null;
	}

	/**
	 * Get bridge information.
	 *
	 * @param string $name
     * @param string|null $tenantId
	 * @return array
	 */
	public function getBridgeInfo(string $name, ?string $tenantId = null): array
	{
		if (!isset($this->bridges[$name]))
		{
			throw new \Exception("Bridge '{$name}' not found");
		}

		$tenantId = $tenantId ?? $_ENV['DEFAULT_TENANT_ID'] ?? 'default';
        
        $bridge = $this->getBridgeForTenant($tenantId, $name);

		return [
			'name' => $name,
			'type' => $bridge->getBridgeType(),
			'class' => $this->bridges[$name]['class'],
			'capabilities' => $bridge->getCapabilities(),
			'health' => $bridge->healthCheck()
		];
	}

	/**
	 * Get information about all bridges.
	 *
     * @param string|null $tenantId
	 * @return array
	 */
	public function getAllBridgesInfo(?string $tenantId = null): array
	{
		// get 'HTTP_X_TENANT_ID' from headers if tenantId is not provided
		if ($tenantId === null && isset($_SERVER['HTTP_X_TENANT_ID'])) {
			$tenantId = $_SERVER['HTTP_X_TENANT_ID'];
		}
		$info = [];

		foreach (array_keys($this->bridges) as $name)
		{
			try
			{
				$info[$name] = $this->getBridgeInfo($name, $tenantId);
			}
			catch (\Exception $e)
			{
				$info[$name] = [
					'name' => $name,
					'error' => $e->getMessage(),
					'status' => 'error'
				];
			}
		}

		return $info;
	}

	/**
	 * Get bridge information for a specific tenant.
	 *
	 * @param string $tenantId
	 * @param string $name
	 * @return array
	 */
	public function getBridgeInfoForTenant(string $tenantId, string $name): array
	{
		if (!isset($this->bridges[$name]))
		{
			throw new \Exception("Bridge '{$name}' not found");
		}

		$bridge = $this->getBridgeForTenant($tenantId, $name);

		return [
			'name' => $name,
			'type' => $bridge->getBridgeType(),
			'class' => $this->bridges[$name]['class'],
			'capabilities' => $bridge->getCapabilities(),
			'health' => $bridge->healthCheck()
		];
	}

	/**
	 * Get information about all bridges for a specific tenant.
	 *
	 * @param string $tenantId
	 * @return array
	 */
	public function getAllBridgesInfoForTenant(string $tenantId): array
	{
		$info = [];

		foreach (array_keys($this->bridges) as $name)
		{
			try
			{
				$info[$name] = $this->getBridgeInfoForTenant($tenantId, $name);
			}
			catch (\Exception $e)
			{
				$info[$name] = [
					'name' => $name,
					'error' => $e->getMessage(),
					'status' => 'error'
				];
			}
		}

		return $info;
	}

























	




	/**
	 * Get all configured bridges organized per tenant with their active configurations.
	 */
	public function getConfiguredBridges()
	{
		$row = $this->configRepository->findAllActive();
		$result = [];
		foreach ($row as $entry)
		{
			$tenantId = $entry['tenant_id'];
			if (!isset($result[$tenantId]))
			{
				$result[$tenantId] = [];
			}
			$result[$tenantId][$entry['bridge_name']] = json_decode($entry['config_data'], true) ?? [];
		}

		return $result;
	}



	/**
	 * Get sync statistics for all bridges
	 */
	public function getAllSyncStats(): array
	{
		$allStats = [];

		$configuredBridges = $this->getConfiguredBridges();
		foreach ($configuredBridges as $tenantId => $bridges)
		{
			foreach (array_keys($bridges) as $bridgeName)
			{
				try
				{
					$bridge = $this->getBridgeForTenant((string)$tenantId, $bridgeName);

					if (method_exists($bridge, 'getSyncStats'))
					{
						$allStats[$bridgeName] = $bridge->getSyncStats();
					}
					else
					{
						// Fallback to basic stats
						$allStats[$bridgeName] = [
							'bridge_name' => $bridgeName,
							'bridge_type' => $bridge->getBridgeType(),
							'sync_stats_available' => false
						];
					}
				}
				catch (\Exception $e)
				{
					$allStats[$bridgeName] = [
						'bridge_name' => $bridgeName,
						'error' => $e->getMessage()
					];

					$this->logger->error('Failed to get sync stats for bridge', [
						'bridge' => $bridgeName,
						'error' => $e->getMessage()
					]);
				}
			}
		}

		return $allStats;
	}

	/**
	 * Get cancelled events for cleanup across all bridges
	 */
	public function getAllCancelledEvents(): array
	{
		$allCancelled = [];

		$configuredBridges = $this->getConfiguredBridges();
		// Process pending syncs for all bridges

		foreach ($configuredBridges as $tenantId => $Bridges)
		{
			foreach (array_keys($Bridges) as $bridgeName)
			{
				try
				{
					$bridge = $this->getBridgeForTenant((string)$tenantId, $bridgeName);
                if (method_exists($bridge, 'getCancelledEvents'))
					{
						$cancelled = $bridge->getCancelledEvents($bridgeName);
						if (!empty($cancelled))
						{
							$allCancelled[$bridgeName] = $cancelled;
						}
					}
				}
				catch (\Exception $e)
				{
					$this->logger->error('Failed to get cancelled events for bridge', [
						'bridge' => $bridgeName,
						'error' => $e->getMessage()
					]);
				}
			}
		}

		return $allCancelled;
	}

}