<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

App\Models\Agent::query()
    ->where('name', 'like', '%EL%')
    ->orWhere('code', 'like', '%EL%')
    ->orderBy('name')
    ->get()
    ->each(function (App\Models\Agent $agent): void {
        $merchantIds = App\Models\Merchant::query()->where('agent_id', $agent->id)->pluck('id');
        $transactions = App\Models\TopupRequest::query()->whereIn('merchant_id', $merchantIds);

        echo implode('|', [
            'agent_id='.$agent->id,
            'name='.$agent->name,
            'code='.$agent->code,
            'stores='.$merchantIds->count(),
            'trx='.(clone $transactions)->count(),
            'success='.(clone $transactions)->where('status', 'success')->count(),
            'pending='.(clone $transactions)->where('status', 'pending')->count(),
            'volume='.(clone $transactions)->sum('amount'),
        ]).PHP_EOL;
    });
