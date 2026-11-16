@extends('layouts.paygrid')

@php
    $money = fn ($value) => 'Rp '.number_format((int) $value, 0, ',', '.');
    $pct = fn ($value) => number_format((float) $value, 3, ',', '.').'%';
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
    <div class="status-banner"><span class="badge ok">{{ session('status') }}</span></div>
@endif
@if($errors->any())
    <div class="status-banner"><span class="badge danger">{{ $errors->first() }}</span></div>
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
            <table class="table qris-table super-summary-table">
                <thead><tr><th>Merchant</th><th>Group</th><th>Menu Fee</th><th>Margin MA</th><th>Margin Agent</th><th>MDR Final</th></tr></thead>
                <tbody>
                @foreach($merchants as $merchant)
                    @php($menuLabel = $merchant->fee_menu ? ($feeMenus->optionsFor('merchant')[$merchant->fee_menu]['label'] ?? $merchant->fee_menu) : '-')
                    <tr>
                        <td><strong>{{ $merchant->name }}</strong><br><span class="muted">{{ strtoupper($merchant->merchant_type) }}</span></td>
                        <td>{{ $merchant->agent?->name ?: '-' }}</td>
                        <td>{{ $menuLabel }}</td>
                        <td>{{ $pct((float) $merchant->agent_fee_percent - (float) $merchant->ma_fee_percent) }}</td>
                        <td>{{ $pct((float) $merchant->merchant_mdr_percent - (float) $merchant->agent_fee_percent) }}</td>
                        <td><strong>{{ $pct($merchant->merchant_mdr_percent) }}</strong></td>
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
                <thead><tr><th>Merchant</th><th>Group</th><th class="fee-menu-col">Fee per Menu</th><th>MDR</th><th>Aksi</th></tr></thead>
                <tbody>
                @foreach($merchants as $merchant)
                    <tr>
                        <td data-label="Merchant"><strong>{{ $merchant->name }}</strong><br><span class="muted">{{ strtoupper($merchant->merchant_type) }}</span></td>
                        <td data-label="Group">{{ $merchant->agent?->name ?: '-' }}</td>
                        <td data-label="Fee per Menu">
                            <form id="fee-{{ $merchant->id }}" method="post" action="{{ route('superadmin.merchant-fee.update', $merchant) }}">
                                @csrf
                            </form>
                            <details>
                                <summary>Atur fee ({{ $feeMenus->ratesSummary($merchant->fee_menu_rates ?? [], 'merchant') }})</summary>
                                @include('paygrid.partials.fee-menu-rates', ['role' => 'merchant', 'typeCategory' => null, 'feeMenus' => $feeMenus, 'currentRates' => $merchant->fee_menu_rates ?? [], 'formId' => 'fee-'.$merchant->id])
                            </details>
                        </td>
                        <td data-label="MDR"><strong>{{ $pct($merchant->merchant_mdr_percent) }}</strong></td>
                        <td data-label=""><button form="fee-{{ $merchant->id }}" class="btn primary compact-btn">Simpan</button></td>
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
                <thead><tr><th>Nama</th><th>Email</th><th>Kontak</th><th>Status</th><th>Password</th><th class="fee-menu-col">Fee per Menu</th><th>Aksi</th></tr></thead>
                <tbody>
                    <tr>
                        <td data-label="Nama"><form id="ma-create" method="post" action="{{ route('superadmin.ma.store') }}">@csrf</form><input form="ma-create" name="name" required></td>
                        <td data-label="Email"><input form="ma-create" name="email" type="email" required></td>
                        <td data-label="Kontak"><input form="ma-create" name="contact"></td>
                        <td data-label="Status"><select form="ma-create" name="is_active"><option value="1">Aktif</option><option value="0">Nonaktif</option></select></td>
                        <td data-label="Password"><input form="ma-create" name="password" required></td>
                        <td data-label="Fee per Menu">@include('paygrid.partials.fee-menu-rates', ['role' => 'ma', 'typeCategory' => null, 'feeMenus' => $feeMenus, 'formId' => 'ma-create'])</td>
                        <td data-label=""><button form="ma-create" class="btn primary compact-btn">Buat</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
    <section class="card qris-panel section">
        <div class="qris-toolbar"><h2>Daftar MA</h2></div>
        <div class="table-wrap"><table class="table qris-table super-ma-list-table"><thead><tr><th>MA</th><th>Fee per Menu</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
            @foreach($mas as $ma)
                <tr>
                    <td><strong>{{ $ma->name }}</strong><br><span class="muted">{{ $ma->email }}</span></td>
                    <td>{{ $feeMenus->ratesSummary($ma->fee_menu_rates ?? [], 'ma') }}</td>
                    <td><span class="badge {{ $ma->is_active ? 'ok' : 'danger' }}">{{ $ma->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    <td>
                        <button class="btn compact-btn approval-detail-open" type="button" data-approval-detail="ma-fee-{{ $ma->id }}">Edit Fee</button>
                        <div class="approval-modal" id="ma-fee-{{ $ma->id }}" hidden>
                            <div class="approval-modal-card">
                                <div class="qris-toolbar"><div><h2>Edit Fee MA</h2><p class="muted" style="margin:4px 0 0">{{ $ma->name }}</p></div><button class="btn compact-btn approval-detail-close" type="button">Tutup</button></div>
                                <form method="post" action="{{ route('superadmin.ma-fee.update', $ma) }}" class="form-grid pad">
                                    @csrf
                                    @include('paygrid.partials.fee-menu-rates', ['role' => 'ma', 'typeCategory' => null, 'feeMenus' => $feeMenus, 'currentRates' => $ma->fee_menu_rates ?? []])
                                    <div><button class="btn primary">Simpan Fee</button></div>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody></table></div>
    </section>
@endif

@if($active === 'merchant-group')
    <section class="card qris-panel section">
        <div class="qris-toolbar"><h2>Create Merchant Group</h2></div>
        <div class="table-wrap">
            <table class="table qris-table super-group-create-table">
                <thead><tr><th>MA</th><th>Nama</th><th>Email</th><th>Kontak</th><th>Tipe</th><th class="fee-menu-col">Fee per Menu</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                    <tr>
                        <td data-label="MA"><form id="group-create" method="post" action="{{ route('superadmin.agent.store') }}">@csrf</form><select form="group-create" name="ma_user_id"><option value="">-</option>@foreach($mas as $ma)<option value="{{ $ma->id }}">{{ $ma->name }}</option>@endforeach</select></td>
                        <td data-label="Nama"><input form="group-create" name="name" required></td>
                        <td data-label="Email"><input form="group-create" name="email" type="email"></td>
                        <td data-label="Kontak"><input form="group-create" name="contact"></td>
                        <td data-label="Tipe">
                            <select form="group-create" name="connection_type" id="group-create-type" onchange="paygridToggleEngineType(this, 'group-create-engine-type')">
                                <option value="cm">CM</option>
                                <option value="script">Engine</option>
                            </select>
                            <select form="group-create" name="engine_type" id="group-create-engine-type" style="display:none" disabled>
                                <option value="sc">Script</option>
                                <option value="api">API</option>
                            </select>
                        </td>
                        <td data-label="Fee per Menu">@include('paygrid.partials.fee-menu-rates', ['role' => 'agent', 'typeCategory' => null, 'feeMenus' => $feeMenus, 'formId' => 'group-create'])</td>
                        <td data-label="Status"><select form="group-create" name="is_active"><option value="1">Aktif</option><option value="0">Nonaktif</option></select></td>
                        <td data-label=""><button form="group-create" class="btn primary compact-btn">Buat</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
    <section class="card qris-panel section"><div class="qris-toolbar"><h2>Daftar Merchant Group</h2></div><table class="table qris-table super-group-list-table"><thead><tr><th>Group</th><th>MA</th><th>Fee per Menu</th></tr></thead><tbody>@foreach($agents as $agent)<tr><td><strong>{{ $agent->name }}</strong><br><span class="muted">{{ $agent->code }}</span></td><td>{{ $agent->ma?->name ?: '-' }}</td><td>{{ $feeMenus->ratesSummary($agent->fee_menu_rates ?? [], 'agent') }}</td></tr>@endforeach</tbody></table></section>
@endif

@if($active === 'fee-menu-settings')
    <section class="card qris-panel section" style="max-width:520px">
        <div class="qris-toolbar"><h2>Tambah Menu Fee</h2></div>
        <form method="post" action="{{ route('superadmin.fee-menus.store') }}" class="admin-minimum-form section">
            @csrf
            <label>Nama Menu<input name="label" required placeholder="mis. Based + Instant"></label>
            <label>Key (opsional)<input name="key" placeholder="mis. based_instant"></label>
            <button class="btn primary">Tambah Menu</button>
        </form>
    </section>

    <section class="card qris-panel section">
        <div class="qris-toolbar"><h2>Floor &amp; Status Menu per Role</h2></div>
        <div class="sub">Floor 0 = default (bebas, tanpa batas minimal). Menu yang dinonaktifkan untuk sebuah role tidak akan muncul di form role tersebut.</div>
        <form id="fee-menu-settings-form" method="post" action="{{ route('superadmin.fee-menus.settings') }}" class="section">
            @csrf
            <div class="table-wrap fee-menu-wrap">
                <table class="table qris-table fee-menu-settings-table">
                    <thead>
                        <tr>
                            <th>Menu</th>
                            <th>MA Aktif</th><th>MA Floor %</th>
                            <th>Agent Aktif</th><th>Agent Floor %</th>
                            <th>Toko Aktif</th><th>Toko Floor %</th>
                            <th>Hapus</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($feeMenuRows as $menu)
                        <tr>
                            <td><input type="text" name="menus[{{ $menu->id }}][label]" value="{{ $menu->label }}" required maxlength="80"><br><span class="muted">{{ $menu->key }}</span></td>
                            <td><input type="checkbox" name="menus[{{ $menu->id }}][ma_enabled]" value="1" @checked($menu->ma_enabled)></td>
                            <td><input type="text" inputmode="decimal" name="menus[{{ $menu->id }}][ma_floor]" value="{{ $pctInput($menu->ma_floor) }}"></td>
                            <td><input type="checkbox" name="menus[{{ $menu->id }}][agent_enabled]" value="1" @checked($menu->agent_enabled)></td>
                            <td><input type="text" inputmode="decimal" name="menus[{{ $menu->id }}][agent_floor]" value="{{ $pctInput($menu->agent_floor) }}"></td>
                            <td><input type="checkbox" name="menus[{{ $menu->id }}][merchant_enabled]" value="1" @checked($menu->merchant_enabled)></td>
                            <td><input type="text" inputmode="decimal" name="menus[{{ $menu->id }}][merchant_floor]" value="{{ $pctInput($menu->merchant_floor) }}"></td>
                            <td><button type="submit" form="delete-fee-menu-{{ $menu->id }}" class="btn danger compact-btn" onclick="return confirm('Hapus menu {{ $menu->label }}?')">Hapus</button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <button class="btn primary">Simpan Semua</button>
        </form>
        @foreach($feeMenuRows as $menu)
            <form id="delete-fee-menu-{{ $menu->id }}" method="post" action="{{ route('superadmin.fee-menus.destroy', $menu) }}" style="display:none">
                @csrf
                @method('DELETE')
            </form>
        @endforeach
    </section>
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
                <tr><td data-label="User"><strong>{{ $user->name }}</strong><br><span class="muted">{{ $user->email }}</span></td><td data-label="Username">{{ $user->username ?: '-' }}</td><td data-label="Role">{{ strtoupper($user->role) }}</td><td data-label="Merchant">{{ $user->merchant?->name ?: '-' }}</td><td data-label="Password"><strong>{{ $user->readablePlainPassword() ?: 'Reset diperlukan' }}</strong></td><td data-label="Reset"><form method="post" action="{{ route('superadmin.accounts.reset', $user) }}" class="actions reset-inline">@csrf<input name="password" placeholder="Kosongkan utk auto"><button class="btn compact-btn">Reset</button></form></td></tr>
            @endforeach
        </tbody></table></div>
    </section>
@endif

@if($active === 'integrations')
    <section class="card pad section" id="integrations-offline" hidden>
        <span class="badge danger">Service Co PayGrid belum berjalan</span>
    </section>
    <section class="grid section" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px">
        @foreach(['co' => ['Co PayGrid', 'Bot teknis: bot Telegram dan server'], 'data' => ['Data PayGrid', 'Bot analisis data']] as $key => [$name, $purpose])
            <div class="card pad" data-bot="{{ $key }}">
                <h2>{{ $name }}</h2>
                <div class="sub">{{ $purpose }}</div>
                <div class="section"><span class="badge" data-state>Memuat...</span></div>
                <div data-phone class="section" hidden></div>
                <div data-configured class="section" hidden></div>
                <div class="section" data-qr-wrap hidden>
                    <img data-qr alt="QR WhatsApp {{ $name }}" width="280" height="280" style="max-width:100%;height:auto">
                    <div class="sub">WhatsApp di HP nomor bot, buka Perangkat tertaut, lalu Tautkan perangkat dan scan. QR berganti otomatis.</div>
                </div>
                <div class="section" data-groups-wrap hidden>
                    <strong>Grup yang diikuti</strong>
                    <div class="sub">Salin ID grup yang dipilih ke .env Co PayGrid, lalu restart service.</div>
                    <ul data-groups></ul>
                </div>
            </div>
        @endforeach
    </section>
@push('scripts')
<script>
    (function () {
        var labels = { connected: ['Terhubung', 'ok'], qr: ['Menunggu scan QR', 'warn'], connecting: ['Menyambung...', ''] };

        function render(card, bot) {
            var label = labels[bot.state] || labels.connecting;
            var state = card.querySelector('[data-state]');
            state.textContent = label[0];
            state.className = 'badge ' + label[1];

            var phone = card.querySelector('[data-phone]');
            phone.hidden = !bot.phone;
            phone.textContent = bot.phone ? 'Nomor: +' + bot.phone : '';

            var connected = bot.state === 'connected';
            var configured = card.querySelector('[data-configured]');
            configured.hidden = !connected;
            configured.textContent = bot.configured_group ? 'Grup aktif: ' + bot.configured_group : 'Grup belum dipilih, jadi bot belum menjawab.';

            card.querySelector('[data-qr-wrap]').hidden = !bot.qr_image;
            if (bot.qr_image) card.querySelector('[data-qr]').src = bot.qr_image;

            var list = card.querySelector('[data-groups]');
            list.textContent = '';
            (bot.groups || []).forEach(function (group) {
                var item = document.createElement('li');
                item.textContent = group.name + ': ' + group.id;
                list.appendChild(item);
            });
            card.querySelector('[data-groups-wrap]').hidden = !connected || !(bot.groups || []).length;
        }

        function refresh() {
            fetch(@json(route('superadmin.integrations.status')), { headers: { Accept: 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (status) {
                    document.getElementById('integrations-offline').hidden = status.online;
                    document.querySelectorAll('[data-bot]').forEach(function (card) {
                        if (status.online) render(card, status[card.dataset.bot]);
                        else card.querySelector('[data-qr-wrap]').hidden = true;
                    });
                })
                .catch(function () { document.getElementById('integrations-offline').hidden = false; });
        }

        refresh();
        setInterval(refresh, 4000);
    })();
</script>
@endpush
@endif

@if($active === 'system-configuration')
    <section class="card pad section" style="max-width:520px">
        <h2>Interval Polling Gateway</h2>
        <div class="sub">Jeda minimum antar tarikan data dari Hilogate/Magnetopay/Artageto. Scheduler tetap jalan tiap 5 detik, tapi dispatch nyata di-skip sampai jeda ini lewat - jadi efeknya bisa diatur tanpa deploy.</div>
        <form method="post" action="{{ route('superadmin.system-configuration.update') }}" class="admin-minimum-form section">
            @csrf
            <label>Detik<input name="gateway_sync_min_interval_seconds" type="number" min="5" max="3600" value="{{ $gatewaySyncIntervalSeconds }}" required></label>
            <button class="btn primary">Simpan</button>
        </form>
    </section>

    <section class="card pad section" style="max-width:520px">
        <h2>Jalankan Reconciliation Sekarang</h2>
        <div class="sub">Normalnya jalan otomatis tanggal 3 tiap bulan (jam 02:00 WIB) untuk bulan sebelumnya. Bisa dipicu manual di sini kalau perlu.</div>
        <form method="post" action="{{ route('superadmin.system-configuration.reconcile') }}" class="admin-minimum-form section">
            @csrf
            <label>Bulan<input name="month" type="month" value="{{ now('Asia/Jakarta')->subMonthNoOverflow()->format('Y-m') }}" required></label>
            <button class="btn primary">Jalankan</button>
        </form>
    </section>

    <div data-live-root data-live-interval="4000">
    <section class="card qris-panel section" data-live-region="reconciliation-runs">
        <div class="qris-toolbar">
            <h2>Hasil Reconciliation</h2>
            @php($runningCount = $reconciliationRuns->where('status', 'running')->count())
            <span class="badge {{ $runningCount > 0 ? 'warn' : 'ok' }}">{{ $runningCount > 0 ? $runningCount.' sedang berjalan' : $reconciliationRuns->count().' run terakhir' }}</span>
        </div>
        <div class="table-wrap">
            <table class="table qris-table">
                <thead><tr><th>Toko</th><th>Tipe</th><th>Bulan</th><th>Status</th><th>Halaman</th><th>Dilihat</th><th>Baru</th><th>Diupdate</th><th>Selesai</th><th>Log</th></tr></thead>
                <tbody>
                @forelse($reconciliationRuns as $run)
                    <tr>
                        <td>{{ $run->merchant?->name ?: '-' }}</td>
                        <td>{{ $run->type === 'withdrawal' ? 'Withdrawal' : 'Collection' }}</td>
                        <td>{{ $run->month }}</td>
                        <td><span class="badge {{ $run->status === 'completed' ? 'ok' : ($run->status === 'failed' ? 'danger' : 'warn') }}">{{ ucfirst($run->status) }}</span></td>
                        <td>{{ $run->pages_walked }}</td>
                        <td>{{ $run->records_seen }}</td>
                        <td><strong>{{ $run->records_added }}</strong></td>
                        <td>{{ $run->records_updated }}</td>
                        <td>{{ $run->completed_at?->timezone('Asia/Jakarta')->format('d M Y H:i') ?: '-' }}</td>
                        <td>@if($run->last_error)<span class="muted" style="font-size:12px" title="{{ $run->last_error }}">{{ \Illuminate\Support\Str::limit($run->last_error, 60) }}</span>@else-@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="empty">Belum ada reconciliation run.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
    </div>
@endif

@if(in_array($active, ['add-fee', 'ma', 'merchant-group'], true))
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
</script>
@endpush
@endif
@endsection
