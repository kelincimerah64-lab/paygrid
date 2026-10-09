<?php

use App\Http\Controllers\Api\N8nTicketApprovalController;
use App\Http\Controllers\Api\OpsAssistantController;
use App\Http\Controllers\Api\TelegramBotController;
use App\Http\Controllers\GatewayCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/callbacks/{gateway}/{type}', [GatewayCallbackController::class, 'receive'])
    ->whereIn('gateway', ['hilogate', 'artageto'])
    ->whereIn('type', ['transaction', 'withdrawal', 'payin']);

Route::post('/n8n/tickets/{ticketNo}/approval', [N8nTicketApprovalController::class, 'update']);

Route::post('/telegram/group-events', [TelegramBotController::class, 'groupEvent']);
Route::post('/telegram/activation-requests', [TelegramBotController::class, 'activationRequest']);
Route::post('/telegram/activate', [TelegramBotController::class, 'activate']);
Route::post('/telegram/absen', [TelegramBotController::class, 'absen']);

Route::post('/ops/merchants', [OpsAssistantController::class, 'createMerchant'])->middleware('throttle:dashboard-writes');
Route::get('/ops/merchants', [OpsAssistantController::class, 'listMerchants']);
Route::get('/ops/summary', [OpsAssistantController::class, 'summary']);
