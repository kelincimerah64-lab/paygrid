<?php

use App\Http\Controllers\Api\N8nTicketApprovalController;
use App\Http\Controllers\GatewayCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/callbacks/{gateway}/{type}', [GatewayCallbackController::class, 'receive'])
    ->whereIn('gateway', ['hilogate', 'artageto'])
    ->whereIn('type', ['transaction', 'withdrawal', 'payin']);

Route::post('/n8n/tickets/{ticketNo}/approval', [N8nTicketApprovalController::class, 'update']);
