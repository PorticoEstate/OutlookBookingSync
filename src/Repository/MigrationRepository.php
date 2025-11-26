<?php

namespace App\Repository;

use PDO;

class MigrationRepository
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ensureSchemaMigrationsTable()
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(50) PRIMARY KEY,
                description TEXT,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function getAppliedMigrations()
    {
        $this->ensureSchemaMigrationsTable();
        $stmt = $this->db->prepare("
            SELECT version, description, applied_at 
            FROM schema_migrations 
            ORDER BY version ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function isMigrationApplied($version)
    {
        $this->ensureSchemaMigrationsTable();
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM schema_migrations WHERE version = ?");
        $stmt->execute([$version]);
        return $stmt->fetchColumn() > 0;
    }

    public function executeMigration($sql)
    {
        $this->db->beginTransaction();
        try {
            $this->db->exec($sql);
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
