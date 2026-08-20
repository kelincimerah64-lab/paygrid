<?php

namespace App\Services;

use App\Models\FeeSnapshot;
use App\Models\Merchant;
use App\Models\TopupRequest;

class FeeService
{
    public function __construct(private readonly FeeCalculator $calculator)
    {
    }

    public function snapshot(Merchant $merchant, TopupRequest $request): FeeSnapshot
    {
        $base = (float) $merchant->base_mdr_percent;
        $connection = (float) $merchant->connection_fee_percent;
        $settlement = (float) $merchant->settlement_fee_percent;
        $ma = (float) $merchant->ma_fee_percent;
        $agent = (float) $merchant->agent_fee_percent;
        $merchantMdr = (float) $merchant->merchant_mdr_percent;

        return FeeSnapshot::query()->updateOrCreate(
            ['topup_request_id' => $request->id],
            [
                'merchant_id' => $merchant->id,
                'merchant_mdr_percent' => $merchantMdr,
                'base_mdr_percent' => $base,
                'payin_fee_percent' => $connection,
                'settlement_fee_percent' => $settlement,
                'ma_fee_percent' => $ma,
                'agent_fee_percent' => $agent,
                'toko_fee_percent' => $this->calculator->tokoResidual($merchantMdr, $base, $connection, $settlement, $ma, $agent),
            ],
        );
    }
}
