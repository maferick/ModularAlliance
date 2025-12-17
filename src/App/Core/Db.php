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

    public function exec(string $sql): void { $this->pdo->exec($sql); }

    public function run(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }
}
