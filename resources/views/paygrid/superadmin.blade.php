@extends('layouts.paygrid')

@php
    $money = fn ($value) => 'Rp '.number_format((int) $value, 0, ',', '.');
    $pct = fn ($value) => number_format((float) $value, 2, ',', '.').'%';
    $pctInput = fn ($value) => number_format((float) $value, 2, '.', '');
@endphp

@section('content')
<section class="qris-hero">
    <div>
        <div class="eyebrow">Superadmin</div>
        <h1>{{ $title }}</h1>
        <div class="sub">Kelola struktur PayGrid: MA, merchant group, toko, fee, timer ticket, dan akun dashboard.</div>
    </div>
</section>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif
@if($errors->any())
    <section class="card pad section"><span class="badge danger">{{ $errors->first() }}</span></section>
@endif

@if(in_array($active, ['dashboard-fee', 'add-fee', 'ma', 'merchant-group', 'accounts'], true))
    <form class="card filters super-filters" method="get">
        <input class="search" name="q" value="{{ $filters['search'] }}" placeholder="Cari nama, email, kode, merchant...">
        <div class="actions">
            @if(in_array($active, ['dashboard-fee', 'add-fee'], true))
                <select name="type"><option value="all" @selected($filters['type'] === 'all')>Semua tipe</option><option value="cm" @selected($filters['type'] === 'cm')>CM</option><option value="script" @selected($filters['type'] === 'script')>Script</option></select>
                <select name="agent_id"><option value="all" @selected($filters['agentId'] === 'all')>Semua group</option>@foreach($agents as $agent)<option value="{{ $agent->id }}" @selected((string) $filters['agentId'] === (string) $agent->id)>{{ $agent->name }}</option>@endforeach</select>
            @endif
            @if($active === 'ma')
                <select name="status"><option value="all" @selected($filters['status'] === 'all')>Semua status</option><option value="active" @selected($filters['status'] === 'active')>Aktif</option><option value="inactive" @selected($filters['status'] === 'inactive')>Nonaktif</option></select>
            @endif
            @if($active === 'merchant-group')
                <select name="ma_id"><option value="all" @selected($filters['maId'] === 'all')>Semua MA</option>@foreach($mas as $ma)<option value="{{ $ma->id }}" @selected((string) $filters['maId'] === (string) $ma->id)>{{ $ma->name }}</option>@endforeach</select>
                <select name="status"><option value="all" @selected($filters['status'] === 'all')>Semua status</option><option value="active" @selected($filters['status'] === 'active')>Aktif</option><option value="inactive" @selected($filters['status'] === 'inactive')>Nonaktif</option></select>
            @endif
            @if($active === 'accounts')
                <select name="role"><option value="all" @selected($filters['role'] === 'all')>Semua role</option>@foreach(['superadmin', 'ma', 'agent', 'admin', 'finance', 'cs', 'cs_pusat'] as $role)<option value="{{ $role }}" @selected($filters['role'] === $role)>{{ strtoupper($role) }}</option>@endforeach</select>
                <select name="status"><option value="all" @selected($filters['status'] === 'all')>Semua status</option><option value="active" @selected($filters['status'] === 'active')>Aktif</option><option value="inactive" @selected($filters['status'] === 'inactive')>Nonaktif</option></select>
            @endif
            <button class="btn primary">Filter</button>
            <a class="btn" href="{{ $active === 'dashboard-fee' ? route('superadmin.overview') : route('superadmin.page', $active) }}">Reset</a>
        </div>
    </form>
@endif

@if($active === 'dashboard-fee')
    <section class="grid qris-metrics">
        <div class="card pad qris-metric primary"><span>Transaksi Sukses</span><strong>{{ number_format($summary['total'], 0, ',', '.') }}</strong></div>
        <div class="card pad qris-metric success"><span>Volume Sukses</span><strong>{{ $money($summary['success_volume']) }}</strong></div>
        <div class="card pad qris-metric pending"><span>Total Fee</span><strong>{{ $money($summary['fee_total']) }}</strong></div>
        <div class="card pad qris-metric"><span>Merchant</span><strong>{{ $merchants->count() }}</strong></div>
    </section>
    <section class="card qris-panel section">
        <div class="qris-toolbar"><h2>Ringkasan Fee Per Merchant</h2></div>
        <div class="table-wrap">
            <table class="table qris-table">
                <thead><tr><th>Merchant</th><th>Group</th><th>Menu Fee</th><th>MA</th><th>Agent</th><th>MDR Final</th></tr></thead>
                <tbody>
                @foreach($merchants as $merchant)
                    @php($menuLabel = $merchant->fee_menu ? ($feeMenus->optionsFor('merchant', $feeMenus->typeCategory($merchant->merchant_type))[$merchant->fee_menu]['label'] ?? $merchant->fee_menu) : '-')
                    <tr>
                        <td><strong>{{ $merchant->name }}</strong><br><span class="muted">{{ strtoupper($merchant->merchant_type) }}</span></td>
                        <td>{{ $merchant->agent?->name ?: '-' }}</td>
                        <td>{{ $menuLabel }}</td>
                        <td>{{ $pct($merchant->agent?->ma?->ma_fee_percent) }}</td>
                        <td>{{ $pct($merchant->agent?->default_agent_fee_percent) }}</td>
                        <td><strong>{{ $pct($merchant->computed_mdr) }}</strong></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

@if($active === 'add-fee')
    <section class="card qris-panel section">
        <div class="qris-toolbar"><h2>Atur Fee Toko</h2></div>
        <div class="table-wrap">
            <table class="table qris-table super-fee-table">
                <thead><tr><th>Merchant</th><th>Group</th><th>Menu Fee</th><th>Fee (%)</th><th>MDR</th><th>Aksi</th></tr></thead>
                <tbody>
                @foreach($merchants as $merchant)
                    @php($merchantTypeCategory = $feeMenus->typeCategory($merchant->merchant_type))
                    @php($merchantMenuOptions = $feeMenus->optionsFor('merchant', $merchantTypeCategory))
                    <tr>
                        <td><strong>{{ $merchant->name }}</strong><br><span class="muted">{{ strtoupper($merchant->merchant_type) }}</span></td>
                        <td>{{ $merchant->agent?->name ?: '-' }}</td>
                        <td>
                            <form id="fee-{{ $merchant->id }}" method="post" action="{{ route('superadmin.merchant-fee.update', $merchant) }}">
                                @csrf
                            </form>
                            <select form="fee-{{ $merchant->id }}" name="fee_menu">
                                @foreach($merchantMenuOptions as $key => $option)
                                    <option value="{{ $key }}" @selected(old('fee_menu', $merchant->fee_menu) === $key)>{{ $option['label'] }} (min {{ $option['floor'] }}%)</option>
                                @endforeach
                            </select>
                        </td>
                        <td><input form="fee-{{ $merchant->id }}" name="merchant_mdr_percent" value="{{ old('merchant_mdr_percent', $pctInput($merchant->merchant_mdr_percent)) }}"></td>
                        <td><strong>{{ $pct($merchant->computed_mdr) }}</strong></td>
                        <td><button form="fee-{{ $merchant->id }}" class="btn primary compact-btn">Simpan</button></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

@if($active === 'ma')
    <section class="card qris-panel section">
        <div class="qris-toolbar"><h2>Create MA</h2></div>
        <div class="table-wrap">
            <table class="table qris-table super-create-table">
                <thead><tr><th>Nama</th><th>Email</th><th>Kontak</th><th>Status</th><th>Password</th><th>Menu Fee</th><th>MA Fee (%)</th><th>Aksi</th></tr></thead>
                <tbody>
                    <tr>
                        <td><form id="ma-create" method="post" action="{{ route('superadmin.ma.store') }}">@csrf</form><input form="ma-create" name="name" required></td>
                        <td><input form="ma-create" name="email" type="email" required></td>
                        <td><input form="ma-create" name="contact"></td>
                        <td><select form="ma-create" name="is_active"><option value="1">Aktif</option><option value="0">Nonaktif</option></select></td>
                        <td><input form="ma-create" name="password" required></td>
                        <td>
                            <select form="ma-create" name="fee_menu">
                                @foreach($feeMenus->optionsFor('ma') as $key => $option)
                                    <option value="{{ $key }}">{{ $option['label'] }} (min {{ $option['floor'] }}%)</option>
                                @endforeach
                            </select>
                        </td>
                        <td><input form="ma-create" name="ma_fee_percent" value="0.85" required></td>
                        <td><button form="ma-create" class="btn primary compact-btn">Buat</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
    <section class="card qris-panel section">
        <div class="qris-toolbar"><h2>Daftar MA</h2></div>
        <table class="table qris-table"><thead><tr><th>MA</th><th>Fee</th><th>MDR MA</th><th>Status</th></tr></thead><tbody>
            @foreach($mas as $ma)
                @php($maMenuLabel = $ma->fee_menu ? ($feeMenus->optionsFor('ma')[$ma->fee_menu]['label'] ?? $ma->fee_menu) : '-')
                <tr><td><strong>{{ $ma->name }}</strong><br><span class="muted">{{ $ma->email }}</span></td><td>{{ $maMenuLabel }}</td><td><strong>{{ $pct($ma->computed_mdr) }}</strong></td><td><span class="badge {{ $ma->is_active ? 'ok' : 'danger' }}">{{ $ma->is_active ? 'Aktif' : 'Nonaktif' }}</span></td></tr>
            @endforeach
        </tbody></table>
    </section>
@endif

@if($active === 'merchant-group')
    <section class="card qris-panel section">
        <div class="qris-toolbar"><h2>Create Merchant Group</h2></div>
        <div class="table-wrap">
            <table class="table qris-table super-group-create-table">
                <thead><tr><th>MA</th><th>Nama</th><th>Email</th><th>Kontak</th><th>Tipe</th><th>Menu Fee</th><th>Agent Fee (%)</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                    <tr>
                        <td><form id="group-create" method="post" action="{{ route('superadmin.agent.store') }}">@csrf</form><select form="group-create" name="ma_user_id"><option value="">-</option>@foreach($mas as $ma)<option value="{{ $ma->id }}">{{ $ma->name }}</option>@endforeach</select></td>
                        <td><input form="group-create" name="name" required></td>
                        <td><input form="group-create" name="email" type="email"></td>
                        <td><input form="group-create" name="contact"></td>
                        <td>
                            <select form="group-create" name="connection_type" id="group-create-type" onchange="paygridToggleAgentFeeMenu(this)">
                                <option value="cm">CM</option>
                                <option value="script">Engine</option>
                            </select>
                            <select form="group-create" name="engine_type" id="group-create-engine-type" style="display:none" disabled>
                                <option value="sc">Script</option>
                                <option value="api">API</option>
                            </select>
                        </td>
                        <td>
                            <select form="group-create" name="fee_menu" id="group-create-fee-menu-cm">
                                @foreach($feeMenus->optionsFor('agent', 'cm') as $key => $option)
                                    <option value="{{ $key }}">{{ $option['label'] }} (min {{ $option['floor'] }}%)</option>
                                @endforeach
                            </select>
                            <select form="group-create" name="fee_menu" id="group-create-fee-menu-engine" style="display:none" disabled>
                                @foreach($feeMenus->optionsFor('agent', 'engine') as $key => $option)
                                    <option value="{{ $key }}">{{ $option['label'] }} (min {{ $option['floor'] }}%)</option>
                                @endforeach
                            </select>
                        </td>
                        <td><input form="group-create" name="default_agent_fee_percent" value="1.00" required></td>
                        <td><select form="group-create" name="is_active"><option value="1">Aktif</option><option value="0">Nonaktif</option></select></td>
                        <td><button form="group-create" class="btn primary compact-btn">Buat</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
    <section class="card qris-panel section"><div class="qris-toolbar"><h2>Daftar Merchant Group</h2></div><table class="table qris-table"><thead><tr><th>Group</th><th>MA</th><th>Menu Fee</th><th>MDR Agen</th></tr></thead><tbody>@foreach($agents as $agent)<tr><td><strong>{{ $agent->name }}</strong><br><span class="muted">{{ $agent->code }}</span></td><td>{{ $agent->ma?->name ?: '-' }}</td><td>{{ $agent->fee_menu ? ($feeMenus->optionsFor('agent', $feeMenus->typeCategory($agent->connection_type))[$agent->fee_menu]['label'] ?? $agent->fee_menu) : '-' }}</td><td><strong>{{ $pct($agent->computed_mdr) }}</strong></td></tr>@endforeach</tbody></table></section>
@endif

@if($active === 'timer-ticket')
    <section class="card pad section" style="max-width:520px">
        <h2>Timer Ticket</h2>
        <div class="sub">Batas waktu transaksi pending sebelum perlu dicek/tindak lanjut.</div>
        <form method="post" action="{{ route('superadmin.timer-ticket.update') }}" class="admin-minimum-form section">
            @csrf
            <label>Menit<input name="ticket_pending_minutes" type="number" min="1" max="1440" value="{{ $timerMinutes }}" required></label>
            <button class="btn primary">Simpan</button>
        </form>
    </section>
@endif

@if($active === 'accounts')
    <section class="card qris-panel section">
        <div class="qris-toolbar"><h2>Daftar Account User</h2></div>
        <div class="table-wrap"><table class="table qris-table compact-user-table"><thead><tr><th>User</th><th>Username</th><th>Role</th><th>Merchant</th><th>Password</th><th>Reset</th></tr></thead><tbody>
            @foreach($users as $user)
                <tr><td><strong>{{ $user->name }}</strong><br><span class="muted">{{ $user->email }}</span></td><td>{{ $user->username ?: '-' }}</td><td>{{ strtoupper($user->role) }}</td><td>{{ $user->merchant?->name ?: '-' }}</td><td><strong>{{ $user->readablePlainPassword() ?: 'Reset diperlukan' }}</strong></td><td><form method="post" action="{{ route('superadmin.accounts.reset', $user) }}" class="actions reset-inline">@csrf<input name="password" placeholder="Kosongkan utk auto"><button class="btn compact-btn">Reset</button></form></td></tr>
            @endforeach
        </tbody></table></div>
    </section>
@endif

@if($active === 'merchant-group')
@push('scripts')
<script>
    function paygridToggleAgentFeeMenu(select) {
        var isEngine = select.value !== 'cm';
        var engineType = document.getElementById('group-create-engine-type');
        var cmMenu = document.getElementById('group-create-fee-menu-cm');
        var engineMenu = document.getElementById('group-create-fee-menu-engine');
        engineType.style.display = isEngine ? '' : 'none';
        engineType.disabled = !isEngine;
        cmMenu.style.display = isEngine ? 'none' : '';
        cmMenu.disabled = isEngine;
        engineMenu.style.display = isEngine ? '' : 'none';
        engineMenu.disabled = !isEngine;
    }
</script>
@endpush
@endif
@endsection
