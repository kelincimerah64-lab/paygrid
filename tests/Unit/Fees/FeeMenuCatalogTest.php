<?php

namespace Tests\Unit\Fees;

use App\Services\FeeMenuCatalog;
use Tests\TestCase;

class FeeMenuCatalogTest extends TestCase
{
    public function test_type_category_maps_cm_and_everything_else(): void
    {
        $catalog = new FeeMenuCatalog();

        $this->assertSame('cm', $catalog->typeCategory('cm'));
        $this->assertSame('engine', $catalog->typeCategory('script'));
        $this->assertSame('engine', $catalog->typeCategory(null));
    }

    public function test_ma_floors_match_the_agreed_fee_scheme(): void
    {
        $catalog = new FeeMenuCatalog();

        $this->assertEqualsWithDelta(0.80, $catalog->floor('ma', null, 'based'), 0.0001);
        $this->assertEqualsWithDelta(0.80, $catalog->floor('ma', null, 'h_plus_1'), 0.0001);
        $this->assertEqualsWithDelta(0.85, $catalog->floor('ma', null, 'everyday'), 0.0001);
        $this->assertEqualsWithDelta(0.90, $catalog->floor('ma', null, 'same_day'), 0.0001);
        $this->assertEqualsWithDelta(0.85, $catalog->floor('ma', null, 'h_plus_1_sc'), 0.0001);
        $this->assertEqualsWithDelta(0.90, $catalog->floor('ma', null, 'everyday_sc'), 0.0001);
        $this->assertEqualsWithDelta(0.95, $catalog->floor('ma', null, 'same_day_sc'), 0.0001);
        $this->assertEqualsWithDelta(0.80, $catalog->floor('ma', null, 'h_plus_1_api'), 0.0001);
        $this->assertEqualsWithDelta(0.85, $catalog->floor('ma', null, 'everyday_api'), 0.0001);
        $this->assertEqualsWithDelta(0.90, $catalog->floor('ma', null, 'same_day_api'), 0.0001);
    }

    /**
     * Agent and Merchant menus are free-form (no minimum) - only MA enforces a floor.
     * Every menu key still exists so the rate table renders the same 10 menus everywhere.
     */
    public function test_agent_and_merchant_menus_share_ma_labels_with_no_floor(): void
    {
        $catalog = new FeeMenuCatalog();

        foreach (['agent', 'merchant'] as $role) {
            $options = $catalog->optionsFor($role);

            $this->assertSame(array_keys($catalog->optionsFor('ma')), array_keys($options));
            foreach ($options as $key => $option) {
                $this->assertSame($catalog->optionsFor('ma')[$key]['label'], $option['label']);
                $this->assertEqualsWithDelta(0.0, $option['floor'], 0.0001);
            }
        }
    }

    public function test_floor_returns_null_for_unknown_combination(): void
    {
        $catalog = new FeeMenuCatalog();

        $this->assertNull($catalog->floor('merchant', null, 'not_a_real_menu'));
    }

    public function test_settlement_method_strips_engine_suffix(): void
    {
        $catalog = new FeeMenuCatalog();

        $this->assertSame('h_plus_1', $catalog->settlementMethod('h_plus_1_sc'));
        $this->assertSame('h_plus_1', $catalog->settlementMethod('h_plus_1_api'));
        $this->assertSame('everyday', $catalog->settlementMethod('everyday'));
    }

    public function test_normalize_rates_defaults_missing_keys_to_zero(): void
    {
        $catalog = new FeeMenuCatalog();

        $rates = $catalog->normalizeRates(['h_plus_1' => '1,10'], 'agent');

        $this->assertSame(1.10, $rates['h_plus_1']);
        $this->assertSame(0.0, $rates['everyday']);
        $this->assertSame(0.0, $rates['based']);
    }

    public function test_normalize_rates_drops_keys_outside_the_catalog(): void
    {
        $catalog = new FeeMenuCatalog();

        $rates = $catalog->normalizeRates(['h_plus_1' => '1.20', 'not_a_real_menu' => '5'], 'agent');

        $this->assertArrayNotHasKey('not_a_real_menu', $rates);
        $this->assertEqualsWithDelta(1.20, $rates['h_plus_1'], 0.0001);
    }

    public function test_rates_summary_lists_only_positive_rates(): void
    {
        $catalog = new FeeMenuCatalog();

        $summary = $catalog->ratesSummary(['h_plus_1' => 1.05, 'everyday' => 0, 'same_day' => 0], 'agent');

        $this->assertSame('Based + H+1: 1.05%', $summary);
    }

    public function test_rates_summary_returns_dash_when_nothing_is_set(): void
    {
        $catalog = new FeeMenuCatalog();

        $this->assertSame('-', $catalog->ratesSummary(['h_plus_1' => 0], 'agent'));
    }
}
