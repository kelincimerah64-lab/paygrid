<?php

namespace App\Rules;

use App\Services\FeeMenuCatalog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class FeeMenuRatesAboveFloor implements ValidationRule
{
    public function __construct(
        private readonly string $role,
        private readonly ?string $typeCategory,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $catalog = app(FeeMenuCatalog::class)->optionsFor($this->role, $this->typeCategory);

        foreach ((array) $value as $menuKey => $percent) {
            if ($percent === null || (float) $percent <= 0) {
                continue;
            }

            $option = $catalog[$menuKey] ?? null;
            if ($option !== null && (float) $percent < (float) $option['floor']) {
                $fail("Fee \"{$option['label']}\" tidak boleh kurang dari {$option['floor']}%.");
            }
        }
    }
}
