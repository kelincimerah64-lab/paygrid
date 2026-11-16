<?php

use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\CsScopeController;
use App\Http\Controllers\CenterSupportController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentTicketController;
use App\Http\Controllers\MaTicketController;
use App\Http\Controllers\MerchantTicketController;
use App\Http\Controllers\MobileAppController;
use App\Http\Controllers\GatewayCallbackController;
use App\Http\Controllers\MerchantRegistrationController;
use App\Http\Controllers\MerchantRegistrationWorkflowController;
use App\Http\Controllers\MerchantAdminController;
use App\Http\Controllers\MerchantProvisioningController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\GatewaySyncRetryController;
use App\Http\Controllers\MaController;
use App\Http\Controllers\MaAssistantController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\SuperadminController;
use App\Http\Controllers\TelegramBroadcastController;
use App\Http\Controllers\TelegramCsMonitorController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

Route::prefix('mobile')->name('mobile.')->group(function () {
    Route::get('/login', [MobileAppController::class, 'loginForm'])->name('login');
    Route::post('/login', [MobileAppController::class, 'login'])->middleware('throttle:6,1')->name('login.attempt');
    Route::post('/logout', [MobileAppController::class, 'logout'])->middleware('auth')->name('logout');
    Route::get('/dashboard', [MobileAppController::class, 'dashboard'])->middleware(['auth', 'role:admin,boss,finance,cs,ma,agent'])->name('dashboard');
});

Route::middleware(['auth', 'role:ma,superadmin'])->group(function () {
Route::get('/ma', [MaController::class, 'page'])->name('ma.overview');
Route::get('/ma/report', fn (MaController $controller) => $controller->page('report'))->name('ma.report');
Route::get('/ma/report/export', [MaController::class, 'export'])->name('ma.report.export');
Route::get('/ma/fee', fn (MaController $controller) => $controller->page('fee'))->name('ma.fee');
Route::get('/ma/fee/export', [MaController::class, 'feeSplitExport'])->name('ma.fee.export');
Route::get('/ma/approvals', fn (MaController $controller) => $controller->page('approval'))->name('ma.approvals');
Route::get('/ma/mapping', fn (MaController $controller) => $controller->page('mapping'))->name('ma.mapping');
Route::get('/ma/insentif', [MaController::class, 'insentifForm'])->name('ma.insentif');
Route::post('/ma/insentif/pdf', [MaController::class, 'insentifPdf'])->middleware('throttle:dashboard-writes')->name('ma.insentif.pdf');
Route::get('/ma/commission', [MaController::class, 'commissionForm'])->name('ma.commission');
Route::post('/ma/commission', [MaController::class, 'commissionStore'])->middleware('throttle:dashboard-writes')->name('ma.commission.store');
Route::get('/ma/commission/report', [MaController::class, 'commissionReport'])->name('ma.commission.report');
Route::patch('/ma/commission/{config}/toggle-status', [MaController::class, 'commissionToggleStatus'])->middleware('throttle:dashboard-writes')->name('ma.commission.toggle-status');
Route::delete('/ma/commission/{config}', [MaController::class, 'commissionDestroy'])->middleware('throttle:dashboard-writes')->name('ma.commission.destroy');
Route::post('/ma/mapping/{merchant}', [MaController::class, 'mapAgent'])->middleware('throttle:dashboard-writes')->name('ma.mapping.update');
Route::get('/ma/stores', fn (MaController $controller) => $controller->page('stores'))->name('ma.stores');
Route::post('/ma/stores/{merchant}/fee', [MaController::class, 'updateStoreFee'])->middleware('throttle:dashboard-writes')->name('ma.stores.fee.update');
Route::get('/ma/agents', fn (MaController $controller) => $controller->page('agents'))->name('ma.agents');
Route::post('/ma/agents/{agent}/fee', [MaController::class, 'updateAgentFee'])->middleware('throttle:dashboard-writes')->name('ma.agents.fee.update');
Route::get('/ma/create-store', fn (MaController $controller) => $controller->page('create-store'))->name('ma.create-store');
Route::post('/ma/create-store', [MaController::class, 'storeMerchant'])->middleware('throttle:dashboard-writes')->name('ma.create-store.store');
Route::post('/ma/onboarding-links', [MaController::class, 'storeOnboardingLink'])->middleware('throttle:dashboard-writes')->name('ma.onboarding-links.store');
Route::post('/ma/onboarding-links/{link}/expire', [MaController::class, 'expireOnboardingLink'])->middleware('throttle:dashboard-writes')->name('ma.onboarding-links.expire');
Route::post('/ma/onboarding-links/bulk', [MaController::class, 'bulkExpireOnboardingLinks'])->middleware('throttle:dashboard-writes')->name('ma.onboarding-links.bulk');
Route::get('/ma/bot-monitoring', fn (MaController $controller) => $controller->page('bot-monitoring'))->name('ma.bot-monitoring');
Route::get('/ma/bot-monitoring/export', [MaController::class, 'botMonitoringExport'])->name('ma.bot-monitoring.export');
Route::get('/ma/analytics', fn (MaController $controller) => $controller->page('analytics'))->name('ma.analytics');
Route::get('/ma/analytics/tab/{tab}', [MaController::class, 'analyticsTab'])->name('ma.analytics.tab');
Route::post('/ma/assistant/ask', [MaAssistantController::class, 'ask'])->middleware('throttle:ma-assistant')->name('ma.assistant.ask');
});

// magneto_ops is a narrow role (see Yesika's account) that only gets these
// Magnetopay onboarding routes - deliberately NOT part of the main
// 'role:ma,superadmin' group above, which also covers unrelated MA pages
// (reports, commissions, full agent list, etc.) this role must not reach.
Route::middleware(['auth', 'role:ma,superadmin,magneto_ops'])->group(function () {
Route::post('/ma/agents', [MaController::class, 'storeAgent'])->middleware('throttle:dashboard-writes')->name('ma.agents.store');
Route::get('/ma/create-store-magnetopay', [MaController::class, 'createStoreMagnetopayForm'])->name('ma.create-store-magnetopay');
Route::post('/ma/create-store-magnetopay', [MaController::class, 'storeMagnetopayMerchant'])->middleware('throttle:dashboard-writes')->name('ma.create-store-magnetopay.store');
Route::get('/ma/magnetopay-submissions', [MaController::class, 'magnetopaySubmissions'])->name('ma.magnetopay-submissions');
Route::post('/ma/magnetopay-submissions/{registration}', [MaController::class, 'updateMagnetopaySubmission'])->middleware('throttle:dashboard-writes')->name('ma.magnetopay-submissions.update');
Route::post('/ma/onboarding-links/magnetopay', [MaController::class, 'storeOnboardingLinkMagnetopay'])->middleware('throttle:dashboard-writes')->name('ma.onboarding-links.magnetopay.store');
});

Route::middleware(['auth', 'role:ma,agent,superadmin'])->group(function () {
Route::get('/ma/tickets', [MaTicketController::class, 'index'])->name('ma.tickets.index');
Route::post('/ma/tickets', [MaTicketController::class, 'store'])->middleware('throttle:dashboard-writes')->name('ma.tickets.store');
});

Route::middleware(['auth', 'role:superadmin'])->group(function () {
Route::get('/superadmin', [SuperadminController::class, 'page'])->name('superadmin.overview');
Route::get('/superadmin/integrations/status', [SuperadminController::class, 'integrationStatus'])->name('superadmin.integrations.status');
Route::get('/superadmin/{page}', [SuperadminController::class, 'page'])->name('superadmin.page');
Route::post('/superadmin/ma', [SuperadminController::class, 'storeMa'])->middleware('throttle:dashboard-writes')->name('superadmin.ma.store');
Route::post('/superadmin/ma/{user}', [SuperadminController::class, 'updateMa'])->middleware('throttle:dashboard-writes')->name('superadmin.ma.update');
Route::post('/superadmin/merchant-fee/{merchant}', [SuperadminController::class, 'updateMerchantFee'])->middleware('throttle:dashboard-writes')->name('superadmin.merchant-fee.update');
Route::post('/superadmin/ma-fee/{user}', [SuperadminController::class, 'updateMaFee'])->middleware('throttle:dashboard-writes')->name('superadmin.ma-fee.update');
Route::post('/superadmin/merchant-group', [SuperadminController::class, 'storeAgent'])->middleware('throttle:dashboard-writes')->name('superadmin.agent.store');
Route::post('/superadmin/timer-ticket', [SuperadminController::class, 'updateTimer'])->middleware('throttle:dashboard-writes')->name('superadmin.timer-ticket.update');
Route::post('/superadmin/accounts/{user}/reset', [SuperadminController::class, 'resetAccount'])->middleware('throttle:dashboard-writes')->name('superadmin.accounts.reset');
Route::post('/superadmin/fee-menus', [SuperadminController::class, 'storeFeeMenu'])->middleware('throttle:dashboard-writes')->name('superadmin.fee-menus.store');
Route::post('/superadmin/fee-menus/settings', [SuperadminController::class, 'updateFeeMenuSettings'])->middleware('throttle:dashboard-writes')->name('superadmin.fee-menus.settings');
Route::delete('/superadmin/fee-menus/{feeMenu}', [SuperadminController::class, 'destroyFeeMenu'])->middleware('throttle:dashboard-writes')->name('superadmin.fee-menus.destroy');
Route::post('/superadmin/system-configuration', [SuperadminController::class, 'updateSystemConfiguration'])->middleware('throttle:dashboard-writes')->name('superadmin.system-configuration.update');
Route::post('/superadmin/system-configuration/reconcile', [SuperadminController::class, 'runReconciliation'])->middleware('throttle:dashboard-writes')->name('superadmin.system-configuration.reconcile');
});

Route::middleware(['auth', 'role:cs_monitor,superadmin'])->group(function () {
Route::get('/cs-monitor', [TelegramCsMonitorController::class, 'index'])->name('cs-monitor.index');
Route::post('/cs-monitor/{telegramUser}/generate-pin', [TelegramCsMonitorController::class, 'generatePin'])->middleware('throttle:dashboard-writes')->name('cs-monitor.generate-pin');
Route::get('/cs-monitor/users', [TelegramCsMonitorController::class, 'users'])->name('cs-monitor.users');
Route::post('/cs-monitor/users/{telegramUser}/cs-flag', [TelegramCsMonitorController::class, 'updateCsFlag'])->middleware('throttle:dashboard-writes')->name('cs-monitor.users.cs-flag');
});

Route::middleware(['auth', 'role:cs_pusat,ma,superadmin'])->group(function () {
Route::get('/cs-pusat', [CenterSupportController::class, 'index'])->name('center-support.tickets');
Route::get('/cs-pusat/bot-monitoring', [CenterSupportController::class, 'botMonitoring'])->name('center-support.bot-monitoring');
Route::get('/cs-pusat/bot-monitoring/export', [CenterSupportController::class, 'botMonitoringExport'])->name('center-support.bot-monitoring.export');
Route::get('/cs-pusat/tickets/{ticket}/attachments/{index}', [CenterSupportController::class, 'attachment'])->whereNumber('index')->name('center-support.tickets.attachment');
Route::post('/cs-pusat/tickets/{ticket}', [CenterSupportController::class, 'update'])->middleware('throttle:dashboard-writes')->name('center-support.tickets.update');
});

Route::middleware(['auth', 'role:cs_pusat,superadmin'])->group(function () {
Route::post('/cs-pusat/bot-monitoring/{ticketId}/reject', [CenterSupportController::class, 'botMonitoringReject'])->middleware('throttle:dashboard-writes')->name('center-support.bot-monitoring.reject');
});

Route::middleware(['auth', 'role:cs_pusat'])->group(function () {
Route::get('/cs-pusat/broadcast-telegram', [TelegramBroadcastController::class, 'index'])->name('center-support.broadcast');
Route::post('/cs-pusat/broadcast-telegram', [TelegramBroadcastController::class, 'send'])->middleware('throttle:dashboard-writes')->name('center-support.broadcast.send');
Route::delete('/cs-pusat/broadcast-telegram/bulk', [TelegramBroadcastController::class, 'destroyMany'])->middleware('throttle:dashboard-writes')->name('center-support.broadcast.destroyMany');
Route::delete('/cs-pusat/broadcast-telegram/{broadcastMessage}', [TelegramBroadcastController::class, 'destroy'])->middleware('throttle:dashboard-writes')->name('center-support.broadcast.destroy');
});

Route::middleware(['auth', 'role:superadmin'])->group(function () {
Route::get('/admin/users', fn () => redirect()->route('superadmin.page', 'accounts'))->name('admin.users');
Route::get('/admin/log-aktivitas', [MonitoringController::class, 'logs'])->name('admin.logs');
Route::get('/admin/monitoring', [MonitoringController::class, 'index'])->name('admin.monitoring');
});

Route::get('/portal/{merchant}/admin', fn ($merchant) => redirect()->route('merchant.admin.users', $merchant));
Route::middleware(['auth', 'role:admin,readonly_admin,boss,ma,superadmin', 'merchant.scope'])->group(function () {
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
Route::get('/agent/insentif', [DashboardController::class, 'agentInsentifForm'])->name('agent.insentif');
Route::post('/agent/insentif/pdf', [DashboardController::class, 'agentInsentifPdf'])->middleware('throttle:dashboard-writes')->name('agent.insentif.pdf');
Route::redirect('/agen', '/agent');
Route::redirect('/agent/create-store', '/agent')->name('agent.create-store');
Route::post('/agent/onboarding-links', [DashboardController::class, 'storeAgentOnboardingLink'])->middleware('throttle:dashboard-writes')->name('agent.onboarding-links.store');
Route::post('/agent/onboarding-links/{link}/expire', [DashboardController::class, 'expireAgentOnboardingLink'])->middleware('throttle:dashboard-writes')->name('agent.onboarding-links.expire');
Route::post('/agent/onboarding-links/bulk', [DashboardController::class, 'bulkAgentOnboardingLinks'])->middleware('throttle:dashboard-writes')->name('agent.onboarding-links.bulk');
Route::get('/agent/requests', fn (DashboardController $controller) => $controller->agentSimple('status-request', app(\App\Services\Navigation\MenuBuilder::class)))->name('agent.requests');
Route::delete('/agent/requests/{registration}', [DashboardController::class, 'deleteAgentRegistration'])->middleware('throttle:dashboard-writes')->name('agent.requests.delete');
Route::get('/agent/requests/{registration}/pdf', [DashboardController::class, 'downloadAgentRegistrationPdf'])->name('agent.requests.pdf');
Route::post('/agent/requests/bulk', [DashboardController::class, 'bulkAgentRegistrations'])->middleware('throttle:dashboard-writes')->name('agent.requests.bulk');
Route::get('/agent/export', [DashboardController::class, 'exportAgentReport'])->name('agent.export');
Route::redirect('/agent/new-store', '/agent/create-store')->name('agent.new-store');
});

Route::match(['get', 'post'], '/form', fn () => abort(404));
Route::get('/onboarding/{link:token}', [MerchantRegistrationController::class, 'tokenForm'])->name('merchant-registration.token-form');
Route::post('/onboarding/{link:token}', [MerchantRegistrationController::class, 'tokenStore'])->middleware('throttle:dashboard-writes')->name('merchant-registration.token-store');
Route::get('/onboarding/magnetopay/{link:token}', [MerchantRegistrationController::class, 'tokenFormMagnetopay'])->name('merchant-registration.magnetopay.token-form');
Route::post('/onboarding/magnetopay/{link:token}', [MerchantRegistrationController::class, 'tokenStoreMagnetopay'])->middleware('throttle:dashboard-writes')->name('merchant-registration.magnetopay.token-store');
Route::middleware(['auth', 'role:agent,ma,superadmin'])->group(function () {
    Route::post('/api/merchant-registrations/{registration}/submit', [MerchantRegistrationWorkflowController::class, 'submit'])->name('api.merchant-registration.submit');
});
Route::middleware(['auth', 'role:ma,superadmin'])->group(function () {
    Route::post('/api/merchant-registrations/{registration}/approve', [MerchantRegistrationWorkflowController::class, 'approve'])->name('api.merchant-registration.approve');
    Route::post('/api/merchant-registrations/{registration}/reject', [MerchantRegistrationWorkflowController::class, 'reject'])->name('api.merchant-registration.reject');
    Route::get('/api/merchant-registrations/{registration}/pdf', [MerchantRegistrationWorkflowController::class, 'downloadPdf'])->name('ma.merchant-registration.pdf');
    Route::get('/api/merchant-registrations/{registration}/export', [MerchantRegistrationWorkflowController::class, 'exportCsv'])->name('ma.merchant-registration.export');
    Route::post('/api/merchants/{merchant}/provision/retry', [MerchantProvisioningController::class, 'retry'])->name('api.merchant.provision.retry');
    Route::post('/api/merchants/{merchant}/sync/retry', [GatewaySyncRetryController::class, 'store'])->name('api.merchant.sync.retry');
});

Route::middleware(['auth', 'role:cs_ma,cs_agent,ma,agent'])->group(function () {
Route::get('/portal/cs-scope', [CsScopeController::class, 'index'])->name('cs-scope.index');
});

Route::get('/portal/{merchant}/cs', fn ($merchant) => redirect()->route('merchant.cs.tickets', $merchant));
Route::middleware(['auth', 'role:cs,readonly_cs,cs_ma,cs_agent,ma,agent,admin,readonly_admin,boss,superadmin', 'merchant.scope'])->group(function () {
Route::get('/portal/{merchant}/cs/tickets', fn (\App\Models\Merchant $merchant, DashboardController $controller) => $controller->merchantCs($merchant, 'tickets', app(\App\Services\Navigation\MenuBuilder::class), app(\App\Services\MetricsService::class)))->name('merchant.cs.tickets');
Route::get('/portal/{merchant}/cs/topup', fn (\App\Models\Merchant $merchant, DashboardController $controller) => $controller->merchantCs($merchant, 'topup', app(\App\Services\Navigation\MenuBuilder::class), app(\App\Services\MetricsService::class)))->name('merchant.cs.topup');
Route::get('/portal/{merchant}/cs/checklist', fn (\App\Models\Merchant $merchant, DashboardController $controller) => $controller->merchantCs($merchant, 'checklist', app(\App\Services\Navigation\MenuBuilder::class), app(\App\Services\MetricsService::class)))->name('merchant.cs.checklist');
Route::post('/portal/{merchant}/cs/tickets/{ticket}/submit', [SupportTicketController::class, 'submit'])->middleware('throttle:dashboard-writes')->name('merchant.cs.ticket.submit');
Route::post('/portal/{merchant}/cs/topup/{topupRequest}/ticket', [SupportTicketController::class, 'createFromTopup'])->middleware('throttle:dashboard-writes')->name('merchant.cs.topup.ticket');
Route::get('/portal/{merchant}/cs/history', fn (\App\Models\Merchant $merchant, DashboardController $controller) => $controller->merchantCs($merchant, 'history', app(\App\Services\Navigation\MenuBuilder::class), app(\App\Services\MetricsService::class)))->name('merchant.cs.history');
});

Route::middleware(['auth', 'role:admin,readonly_admin,boss,cs,readonly_cs,ma,agent,superadmin', 'merchant.scope'])->group(function () {
Route::get('/portal/{merchant}/tickets', [MerchantTicketController::class, 'index'])->name('merchant.tickets.index');
Route::post('/portal/{merchant}/tickets', [MerchantTicketController::class, 'store'])->middleware('throttle:dashboard-writes')->name('merchant.tickets.store');
Route::get('/portal/{merchant}/tickets/{ticket}', [MerchantTicketController::class, 'show'])->name('merchant.tickets.show');
Route::post('/portal/{merchant}/tickets/{ticket}/reply', [MerchantTicketController::class, 'reply'])->middleware('throttle:dashboard-writes')->name('merchant.tickets.reply');
Route::patch('/portal/{merchant}/tickets/{ticket}/messages/{message}', [MerchantTicketController::class, 'updateMessage'])->middleware('throttle:dashboard-writes')->name('merchant.tickets.messages.update');
Route::get('/portal/{merchant}/tickets/{ticket}/attachment/{index}', [MerchantTicketController::class, 'attachment'])->whereNumber('index')->name('merchant.tickets.attachment');
});

Route::middleware(['auth', 'role:cs_pusat,dept_cs,dept_dev,dept_tech_supp,dept_finance,dept_settlement,dept_fraud,dept_dispute,superadmin'])->group(function () {
Route::get('/dept-support/tickets', [DepartmentTicketController::class, 'index'])->name('dept-tickets.index');
Route::get('/dept-support/tickets/{ticket}', [DepartmentTicketController::class, 'show'])->name('dept-tickets.show');
Route::get('/dept-support/tickets/{ticket}/attachment/{index}', [DepartmentTicketController::class, 'attachment'])->whereNumber('index')->name('dept-tickets.attachment');
Route::post('/dept-support/tickets/{ticket}/claim', [DepartmentTicketController::class, 'claim'])->middleware('throttle:dashboard-writes')->name('dept-tickets.claim');
Route::post('/dept-support/tickets/{ticket}/reply', [DepartmentTicketController::class, 'reply'])->middleware('throttle:dashboard-writes')->name('dept-tickets.reply');
Route::post('/dept-support/tickets/{ticket}/status', [DepartmentTicketController::class, 'updateStatus'])->middleware('throttle:dashboard-writes')->name('dept-tickets.status');
Route::post('/dept-support/tickets/{ticket}/push', [DepartmentTicketController::class, 'pushDepartment'])->middleware('throttle:dashboard-writes')->name('dept-tickets.push');
});

Route::middleware(['auth', 'role:ma,superadmin'])->group(function () {
Route::get('/embedded/transactpro/toko/cs/index.html', fn (DashboardController $controller) => $controller->merchantCs(\App\Models\Merchant::query()->where('merchant_type', 'cm')->firstOrFail(), 'tickets', app(\App\Services\Navigation\MenuBuilder::class), app(\App\Services\MetricsService::class)))->middleware('auth')->name('legacy.cm.cs');
Route::get('/embedded/transactpro/toko/finance/index.html', fn (DashboardController $controller) => $controller->merchantFinance(\App\Models\Merchant::query()->where('merchant_type', 'cm')->firstOrFail(), 'overview', app(\App\Services\Navigation\MenuBuilder::class)))->middleware('auth')->name('legacy.cm.finance');
Route::get('/embedded/merchant_script/toko/cs/index.html', fn (DashboardController $controller) => $controller->merchantCs(\App\Models\Merchant::query()->where('merchant_type', 'script')->firstOrFail(), 'tickets', app(\App\Services\Navigation\MenuBuilder::class), app(\App\Services\MetricsService::class)))->middleware('auth')->name('legacy.script.cs');
Route::get('/embedded/merchant_script/toko/finance/index.html', fn (DashboardController $controller) => $controller->merchantFinance(\App\Models\Merchant::query()->where('merchant_type', 'script')->firstOrFail(), 'overview', app(\App\Services\Navigation\MenuBuilder::class)))->middleware('auth')->name('legacy.script.finance');
});

Route::middleware(['auth', 'role:finance,ma,admin,readonly_admin,boss,superadmin', 'merchant.scope'])->group(function () {
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
