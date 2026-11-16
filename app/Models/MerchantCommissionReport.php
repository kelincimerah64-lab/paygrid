<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantCommissionReport extends Model
{
    protected $fillable = [
        'merchant_id',
        'commission_config_id',
        'transaction_date',
        'day_of_week',
        'volume',
        'rate_percent',
        'commission_amount',
        'wd_volume',
        'wd_trx_count',
        'wd_fee_fixed',
        'wd_commission_amount',
        'report_date',
    ];

    /**
     * transaction_date/report_date are deliberately NOT cast to 'date' -
     * Eloquent's date cast serializes writes as "Y-m-d 00:00:00", which no
     * longer string-matches the plain "Y-m-d" key used to look the row up
     * again (e.g. the generator job's updateOrCreate search key, or any
     * ->where('transaction_date', $wibDateString) query). Keeping them as
     * bare strings keeps every read/write comparing the same format.
     */
    protected $casts = [
        'rate_percent' => 'decimal:4',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(CommissionConfig::class, 'commission_config_id');
    }
}
