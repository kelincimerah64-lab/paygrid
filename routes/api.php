<?php

use App\Http\Controllers\Api\JiraSyncController;
use App\Http\Controllers\Api\N8nTicketApprovalController;
use App\Http\Controllers\Api\OpsAssistantController;
use App\Http\Controllers\Api\TelegramBotController;
use App\Http\Controllers\BotTele\WahaWebhookController as BotTeleWahaWebhookController;
use App\Http\Controllers\BotTele\WebhookController as BotTeleWebhookController;
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

Route::post('/bottele/webhook', BotTeleWebhookController::class)->name('bottele.webhook');
Route::post('/bottele/webhooks/waha-incoming', BotTeleWahaWebhookController::class)->name('bottele.waha-webhook');

Route::post('/ops/merchants', [OpsAssistantController::class, 'createMerchant'])->middleware('throttle:dashboard-writes');
Route::get('/ops/merchants', [OpsAssistantController::class, 'listMerchants']);
Route::get('/ops/summary', [OpsAssistantController::class, 'summary']);
Route::post('/ops/query', [OpsAssistantController::class, 'query']);

Route::post('/jira-sync/trigger', [JiraSyncController::class, 'trigger'])->middleware('throttle:dashboard-writes');
