<?php

use App\Http\Controllers\GatewayCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/callbacks/{gateway}/{type}', [GatewayCallbackController::class, 'receive'])
    ->whereIn('gateway', ['hilogate', 'artageto'])
    ->whereIn('type', ['transaction', 'withdrawal', 'payin']);
