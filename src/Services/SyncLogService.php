<?php

namespace App\Services;

use PDO;

/**
 * SyncLogService persists operational sync metrics and events to bridge_sync_logs.
 */
class SyncLogService
{
	private PDO $db;

	/**
	 * @param PDO $db Database connection
	 */
	public function __construct(PDO $db)
	{
		$this->db = $db;
	}

	/**
	 * Write a sync log entry to bridge_sync_logs.
	 *
	 * @param string $operation E.g., sync, dry_run, update, delete
	 * @param string $sourceBridge
	 * @param string $targetBridge
	 * @param string $status success|error|pending
	 * @param int $eventCount Count of events processed
	 * @param array $details Arbitrary details to persist (JSON)
	 * @param int|null $durationMs Optional duration in ms
	 * @param string|null $errorMessage Optional error text
	 * @param string|null $tenantId Optional tenant identifier
	 */
	public function write(
		string $operation,
		string $sourceBridge,
		string $targetBridge,
		string $status,
		int $eventCount = 0,
		array $details = [],
		?int $durationMs = null,
		?string $errorMessage = null,
		?string $tenantId = null
	): void
	{
		if (!$this->db)
		{
			return; // DB not available; skip logging gracefully
		}

		$sql = "INSERT INTO bridge_sync_logs 
		(source_bridge, target_bridge, operation, status, event_count, details, error_message, duration_ms, tenant_id) 
		VALUES (:source_bridge, :target_bridge, :operation, :status, :event_count, :details, :error_message, :duration_ms, :tenant_id)";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':source_bridge' => $sourceBridge,
			':target_bridge' => $targetBridge,
			':operation' => $operation,
			':status' => $status,
			':event_count' => $eventCount,
			':details' => empty($details) ? null : json_encode($details),
			':error_message' => $errorMessage,
			':duration_ms' => $durationMs,
			':tenant_id' => $tenantId,
		]);
	}

	/**
	 * Cleanup old bridge sync logs.
	 *
	 * @param int $days Number of days to keep
	 * @return int Number of deleted rows
	 */
	public function cleanupOldLogs(int $days): int
	{
		$stmt = $this->db->prepare("SELECT cleanup_old_bridge_logs(:days) AS deleted_count");
		$stmt->execute([':days' => $days]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ? (int)$row['deleted_count'] : 0;
	}
}
