<?php
declare(strict_types=1);

function logs_format_user_labels(array $row): array
{
    $characterName = (string)($row['user_character_name'] ?? '');

    return [
        'user' => $characterName !== '' ? $characterName : 'Unknown',
        'character' => $characterName !== '' ? $characterName : '-',
    ];
}
