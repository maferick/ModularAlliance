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
  status ENUM('applied','failed','mismatch') NOT NULL,
  message VARCHAR(255) NULL,
  ran_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_mig (module_slug, file_path, checksum),
  KEY idx_ran_at (ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);

        if (db_driver($this->db) === 'mysql') {
            db_exec(
                $this->db,
                "ALTER TABLE migration_log
                  MODIFY status ENUM('applied','failed','mismatch') NOT NULL"
            );
        }
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
                $this->logMismatch($moduleSlug, $path, $checksum, $existing['checksum']);
                echo "[MISMATCH] {$moduleSlug}: {$path} (applied {$existing['checksum']}, current {$checksum})\n";
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

            $this->recordMigration($moduleSlug, $path, $checksum, 'applied', '');

            echo "[OK] {$moduleSlug}: {$path}\n";
        } catch (\Throwable $e) {
            if ($useTx && $this->db->inTx()) $this->db->rollback();

            try {
                $this->recordMigration(
                    $moduleSlug,
                    $path,
                    $checksum,
                    'failed',
                    substr($e->getMessage(), 0, 255)
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

    public function appliedMigrations(): array
    {
        $rows = db_all(
            $this->db,
            "SELECT id, module_slug, file_path, checksum
             FROM migration_log
             WHERE status='applied'
             ORDER BY id ASC"
        );

        $map = [];
        foreach ($rows as $row) {
            $key = $row['module_slug'] . '::' . $row['file_path'];
            if (!isset($map[$key]) || (int)$row['id'] > (int)$map[$key]['id']) {
                $map[$key] = [
                    'id' => (int)$row['id'],
                    'module_slug' => (string)$row['module_slug'],
                    'file_path' => (string)$row['file_path'],
                    'checksum' => (string)$row['checksum'],
                ];
            }
        }

        return $map;
    }

    public function logMismatch(
        string $moduleSlug,
        string $path,
        string $currentChecksum,
        string $appliedChecksum
    ): void {
        $message = substr("checksum mismatch: applied {$appliedChecksum}, current {$currentChecksum}", 0, 255);
        $this->recordMigration($moduleSlug, $path, $currentChecksum, 'mismatch', $message);
    }

    public function schemaDiffStatements(string $filePath): array
    {
        $sql = trim((string)file_get_contents($filePath));
        if ($sql === '') {
            return [];
        }

        $expected = $this->buildExpectedSchema($this->splitSqlStatements($sql));
        $diffs = [];

        foreach ($expected as $table => $details) {
            if (!$this->tableExists($table)) {
                if (!empty($details['create'])) {
                    $diffs[] = rtrim($details['create'], ';') . ';';
                }
                continue;
            }

            $actualColumns = $this->fetchTableColumns($table);
            $actualIndexes = $this->fetchTableIndexes($table);

            foreach ($details['columns'] as $columnName => $definition) {
                if (!isset($actualColumns[$columnName])) {
                    $diffs[] = "ALTER TABLE `{$table}` ADD COLUMN {$definition};";
                    continue;
                }

                $expectedColumn = $this->parseColumnDefinition($definition);
                if ($expectedColumn !== null && !$this->columnMatches($expectedColumn, $actualColumns[$columnName])) {
                    $diffs[] = "ALTER TABLE `{$table}` MODIFY COLUMN {$definition};";
                }
            }

            foreach ($details['indexes'] as $indexName => $indexDef) {
                if (!isset($actualIndexes[$indexName]) || !$this->indexMatches($indexDef, $actualIndexes[$indexName])) {
                    $diffs[] = "ALTER TABLE `{$table}` ADD {$indexDef['definition']};";
                }
            }
        }

        return $diffs;
    }

    public function schemaMatchesFile(string $filePath): bool
    {
        return $this->schemaDiffStatements($filePath) === [];
    }

    public function repairChecksum(string $moduleSlug, string $filePath): array
    {
        $sql = trim((string)file_get_contents($filePath));
        if ($sql === '') {
            return ['ok' => false, 'message' => 'Migration file is empty.'];
        }

        $path = $this->relPath($filePath);
        $checksum = hash('sha256', $sql);

        $applied = db_one(
            $this->db,
            "SELECT id, checksum FROM migration_log
             WHERE module_slug=? AND file_path=? AND status='applied'
             ORDER BY id DESC
             LIMIT 1",
            [$moduleSlug, $path]
        );

        if (!$applied) {
            return ['ok' => false, 'message' => 'No applied migration found for this file.'];
        }

        if ($applied['checksum'] === $checksum) {
            return ['ok' => true, 'message' => 'Checksum already matches applied migration.'];
        }

        $diffs = $this->schemaDiffStatements($filePath);
        if ($diffs !== []) {
            return [
                'ok' => false,
                'message' => 'Schema does not match current migration contents.',
                'diffs' => $diffs,
            ];
        }

        db_tx($this->db, function () use ($moduleSlug, $path, $checksum, $applied): void {
            db_exec(
                $this->db,
                "DELETE FROM migration_log
                 WHERE module_slug=? AND file_path=? AND checksum=? AND status='mismatch'",
                [$moduleSlug, $path, $checksum]
            );

            db_exec(
                $this->db,
                "UPDATE migration_log
                 SET checksum=?, message=?, ran_at=NOW()
                 WHERE id=?",
                [$checksum, 'checksum repaired', $applied['id']]
            );
        });

        return ['ok' => true, 'message' => 'Checksum updated to match current migration.'];
    }

    private function recordMigration(
        string $moduleSlug,
        string $path,
        string $checksum,
        string $status,
        string $message
    ): void {
        $updated = db_exec(
            $this->db,
            "UPDATE migration_log
             SET status=?, message=?, ran_at=NOW()
             WHERE module_slug=? AND file_path=? AND checksum=?",
            [$status, $message, $moduleSlug, $path, $checksum]
        );

        if ($updated > 0) {
            return;
        }

        db_exec(
            $this->db,
            "INSERT INTO migration_log (module_slug, file_path, checksum, status, message, ran_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [$moduleSlug, $path, $checksum, $status, $message]
        );
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

    private function buildExpectedSchema(array $statements): array
    {
        $expected = [];

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }

            $create = $this->parseCreateTable($statement);
            if ($create) {
                $table = $create['table'];
                if (!isset($expected[$table])) {
                    $expected[$table] = ['create' => $create['statement'], 'columns' => [], 'indexes' => []];
                }
                $expected[$table]['create'] = $create['statement'];
                $expected[$table]['columns'] = array_merge($expected[$table]['columns'], $create['columns']);
                $expected[$table]['indexes'] = array_merge($expected[$table]['indexes'], $create['indexes']);
                continue;
            }

            $alter = $this->parseAlterTable($statement);
            if ($alter) {
                $table = $alter['table'];
                if (!isset($expected[$table])) {
                    $expected[$table] = ['create' => null, 'columns' => [], 'indexes' => []];
                }
                $expected[$table]['columns'] = array_merge($expected[$table]['columns'], $alter['columns']);
                $expected[$table]['indexes'] = array_merge($expected[$table]['indexes'], $alter['indexes']);
            }
        }

        return $expected;
    }

    private function parseCreateTable(string $statement): ?array
    {
        if (!preg_match('/^CREATE\s+TABLE/i', $statement)) {
            return null;
        }

        if (!preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $statement, $matches)) {
            return null;
        }

        $table = $matches[1];
        $openParen = strpos($statement, '(');
        if ($openParen === false) {
            return null;
        }

        $definition = $this->extractBalancedSection($statement, $openParen);
        if ($definition === null) {
            return null;
        }

        $columns = [];
        $indexes = [];
        foreach ($this->splitSqlClauses($definition) as $clause) {
            $clause = trim($clause);
            if ($clause === '') {
                continue;
            }

            $index = $this->parseIndexDefinition($clause);
            if ($index) {
                $indexes[$index['name']] = $index;
                continue;
            }

            $column = $this->parseColumnName($clause);
            if ($column !== null) {
                $columns[$column] = $clause;
            }
        }

        return [
            'table' => $table,
            'statement' => rtrim($statement, ';'),
            'columns' => $columns,
            'indexes' => $indexes,
        ];
    }

    private function parseAlterTable(string $statement): ?array
    {
        if (!preg_match('/^ALTER\s+TABLE\s+`?([a-zA-Z0-9_]+)`?\s+(.+)$/is', $statement, $matches)) {
            return null;
        }

        $table = $matches[1];
        $actions = trim($matches[2]);

        $columns = [];
        $indexes = [];

        foreach ($this->splitSqlClauses($actions) as $clause) {
            $clause = trim($clause);
            if ($clause === '') {
                continue;
            }

            if (preg_match('/^ADD\s+(.*)$/i', $clause, $addMatch)) {
                $content = trim($addMatch[1]);
                $index = $this->parseIndexDefinition($content);
                if ($index) {
                    $indexes[$index['name']] = $index;
                    continue;
                }

                if (preg_match('/^(?:COLUMN\s+)?(.+)$/i', $content, $columnMatch)) {
                    $definition = trim($columnMatch[1]);
                    $column = $this->parseColumnName($definition);
                    if ($column !== null) {
                        $columns[$column] = $definition;
                    }
                }
                continue;
            }

            if (preg_match('/^MODIFY\s+(?:COLUMN\s+)?(.+)$/i', $clause, $modifyMatch)) {
                $definition = trim($modifyMatch[1]);
                $column = $this->parseColumnName($definition);
                if ($column !== null) {
                    $columns[$column] = $definition;
                }
                continue;
            }

            if (preg_match('/^CHANGE\s+(?:COLUMN\s+)?`?([a-zA-Z0-9_]+)`?\s+(.+)$/i', $clause, $changeMatch)) {
                $definition = trim($changeMatch[2]);
                $column = $this->parseColumnName($definition);
                if ($column !== null) {
                    $columns[$column] = $definition;
                }
                continue;
            }

            if (preg_match('/^ADD\s+PRIMARY\s+KEY/i', $clause)) {
                $index = $this->parseIndexDefinition($clause);
                if ($index) {
                    $indexes[$index['name']] = $index;
                }
            }
        }

        return [
            'table' => $table,
            'columns' => $columns,
            'indexes' => $indexes,
        ];
    }

    private function parseIndexDefinition(string $clause): ?array
    {
        $clause = trim($clause);

        if (preg_match('/^PRIMARY\s+KEY\s*\((.+)\)$/i', $clause, $matches)) {
            return [
                'name' => 'PRIMARY',
                'unique' => true,
                'columns' => $this->parseIndexColumns($matches[1]),
                'definition' => 'PRIMARY KEY (' . $matches[1] . ')',
            ];
        }

        if (preg_match('/^UNIQUE\s+(?:KEY|INDEX)\s+`?([a-zA-Z0-9_]+)`?\s*\((.+)\)$/i', $clause, $matches)) {
            return [
                'name' => $matches[1],
                'unique' => true,
                'columns' => $this->parseIndexColumns($matches[2]),
                'definition' => 'UNIQUE KEY `' . $matches[1] . '` (' . $matches[2] . ')',
            ];
        }

        if (preg_match('/^(?:KEY|INDEX)\s+`?([a-zA-Z0-9_]+)`?\s*\((.+)\)$/i', $clause, $matches)) {
            return [
                'name' => $matches[1],
                'unique' => false,
                'columns' => $this->parseIndexColumns($matches[2]),
                'definition' => 'KEY `' . $matches[1] . '` (' . $matches[2] . ')',
            ];
        }

        return null;
    }

    private function parseIndexColumns(string $columns): array
    {
        $parts = $this->splitSqlClauses($columns);
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            $part = trim($part, '`');
            if ($part === '') {
                continue;
            }
            $paren = strpos($part, '(');
            if ($paren !== false) {
                $part = substr($part, 0, $paren);
            }
            $out[] = $part;
        }
        return $out;
    }

    private function parseColumnName(string $definition): ?string
    {
        if (preg_match('/^`?([a-zA-Z0-9_]+)`?\s+/i', trim($definition), $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function extractBalancedSection(string $sql, int $start): ?string
    {
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $length = strlen($sql);

        for ($i = $start; $i < $length; $i++) {
            $ch = $sql[$i];
            $prev = $i > 0 ? $sql[$i - 1] : '';

            if ($ch === "'" && !$inDouble && $prev !== '\\') {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle && $prev !== '\\') {
                $inDouble = !$inDouble;
            }

            if ($inSingle || $inDouble) {
                continue;
            }

            if ($ch === '(') {
                $depth++;
                continue;
            }

            if ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($sql, $start + 1, $i - $start - 1);
                }
            }
        }

        return null;
    }

    private function splitSqlClauses(string $sql): array
    {
        $parts = [];
        $buffer = '';
        $inSingle = false;
        $inDouble = false;
        $depth = 0;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $ch = $sql[$i];
            $prev = $i > 0 ? $sql[$i - 1] : '';

            if ($ch === "'" && !$inDouble && $prev !== '\\') {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle && $prev !== '\\') {
                $inDouble = !$inDouble;
            }

            if (!$inSingle && !$inDouble) {
                if ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                }
            }

            if ($ch === ',' && !$inSingle && !$inDouble && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
        }

        if (trim($buffer) !== '') {
            $parts[] = $buffer;
        }

        return $parts;
    }

    private function tableExists(string $table): bool
    {
        $row = db_one($this->db, "SHOW TABLES LIKE ?", [$table]);
        return $row !== null;
    }

    private function fetchTableColumns(string $table): array
    {
        $rows = db_all(
            $this->db,
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",
            [$table]
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[$row['COLUMN_NAME']] = [
                'column_type' => strtolower(trim((string)$row['COLUMN_TYPE'])),
                'is_nullable' => (string)$row['IS_NULLABLE'],
                'column_default' => $row['COLUMN_DEFAULT'],
                'extra' => strtolower(trim((string)$row['EXTRA'])),
            ];
        }

        return $columns;
    }

    private function fetchTableIndexes(string $table): array
    {
        $rows = db_all($this->db, "SHOW INDEX FROM `{$table}`");
        $indexes = [];

        foreach ($rows as $row) {
            $name = (string)$row['Key_name'];
            if (!isset($indexes[$name])) {
                $indexes[$name] = [
                    'unique' => ((int)$row['Non_unique'] === 0),
                    'columns' => [],
                ];
            }
            $indexes[$name]['columns'][(int)$row['Seq_in_index']] = (string)$row['Column_name'];
        }

        foreach ($indexes as &$index) {
            ksort($index['columns']);
            $index['columns'] = array_values($index['columns']);
        }
        unset($index);

        return $indexes;
    }

    private function parseColumnDefinition(string $definition): ?array
    {
        $definition = trim($definition);
        if (!preg_match('/^`?([a-zA-Z0-9_]+)`?\s+(.*)$/s', $definition, $matches)) {
            return null;
        }

        $rest = trim($matches[2]);
        [$type, $afterType] = $this->extractColumnType($rest);

        $expected = [
            'type' => strtolower(trim($type)),
            'nullable' => null,
            'default_set' => false,
            'default' => null,
            'auto_increment' => false,
            'on_update_current_timestamp' => false,
        ];

        if (preg_match('/\bNOT\s+NULL\b/i', $afterType)) {
            $expected['nullable'] = false;
        } elseif (preg_match('/\bNULL\b/i', $afterType)) {
            $expected['nullable'] = true;
        }

        if (preg_match('/\bAUTO_INCREMENT\b/i', $afterType)) {
            $expected['auto_increment'] = true;
        }

        if (preg_match('/\bON\s+UPDATE\s+CURRENT_TIMESTAMP\b/i', $afterType)) {
            $expected['on_update_current_timestamp'] = true;
        }

        $default = $this->extractDefaultValue($afterType);
        if ($default !== null) {
            $expected['default_set'] = true;
            $expected['default'] = $default;
        }

        return $expected;
    }

    private function extractColumnType(string $definition): array
    {
        $tokens = [];
        $buffer = '';
        $inSingle = false;
        $inDouble = false;
        $depth = 0;
        $length = strlen($definition);

        for ($i = 0; $i < $length; $i++) {
            $ch = $definition[$i];
            $prev = $i > 0 ? $definition[$i - 1] : '';

            if ($ch === "'" && !$inDouble && $prev !== '\\') {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle && $prev !== '\\') {
                $inDouble = !$inDouble;
            }

            if (!$inSingle && !$inDouble) {
                if ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                }
            }

            if (ctype_space($ch) && !$inSingle && !$inDouble && $depth === 0) {
                if ($buffer !== '') {
                    $tokens[] = $buffer;
                    $buffer = '';
                }
                continue;
            }

            $buffer .= $ch;
        }

        if ($buffer !== '') {
            $tokens[] = $buffer;
        }

        $typeTokens = [];
        $keywords = ['not', 'null', 'default', 'auto_increment', 'comment', 'primary', 'unique', 'key', 'on', 'collate', 'character', 'check', 'references', 'generated', 'constraint'];
        $stopIndex = count($tokens);
        foreach ($tokens as $index => $token) {
            $tokenLower = strtolower($token);
            if (in_array($tokenLower, $keywords, true)) {
                $stopIndex = $index;
                break;
            }
        }

        $typeTokens = array_slice($tokens, 0, $stopIndex);
        $type = implode(' ', $typeTokens);
        $after = implode(' ', array_slice($tokens, $stopIndex));

        return [$type, $after];
    }

    private function extractDefaultValue(string $definition): ?string
    {
        if (!preg_match('/\bDEFAULT\b/i', $definition, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $offset = $matches[0][1] + strlen($matches[0][0]);
        $substr = ltrim(substr($definition, $offset));
        if ($substr === '') {
            return null;
        }

        $first = $substr[0];
        if ($first === "'" || $first === '"') {
            $value = '';
            $escaped = false;
            for ($i = 1, $len = strlen($substr); $i < $len; $i++) {
                $ch = $substr[$i];
                if ($escaped) {
                    $value .= $ch;
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($ch === $first) {
                    return $value;
                }
                $value .= $ch;
            }
            return $value;
        }

        $token = preg_split('/\s+/', $substr)[0] ?? '';
        if ($token === '') {
            return null;
        }

        return strtoupper($token) === 'NULL' ? null : $token;
    }

    private function columnMatches(array $expected, array $actual): bool
    {
        $actualType = strtolower(trim((string)$actual['column_type']));
        if ($expected['type'] !== '' && $actualType !== $expected['type']) {
            return false;
        }

        if ($expected['nullable'] !== null) {
            $expectedNullable = $expected['nullable'] ? 'YES' : 'NO';
            if (strtoupper((string)$actual['is_nullable']) !== $expectedNullable) {
                return false;
            }
        }

        if ($expected['default_set']) {
            $actualDefault = $actual['column_default'];
            $expectedDefault = $expected['default'];

            if ($expectedDefault === null) {
                if ($actualDefault !== null) {
                    return false;
                }
            } else {
                $normalizedExpected = (string)$expectedDefault;
                $normalizedActual = $actualDefault === null ? '' : (string)$actualDefault;

                if (strtoupper($normalizedExpected) === 'CURRENT_TIMESTAMP') {
                    if (strtoupper($normalizedActual) !== 'CURRENT_TIMESTAMP') {
                        return false;
                    }
                } elseif ($normalizedActual !== $normalizedExpected) {
                    return false;
                }
            }
        }

        $extra = strtolower((string)$actual['extra']);
        if ($expected['auto_increment'] && !str_contains($extra, 'auto_increment')) {
            return false;
        }

        if ($expected['on_update_current_timestamp'] && !str_contains($extra, 'on update current_timestamp')) {
            return false;
        }

        return true;
    }

    private function indexMatches(array $expected, array $actual): bool
    {
        if ($expected['unique'] !== $actual['unique']) {
            return false;
        }

        return $expected['columns'] === $actual['columns'];
    }
}
