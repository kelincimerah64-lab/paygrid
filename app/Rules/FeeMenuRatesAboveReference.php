<?php

namespace App\Rules;

use App\Services\FeeMenuCatalog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class FeeMenuRatesAboveReference implements ValidationRule
{
    public function __construct(
        private readonly string $role,
        private readonly array $referenceRates,
        private readonly string $referenceLabel,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $catalog = app(FeeMenuCatalog::class)->optionsFor($this->role);

        foreach ((array) $value as $menuKey => $percent) {
            if ($percent === null || (float) $percent <= 0) {
                continue;
            }

            $reference = (float) ($this->referenceRates[$menuKey] ?? 0);
            if ((float) $percent < $reference) {
                $label = $catalog[$menuKey]['label'] ?? $menuKey;
                $fail("Fee \"{$label}\" tidak boleh kurang dari {$this->referenceLabel} ({$reference}%).");
            }
        }
    }
}
