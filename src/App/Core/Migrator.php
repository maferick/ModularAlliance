<?php
declare(strict_types=1);

namespace App\Core;

final class Migrator
{
    public function __construct(private readonly Db $db) {}

    public function ensureLogTable(): void
    {
        db_exec($this->db, <<<SQL
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

        $existing = db_one(
            $this->db,
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

        $driver = db_driver($this->db);

        // MySQL/MariaDB DDL is not transaction-safe due to implicit commits.
        $useTx = ($driver !== 'mysql');

        try {
            if ($useTx) $this->db->begin();

            foreach ($this->splitSqlStatements($sql) as $statement) {
                db_exec($this->db, $statement);
            }

            if ($useTx && $this->db->inTx()) $this->db->commit();

            db_exec(
                $this->db,
                "INSERT INTO migration_log (module_slug, file_path, checksum, status, message, ran_at)
                 VALUES (?, ?, ?, 'applied', '', NOW())",
                [$moduleSlug, $path, $checksum]
            );

            echo "[OK] {$moduleSlug}: {$path}\n";
        } catch (\Throwable $e) {
            if ($useTx && $this->db->inTx()) $this->db->rollback();

            try {
                db_exec(
                    $this->db,
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

    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $inSingle = false;
        $inDouble = false;
        $inLineComment = false;
        $inBlockComment = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($ch === "\n") {
                    $inLineComment = false;
                    $buffer .= $ch;
                }
                continue;
            }

            if ($inBlockComment) {
                if ($ch === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            if (!$inSingle && !$inDouble) {
                $prev = $i > 0 ? $sql[$i - 1] : '';
                if ($ch === '-' && $next === '-' && ($prev === '' || ctype_space($prev))) {
                    $inLineComment = true;
                    $i++;
                    continue;
                }
                if ($ch === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;
                    continue;
                }
            }

            if ($ch === "'" && !$inDouble) {
                $escaped = $i > 0 && $sql[$i - 1] === '\\';
                if (!$escaped) {
                    $inSingle = !$inSingle;
                }
            } elseif ($ch === '"' && !$inSingle) {
                $escaped = $i > 0 && $sql[$i - 1] === '\\';
                if (!$escaped) {
                    $inDouble = !$inDouble;
                }
            }

            if ($ch === ';' && !$inSingle && !$inDouble) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }
}
