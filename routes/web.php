<?php

use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\CenterSupportController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GatewayCallbackController;
use App\Http\Controllers\MerchantRegistrationController;
use App\Http\Controllers\MerchantRegistrationWorkflowController;
use App\Http\Controllers\MerchantAdminController;
use App\Http\Controllers\MerchantProvisioningController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\GatewaySyncRetryController;
use App\Http\Controllers\MaController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\SuperadminController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'role:ma,superadmin'])->group(function () {
Route::get('/ma', [MaController::class, 'page'])->name('ma.overview');
Route::get('/ma/report', fn (MaController $controller) => $controller->page('report'))->name('ma.report');
Route::get('/ma/report/export', [MaController::class, 'export'])->name('ma.report.export');
Route::get('/ma/fee', fn (MaController $controller) => $controller->page('fee'))->name('ma.fee');
Route::get('/ma/approvals', fn (MaController $controller) => $controller->page('approval'))->name('ma.approvals');
Route::get('/ma/mapping', fn (MaController $controller) => $controller->page('mapping'))->name('ma.mapping');
Route::post('/ma/mapping/{merchant}', [MaController::class, 'mapAgent'])->middleware('throttle:dashboard-writes')->name('ma.mapping.update');
Route::get('/ma/stores', fn (MaController $controller) => $controller->page('stores'))->name('ma.stores');
Route::post('/ma/stores/{merchant}/fee', [MaController::class, 'updateStoreFee'])->middleware('throttle:dashboard-writes')->name('ma.stores.fee.update');
Route::get('/ma/agents', fn (MaController $controller) => $controller->page('agents'))->name('ma.agents');
Route::post('/ma/agents', [MaController::class, 'storeAgent'])->middleware('throttle:dashboard-writes')->name('ma.agents.store');
Route::get('/ma/create-store', fn (MaController $controller) => $controller->page('create-store'))->name('ma.create-store');
Route::post('/ma/create-store', [MaController::class, 'storeMerchant'])->middleware('throttle:dashboard-writes')->name('ma.create-store.store');
Route::get('/ma/bot-monitoring', fn (MaController $controller) => $controller->page('bot-monitoring'))->name('ma.bot-monitoring');
});

Route::middleware(['auth', 'role:superadmin'])->group(function () {
Route::get('/superadmin', [SuperadminController::class, 'page'])->name('superadmin.overview');
Route::get('/superadmin/{page}', [SuperadminController::class, 'page'])->name('superadmin.page');
Route::post('/superadmin/ma', [SuperadminController::class, 'storeMa'])->middleware('throttle:dashboard-writes')->name('superadmin.ma.store');
Route::post('/superadmin/ma/{user}', [SuperadminController::class, 'updateMa'])->middleware('throttle:dashboard-writes')->name('superadmin.ma.update');
Route::post('/superadmin/merchant-fee/{merchant}', [SuperadminController::class, 'updateMerchantFee'])->middleware('throttle:dashboard-writes')->name('superadmin.merchant-fee.update');
Route::post('/superadmin/merchant-group', [SuperadminController::class, 'storeAgent'])->middleware('throttle:dashboard-writes')->name('superadmin.agent.store');
Route::post('/superadmin/timer-ticket', [SuperadminController::class, 'updateTimer'])->middleware('throttle:dashboard-writes')->name('superadmin.timer-ticket.update');
Route::post('/superadmin/accounts/{user}/reset', [SuperadminController::class, 'resetAccount'])->middleware('throttle:dashboard-writes')->name('superadmin.accounts.reset');
});

Route::middleware(['auth', 'role:cs_pusat,ma,superadmin'])->group(function () {
Route::get('/cs-pusat', [CenterSupportController::class, 'index'])->name('center-support.tickets');
Route::get('/cs-pusat/tickets/{ticket}/attachments/{index}', [CenterSupportController::class, 'attachment'])->whereNumber('index')->name('center-support.tickets.attachment');
Route::post('/cs-pusat/tickets/{ticket}', [CenterSupportController::class, 'update'])->middleware('throttle:dashboard-writes')->name('center-support.tickets.update');
});

Route::middleware(['auth', 'role:superadmin'])->group(function () {
Route::get('/admin/users', fn (DashboardController $controller) => $controller->adminSimple('users', app(\App\Services\Navigation\MenuBuilder::class)))->name('admin.users');
Route::get('/admin/log-aktivitas', fn (DashboardController $controller) => $controller->adminSimple('logs', app(\App\Services\Navigation\MenuBuilder::class)))->name('admin.logs');
Route::get('/admin/monitoring', [MonitoringController::class, 'index'])->name('admin.monitoring');
});

Route::get('/portal/{merchant}/admin', fn ($merchant) => redirect()->route('merchant.admin.users', $merchant));
Route::middleware(['auth', 'role:admin,readonly_admin,ma,superadmin', 'merchant.scope'])->group(function () {
Route::get('/portal/{merchant}/admin/users', fn (\App\Models\Merchant $merchant, MerchantAdminController $controller) => $controller->page($merchant, 'users', app(\App\Services\Navigation\MenuBuilder::class)))->name('merchant.admin.users');
Route::post('/portal/{merchant}/admin/users', [MerchantAdminController::class, 'storeUser'])->middleware('throttle:dashboard-writes')->name('merchant.admin.users.store');
Route::post('/portal/{merchant}/admin/users/{user}/reset-password', [MerchantAdminController::class, 'resetPassword'])->middleware('throttle:dashboard-writes')->name('merchant.admin.users.reset-password');
Route::get('/portal/{merchant}/admin/settings', fn (\App\Models\Merchant $merchant, MerchantAdminController $controller) => $controller->page($merchant, 'settings', app(\App\Services\Navigation\MenuBuilder::class)))->name('merchant.admin.settings');
Route::post('/portal/{merchant}/admin/settings/minimum-topup', [MerchantAdminController::class, 'updateMinimumTopup'])->middleware('throttle:dashboard-writes')->name('merchant.admin.minimum-topup.update');
Route::get('/portal/{merchant}/admin/logs', fn (\App\Models\Merchant $merchant, MerchantAdminController $controller) => $controller->page($merchant, 'logs', app(\App\Services\Navigation\MenuBuilder::class)))->name('merchant.admin.logs');
Route::get('/portal/{merchant}/admin/qris', fn (\App\Models\Merchant $merchant, MerchantAdminController $controller) => $controller->page($merchant, 'qris', app(\App\Services\Navigation\MenuBuilder::class)))->name('merchant.admin.qris');
Route::get('/portal/{merchant}/admin/checklist', fn (\App\Models\Merchant $merchant, MerchantAdminController $controller) => $controller->page($merchant, 'checklist', app(\App\Services\Navigation\MenuBuilder::class)))->name('merchant.admin.checklist');
Route::get('/portal/{merchant}/admin/history', fn (\App\Models\Merchant $merchant, MerchantAdminController $controller) => $controller->page($merchant, 'history', app(\App\Services\Navigation\MenuBuilder::class)))->name('merchant.admin.history');
});

Route::middleware(['auth', 'role:agent'])->group(function () {
Route::get('/agent', [DashboardController::class, 'agent'])->name('agent.overview');
Route::get('/agent/fee', [DashboardController::class, 'agentFee'])->name('agent.fee');
Route::redirect('/agen', '/agent');
Route::get('/agent/create-store', fn (DashboardController $controller) => $controller->agentSimple('create-store', app(\App\Services\Navigation\MenuBuilder::class)))->name('agent.create-store');
Route::post('/agent/onboarding-links', [DashboardController::class, 'storeAgentOnboardingLink'])->middleware('throttle:dashboard-writes')->name('agent.onboarding-links.store');
Route::post('/agent/onboarding-links/{link}/expire', [DashboardController::class, 'expireAgentOnboardingLink'])->middleware('throttle:dashboard-writes')->name('agent.onboarding-links.expire');
Route::post('/agent/onboarding-links/bulk', [DashboardController::class, 'bulkAgentOnboardingLinks'])->middleware('throttle:dashboard-writes')->name('agent.onboarding-links.bulk');
Route::get('/agent/requests', fn (DashboardController $controller) => $controller->agentSimple('status-request', app(\App\Services\Navigation\MenuBuilder::class)))->name('agent.requests');
Route::delete('/agent/requests/{registration}', [DashboardController::class, 'deleteAgentRegistration'])->middleware('throttle:dashboard-writes')->name('agent.requests.delete');
Route::post('/agent/requests/bulk', [DashboardController::class, 'bulkAgentRegistrations'])->middleware('throttle:dashboard-writes')->name('agent.requests.bulk');
Route::get('/agent/export', [DashboardController::class, 'exportAgentReport'])->name('agent.export');
Route::redirect('/agent/new-store', '/agent/create-store')->name('agent.new-store');
});

Route::match(['get', 'post'], '/form', fn () => abort(404));
Route::get('/onboarding/{link:token}', [MerchantRegistrationController::class, 'tokenForm'])->name('merchant-registration.token-form');
Route::post('/onboarding/{link:token}', [MerchantRegistrationController::class, 'tokenStore'])->middleware('throttle:dashboard-writes')->name('merchant-registration.token-store');
Route::middleware(['auth', 'role:agent,ma,superadmin'])->group(function () {
    Route::post('/api/merchant-registrations/{registration}/submit', [MerchantRegistrationWorkflowController::class, 'submit'])->name('api.merchant-registration.submit');
});
Route::middleware(['auth', 'role:ma,superadmin'])->group(function () {
    Route::post('/api/merchant-registrations/{registration}/approve', [MerchantRegistrationWorkflowController::class, 'approve'])->name('api.merchant-registration.approve');
    Route::post('/api/merchant-registrations/{registration}/reject', [MerchantRegistrationWorkflowController::class, 'reject'])->name('api.merchant-registration.reject');
    Route::post('/api/merchants/{merchant}/provision/retry', [MerchantProvisioningController::class, 'retry'])->name('api.merchant.provision.retry');
    Route::post('/api/merchants/{merchant}/sync/retry', [GatewaySyncRetryController::class, 'store'])->name('api.merchant.sync.retry');
});

Route::get('/portal/{merchant}/cs', fn ($merchant) => redirect()->route('merchant.cs.tickets', $merchant));
Route::middleware(['auth', 'role:cs,readonly_cs,ma,admin,readonly_admin,superadmin', 'merchant.scope'])->group(function () {
Route::get('/portal/{merchant}/cs/tickets', fn (\App\Models\Merchant $merchant, DashboardController $controller) => $controller->merchantCs($merchant, 'tickets', app(\App\Services\Navigation\MenuBuilder::class), app(\App\Services\MetricsService::class)))->name('merchant.cs.tickets');
Route::get('/portal/{merchant}/cs/topup', fn (\App\Models\Merchant $merchant, DashboardController $controller) => $controller->merchantCs($merchant, 'topup', app(\App\Services\Navigation\MenuBuilder::class), app(\App\Services\MetricsService::class)))->name('merchant.cs.topup');
Route::get('/portal/{merchant}/cs/checklist', fn (\App\Models\Merchant $merchant, DashboardController $controller) => $controller->merchantCs($merchant, 'checklist', app(\App\Services\Navigation\MenuBuilder::class), app(\App\Services\MetricsService::class)))->name('merchant.cs.checklist');
Route::post('/portal/{merchant}/cs/tickets/{ticket}/submit', [SupportTicketController::class, 'submit'])->middleware('throttle:dashboard-writes')->name('merchant.cs.ticket.submit');
Route::post('/portal/{merchant}/cs/topup/{topupRequest}/ticket', [SupportTicketController::class, 'createFromTopup'])->middleware('throttle:dashboard-writes')->name('merchant.cs.topup.ticket');
Route::get('/portal/{merchant}/cs/history', fn (\App\Models\Merchant $merchant, DashboardController $controller) => $controller->merchantCs($merchant, 'history', app(\App\Services\Navigation\MenuBuilder::class), app(\App\Services\MetricsService::class)))->name('merchant.cs.history');
});

Route::middleware(['auth', 'role:ma,superadmin'])->group(function () {
Route::get('/embedded/transactpro/toko/cs/index.html', fn (DashboardController $controller) => $controller->merchantCs(\App\Models\Merchant::query()->where('merchant_type', 'cm')->firstOrFail(), 'tickets', app(\App\Services\Navigation\MenuBuilder::class), app(\App\Services\MetricsService::class)))->middleware('auth')->name('legacy.cm.cs');
Route::get('/embedded/transactpro/toko/finance/index.html', fn (DashboardController $controller) => $controller->merchantFinance(\App\Models\Merchant::query()->where('merchant_type', 'cm')->firstOrFail(), 'overview', app(\App\Services\Navigation\MenuBuilder::class)))->middleware('auth')->name('legacy.cm.finance');
Route::get('/embedded/merchant_script/toko/cs/index.html', fn (DashboardController $controller) => $controller->merchantCs(\App\Models\Merchant::query()->where('merchant_type', 'script')->firstOrFail(), 'tickets', app(\App\Services\Navigation\MenuBuilder::class), app(\App\Services\MetricsService::class)))->middleware('auth')->name('legacy.script.cs');
Route::get('/embedded/merchant_script/toko/finance/index.html', fn (DashboardController $controller) => $controller->merchantFinance(\App\Models\Merchant::query()->where('merchant_type', 'script')->firstOrFail(), 'overview', app(\App\Services\Navigation\MenuBuilder::class)))->middleware('auth')->name('legacy.script.finance');
});

Route::middleware(['auth', 'role:finance,ma,admin,readonly_admin,superadmin', 'merchant.scope'])->group(function () {
Route::get('/portal/{merchant}/finance', fn ($merchant) => redirect()->route('merchant.finance.overview', $merchant));
Route::get('/portal/{merchant}/finance/overview', fn (\App\Models\Merchant $merchant, DashboardController $controller) => $controller->merchantFinance($merchant, 'overview', app(\App\Services\Navigation\MenuBuilder::class)))->name('merchant.finance.overview');
Route::get('/portal/{merchant}/finance/settlement', fn (\App\Models\Merchant $merchant, DashboardController $controller) => $controller->merchantFinance($merchant, 'settlement', app(\App\Services\Navigation\MenuBuilder::class)))->name('merchant.finance.settlement');
Route::get('/portal/{merchant}/finance/report', fn (\App\Models\Merchant $merchant, DashboardController $controller) => $controller->merchantFinance($merchant, 'report', app(\App\Services\Navigation\MenuBuilder::class)))->name('merchant.finance.report');
});

Route::get('/topup/{merchant?}', [MerchantRegistrationController::class, 'topup'])->name('topup');
Route::post('/topup/{merchant}/submit', [\App\Http\Controllers\TopupController::class, 'store'])->middleware('throttle:topup-submit')->name('topup.submit');
Route::get('/topup/{merchant}/status/{topupRequest:public_token}', [\App\Http\Controllers\TopupController::class, 'status'])->middleware('throttle:topup-public')->name('topup.status');
Route::get('/topup/{merchant}/qr/{topupRequest:public_token}', [\App\Http\Controllers\TopupController::class, 'qr'])->middleware('throttle:topup-public')->name('topup.qr');
Route::post('/topup/{merchant}/regenerate/{topupRequest:public_token}', [\App\Http\Controllers\TopupController::class, 'regenerate'])->middleware('throttle:topup-submit')->name('topup.regenerate');
Route::get('/api/topup/{merchant}/status/{topupRequest:public_token}', [\App\Http\Controllers\TopupController::class, 'statusJson'])->middleware('throttle:topup-public')->name('api.topup.status');

Route::patch('/api/topup-requests/{topupRequest}/checklist', [ChecklistController::class, 'update'])->middleware(['auth', 'role:cs,admin,ma,superadmin', 'throttle:dashboard-writes'])->name('api.checklist.update');
Route::patch('/api/topup-requests/{topupRequest}/cs-note', [ChecklistController::class, 'updateNote'])->middleware(['auth', 'role:cs,admin,ma,superadmin', 'throttle:dashboard-writes'])->name('api.topup-requests.cs-note');
