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

        $this->assertEqualsWithDelta(0.85, $catalog->floor('ma', null, 'h_plus_1_sc'), 0.0001);
        $this->assertEqualsWithDelta(0.80, $catalog->floor('ma', null, 'h_plus_1_api'), 0.0001);
        $this->assertEqualsWithDelta(0.90, $catalog->floor('ma', null, 'everyday_sc'), 0.0001);
        $this->assertEqualsWithDelta(0.85, $catalog->floor('ma', null, 'everyday_api'), 0.0001);
        $this->assertEqualsWithDelta(0.90, $catalog->floor('ma', null, 'same_day_sc'), 0.0001);
        $this->assertEqualsWithDelta(0.85, $catalog->floor('ma', null, 'same_day_api'), 0.0001);
    }

    public function test_agent_floors_match_the_agreed_fee_scheme(): void
    {
        $catalog = new FeeMenuCatalog();

        $this->assertEqualsWithDelta(1.00, $catalog->floor('agent', 'cm', 'h_plus_1'), 0.0001);
        $this->assertEqualsWithDelta(1.10, $catalog->floor('agent', 'cm', 'everyday'), 0.0001);
        $this->assertEqualsWithDelta(1.15, $catalog->floor('agent', 'cm', 'same_day'), 0.0001);

        $this->assertEqualsWithDelta(0.95, $catalog->floor('agent', 'engine', 'h_plus_1_sc'), 0.0001);
        $this->assertEqualsWithDelta(0.85, $catalog->floor('agent', 'engine', 'h_plus_1_api'), 0.0001);
        $this->assertEqualsWithDelta(1.05, $catalog->floor('agent', 'engine', 'everyday_sc'), 0.0001);
        $this->assertEqualsWithDelta(0.95, $catalog->floor('agent', 'engine', 'everyday_api'), 0.0001);
        $this->assertEqualsWithDelta(1.10, $catalog->floor('agent', 'engine', 'same_day_sc'), 0.0001);
        $this->assertEqualsWithDelta(1.00, $catalog->floor('agent', 'engine', 'same_day_api'), 0.0001);
    }

    public function test_merchant_floors_match_the_agreed_fee_scheme(): void
    {
        $catalog = new FeeMenuCatalog();

        $this->assertEqualsWithDelta(0.85, $catalog->floor('merchant', 'cm', 'h_plus_1'), 0.0001);
        $this->assertEqualsWithDelta(0.85, $catalog->floor('merchant', 'cm', 'everyday'), 0.0001);
        $this->assertEqualsWithDelta(0.85, $catalog->floor('merchant', 'cm', 'same_day'), 0.0001);

        // Engine-type merchant floors are still 0.00 in the source scheme (business hasn't filled them in yet).
        $this->assertEqualsWithDelta(0.00, $catalog->floor('merchant', 'engine', 'h_plus_1_sc'), 0.0001);
    }

    public function test_floor_returns_null_for_unknown_combination(): void
    {
        $catalog = new FeeMenuCatalog();

        $this->assertNull($catalog->floor('merchant', 'cm', 'not_a_real_menu'));
    }

    public function test_settlement_method_strips_engine_suffix(): void
    {
        $catalog = new FeeMenuCatalog();

        $this->assertSame('h_plus_1', $catalog->settlementMethod('h_plus_1_sc'));
        $this->assertSame('h_plus_1', $catalog->settlementMethod('h_plus_1_api'));
        $this->assertSame('everyday', $catalog->settlementMethod('everyday'));
    }
}
