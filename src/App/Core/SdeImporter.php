<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class SdeImporter
{
    private const BASE_URL = 'https://www.fuzzwork.co.uk/dump/latest/';
    private const CACHE_DIR = '/var/cache/modularalliance/sde';

    /** @var array<string, array<string, mixed>> */
    private array $files = [
        'invCategories.csv.bz2' => [
            'table' => 'sde_inv_categories',
            'columns' => [
                'category_id' => 'categoryID',
                'name' => 'categoryName',
                'description' => 'description',
                'icon_id' => 'iconID',
                'published' => 'published',
            ],
        ],
        'invGroups.csv.bz2' => [
            'table' => 'sde_inv_groups',
            'columns' => [
                'group_id' => 'groupID',
                'category_id' => 'categoryID',
                'name' => 'groupName',
                'description' => 'description',
                'icon_id' => 'iconID',
                'published' => 'published',
            ],
        ],
        'invTypes.csv.bz2' => [
            'table' => 'sde_inv_types',
            'columns' => [
                'type_id' => 'typeID',
                'group_id' => 'groupID',
                'name' => 'typeName',
                'description' => 'description',
                'mass' => 'mass',
                'volume' => 'volume',
                'capacity' => 'capacity',
                'portion_size' => 'portionSize',
                'race_id' => 'raceID',
                'base_price' => 'basePrice',
                'published' => 'published',
                'market_group_id' => 'marketGroupID',
                'icon_id' => 'iconID',
                'sound_id' => 'soundID',
                'graphic_id' => 'graphicID',
            ],
        ],
        'mapRegions.csv.bz2' => [
            'table' => 'sde_map_regions',
            'columns' => [
                'region_id' => 'regionID',
                'name' => 'regionName',
            ],
        ],
        'mapConstellations.csv.bz2' => [
            'table' => 'sde_map_constellations',
            'columns' => [
                'constellation_id' => 'constellationID',
                'region_id' => 'regionID',
                'name' => 'constellationName',
            ],
        ],
        'mapSolarSystems.csv.bz2' => [
            'table' => 'sde_map_solar_systems',
            'columns' => [
                'solar_system_id' => 'solarSystemID',
                'constellation_id' => 'constellationID',
                'region_id' => 'regionID',
                'name' => 'solarSystemName',
                'security' => 'security',
                'security_class' => 'securityClass',
            ],
        ],
        'staStations.csv.bz2' => [
            'table' => 'sde_sta_stations',
            'columns' => [
                'station_id' => 'stationID',
                'station_type_id' => 'stationTypeID',
                'corporation_id' => 'corporationID',
                'solar_system_id' => 'solarSystemID',
                'constellation_id' => 'constellationID',
                'region_id' => 'regionID',
                'name' => 'stationName',
                'security' => 'security',
            ],
        ],
    ];

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed> */
    public function refresh(array $context = []): array
    {
        $logLines = [];
        $metrics = [
            'files_total' => count($this->files),
            'files_loaded' => 0,
            'rows' => [],
        ];

        try {
            sde_ensure_tables($this->db);

            $startedAt = microtime(true);

            $this->ensureCacheDir();

            $datasetHashes = [];
            $datasetMtimes = [];

            foreach ($this->files as $filename => $spec) {
                $logLines[] = "[download] {$filename}: start";
                $fileStart = microtime(true);
                $result = $this->downloadIfNeeded($filename, $logLines);
                $datasetHashes[] = $result['hash'];
                if (!empty($result['mtime'])) {
                    $datasetMtimes[] = (int)$result['mtime'];
                }
                $logLines[] = sprintf(
                    "[download] %s: %s (%.2fs)",
                    $filename,
                    $result['status'],
                    microtime(true) - $fileStart
                );
            }

            $datasetHash = hash('sha256', implode('|', $datasetHashes));
            $previousHash = $this->metaGet('data_hash');
            if ($previousHash && hash_equals($previousHash, $datasetHash)) {
                $logLines[] = '[skip] dataset hash unchanged, skipping import.';
                return [
                    'status' => 'success',
                    'message' => 'SDE unchanged; no import needed.',
                    'metrics' => $metrics,
                    'log_lines' => $logLines,
                ];
            }

            $stagingTables = [];
            foreach ($this->files as $filename => $spec) {
                $csvPath = $this->decompressIfNeeded($filename, $logLines);
                $table = (string)$spec['table'];
                $staging = $table . '_staging';
                $stagingTables[$table] = $staging;

                $this->prepareStagingTable($table, $staging, $logLines);
                $rows = $this->loadCsv($csvPath, $staging, (array)$spec['columns'], $logLines);
                $metrics['rows'][$table] = $rows;
                $metrics['files_loaded']++;
            }

            $this->swapTables($stagingTables, $logLines);

            $duration = microtime(true) - $startedAt;
            $datasetVersion = $datasetMtimes ? gmdate('Y-m-d', max($datasetMtimes)) : gmdate('Y-m-d');
            $this->metaSet('data_hash', $datasetHash);
            $this->metaSet('data_version', $datasetVersion);
            $this->metaSet('last_success_at', gmdate('c'));

            $logLines[] = sprintf('[done] SDE refresh completed in %.2fs', $duration);

            return [
                'status' => 'success',
                'message' => "SDE refreshed successfully ({$datasetVersion}).",
                'metrics' => $metrics,
                'log_lines' => $logLines,
            ];
        } catch (\Throwable $e) {
            $logLines[] = '[error] ' . $e->getMessage();
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
                'metrics' => $metrics,
                'log_lines' => $logLines,
            ];
        }
    }

    private function ensureCacheDir(): void
    {
        if (!is_dir(self::CACHE_DIR)) {
            if (!mkdir(self::CACHE_DIR, 0o755, true) && !is_dir(self::CACHE_DIR)) {
                throw new \RuntimeException('Unable to create cache directory: ' . self::CACHE_DIR);
            }
        }
    }

    /** @param array<int, string> $logLines */
    private function downloadIfNeeded(string $filename, array &$logLines): array
    {
        $url = self::BASE_URL . $filename;
        $md5Url = $url . '.md5';

        $localPath = rtrim(self::CACHE_DIR, '/') . '/' . $filename;
        $remoteMd5 = $this->fetchRemoteMd5($md5Url);
        $remoteMtime = $this->fetchRemoteMtime($url);

        $localMtime = is_file($localPath) ? (int)filemtime($localPath) : 0;
        $localMd5 = is_file($localPath) ? md5_file($localPath) : null;

        $needsDownload = !is_file($localPath) || !$localMd5 || ($remoteMd5 && $remoteMd5 !== $localMd5);
        if ($remoteMtime && $localMtime > 0 && $remoteMtime > $localMtime) {
            $needsDownload = true;
        }

        if ($needsDownload) {
            $logLines[] = "[download] {$filename}: fetching";
            $data = $this->httpGet($url, 60);
            file_put_contents($localPath, $data);
            touch($localPath, $remoteMtime ?: time());
        }

        $checksum = md5_file($localPath);
        if ($remoteMd5 && $checksum !== $remoteMd5) {
            throw new \RuntimeException("Checksum mismatch for {$filename}");
        }

        $this->metaSet("file:{$filename}:md5", $checksum ?? '');
        if ($remoteMtime) {
            $this->metaSet("file:{$filename}:mtime", (string)$remoteMtime);
        }

        return [
            'path' => $localPath,
            'hash' => $checksum ?? '',
            'mtime' => $remoteMtime ?? $localMtime,
            'status' => $needsDownload ? 'downloaded' : 'cached',
        ];
    }

    /** @param array<int, string> $logLines */
    private function decompressIfNeeded(string $filename, array &$logLines): string
    {
        $bz2Path = rtrim(self::CACHE_DIR, '/') . '/' . $filename;
        $csvPath = substr($bz2Path, 0, -4);

        $bzMtime = is_file($bz2Path) ? (int)filemtime($bz2Path) : 0;
        $csvMtime = is_file($csvPath) ? (int)filemtime($csvPath) : 0;

        if (!is_file($csvPath) || $csvMtime < $bzMtime) {
            $logLines[] = "[decompress] {$filename}: start";
            $start = microtime(true);
            $this->decompressBz2($bz2Path, $csvPath);
            $logLines[] = sprintf('[decompress] %s: done (%.2fs)', $filename, microtime(true) - $start);
        }

        return $csvPath;
    }

    private function decompressBz2(string $source, string $dest): void
    {
        $in = bzopen($source, 'r');
        if (!$in) {
            throw new \RuntimeException('Unable to open bzip file: ' . $source);
        }

        $out = fopen($dest, 'wb');
        if (!$out) {
            bzclose($in);
            throw new \RuntimeException('Unable to write decompressed file: ' . $dest);
        }

        while (!feof($in)) {
            $chunk = bzread($in, 8192);
            if ($chunk === false) {
                break;
            }
            fwrite($out, $chunk);
        }

        bzclose($in);
        fclose($out);
    }

    /** @param array<int, string> $logLines */
    private function prepareStagingTable(string $table, string $staging, array &$logLines): void
    {
        $this->db->exec("DROP TABLE IF EXISTS `{$staging}`");
        $this->db->exec("CREATE TABLE `{$staging}` LIKE `{$table}`");
        $logLines[] = "[staging] {$table}: prepared {$staging}";
    }

    /** @param array<string, string> $columns */
    private function loadCsv(string $csvPath, string $staging, array $columns, array &$logLines): int
    {
        $logLines[] = "[import] {$staging}: start";
        $start = microtime(true);

        $rows = 0;
        $pdo = $this->db->pdo();
        $pdo->setAttribute(PDO::MYSQL_ATTR_LOCAL_INFILE, true);

        if ($this->localInfileEnabled()) {
            try {
                $rows = $this->loadWithLocalInfile($csvPath, $staging, $columns);
                $logLines[] = sprintf('[import] %s: LOAD DATA (%d rows, %.2fs)', $staging, $rows, microtime(true) - $start);
                return $rows;
            } catch (\Throwable $e) {
                $logLines[] = "[import] {$staging}: LOAD DATA failed, falling back ({$e->getMessage()})";
            }
        }

        $rows = $this->loadWithStreamingInserts($csvPath, $staging, $columns);
        $logLines[] = sprintf('[import] %s: streamed (%d rows, %.2fs)', $staging, $rows, microtime(true) - $start);
        return $rows;
    }

    /** @param array<string, string> $columns */
    private function loadWithLocalInfile(string $csvPath, string $staging, array $columns): int
    {
        $header = $this->readHeader($csvPath);
        $headerMap = $this->buildHeaderMap($header);

        $setSql = [];
        foreach ($columns as $col => $headerName) {
            if (!isset($headerMap[$headerName])) {
                throw new \RuntimeException("Missing column {$headerName} in {$csvPath}");
            }
            $setSql[] = "`{$col}` = {$headerMap[$headerName]}";
        }

        $userVars = implode(', ', array_values($headerMap));
        $sql = sprintf(
            "LOAD DATA LOCAL INFILE %s INTO TABLE %s FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"' ESCAPED BY '\\\\' LINES TERMINATED BY '\\n' IGNORE 1 LINES (%s) SET %s",
            $this->db->pdo()->quote($csvPath),
            "`{$staging}`",
            $userVars,
            implode(', ', $setSql)
        );

        $this->db->begin();
        try {
            $this->db->exec($sql);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $row = $this->db->one("SELECT COUNT(*) AS cnt FROM `{$staging}`");
        return (int)($row['cnt'] ?? 0);
    }

    /** @param array<string, string> $columns */
    private function loadWithStreamingInserts(string $csvPath, string $staging, array $columns): int
    {
        $handle = fopen($csvPath, 'rb');
        if (!$handle) {
            throw new \RuntimeException('Unable to read CSV: ' . $csvPath);
        }

        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);
            throw new \RuntimeException('Missing CSV header: ' . $csvPath);
        }

        $headerIndexes = [];
        foreach ($header as $idx => $name) {
            $headerIndexes[$name] = $idx;
        }

        $orderedCols = array_keys($columns);
        $columnIndexes = [];
        foreach ($columns as $col => $headerName) {
            if (!isset($headerIndexes[$headerName])) {
                fclose($handle);
                throw new \RuntimeException("Missing column {$headerName} in {$csvPath}");
            }
            $columnIndexes[$col] = $headerIndexes[$headerName];
        }

        $batchSize = 2000;
        $values = [];
        $placeholders = '(' . implode(',', array_fill(0, count($orderedCols), '?')) . ')';
        $rows = 0;

        $this->db->begin();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $entry = [];
                foreach ($orderedCols as $col) {
                    $idx = $columnIndexes[$col];
                    $entry[] = $row[$idx] !== '' ? $row[$idx] : null;
                }
                $values[] = $entry;
                $rows++;

                if (count($values) >= $batchSize) {
                    $this->insertBatch($staging, $orderedCols, $placeholders, $values);
                    $values = [];
                }
            }

            if ($values) {
                $this->insertBatch($staging, $orderedCols, $placeholders, $values);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            fclose($handle);
            throw $e;
        }

        fclose($handle);
        return $rows;
    }

    /** @param array<int, array<int, mixed>> $values */
    private function insertBatch(string $table, array $columns, string $placeholders, array $values): void
    {
        $flat = [];
        foreach ($values as $row) {
            foreach ($row as $val) {
                $flat[] = $val;
            }
        }

        $quotedCols = array_map(fn(string $col) => "`{$col}`", $columns);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES %s',
            $table,
            implode(', ', $quotedCols),
            implode(', ', array_fill(0, count($values), $placeholders))
        );

        $this->db->run($sql, $flat);
    }

    /** @param array<string, string> $tables */
    private function swapTables(array $tables, array &$logLines): void
    {
        $timestamp = gmdate('YmdHis');
        $renames = [];
        foreach ($tables as $final => $staging) {
            $backup = $final . '_backup';
            $this->db->exec("DROP TABLE IF EXISTS `{$backup}`");
            $renames[] = "`{$final}` TO `{$backup}`";
            $renames[] = "`{$staging}` TO `{$final}`";
            $logLines[] = "[swap] {$final}: staged";
        }

        $this->db->exec('RENAME TABLE ' . implode(', ', $renames));
        $logLines[] = "[swap] completed at {$timestamp}";
    }

    private function localInfileEnabled(): bool
    {
        $row = $this->db->one("SHOW VARIABLES LIKE 'local_infile'");
        if (!$row) {
            return false;
        }
        $value = (string)($row['Value'] ?? $row['value'] ?? '');
        return strtolower($value) === 'on' || $value === '1';
    }

    private function readHeader(string $csvPath): array
    {
        $handle = fopen($csvPath, 'rb');
        if (!$handle) {
            throw new \RuntimeException('Unable to read CSV header: ' . $csvPath);
        }
        $header = fgetcsv($handle);
        fclose($handle);
        if (!is_array($header)) {
            throw new \RuntimeException('Missing CSV header: ' . $csvPath);
        }
        return $header;
    }

    /** @param array<int, string> $header */
    private function buildHeaderMap(array $header): array
    {
        $map = [];
        foreach ($header as $name) {
            $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
            if ($safe === null || $safe === '') {
                continue;
            }
            $map[$name] = '@h_' . $safe;
        }
        return $map;
    }

    private function fetchRemoteMd5(string $url): ?string
    {
        try {
            $data = $this->httpGet($url, 20);
        } catch (\Throwable $e) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($data));
        if (!$parts || $parts[0] === '') {
            return null;
        }
        return strtolower($parts[0]);
    }

    private function fetchRemoteMtime(string $url): ?int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FILETIME => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        curl_exec($ch);
        $filetime = (int)curl_getinfo($ch, CURLINFO_FILETIME);
        curl_close($ch);
        return $filetime > 0 ? $filetime : null;
    }

    private function httpGet(string $url, int $timeout): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("cURL error: {$err}");
        }
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("HTTP {$status} from {$url}");
        }
        return (string)$resp;
    }

    private function metaGet(string $key): ?string
    {
        $row = $this->db->one("SELECT meta_value FROM sde_meta WHERE meta_key=?", [$key]);
        return $row ? (string)$row['meta_value'] : null;
    }

    private function metaSet(string $key, string $value): void
    {
        $this->db->run(
            "INSERT INTO sde_meta (meta_key, meta_value) VALUES (?, ?)\n"
            . "ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
            [$key, $value]
        );
    }
}
