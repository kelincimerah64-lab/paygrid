@extends('layouts.paygrid')

@php
    $merchants = $merchants ?? collect();
    $agents = $agents ?? collect();
    $registrations = $registrations ?? collect();
    $tickets = $tickets ?? collect();
    $agent = $agent ?? null;
    $statusClass = fn ($status) => $status === 'approved' ? 'ok' : ($status === 'rejected' ? 'danger' : 'warn');
    $money = fn ($value) => number_format((int) ($value ?? 0), 0, ',', '.');
@endphp

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $title }}</h1>
        <div class="sub">{{ $subtitle }}</div>
    </div>
    <div class="page-actions"></div>
</div>

@if($active === 'approval')
    <section class="card filters">
        <input class="search" placeholder="Cari agen, toko, merchant, user, status...">
        <div class="actions"><span class="muted">{{ $registrations->count() }} request</span><select><option>Semua status</option><option>Pending</option><option>Approved</option></select></div>
    </section>
    @foreach($registrations->concat($merchants->map(fn ($merchant) => (object) [
        'store_name' => $merchant->name,
        'merchant_type' => $merchant->merchant_type,
        'gateway' => $merchant->gateway,
        'status' => $merchant->approval_status,
        'agent' => $merchant->agent,
        'merchant' => $merchant,
        'payload' => $merchant->onboarding_payload ?? [],
    ])) as $item)
        @php($merchant = $item->merchant ?? null)
        <section class="card pad section approval-card">
            <div>
                <div class="label">Toko</div>
                <div class="store-title" style="margin-top:12px">
                    <div class="initial">{{ strtoupper(substr($item->store_name ?? 'T', 0, 1)) }}</div>
                    <div style="min-width:0">
                        <h2 class="truncate">{{ $item->store_name }}</h2>
                        <div class="muted">Agen: {{ $item->agent?->name ?? '-' }}</div>
                        @if($merchant?->topup_url)<div class="muted truncate">{{ $merchant->topup_url }}</div>@endif
                    </div>
                </div>
            </div>
            <div>
                <div class="label">Merchant</div>
                <h2 style="margin:14px 0 8px" class="truncate">{{ $merchant?->merchant_group_name ?? strtoupper($item->gateway ?? '-') }}</h2>
                <div class="muted">{{ $merchant?->merchant_id ?? 'Pending MA' }}</div>
            </div>
            <div>
                <div class="label">Structure Fee</div>
                <div class="fee-row"><span>Agen Pemegang Toko</span><strong>{{ $item->agent?->name ?? '-' }}</strong></div>
                <div class="fee-row"><span>Payment Gateway</span><strong>{{ ucfirst($item->gateway ?? 'hilogate') }}</strong></div>
                <div class="fee-row"><span>Merchant MDR</span><strong>{{ $merchant?->merchant_mdr_percent ?? ($item->payload['merchant_mdr_percent'] ?? '0') }}%</strong></div>
                <div class="mini-grid" style="margin-top:10px">
                    <div class="fee-pill"><span>Base MDR</span><strong>{{ $merchant?->base_mdr_percent ?? ($item->payload['base_mdr_percent'] ?? '0') }}%</strong></div>
                    <div class="fee-pill"><span>MA</span><strong>{{ $merchant?->ma_fee_percent ?? ($item->payload['ma_fee_percent'] ?? '0') }}%</strong></div>
                    <div class="fee-pill"><span>Agen</span><strong>{{ $merchant?->agent_fee_percent ?? ($item->payload['agent_fee_percent'] ?? '0') }}%</strong></div>
                    <div class="fee-pill"><span>Pay In</span><strong>{{ $merchant?->payin_fee_percent ?? ($item->payload['payin_fee_percent'] ?? '0') }}%</strong></div>
                </div>
                <button class="btn" style="width:100%; margin-top:12px">View Details</button>
            </div>
            <div>
                <div class="label">Decision</div>
                <div style="margin:12px 0"><span class="badge {{ $statusClass($item->status ?? 'pending') }}">{{ ucfirst(str_replace('_', ' ', $item->status ?? 'pending')) }}</span></div>
                @if(($item->status ?? '') !== 'approved')
                    <form method="post" action="{{ route('api.merchant-registration.approve', $item->id) }}">
                        @csrf
                        <input type="hidden" name="merchant_mdr_percent" value="{{ $item->payload['merchant_mdr_percent'] ?? 0 }}">
                        <input type="hidden" name="base_mdr_percent" value="{{ $item->payload['base_mdr_percent'] ?? 0 }}">
                        <input type="hidden" name="payin_fee_percent" value="{{ $item->payload['payin_fee_percent'] ?? 0 }}">
                        <input type="hidden" name="ma_fee_percent" value="{{ $item->payload['ma_fee_percent'] ?? 0 }}">
                        <input type="hidden" name="agent_fee_percent" value="{{ $item->payload['agent_fee_percent'] ?? 0 }}">
                        <button class="btn primary" style="width:100%; margin-bottom:10px">Approve</button>
                    </form>
                    <form method="post" action="{{ route('api.merchant-registration.reject', $item->id) }}">@csrf<button class="btn danger" style="width:100%">Reject</button></form>
                @else
                    <div class="muted">-</div>
                @endif
            </div>
        </section>
    @endforeach
@elseif($active === 'stores')
    <section class="card">
        <div class="filters"><input class="search" placeholder="Cari toko, merchant ID, grup, agen, PIC, status..."></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Toko</th><th>Merchant ID</th><th>Grup / Agen</th><th>Gateway / Tipe</th><th>Hitungan Fee</th><th>PIC</th><th>Status</th><th>Provisioning</th><th>Detail</th></tr></thead>
                <tbody>
                @forelse($merchants as $merchant)
                    <tr>
                        <td><strong>{{ $merchant->name }}</strong><br><span class="muted truncate" style="display:block; max-width:190px">{{ $merchant->topup_url ?: '-' }}</span></td>
                        <td>{{ $merchant->merchant_id ?: '-' }}</td>
                        <td><strong>{{ $merchant->merchant_group_name ?: '-' }}</strong><br><span class="muted">Agen: {{ $merchant->agent?->name ?? '-' }}</span></td>
                        <td><strong>{{ ucfirst($merchant->gateway) }}</strong> {{ strtoupper($merchant->merchant_type) }}</td>
                        <td class="fee-list">
                            <div class="fee-row"><span>MDR</span><strong>{{ $merchant->merchant_mdr_percent }}%</strong></div>
                            <div class="fee-row"><span>HG</span><strong>{{ $merchant->base_mdr_percent }}%</strong></div>
                            <div class="fee-row"><span>MA</span><strong>{{ $merchant->ma_fee_percent }}%</strong></div>
                            <div class="fee-row"><span>Agen</span><strong>{{ $merchant->agent_fee_percent }}%</strong></div>
                        </td>
                        <td>PIC: {{ $merchant->pic_email ?: '-' }}<br>FN: {{ $merchant->finance_email ?: '-' }}<br>CS: {{ $merchant->cs_email ?: '-' }}</td>
                        <td><span class="badge {{ $statusClass($merchant->approval_status) }}">{{ ucfirst($merchant->approval_status) }}</span></td>
                        <td>
                            <span class="badge {{ $merchant->provisioning_status === 'success' ? 'ok' : ($merchant->provisioning_status === 'failed' ? 'danger' : 'warn') }}">{{ ucfirst(str_replace('_', ' ', $merchant->provisioning_status ?? 'not started')) }}</span>
                            @if(in_array($merchant->provisioning_status, ['failed', 'not_started'], true))
                                <form method="post" action="{{ route('api.merchant.provision.retry', $merchant) }}" style="margin-top:8px">@csrf<button class="btn">Retry</button></form>
                            @endif
                        </td>
                        <td><button class="btn">Details</button> <button class="btn">Edit Fee</button></td>
                    </tr>
                @empty
                    <tr><td colspan="9">Data toko belum ada.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@elseif($active === 'mapping')
    <section class="card">
        <div class="filters"><input class="search" placeholder="Cari toko atau agen..."></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Toko</th><th>Agen Sekarang</th><th>Pilih Agen</th><th>Simpan</th></tr></thead>
                <tbody>
                @foreach($merchants as $merchant)
                    <tr>
                        <td><strong>{{ $merchant->name }}</strong><br><span class="muted">Status: {{ ucfirst($merchant->approval_status) }}</span></td>
                        <td><div class="card pad"><label>Agen Sekarang</label><br><strong>{{ $merchant->agent?->name ?? '-' }}</strong></div></td>
                        <td><select style="min-width:220px">@foreach($agents as $rowAgent)<option @selected($merchant->agent_id === $rowAgent->id)>{{ $rowAgent->name }}</option>@endforeach</select></td>
                        <td><button class="btn">Simpan Agen</button></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@elseif($active === 'agents')
    <section class="card">
        <div class="filters"><input class="search" placeholder="Cari agen, email, kontak, fee, status, group HG..."></div>
        <table class="table">
            <thead><tr><th>Agen ID</th><th>Nama Agen</th><th>Email</th><th>HG Group ID</th><th>Fee Agen</th><th>Status</th></tr></thead>
            <tbody>@foreach($agents as $rowAgent)<tr><td>{{ $rowAgent->code }}</td><td>{{ $rowAgent->name }}</td><td>{{ $rowAgent->email ?: '-' }}</td><td>{{ $rowAgent->hg_group_id ?: '-' }}</td><td>{{ $rowAgent->default_agent_fee_percent }}%</td><td><span class="badge ok">Active</span></td></tr>@endforeach</tbody>
        </table>
    </section>
@elseif($active === 'fee')
    <section class="card pad">
        <h2>Skema Fee</h2>
        <p class="muted">Merchant MDR = Base MDR + MA + Agent Fee. Pay In Fee dan Disbursement Fee adalah cashback/marketing fee dari gateway.</p>
        <div class="grid cards">
            <div class="card pad metric blue"><label>Total Fee MA</label><strong>{{ $money($merchants->sum(fn($m) => ($m->metric_volume_success ?? 0) * ((float) $m->ma_fee_percent / 100))) }}</strong></div>
            <div class="card pad metric"><label>Total Fee Agen</label><strong>{{ $money($merchants->sum(fn($m) => ($m->metric_volume_success ?? 0) * ((float) $m->agent_fee_percent / 100))) }}</strong></div>
            <div class="card pad metric"><label>Total Fee Merchant</label><strong>{{ $money($merchants->sum(fn($m) => ($m->metric_volume_success ?? 0) * ((float) $m->merchant_mdr_percent / 100))) }}</strong></div>
            <div class="card pad metric"><label>Pay In Cashback</label><strong>{{ $money($merchants->sum(fn($m) => ($m->metric_volume_success ?? 0) * ((float) $m->payin_fee_percent / 100))) }}</strong></div>
        </div>
    </section>
@elseif($active === 'status-request')
    @php($hasBulkSelectable = $registrations->contains(fn ($registration) => in_array($registration->status, ['draft', 'pending_agent'], true)))
    <section class="card agent-filter-card">
        <form class="agent-filter-grid" method="get">
            <label class="agent-filter-search"><span>Pencarian</span><input class="search" name="q" value="{{ $requestFilters['q'] ?? '' }}" placeholder="Cari toko, merchant ID, atau token request"></label>
            <label><span>Status</span><select name="status"><option value="all" @selected(($requestFilters['status'] ?? 'all') === 'all')>Semua status</option>@foreach(['pending_agent', 'pending_ma', 'approved', 'rejected'] as $status)<option value="{{ $status }}" @selected(($requestFilters['status'] ?? 'all') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>@endforeach</select></label>
            <label><span>Dari tanggal</span><input type="date" name="from" value="{{ $requestFilters['from'] ?? '' }}"></label>
            <label><span>Sampai tanggal</span><input type="date" name="to" value="{{ $requestFilters['to'] ?? '' }}"></label>
            <div class="agent-filter-actions"><button class="btn primary">Terapkan Filter</button><a class="btn" href="{{ route('agent.requests') }}">Reset</a><a class="btn ghost" href="{{ route('agent.export') }}">Export CSV</a></div>
        </form>
        <form method="post" action="{{ route('agent.requests.bulk') }}">@csrf
        @if($hasBulkSelectable)
            <div class="agent-bulk-bar"><div><strong>Bulk Action</strong><span>Pilih request pending, lalu kirim ke MA untuk proses approval.</span></div><div class="actions"><input type="hidden" name="action" value="submit"><button class="btn primary">Submit Selected ke MA</button></div></div>
        @endif
        <table class="table agent-request-table">
            <thead><tr><th>Nama Toko</th><th>Merchant ID</th><th>Tanggal Request</th><th>User Finance</th><th>User CS</th><th>Status Approval</th><th>Action</th></tr></thead>
            <tbody>
            @foreach($registrations as $registration)
                <tr><td>@if(in_array($registration->status, ['draft', 'pending_agent'], true))<input type="checkbox" name="registration_ids[]" value="{{ $registration->id }}">@endif {{ $registration->store_name }}</td><td>{{ $registration->merchant?->merchant_id ?: 'Pending MA' }}</td><td>{{ $registration->created_at->format('d M y') }}</td><td>FIN-{{ strtoupper(substr($registration->token, 0, 8)) }}<br><span class="muted">{{ $registration->payload['finance_email'] ?? 'Menunggu approval' }}</span></td><td>CS-{{ strtoupper(substr($registration->token, 0, 8)) }}<br><span class="muted">{{ $registration->payload['cs_email'] ?? 'Menunggu approval' }}</span></td><td><span class="badge {{ $statusClass($registration->status) }}">{{ ucfirst(str_replace('_', ' ', $registration->status)) }}</span></td><td>@if($registration->status === 'pending_agent')<button class="btn primary" formaction="{{ route('api.merchant-registration.submit', $registration) }}" formmethod="post">Submit MA</button><button class="btn danger" formaction="{{ route('agent.requests.delete', $registration) }}" formmethod="post" name="_method" value="delete">Delete</button>@elseif($registration->status === 'pending_ma')<span class="badge warn">Terkirim MA</span>@else<span class="muted">-</span>@endif</td></tr>
            @endforeach
            @foreach($merchants as $merchant)
                <tr><td>{{ $merchant->name }}</td><td>{{ $merchant->merchant_id }}</td><td>-</td><td>-</td><td>-</td><td><span class="badge ok">Approved</span></td><td>-</td></tr>
            @endforeach
            </tbody>
        </table>
        </form>
    </section>
@elseif(in_array($active, ['create-store', 'new-store']))
    @php($latestLink = session('onboarding_link') ?: (($onboardingLinks ?? collect())->firstWhere('status', 'active') ? route('merchant-registration.token-form', ($onboardingLinks ?? collect())->firstWhere('status', 'active')) : ''))
    <section class="card pad agent-onboarding-card">
        <div class="qris-toolbar compact-toolbar"><div><h2>Generate Link Onboarding</h2><p class="muted">Buat link registrasi unik untuk merchant. Link otomatis expired setelah satu kali submit.</p></div></div>
        <form method="post" action="{{ route('agent.onboarding-links.store') }}" class="form-grid pad">
            @csrf
            <label>Email Penerima<input name="recipient_email" type="email" placeholder="merchant@domain.com"></label>
            <label>Username Telegram<input name="recipient_telegram" placeholder="@merchantusername"></label>
            <div class="actions"><button class="btn primary">Generate Link</button><button class="btn" type="button" data-copy-onboarding-link>Copy Link</button></div>
            <label style="grid-column:1 / -1">Link Form Unik<input data-onboarding-link readonly value="{{ $latestLink ?: 'Link akan muncul di sini.' }}"></label>
        </form>
    </section>
    <section class="card section">
        <div class="qris-toolbar"><h2>Link Terakhir</h2><span class="muted">{{ ($onboardingLinks ?? collect())->count() }} link</span></div>
        <form method="post" action="{{ route('agent.onboarding-links.bulk') }}">@csrf<input type="hidden" name="action" value="expire"><div class="actions pad"><button class="btn danger">Expire Selected</button></div><table class="table"><thead><tr><th>Dibuat</th><th>Penerima</th><th>Status</th><th>Link</th><th>Request</th><th>Aksi</th></tr></thead><tbody>@forelse(($onboardingLinks ?? collect()) as $row)@php($effectiveStatus = $row->isUsable() ? $row->status : 'expired')<tr><td>@if($row->isUsable())<input type="checkbox" name="link_ids[]" value="{{ $row->id }}">@endif {{ $row->created_at->format('d M y H:i') }}</td><td>{{ $row->recipient_email ?: '-' }}<br><span class="muted">{{ $row->recipient_telegram ?: '-' }}</span></td><td><span class="badge {{ $effectiveStatus === 'active' ? 'ok' : 'warn' }}">{{ ucfirst($effectiveStatus) }}</span></td><td><input readonly value="{{ route('merchant-registration.token-form', $row) }}" style="width:100%"></td><td>{{ $row->registration?->store_name ?: '-' }}</td><td>@if($row->isUsable())<button class="btn danger" formaction="{{ route('agent.onboarding-links.expire', $row) }}" formmethod="post">Expire</button>@else<span class="muted">-</span>@endif</td></tr>@empty<tr><td colspan="6" class="empty">Belum ada link.</td></tr>@endforelse</tbody></table></form>
    </section>
    <script>document.addEventListener('DOMContentLoaded', () => document.querySelector('[data-copy-onboarding-link]')?.addEventListener('click', () => navigator.clipboard?.writeText(document.querySelector('[data-onboarding-link]')?.value || '')));</script>
@else
    <section class="card">
        <div class="filters"><input class="search" placeholder="Cari data..."><div class="actions"><input type="date"><input type="date"><button class="btn primary">Submit Filter</button></div></div>
        <table class="table"><thead><tr><th>Tanggal</th><th>Toko</th><th>Reference</th><th>Nominal</th><th>Status</th></tr></thead><tbody><tr><td colspan="5">Backend detail {{ $active }} siap disambungkan ke service Laravel.</td></tr></tbody></table>
    </section>
@endif
@endsection
