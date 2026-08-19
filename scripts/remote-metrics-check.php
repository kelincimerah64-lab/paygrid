<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$from = Carbon\CarbonImmutable::parse('2026-08-01 00:00:01', 'Asia/Jakarta');
$to = Carbon\CarbonImmutable::parse('2026-08-12 23:59:59', 'Asia/Jakarta');

$stores = ['Tiktok5000', 'BL77', 'gate69', 'nnp CM - BJ', 'Agent 5758', 'HiuBet88 [3MP]', 'Kribo88', 'Rajakhodam89 [1LG]', 'KongsiBet'];

foreach ($stores as $name) {
    $merchant = App\Models\Merchant::query()->where('name', $name)->first();
    if (! $merchant) {
        echo $name."|missing".PHP_EOL;
        continue;
    }

    $base = App\Models\TopupRequest::query()
        ->where('merchant_id', $merchant->id)
        ->whereBetween('submitted_at', [$from, $to]);
    $success = (clone $base)->where('status', 'success');
    $pending = (clone $base)->where('status', 'pending');
    $expired = (clone $base)->whereIn('status', ['expired', 'failed', 'rejected']);

    echo implode('|', [
        $name,
        'agent='.($merchant->agent?->name ?: '-'),
        'total='.(clone $base)->count(),
        'success_count='.(clone $success)->count(),
        'success_amount='.(clone $success)->sum('amount'),
        'pending_count='.(clone $pending)->count(),
        'pending_amount='.(clone $pending)->sum('amount'),
        'expired_count='.(clone $expired)->count(),
        'expired_amount='.(clone $expired)->sum('amount'),
        'checked_count='.(clone $success)->where('is_processed', true)->count(),
        'checked_amount='.(clone $success)->where('is_processed', true)->sum('amount'),
        'unchecked_count='.(clone $success)->where('is_processed', false)->count(),
        'unchecked_amount='.(clone $success)->where('is_processed', false)->sum('amount'),
    ]).PHP_EOL;
}

$agent = App\Models\Merchant::query()->where('name', 'Agent 5758')->first();
if ($agent) {
    $all = App\Models\TopupRequest::query()->where('merchant_id', $agent->id);
    echo 'ALL_AGENT_5758|total='.(clone $all)->count().'|success='.(clone $all)->where('status', 'success')->count().'|pending='.(clone $all)->where('status', 'pending')->count().'|expired='.(clone $all)->whereIn('status', ['expired','failed','rejected'])->count().'|amount='.(clone $all)->sum('amount').PHP_EOL;
}
