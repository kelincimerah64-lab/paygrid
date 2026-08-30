@extends((request()->boolean('partial') || request()->header('X-PayGrid-Partial') === '1') ? 'layouts.partial' : 'layouts.paygrid')

@php
    $money = fn ($value) => 'Rp '.number_format((int) ($value ?? 0), 0, ',', '.');
    $pct = fn ($value) => number_format((float) $value, 2, ',', '.').'%';
    $pctInput = fn ($value) => number_format((float) $value, 2, '.', '');
    $badge = fn ($status) => App\Support\PayGridLabels::badge($status);
    $dateInput = fn ($value) => $value ? \Carbon\CarbonImmutable::parse($value)->format('Y-m-d') : '';
@endphp

@section('content')
<section class="qris-hero">
    <div>
        <div class="eyebrow">MA</div>
        <h1>{{ $title }}</h1>
        <div class="sub">Dashboard MA berbasis DB PayGrid lokal: transaksi, merchant, agen, approval, fee, dan mapping.</div>
    </div>
    @if($active === 'report')<a class="btn primary" href="{{ route('ma.report.export', request()->query()) }}">Export Excel</a>@endif
</section>

@if(session('status'))<section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>@endif
@if($errors->any())<section class="card pad section"><span class="badge danger">{{ $errors->first() }}</span></section>@endif
@if(! in_array($active, ['overview', 'report', 'fee', 'bot-monitoring'], true))
<form class="card filters" method="get">
    @if($active !== 'approval')<input class="search" name="q" value="{{ $filters['q'] }}" placeholder="Cari toko, agen, status, payment, RRN...">@endif
    <div class="actions">
        @if(in_array($active, ['report', 'fee', 'stores'], true))
            <select name="agent_id"><option value="all" @selected($filters['agent_id'] === 'all')>Semua agen</option>@foreach($allAgents as $agent)<option value="{{ $agent->id }}" @selected((string) $filters['agent_id'] === (string) $agent->id)>{{ $agent->name }}</option>@endforeach</select>
            <select name="type"><option value="all" @selected($filters['type'] === 'all')>Semua tipe</option><option value="cm" @selected($filters['type'] === 'cm')>CM</option><option value="script" @selected($filters['type'] === 'script')>Script</option></select>
        @endif
        @if($active === 'approval')
            <select name="status"><option value="all" @selected($filters['status'] === 'all')>Semua status</option>@foreach(['pending', 'pending_agent', 'pending_ma', 'approved', 'rejected'] as $status)<option value="{{ $status }}" @selected($filters['status'] === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>@endforeach</select>
        @elseif(in_array($active, ['report', 'agents'], true))
            <select name="status"><option value="all" @selected($filters['status'] === 'all')>Semua status</option>@foreach(['success', 'pending', 'expired', 'pending_agent', 'pending_ma', 'approved', 'rejected', 'Active', 'Review', 'Suspended'] as $status)<option value="{{ $status }}" @selected($filters['status'] === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>@endforeach</select>
        @endif
        @if($active === 'report')<input type="date" name="from" value="{{ $filters['from'] }}"><input type="date" name="to" value="{{ $filters['to'] }}">@endif
        <button class="btn primary">Filter</button><a class="btn" href="{{ route('ma.'.($active === 'overview' ? 'overview' : ($active === 'approval' ? 'approvals' : $active))) }}">Reset</a>
    </div>
</form>
@endif

@if($active === 'overview')
    @php
        $overviewCards = [
            'volume_success' => ['label' => 'Volume Sukses', 'value' => $money($summary['volume_success']), 'class' => 'success', 'note' => 'Klik untuk list trx sukses'],
            'pending_transaction_amount' => ['label' => 'Pending Transaksi', 'value' => $money($summary['pending_transaction_amount'] ?? $summary['pending_balance'] ?? 0), 'class' => 'pending', 'note' => 'Nominal trx pending dari topup_requests'],
            'total_settlement' => ['label' => 'Settlement', 'value' => $money($summary['total_settlement']), 'class' => '', 'note' => 'Net dari trx sukses'],
            'hg_settlement' => ['label' => 'Settlement Real HG', 'value' => $money($summary['hg_settlement']), 'class' => 'primary', 'note' => 'Status final dari endpoint HG'],
            'trx_total' => ['label' => 'Transaksi Sukses', 'value' => number_format($summary['trx_total'], 0, ',', '.'), 'class' => 'success', 'note' => 'Hanya status sukses'],
            'trx_pending' => ['label' => 'Pending Transaksi', 'value' => number_format($summary['trx_pending'], 0, ',', '.'), 'class' => 'pending', 'note' => 'Klik untuk lihat list'],
            'trx_expired' => ['label' => 'Expired Transaksi', 'value' => number_format($summary['trx_expired'], 0, ',', '.'), 'class' => 'expired', 'note' => 'Klik untuk lihat list'],
            'issue_total' => ['label' => 'Total Issue', 'value' => number_format($summary['issue_total'], 0, ',', '.'), 'class' => '', 'note' => $summary['issue_solved'].' solved'],
            'issue_solved' => ['label' => 'Issue Solved', 'value' => number_format($summary['issue_solved'], 0, ',', '.'), 'class' => 'success', 'note' => 'Ticket status done'],
            'agent_total' => ['label' => 'Total Agen', 'value' => number_format($summary['agent_total'], 0, ',', '.'), 'class' => '', 'note' => 'Klik untuk list agen'],
            'merchant_total' => ['label' => 'Total Toko', 'value' => number_format($summary['merchant_total'], 0, ',', '.'), 'class' => '', 'note' => 'Klik untuk list toko'],
            'unassigned' => ['label' => 'Belum Assign Agen', 'value' => number_format($summary['unassigned'], 0, ',', '.'), 'class' => 'pending', 'note' => 'Toko tanpa agen'],
            'fee_ma' => ['label' => 'Fee MA', 'value' => $money($summary['fee_ma']), 'class' => 'primary', 'note' => 'Dari trx sukses'],
            'fee_agent' => ['label' => 'Fee Agen', 'value' => $money($summary['fee_agent']), 'class' => '', 'note' => 'Dari trx sukses'],
        ];
    @endphp
    <div data-live-root data-live-interval="15000">
    <section class="grid qris-metrics history-metrics section" data-live-region="ma-overview-cards">
        @foreach($overviewCards as $key => $card)<button class="card pad qris-metric ma-metric-card {{ $card['class'] }}" type="button" data-ma-detail="{{ $key }}"><span>{{ $card['label'] }}</span><strong>{{ $card['value'] }}</strong><small>{{ $card['note'] }}</small></button>@endforeach
    </section>
    <section class="card qris-panel section" data-live-region="ma-ranking"><div class="qris-toolbar"><h2>Analytics Top 10 Terendah</h2><span class="muted">Periode {{ $periodLabel }} | hanya trx sukses</span><div class="ma-tabs"><button class="btn compact-btn active" type="button" data-ma-tab="toko">Toko</button><button class="btn compact-btn" type="button" data-ma-tab="agen">Agen</button></div></div><div data-ma-panel="toko"><table class="table qris-table"><thead><tr><th>Toko</th><th>TRX Sukses</th><th>Volume Sukses</th></tr></thead><tbody>@forelse($storeRanking as $row)<tr><td>{{ $row->merchant?->name ?: '-' }}</td><td>{{ $row->trx }}</td><td>{{ $money($row->volume) }}</td></tr>@empty<tr><td colspan="3" class="empty"><strong>Belum ada data toko.</strong>Filter periode ini belum memiliki transaksi sukses.</td></tr>@endforelse</tbody></table></div><div data-ma-panel="agen" hidden><table class="table qris-table"><thead><tr><th>Agen</th><th>TRX Sukses</th><th>Volume Sukses</th></tr></thead><tbody>@forelse($agentRanking as $row)<tr><td>{{ $row->agent?->name ?: '-' }}</td><td>{{ $row->trx }}</td><td>{{ $money($row->volume) }}</td></tr>@empty<tr><td colspan="3" class="empty"><strong>Belum ada data agen.</strong>Filter periode ini belum memiliki transaksi sukses.</td></tr>@endforelse</tbody></table></div></section>
    <section class="card qris-panel ma-store-panel section" data-live-region="ma-store-summary"><div class="qris-toolbar"><h2>List Toko</h2><span class="muted">Top 10 trx sukses terendah | {{ $periodLabel }}</span></div><div class="table-wrap sticky-head"><table class="table qris-table ma-store-summary-table"><thead><tr><th>Nama Toko</th><th>Agen</th><th>Transaksi Sukses</th><th>Volume Sukses</th><th>Saldo Pending HG</th><th>Settlement</th></tr></thead><tbody>@forelse($storeSummaries as $store)<tr><td><strong>{{ $store['name'] }}</strong></td><td>{{ $store['agent'] }}</td><td>{{ number_format($store['trx_total'], 0, ',', '.') }}</td><td>{{ $money($store['volume_success']) }}</td><td>{{ $money($store['pending_balance']) }}</td><td>{{ $money($store['settlement']) }}</td></tr>@empty<tr><td colspan="6" class="empty"><strong>Belum ada toko.</strong>Belum ada toko sesuai filter ini.</td></tr>@endforelse</tbody></table></div></section>
    </div>
    <script type="application/json" id="ma-overview-details">@json($overviewDetails)</script>
@endif

@if($active === 'report')
    <section class="card pad section ma-period-card"><form method="get" class="ma-period-form"><input type="hidden" name="agent_id" value="{{ $filters['agent_id'] }}"><input type="hidden" name="store_id" value="{{ $filters['store_id'] }}"><input type="hidden" name="agents_view" value="{{ $filters['agents_view'] }}"><label>Periode<select name="period" data-ma-period-select><option value="this_month" @selected($filters['period'] === 'this_month')>Bulan Ini</option><option value="last_month" @selected($filters['period'] === 'last_month')>Bulan Lalu</option><option value="last_30_days" @selected($filters['period'] === 'last_30_days')>30 Hari</option><option value="custom" @selected($filters['period'] === 'custom')>Custom</option></select></label><label>Dari<input type="date" name="from" value="{{ $dateInput($dataFilters['from'] ?? $filters['from']) }}" data-ma-period-custom></label><label>Sampai<input type="date" name="to" value="{{ $dateInput($dataFilters['to'] ?? $filters['to']) }}" data-ma-period-custom></label><button class="btn primary compact-btn">Terapkan</button><span class="badge ok">{{ $periodLabel }}</span></form></section>
    <section class="card qris-panel section"><div class="qris-toolbar"><div><h2>Report per Agen</h2><p class="muted" style="margin:4px 0 0">Default menampilkan 5 agen teratas. Klik baris agen untuk melihat toko, lalu klik toko untuk membuka transaksi toko tersebut.</p></div><a class="btn compact-btn" href="{{ route('ma.report', $filters['agents_view'] === 'all' ? request()->except(['page', 'agents_view']) : array_merge(request()->except('page'), ['agents_view' => 'all'])) }}">{{ $filters['agents_view'] === 'all' ? 'Top 5 Agen' : 'Semua Agen' }}</a></div><div class="table-wrap"><table class="table qris-table ma-agent-report-table"><thead><tr><th>Nama Agent</th><th>Toko</th><th>Total IDR Sukses</th><th>Total Pending</th><th>Settled</th></tr></thead><tbody>@forelse($reportAgents as $agent)<tr class="ma-click-row {{ (string) $filters['agent_id'] === (string) $agent['id'] ? 'active' : '' }}" onclick="window.location='{{ route('ma.report', array_merge(request()->except(['page', 'store_id']), ['agent_id' => $agent['id']])) }}'"><td><strong>{{ $agent['name'] }}</strong></td><td>{{ number_format($agent['stores'], 0, ',', '.') }}</td><td>{{ number_format($agent['volume'], 0, ',', '.') }}</td><td>{{ number_format($agent['pending'], 0, ',', '.') }}</td><td>{{ number_format($agent['settled'], 0, ',', '.') }}</td></tr>@empty<tr><td colspan="5" class="empty">Belum ada agen.</td></tr>@endforelse</tbody></table></div></section>
    @if($selectedAgent)
        <section class="card qris-panel section"><div class="qris-toolbar"><div><h2>Toko {{ $selectedAgent->name }}</h2><p class="muted" style="margin:4px 0 0">Pilih toko untuk menampilkan transaksi toko di tabel report.</p></div><a class="btn compact-btn" href="{{ route('ma.report', request()->except(['page', 'store_id'])) }}">Semua Toko</a></div><div class="ma-report-shops">@forelse($selectedAgentStores as $store)<a class="report-pill {{ (string) $filters['store_id'] === (string) $store->id ? 'active' : '' }}" href="{{ route('ma.report', array_merge(request()->except('page'), ['agent_id' => $selectedAgent->id, 'store_id' => $store->id])) }}"><b>{{ $store->name }}</b><span>{{ number_format((int) ($store->metric_trx_total ?? 0), 0, ',', '.') }} trx | {{ $money((int) ($store->metric_volume_success ?? 0)) }}</span></a>@empty<p class="muted">Agen ini belum punya toko.</p>@endforelse</div></section>
    @endif
    @if($selectedStore)
        <div data-live-root data-live-interval="15000"><section class="grid qris-metrics section" data-live-region="ma-report-cards"><div class="card pad qris-metric success"><span>TRX Sukses</span><strong>{{ number_format($selectedStoreStats['trx_total'] ?? 0, 0, ',', '.') }}</strong><small>{{ $selectedStore->name }}</small></div><div class="card pad qris-metric success"><span>Sukses</span><strong>{{ number_format($selectedStoreStats['trx_success'] ?? 0, 0, ',', '.') }}</strong><small>{{ $periodLabel }}</small></div><div class="card pad qris-metric pending"><span>Pending Transaksi</span><strong>{{ number_format($selectedStoreStats['trx_pending'] ?? 0, 0, ',', '.') }}</strong><small>Total sesuai periode</small></div><div class="card pad qris-metric primary"><span>Settlement</span><strong>{{ $money($selectedStoreStats['settlement'] ?? 0) }}</strong><small>Net dari trx sukses</small></div></section>
        <section class="card qris-panel section" data-live-region="ma-report-table"><div class="qris-toolbar"><h2>Transaksi {{ $selectedStore->name }}</h2><span class="muted">25 transaksi per halaman | {{ $periodLabel }}</span></div><div class="table-wrap sticky-head"><table class="table qris-table ma-report-table"><thead><tr><th>Masuk</th><th>Sukses</th><th>Durasi</th><th>Toko</th><th>Agen</th><th>Status</th><th>Amount</th><th>Reference</th><th>RRN</th><th>Payment ID</th><th>Net</th><th>Settlement</th><th>Sumber TRX</th></tr></thead><tbody>@forelse($transactions as $trx)<tr data-agent-id="{{ $trx->merchant?->agent?->id }}" data-store-id="{{ $trx->merchant?->id }}"><td class="time-cell">{{ $trx->submitted_at?->format('d/m/y') }}<span>{{ $trx->submitted_at?->format('H.i') }} WIB</span></td><td class="time-cell">{{ $trx->succeeded_at?->format('d/m/y') ?? '-' }}<span>{{ $trx->succeeded_at?->format('H.i') ? $trx->succeeded_at?->format('H.i').' WIB' : '-' }}</span></td><td>{{ $trx->successDurationLabel() }}</td><td>{{ $trx->merchant?->name ?: '-' }}</td><td>{{ $trx->merchant?->agent?->name ?: '-' }}</td><td><span class="badge {{ $badge($trx->status) }}">{{ App\Support\PayGridLabels::status($trx->status) }}</span></td><td>{{ $money($trx->amount) }}</td><td>{{ $trx->customer_reference ?: $trx->gateway_ref_id ?: '-' }}</td><td>{{ $trx->rrn ?: '-' }}</td><td>{{ $trx->payment_id ?: '-' }}</td><td>{{ $money($trx->net_amount) }}</td><td>{{ $trx->status === 'success' ? 'Settleable' : 'Pending Transaksi' }}</td><td>{{ $trx->data_source }}</td></tr>@empty<tr><td colspan="13" class="empty"><strong>Belum ada transaksi.</strong>Pilih periode/status lain atau tunggu sync Hilogate berikutnya.</td></tr>@endforelse</tbody></table></div><div class="qris-pagination pad"><div class="pager-summary">Showing {{ $transactions->firstItem() ?? 0 }} to {{ $transactions->lastItem() ?? 0 }}</div><div class="pager-links">@if($transactions->onFirstPage())<span class="pager disabled">Prev</span>@else<a class="pager" href="{{ $transactions->previousPageUrl() }}">Prev</a>@endif @if($transactions->hasMorePages())<a class="pager" href="{{ $transactions->nextPageUrl() }}">Next</a>@else<span class="pager disabled">Next</span>@endif</div></div></section></div>
    @else
        <section class="card pad section"><h2>Pilih Toko</h2><p class="muted">Transaksi tidak dimuat dulu supaya report tetap ringan. Pilih agen, lalu pilih toko untuk melihat transaksi paginated dari topup_requests.</p></section>
    @endif
@endif

@if($active === 'fee')
    <section class="grid qris-metrics section"><div class="card pad qris-metric primary"><span>Total Fee MA</span><strong>{{ $money($summary['fee_ma']) }}</strong></div><div class="card pad qris-metric pending"><span>Total Fee Agen</span><strong>{{ $money($summary['fee_agent']) }}</strong></div><div class="card pad qris-metric"><span>Total Fee Merchant</span><strong>{{ $money($summary['fee_merchant']) }}</strong></div></section>
    <section class="card qris-panel section"><div class="qris-toolbar"><h2>Pembagian Fee Per Toko</h2></div><table class="table qris-table"><thead><tr><th>Toko</th><th>Menu Fee</th><th>Merchant MDR</th><th>Agent</th><th>MA Fee</th></tr></thead><tbody>@foreach($merchants as $m)<tr><td><strong>{{ $m->name }}</strong></td><td>{{ $m->fee_menu ? ($feeMenus->optionsFor('merchant')[$m->fee_menu]['label'] ?? $m->fee_menu) : '-' }}</td><td>{{ $pct($m->merchant_mdr_percent) }}</td><td>{{ $pct($m->agent_fee_percent) }}</td><td><strong>{{ $pct($m->ma_fee_percent) }}</strong></td></tr>@endforeach</tbody></table></section>
@endif

@if($active === 'approval')
    <section class="card qris-panel section"><div class="qris-toolbar"><div><h2>Request Approval</h2><p class="muted" style="margin:4px 0 0">Review toko, user access, dan fee structure. Request baru dari submit agen masuk ke sini, pending diprioritaskan paling atas.</p></div></div></section>
    @if($registrations->isEmpty())
        <section class="card pad section"><p class="muted">Belum ada request.</p></section>
    @else
    @foreach($registrations as $r)
        @php
            $payload = (array) ($r->payload ?? []);
            $merchant = $r->merchant;
            $initial = strtoupper(substr($r->store_name ?: 'T', 0, 1));
            $adminEmail = $payload['admin_email'] ?? $payload['email_admin'] ?? $payload['email_pic'] ?? '-';
            $adminName = $payload['admin_name'] ?? $payload['username'] ?? ($adminEmail !== '-' ? str($adminEmail)->before('@')->replace(['.', '_', '-'], ' ')->title()->toString() : '-');
            $adminPassword = $payload['admin_password'] ?? $payload['password'] ?? config('paygrid.demo_password');
            $merchantMdr = $merchant?->merchant_mdr_percent ?? ($payload['merchant_mdr_percent'] ?? 0);
            $payinFee = $merchant?->payin_fee_percent ?? ($payload['payin_fee_percent'] ?? $payload['engine_service_fee_percent'] ?? 0);
            $requestMenuOptions = $feeMenus->optionsFor('merchant');
            $requestFeeMenu = $merchant?->fee_menu ?? $payload['fee_menu'] ?? null;
            $requestRates = $merchant?->fee_menu_rates ?? ($payload['fee_menu_rates'] ?? []);
            $detailRows = [
                'HG Merchant ID' => $merchant?->merchant_id ?? ($payload['merchant_id'] ?? $payload['hg_merchant_id'] ?? '-'),
                'HG Merchant Key' => $payload['merchant_key'] ?? $payload['hg_merchant_key'] ?? '-',
                'HG Group ID' => $r->agent?->hg_group_id ?? $payload['merchant_group_id'] ?? $payload['hg_group_id'] ?? '-',
                'Transaction Gateway ID' => $payload['transaction_gateway_id'] ?? '-',
                'Withdrawal Gateway ID' => $payload['withdrawal_gateway_id'] ?? '-',
                'Settlement Type ID' => $payload['settlement_type_id'] ?? $r->settlement_method ?? '-',
                'Transaction Callback' => $payload['transaction_callback_url'] ?? url('/api/callbacks/hilogate/transaction'),
                'Withdrawal Callback' => $payload['withdrawal_callback_url'] ?? url('/api/callbacks/hilogate/withdrawal'),
                'API IP Whitelist' => $payload['api_ip_whitelist'] ?? '15.232.137.74',
                'Link Topup' => $payload['topup_url'] ?? $merchant?->topup_url ?? '-',
                'Whitelist' => ($payload['is_whitelist_enabled'] ?? false) ? 'Ya' : 'Tidak',
                'Pay In Fee' => $pct($payinFee),
                'Second TRX Fee' => $pct($payload['second_transaction_fee_percentage'] ?? 0),
                'Third TRX Fee' => $pct($payload['third_transaction_fee_percentage'] ?? 0),
                'Disbursement Fee' => $payload['withdrawal_fee'] ?? '-',
                'Disbursement Fee %' => $pct($payload['withdrawal_fee_percentage'] ?? 0),
            ];
        @endphp
        <section class="card pad section approval-review-card">
            <div class="approval-store-cell"><div class="initial">{{ $initial }}</div><div><div class="label">Toko</div><h2 class="truncate">{{ $r->store_name }}</h2><div class="muted">Agen: {{ $r->agent?->name ?: '-' }}</div><div class="muted truncate">{{ $payload['topup_url'] ?? $merchant?->topup_url ?? $r->engine_name ?? '-' }}</div></div></div>
            <div><div class="label">Merchant</div><div class="merchant-id-box">ID</div><h2 class="truncate">{{ $payload['merchant_group_name'] ?? $r->agent?->name ?? strtoupper($r->gateway) }}</h2><div class="muted truncate">{{ $merchant?->merchant_id ?? ($payload['merchant_id'] ?? 'Pending MA') }}</div></div>
            <div><div class="label">Data User</div><div class="user-access-card"><strong>ADMIN</strong><h2>{{ $adminName }}</h2><div class="muted truncate">{{ $adminEmail }}</div><b>PW: {{ $adminPassword }}</b></div></div>
            <div><div class="label">Structure Fee</div><div class="fee-row"><span>Agen Pemegang Toko</span><strong>{{ $r->agent?->name ?: '-' }}</strong></div><div class="fee-row"><span>Payment Gateway</span><strong>{{ ucfirst($r->gateway) }}</strong></div><div class="fee-pill wide"><span>Merchant MDR</span><strong>{{ $pct($merchantMdr) }}</strong></div><div class="mini-grid"><div class="fee-pill"><span>Menu Fee</span><strong>{{ $requestFeeMenu ? ($requestMenuOptions[$requestFeeMenu]['label'] ?? $requestFeeMenu) : '-' }}</strong></div><div class="fee-pill"><span>Pay In Fee</span><strong>{{ $pct($payinFee) }}</strong></div><div class="fee-pill"><span>Disbursement Fee</span><strong>{{ $payload['withdrawal_fee'] ?? '-' }}</strong></div></div><button class="btn compact-btn approval-detail-open" type="button" data-approval-detail="approval-detail-{{ $r->id }}">View Details</button></div>
            <div>
                <div class="label">Decision</div>
                <div style="margin:12px 0"><span class="badge {{ $badge($r->status) }}">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</span></div>
                @if(!in_array($r->status, ['approved', 'rejected'], true))
                    <form method="post" action="{{ route('api.merchant-registration.approve', $r) }}" class="approve-fee-form">
                        @csrf
                        @include('paygrid.partials.fee-menu-rates', ['role' => 'merchant', 'typeCategory' => null, 'feeMenus' => $feeMenus, 'currentRates' => $requestRates])
                        <input type="hidden" name="payin_fee_percent" value="{{ $payinFee }}">
                        <button class="btn primary compact-btn" style="width:100%; margin-bottom:8px">Approve</button>
                    </form>
                    <form method="post" action="{{ route('api.merchant-registration.reject', $r) }}">
                        @csrf
                        <button class="btn danger compact-btn" style="width:100%">Reject</button>
                    </form>
                @else
                    <div class="muted" style="text-align:center">-</div>
                @endif
            </div>
        </section>
        <div class="approval-modal" id="approval-detail-{{ $r->id }}" hidden>
            <div class="approval-modal-card">
                <div class="qris-toolbar"><div><h2>Detail Hilogate</h2><p class="muted" style="margin:4px 0 0">Data final request toko.</p></div><button class="btn compact-btn approval-detail-close" type="button">Tutup</button></div>
                <div class="approval-detail-grid">
                    @foreach($detailRows as $label => $value)
                        <div class="fee-pill"><span>{{ $label }}</span><strong class="truncate">{{ $value ?: '-' }}</strong></div>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach
    @endif
@endif

@if($active === 'mapping')
    <section class="card qris-panel section"><div class="qris-toolbar"><div><h2>Mapping Agen</h2><p class="muted" style="margin:4px 0 0">Ubah agen per toko. Perubahan disimpan ke DB lokal merchant dan fee agen/MA dihitung ulang.</p></div></div><table class="table qris-table ma-mapping-table"><thead><tr><th>Toko</th><th>Agen Sekarang</th><th>Pilih Agen</th><th>Simpan</th></tr></thead><tbody>@forelse($merchants as $m)<tr><td><strong>{{ $m->name }}</strong><br><span class="muted">Status: {{ ucfirst($m->approval_status) }}</span><br><span class="muted">{{ $m->merchant_id ?: $m->slug }}</span></td><td><div class="current-agent-box"><span>Agen Sekarang</span><strong>{{ $m->agent?->name ?: 'Belum assign' }}</strong></div></td><td><form id="map-{{ $m->id }}" method="post" action="{{ route('ma.mapping.update', $m) }}">@csrf<select name="agent_id" required>@foreach($allAgents as $a)<option value="{{ $a->id }}" @selected($m->agent_id === $a->id)>{{ $a->name }}</option>@endforeach</select></form></td><td><button form="map-{{ $m->id }}" class="btn primary compact-btn">Simpan Agen</button></td></tr>@empty<tr><td colspan="4" class="empty">Belum ada toko untuk mapping.</td></tr>@endforelse</tbody></table></section>
@endif

@if($active === 'stores')
    <section class="card qris-panel section ma-store-list-panel">
        <div class="qris-toolbar"><div><h2>List Toko</h2><p class="muted" style="margin:4px 0 0">Compact table. Klik Details untuk data lengkap, Edit Fee untuk ubah fee toko.</p></div><a class="btn primary compact-btn" href="{{ route('ma.create-store') }}">Create Toko</a></div>
        <div class="table-wrap"><table class="table qris-table ma-store-list-table"><thead><tr><th>Toko</th><th>Merchant</th><th>Agen</th><th>Tipe</th><th>Fee</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
        @forelse($merchants as $m)
            @php
                $detailRows = [
                    'Nama Toko' => $m->name,
                    'Slug' => $m->slug,
                    'Merchant ID' => $m->merchant_id ?: '-',
                    'Merchant Key' => $m->merchant_key ?: '-',
                    'Merchant Group' => $m->merchant_group_name ?: '-',
                    'Merchant Group ID' => $m->merchant_group_id ?: '-',
                    'Agen' => $m->agent?->name ?: '-',
                    'Gateway' => strtoupper($m->gateway),
                    'Tipe Toko' => strtoupper($m->merchant_type),
                    'Link Topup QRIS' => $m->topup_enabled ? ($m->topup_url ?: route('topup', ['merchant' => $m->slug])) : '-',
                    'Transaction Callback' => $m->transaction_callback_url ?: '-',
                    'Withdrawal Callback' => $m->withdrawal_callback_url ?: '-',
                    'PIC Email' => $m->pic_email ?: '-',
                    'PIC Telegram' => $m->pic_telegram ?: '-',
                    'Finance Email' => $m->finance_email ?: '-',
                    'CS Email' => $m->cs_email ?: '-',
                    'Settlement Method' => $m->settlement_method ?: '-',
                    'Minimum Topup' => $money($m->minimum_topup_amount),
                    'Approval Status' => $m->approval_status ?: '-',
                    'Provisioning Status' => $m->provisioning_status ?: '-',
                ];
            @endphp
            <tr><td><strong>{{ $m->name }}</strong><br><span class="muted">{{ $m->slug }}</span></td><td>{{ $m->merchant_id ?: '-' }}</td><td>{{ $m->agent?->name ?: '-' }}</td><td><span class="badge {{ $m->merchant_type === 'cm' ? 'ok' : 'warn' }}">{{ strtoupper($m->merchant_type) }}</span></td><td>MDR {{ $pct($m->merchant_mdr_percent) }}<br><span class="muted">MA {{ $pct($m->ma_fee_percent) }} / Agent {{ $pct($m->agent_fee_percent) }}</span></td><td><span class="badge {{ $badge($m->approval_status) }}">{{ $m->approval_status }}</span></td><td><button class="btn compact-btn approval-detail-open" type="button" data-approval-detail="store-detail-{{ $m->id }}">Details</button><button class="btn primary compact-btn approval-detail-open" type="button" data-approval-detail="store-fee-{{ $m->id }}">Edit Fee</button>
            <div class="approval-modal" id="store-detail-{{ $m->id }}" hidden><div class="approval-modal-card"><div class="qris-toolbar"><div><h2>Detail Toko</h2><p class="muted" style="margin:4px 0 0">{{ $m->name }}</p></div><button class="btn compact-btn approval-detail-close" type="button">Tutup</button></div><div class="approval-detail-grid">@foreach($detailRows as $label => $value)<div class="fee-pill"><span>{{ $label }}</span><strong class="truncate">{{ $value ?: '-' }}</strong></div>@endforeach</div></div></div>
            <div class="approval-modal" id="store-fee-{{ $m->id }}" hidden><div class="approval-modal-card"><div class="qris-toolbar"><div><h2>Edit Fee</h2><p class="muted" style="margin:4px 0 0">{{ $m->name }}</p></div><button class="btn compact-btn approval-detail-close" type="button">Tutup</button></div><form method="post" action="{{ route('ma.stores.fee.update', $m) }}" class="form-grid pad">@csrf
                @include('paygrid.partials.fee-menu-rates', ['role' => 'merchant', 'typeCategory' => null, 'feeMenus' => $feeMenus, 'currentRates' => $m->fee_menu_rates ?? []])
                <label>Pay In Fee %<input name="payin_fee_percent" value="{{ $pctInput($m->payin_fee_percent) }}" required></label><div><button class="btn primary">Simpan Fee</button></div></form></div></div></td></tr>
        @empty
            <tr><td colspan="7" class="empty">Belum ada toko.</td></tr>
        @endforelse
        </tbody></table></div>
    </section>
@endif

@if($active === 'agents')
    <section class="card qris-panel section"><div class="qris-toolbar"><h2>Create Agen</h2></div><table class="table qris-table super-create-table"><thead><tr><th>Nama</th><th>Email</th><th>Kontak</th><th>Status</th><th>Tipe</th><th>Fee per Menu</th><th>Password</th><th>Aksi</th></tr></thead><tbody><tr>
        <td><form id="agent-create" method="post" action="{{ route('ma.agents.store') }}">@csrf</form><input form="agent-create" name="name" required></td>
        <td><input form="agent-create" name="email" type="email" required></td>
        <td><input form="agent-create" name="contact"></td>
        <td><select form="agent-create" name="status"><option>Active</option><option>Review</option><option>Suspended</option></select></td>
        <td>
            <select form="agent-create" name="connection_type" id="agent-create-type" onchange="paygridToggleEngineType(this, 'agent-create-engine-type')">
                <option value="cm">CM</option>
                <option value="script">Engine</option>
            </select>
            <select form="agent-create" name="engine_type" id="agent-create-engine-type" style="display:none" disabled>
                <option value="sc">Script</option>
                <option value="api">API</option>
            </select>
        </td>
        <td>@include('paygrid.partials.fee-menu-rates', ['role' => 'agent', 'typeCategory' => null, 'feeMenus' => $feeMenus, 'formId' => 'agent-create'])</td>
        <td><input form="agent-create" name="password" value="{{ config('paygrid.demo_password') }}"></td>
        <td><button form="agent-create" class="btn primary compact-btn">Buat</button></td>
    </tr></tbody></table></section>
    <section class="card qris-panel section"><div class="qris-toolbar"><h2>Daftar Agen</h2></div><table class="table qris-table"><thead><tr><th>Agen</th><th>Email</th><th>Kontak</th><th>Fee per Menu</th><th>Status</th><th>Aksi</th></tr></thead><tbody>@foreach($agents as $a)
        <tr><td><strong>{{ $a->name }}</strong><br><span class="muted">{{ $a->code }}</span></td><td>{{ $a->email ?: '-' }}</td><td>{{ $a->contact ?: '-' }}</td><td>{{ $feeMenus->ratesSummary($a->fee_menu_rates ?? [], 'agent') }}</td><td><span class="badge {{ $a->is_active ? 'ok' : 'danger' }}">{{ $a->is_active ? 'Active' : 'Suspended' }}</span></td><td><button class="btn compact-btn approval-detail-open" type="button" data-approval-detail="agent-fee-{{ $a->id }}">Edit Fee</button>
        <div class="approval-modal" id="agent-fee-{{ $a->id }}" hidden><div class="approval-modal-card"><div class="qris-toolbar"><div><h2>Edit Fee Agen</h2><p class="muted" style="margin:4px 0 0">{{ $a->name }}</p></div><button class="btn compact-btn approval-detail-close" type="button">Tutup</button></div><form method="post" action="{{ route('ma.agents.fee.update', $a) }}" class="form-grid pad">@csrf
            @include('paygrid.partials.fee-menu-rates', ['role' => 'agent', 'typeCategory' => null, 'feeMenus' => $feeMenus, 'currentRates' => $a->fee_menu_rates ?? []])
            <div><button class="btn primary">Simpan Fee</button></div></form></div></div></td></tr>
    @endforeach</tbody></table></section>
@endif

@if($active === 'create-store')
    <section class="card qris-panel section"><div class="qris-toolbar"><h2>Create Toko</h2></div><form method="post" action="{{ route('ma.create-store.store') }}" class="form-grid pad">@csrf<label>Nama Toko<input name="name" required></label><label>Username<input name="username"></label><label>Engine Name<input name="engine_name"></label><label>Agen<select name="agent_id" required>@foreach($allAgents as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label><label>Email PIC<input name="pic_email" type="email"></label><label>Telegram PIC<input name="pic_telegram"></label><label>Email Admin<input name="admin_email" type="email" required></label><label>Nomor Kontak<input name="phone"></label><label>Environment<select name="environment"><option>Production</option><option>Sandbox</option></select></label><label>Payment Gateway<select name="gateway"><option value="hilogate">hilogate</option><option value="artageto">artageto</option></select></label><label>Merchant ID<input name="merchant_id" placeholder="Kosongkan jika belum real"></label><label>Merchant Key<input name="merchant_key" placeholder="Kosongkan jika belum real"></label><label>Callback URL<input name="transaction_callback_url" value="{{ url('/api/callbacks/hilogate/transaction') }}"></label><label>Withdrawal Callback<input name="withdrawal_callback_url" value="{{ url('/api/callbacks/hilogate/withdrawal') }}"></label><label>API IP Whitelist<input name="api_ip_whitelist" value="15.232.137.74"></label>
        <label>Tipe Toko<select name="merchant_type" id="create-store-type" onchange="paygridToggleEngineType(this, 'create-store-engine-type')"><option value="cm">CM</option><option value="script">Engine</option></select></label>
        <label>Engine Type<select name="engine_type" id="create-store-engine-type" disabled><option value="sc">Script</option><option value="api">API</option></select></label>
        @include('paygrid.partials.fee-menu-rates', ['role' => 'merchant', 'typeCategory' => null, 'feeMenus' => $feeMenus])
        <label>Catatan<input name="note"></label><button class="btn primary">Buat Toko</button></form></section>
@endif

@if($active === 'bot-monitoring')
    @include('paygrid.partials.bot-monitoring-panel', ['botRouteName' => 'ma.bot-monitoring'])
@endif

@push('scripts')
<script>
function paygridToggleEngineType(select, engineTypeId) {
    var isEngine = select.value !== 'cm';
    var engineType = document.getElementById(engineTypeId);
    engineType.style.display = isEngine ? '' : 'none';
    engineType.disabled = !isEngine;
}
function paygridWarnBelowFloor(input) {
    var floor = parseFloat(input.getAttribute('data-floor'));
    var value = parseFloat(String(input.value).replace(',', '.'));
    var hint = input.parentElement.querySelector('.field-hint');
    if (!hint) return;
    hint.hidden = !(value > 0 && !isNaN(floor) && value < floor);
}
document.addEventListener('input', function (e) {
    if (e.target.matches && e.target.matches('[data-floor]')) {
        paygridWarnBelowFloor(e.target);
    }
});
document.addEventListener('DOMContentLoaded', () => {
    const money = (value) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value || 0));
    const badgeClass = (status) => ['approved', 'success', 'Active', 'active', 'done'].includes(String(status)) ? 'ok' : (['rejected', 'failed', 'expired', 'Suspended'].includes(String(status)) ? 'danger' : 'warn');
    const detailsEl = document.getElementById('ma-overview-details');
    let panel = document.querySelector('[data-ma-detail-panel]');
    const details = detailsEl ? JSON.parse(detailsEl.textContent || '{}') : {};
    const detailState = { page: 1, perPage: 10 };
    const renderItems = (detail, page = 1) => {
        if (!detail) return;
        if (!panel) {
            const metrics = document.querySelector('.history-metrics');
            panel = document.createElement('section');
            panel.className = 'card pad section ma-detail-panel';
            panel.dataset.maDetailPanel = 'true';
            metrics?.insertAdjacentElement('afterend', panel);
        }
        const items = detail.items || [];
        const totalPages = Math.max(1, Math.ceil(items.length / detailState.perPage));
        detailState.page = Math.min(Math.max(1, page), totalPages);
        const start = (detailState.page - 1) * detailState.perPage;
        const visibleItems = items.slice(start, start + detailState.perPage);
        const rows = visibleItems.map((item) => `<div class="ma-detail-row"><div><b>${item.title || '-'}</b><span>${item.subtitle || item.date || '-'}</span></div><span class="badge ${badgeClass(item.status)}">${item.status || '-'}</span><strong>${item.amount === null || item.amount === undefined ? (item.meta || '-') : money(item.amount)}</strong></div>`).join('');
        const pager = items.length > detailState.perPage ? `<div class="qris-pagination" style="padding-top:14px"><div class="pager-summary">Showing ${start + 1} to ${Math.min(start + detailState.perPage, items.length)} of ${items.length}</div><div class="pager-links"><button class="pager" type="button" data-ma-detail-page="prev" ${detailState.page === 1 ? 'disabled' : ''}>Prev</button><button class="pager" type="button" data-ma-detail-page="next" ${detailState.page === totalPages ? 'disabled' : ''}>Next</button></div></div>` : '';
        panel.innerHTML = `<div class="qris-toolbar"><h2>${detail.title}</h2><span class="muted">${items.length} item | ${detailState.perPage} per halaman</span></div>` + (items.length ? `<div class="ma-detail-list">${rows}</div>${pager}` : '<p class="muted">Belum ada data.</p>');
        panel.querySelectorAll('[data-ma-detail-page]').forEach((button) => {
            button.addEventListener('click', () => renderItems(detail, detailState.page + (button.dataset.maDetailPage === 'next' ? 1 : -1)));
        });
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    document.querySelectorAll('[data-ma-detail]').forEach((card) => {
        card.addEventListener('click', () => {
            document.querySelectorAll('[data-ma-detail]').forEach((item) => item.classList.remove('active'));
            card.classList.add('active');
            renderItems(details[card.dataset.maDetail], 1);
        });
    });
    document.querySelectorAll('[data-ma-tab]').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelectorAll('[data-ma-tab]').forEach((item) => item.classList.toggle('active', item === button));
            document.querySelectorAll('[data-ma-panel]').forEach((item) => item.hidden = item.dataset.maPanel !== button.dataset.maTab);
        });
    });
    const periodSelect = document.querySelector('[data-ma-period-select]');
    const customDates = document.querySelectorAll('[data-ma-period-custom]');
    customDates.forEach((input) => input.addEventListener('input', () => {
        if (periodSelect) periodSelect.value = 'custom';
    }));
    document.querySelectorAll('[data-approval-detail]').forEach((button) => {
        button.addEventListener('click', () => {
            const target = document.getElementById(button.dataset.approvalDetail);
            if (target) target.hidden = false;
        });
    });
    document.querySelectorAll('.approval-detail-close, .approval-modal').forEach((item) => {
        item.addEventListener('click', (event) => {
            if (event.target.closest('.approval-modal-card') && !event.target.classList.contains('approval-detail-close')) return;
            item.closest('.approval-modal').hidden = true;
        });
    });
});
</script>
@endpush

@endsection
