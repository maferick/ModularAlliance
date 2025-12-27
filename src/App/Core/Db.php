<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Db
{
    private PDO $pdo;

    private function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function fromConfig(array $cfg): self
    {
        $host    = $cfg['host'] ?? '127.0.0.1';
        $port    = (int)($cfg['port'] ?? 3306);
        $db      = $cfg['database'] ?? '';
        $user    = $cfg['user'] ?? '';
        $pass    = $cfg['password'] ?? '';
        $charset = $cfg['charset'] ?? 'utf8mb4';

        if ($db === '' || $user === '') {
            throw new PDOException("DB config incomplete: database/user missing");
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

        try {
            $pdo = new PDO($dsn, $user, $pass);
        } catch (PDOException $e) {
            throw new PDOException("DB connect failed: " . $e->getMessage(), (int)$e->getCode(), $e);
        }

        return new self($pdo);
    }

    public function pdo(): PDO { return $this->pdo; }

    // Transaction helpers (Option B: keep PDO encapsulated for callers)
    public function begin(): void { $this->pdo->beginTransaction(); }
    public function commit(): void { $this->pdo->commit(); }
    public function rollback(): void { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); }
    public function inTx(): bool { return $this->pdo->inTransaction(); }

}
