<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/core/bootstrap.php';

use App\Core\EsiDateTime;

$cases = [
    ['2025-10-08T20:26:00Z', '2025-10-08 20:26:00'],
    ['2025-10-08T20:26:00+02:00', '2025-10-08 18:26:00'],
    ['2025-10-08 20:26:00', '2025-10-08 20:26:00'],
    ['2025-10-08T20:26:00-05:00', '2025-10-09 01:26:00'],
    ['', null],
    [null, null],
];

$failed = 0;
foreach ($cases as [$input, $expected]) {
    $actual = EsiDateTime::parseEsiDatetimeToMysql($input);
    if ($actual !== $expected) {
        $failed++;
        echo "[fail] input=" . var_export($input, true) . " expected={$expected} actual={$actual}\n";
    } else {
        echo "[ok] input=" . var_export($input, true) . " => {$actual}\n";
    }
}

if ($failed > 0) {
    echo "FAILED: {$failed} case(s)\n";
    exit(1);
}

echo "All cases passed.\n";
