<?php
declare(strict_types=1);

namespace App\Corptools\Audit\Collectors;

use App\Corptools\Audit\AbstractCollector;

final class RolesCollector extends AbstractCollector
{
    public function key(): string
    {
        return 'roles';
    }

    public function scopes(): array
    {
        return [
            'esi-characters.read_corporation_roles.v1',
            'esi-characters.read_titles.v1',
        ];
    }

    public function endpoints(int $characterId): array
    {
        return [
            "/latest/characters/{$characterId}/roles/",
            "/latest/characters/{$characterId}/titles/",
        ];
    }

    public function summarize(int $characterId, array $payloads): array
    {
        $rolesPayload = $payloads[0] ?? [];
        $titlesPayload = $payloads[1] ?? [];

        $roles = [];
        foreach (['roles', 'roles_at_base', 'roles_at_hq', 'roles_at_other'] as $key) {
            if (!empty($rolesPayload[$key]) && is_array($rolesPayload[$key])) {
                $roles = array_merge($roles, $rolesPayload[$key]);
            }
        }
        $roles = array_values(array_unique(array_filter($roles, 'is_string')));

        $title = '';
        if (is_array($titlesPayload) && !empty($titlesPayload[0])) {
            $title = (string)($titlesPayload[0]['name'] ?? '');
        }

        return [
            'corp_roles_json' => json_encode($roles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'corp_title' => $title,
        ];
    }
}
