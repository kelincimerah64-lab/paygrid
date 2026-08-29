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
}
