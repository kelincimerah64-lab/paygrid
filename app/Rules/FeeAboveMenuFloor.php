<?php

namespace App\Rules;

use App\Services\FeeMenuCatalog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class FeeAboveMenuFloor implements ValidationRule
{
    public function __construct(
        private readonly string $role,
        private readonly ?string $typeCategory,
        private readonly ?string $menuKey,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->menuKey === null) {
            return;
        }

        $floor = app(FeeMenuCatalog::class)->floor($this->role, $this->typeCategory, $this->menuKey);

        if ($floor !== null && (float) $value < $floor) {
            $fail("Fee tidak boleh kurang dari {$floor}% untuk menu ini.");
        }
    }
}
