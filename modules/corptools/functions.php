<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\Universe;

function corptools_resolve_entity_id(Db $db, string $type, string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (ctype_digit($value)) {
        return $value;
    }

    $row = db_one(
        $db,
        "SELECT entity_id FROM universe_entities WHERE entity_type=? AND name LIKE ? ORDER BY fetched_at DESC LIMIT 1",
        [$type, '%' . $value . '%']
    );

    return $row ? (string)($row['entity_id'] ?? '') : '';
}

function corptools_format_org(Universe $universe, string $type, int $id): string
{
    if ($id <= 0) {
        return '—';
    }

    return $universe->nameOrUnknown($type, $id);
}
