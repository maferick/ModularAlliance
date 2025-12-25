<?php
declare(strict_types=1);

namespace App\Securegroups;

interface ProviderInterface
{
    public function getKey(): string;

    public function getDisplayName(): string;

    /** @return array<int, array<string, mixed>> */
    public function getAvailableRules(): array;

    /** @return array<string, mixed> */
    public function evaluateRule(int $userId, array $rule, array $context = []): array;
}
