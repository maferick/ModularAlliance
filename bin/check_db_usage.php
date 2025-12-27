<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$allowlist = [realpath($root . '/src/App/Core/functiondb.php')];

$patterns = [
    '/->' . 'prepare\s*\(/',
    '/->' . 'query\s*\(/',
    '/->' . 'exec\s*\(/',
    '/->' . 'pdo\s*\(/',
    '/\$\w+->' . '(run|one|all|exec)\s*\(/',
];

$violations = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    if (!str_ends_with($path, '.php')) {
        continue;
    }

    $real = realpath($path);
    if ($real === false) {
        continue;
    }
    if (in_array($real, $allowlist, true)) {
        continue;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        continue;
    }

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            $line = substr_count(substr($contents, 0, $matches[0][1]), "\n") + 1;
            $violations[] = $path . ':' . $line . ' -> ' . trim($matches[0][0]);
        }
    }
}

if ($violations) {
    fwrite(STDERR, "DB usage violations detected:\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, " - {$violation}\n");
    }
    exit(1);
}

echo "DB usage check passed.\n";
