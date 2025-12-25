<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class Migrator
{
    public function __construct(private readonly Db $db) {}

    public function ensureLogTable(): void
    {
        $this->db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS migration_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  module_slug VARCHAR(64) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  checksum CHAR(64) NOT NULL,
  status ENUM('applied','failed') NOT NULL,
  message VARCHAR(255) NULL,
  ran_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_mig (module_slug, file_path, checksum),
  KEY idx_ran_at (ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);
    }

    public function applyDir(string $moduleSlug, string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = glob(rtrim($dir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $f) $this->applySqlFile($moduleSlug, $f);
    }

    public function applySqlFile(string $moduleSlug, string $filePath): void
    {
        $sql = trim((string)file_get_contents($filePath));
        if ($sql === '') return;

        $checksum = hash('sha256', $sql);
        $path = $this->relPath($filePath);

        $existing = $this->db->one(
            "SELECT id, checksum FROM migration_log
             WHERE module_slug=? AND file_path=? AND status='applied'
             ORDER BY id DESC
             LIMIT 1",
            [$moduleSlug, $path]
        );
        if ($existing) {
            if ($existing['checksum'] === $checksum) {
                echo "[SKIP] {$moduleSlug}: {$path}\n";
            } else {
                echo "[SKIP] {$moduleSlug}: {$path} (already applied with different checksum)\n";
            }
            return;
        }

        $pdo = $this->db->pdo();
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // MySQL/MariaDB DDL is not transaction-safe due to implicit commits.
        $useTx = ($driver !== 'mysql');

        try {
            if ($useTx) $pdo->beginTransaction();

            $pdo->exec($sql);

            if ($useTx && $pdo->inTransaction()) $pdo->commit();

            $this->db->run(
                "INSERT INTO migration_log (module_slug, file_path, checksum, status, message, ran_at)
                 VALUES (?, ?, ?, 'applied', '', NOW())",
                [$moduleSlug, $path, $checksum]
            );

            echo "[OK] {$moduleSlug}: {$path}\n";
        } catch (\Throwable $e) {
            if ($useTx && $pdo->inTransaction()) $pdo->rollBack();

            try {
                $this->db->run(
                    "INSERT INTO migration_log (module_slug, file_path, checksum, status, message, ran_at)
                     VALUES (?, ?, ?, 'failed', ?, NOW())",
                    [$moduleSlug, $path, $checksum, substr($e->getMessage(), 0, 255)]
                );
            } catch (\Throwable $ignore) {}

            throw $e;
        }
    }

    private function relPath(string $path): string
    {
        $root = rtrim((string)APP_ROOT, '/') . '/';
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
