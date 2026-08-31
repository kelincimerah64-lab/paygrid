<?php

namespace App\Services;

use App\Models\FeeMenu;

class FeeMenuCatalog
{
    private const ROLES = ['ma', 'agent', 'merchant'];

    private static array $cache = [];

    public function typeCategory(?string $rawType): string
    {
        return $rawType === 'cm' ? 'cm' : 'engine';
    }

    /**
     * Menus and their per-role floor/enabled state are managed by superadmin
     * (see App\Models\FeeMenu). Cached per-request only, cleared via
     * clearCache() after any write - there's no cm/engine split anymore, so
     * $typeCategory is accepted for call-site compatibility but unused.
     */
    public function optionsFor(string $role, ?string $typeCategory = null): array
    {
        if (! in_array($role, self::ROLES, true)) {
            return [];
        }

        return self::$cache[$role] ??= FeeMenu::query()
            ->where("{$role}_enabled", true)
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (FeeMenu $menu) => [
                $menu->key => ['label' => $menu->label, 'floor' => (float) $menu->{"{$role}_floor"}],
            ])
            ->all();
    }

    public static function clearCache(): void
    {
        self::$cache = [];
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
