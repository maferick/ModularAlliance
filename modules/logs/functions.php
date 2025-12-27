<?php
declare(strict_types=1);

function logs_format_user_labels(array $row): array
{
    $userId = (string)($row['user_public_id'] ?? '');
    $characterName = (string)($row['user_character_name'] ?? '');

    return [
        'user' => $userId !== '' ? $userId : '-',
        'character' => $characterName !== '' ? $characterName : '-',
    ];
}
