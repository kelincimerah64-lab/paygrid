<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A merchant runs on exactly one real menu at a time (it drives settlement_method
 * and the topup link), so unlike Agent/MA - which can have many simultaneous
 * rates for their many downstream entities - the merchant fee form must have
 * exactly one filled (non-zero) rate; that one is the merchant's active menu.
 */
class ExactlyOneFeeMenuFilled implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $filled = array_filter((array) $value, static fn ($rate) => (float) $rate > 0);

        if (count($filled) !== 1) {
            $fail('Isi tepat satu menu untuk toko ini (menu yang beneran dipakai sekarang).');
        }
    }
}
