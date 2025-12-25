<?php
declare(strict_types=1);

namespace App\Fittings;

final class EftParser
{
    private const SECTION_ORDER = ['low', 'mid', 'high', 'rig', 'subsystem', 'drone', 'cargo'];

    /** @return array{ship:string, name:string, items:array<int, array<string, mixed>>, errors:array<int, string>} */
    public function parse(string $eftText): array
    {
        $errors = [];
        $items = [];

        $normalized = str_replace(["\r\n", "\r"], "\n", trim($eftText));
        if ($normalized === '') {
            return ['ship' => '', 'name' => '', 'items' => [], 'errors' => ['EFT text is empty.']];
        }

        $lines = explode("\n", $normalized);
        $header = trim((string)array_shift($lines));
        if (!preg_match('/^\[(.+?),\s*(.+?)\]$/', $header, $matches)) {
            $errors[] = 'Missing EFT header. Expected: [Ship Name, Fit Name].';
            return ['ship' => '', 'name' => '', 'items' => [], 'errors' => $errors];
        }

        $ship = trim($matches[1]);
        $fitName = trim($matches[2]);
        if ($ship === '' || $fitName === '') {
            $errors[] = 'EFT header must include ship and fit name.';
        }

        $sectionIndex = 0;
        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                $sectionIndex++;
                continue;
            }

            if (str_starts_with($line, '[') && str_ends_with($line, ']')) {
                continue;
            }

            $section = self::SECTION_ORDER[min($sectionIndex, count(self::SECTION_ORDER) - 1)];

            $quantity = 1;
            $name = $line;
            if (preg_match('/\s+x(\d+)$/i', $line, $qtyMatch)) {
                $quantity = max(1, (int)$qtyMatch[1]);
                $name = trim(preg_replace('/\s+x(\d+)$/i', '', $line) ?? '');
            }

            $moduleName = $name;
            $chargeName = null;
            if (str_contains($name, ',')) {
                [$moduleName, $chargeName] = array_map('trim', explode(',', $name, 2));
            }

            if ($moduleName === '') {
                continue;
            }

            $items[] = [
                'name' => $moduleName,
                'quantity' => $quantity,
                'section' => $section,
                'type' => $section === 'drone' ? 'drone' : 'module',
            ];

            if ($chargeName) {
                $items[] = [
                    'name' => $chargeName,
                    'quantity' => $quantity,
                    'section' => 'cargo',
                    'type' => 'charge',
                ];
            }
        }

        if (empty($items)) {
            $errors[] = 'No modules or cargo items detected in EFT text.';
        }

        return [
            'ship' => $ship,
            'name' => $fitName,
            'items' => $items,
            'errors' => $errors,
        ];
    }
}
