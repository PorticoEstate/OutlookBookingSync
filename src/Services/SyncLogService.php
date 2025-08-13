<?php

namespace App\Services;

use PDO;

class SyncLogService
{
	private PDO $db;

	public function __construct(PDO $db)
	{
		$this->db = $db;
	}

	/**
	 * Write a sync log entry to bridge_sync_logs
	 */
	public function write(
		string $operation,
		string $sourceBridge,
		string $targetBridge,
		string $status,
		int $eventCount = 0,
		array $details = [],
		?int $durationMs = null,
		?string $errorMessage = null
	): void
	{
		if (!$this->db)
		{
			return; // DB not available; skip logging gracefully
		}

		$sql = "INSERT INTO bridge_sync_logs 
                (source_bridge, target_bridge, operation, status, event_count, details, error_message, duration_ms) 
                VALUES (:source_bridge, :target_bridge, :operation, :status, :event_count, :details, :error_message, :duration_ms)";

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
		]);
	}
}
