<?php

namespace App\Services;

class FeeMenuCatalog
{
    public function typeCategory(?string $rawType): string
    {
        return $rawType === 'cm' ? 'cm' : 'engine';
    }

    public function optionsFor(string $role, ?string $typeCategory = null): array
    {
        $menus = config("paygrid.fee_menus.{$role}");

        if ($menus === null) {
            return [];
        }

        return $typeCategory === null ? $menus : (array) ($menus[$typeCategory] ?? []);
    }

    public function floor(string $role, ?string $typeCategory, string $menuKey): ?float
    {
        $option = $this->optionsFor($role, $typeCategory)[$menuKey] ?? null;

        return $option === null ? null : (float) $option['floor'];
    }

    public function settlementMethod(string $menuKey): string
    {
        return preg_replace('/_(sc|api)$/', '', $menuKey);
    }

    public function normalizeRates(array $raw, string $role, ?string $typeCategory = null): array
    {
        $catalog = $this->optionsFor($role, $typeCategory);

        $rates = [];
        foreach (array_keys($catalog) as $menuKey) {
            $value = $raw[$menuKey] ?? null;
            $rates[$menuKey] = $value === null || $value === ''
                ? 0.0
                : (float) str_replace(',', '.', (string) $value);
        }

        return $rates;
    }

    public function ratesSummary(array $rates, string $role, ?string $typeCategory = null): string
    {
        $catalog = $this->optionsFor($role, $typeCategory);
        $parts = [];
        foreach ($rates as $key => $value) {
            if ((float) $value <= 0) {
                continue;
            }
            $label = $catalog[$key]['label'] ?? $key;
            $parts[] = "{$label}: ".number_format((float) $value, 2).'%';
        }

        return $parts === [] ? '-' : implode(', ', $parts);
    }
}
