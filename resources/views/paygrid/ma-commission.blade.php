@extends('layouts.paygrid')

@php
    $days = [
        0 => 'Minggu', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu',
        4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu',
    ];
    $nextDay = fn ($d) => $days[($d + 1) % 7];
    $badge = fn ($status) => App\Support\PayGridLabels::badge($status);
    $defaultEnabled = [1, 2, 3, 4];
@endphp

@section('content')
@if(session('status'))
    <div class="status-banner"><span class="badge ok">{{ session('status') }}</span></div>
@endif
@if($errors->any())
    <div class="status-banner"><span class="badge danger">{{ $errors->first() }}</span></div>
@endif

<form method="post" action="{{ route('ma.commission.store') }}" id="commission-form" onsubmit="return confirm('Simpan konfigurasi komisi ini?')">
    @csrf
    <div class="page-head">
        <div>
            <h1>Commission Configuration</h1>
            <div class="sub">Atur jadwal dan rate komisi harian per Merchant Group.</div>
        </div>
        <div class="page-actions"></div>
    </div>
    <script id="commission-template-data" type="application/json">@json($templates)</script>

<div class="commission-grid section">
    <section class="card pad">
        <div class="commission-card-head">
            <div>
                <h2 style="margin:0">CashBack &amp; Disbursement Incentives</h2>
                <p class="muted">Komisi dihitung dari volume transaksi hari terpilih dan dilaporkan di hari berikutnya.</p>
            </div>
            <div class="commission-config-fields">
                <label class="commission-template-label">Name This Configuration *
                    <input type="text" name="name" id="commission-name-input" placeholder="Contoh: Komisi Weekday 0.10%" required>
                </label>
                <label class="commission-template-label">Merchant Group *
                    <select name="agent_id" id="commission-agent-select" required>
                        <option value="">Pilih group</option>
                        @foreach($agents as $a)
                            <option value="{{ $a->id }}">{{ $a->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="commission-template-label">Merchant Name
                    <select name="merchant_id" id="commission-merchant-select">
                        <option value="">Semua toko di group</option>
                        @foreach($merchants as $m)
                            <option value="{{ $m->id }}" data-agent-id="{{ $m->agent_id }}">{{ $m->name }}</option>
                        @endforeach
                    </select>
                    <span class="muted commission-field-hint">Kosong = semua toko di group</span>
                </label>
                <label class="commission-template-label">Template
                    <select id="commission-template-select">
                        <option value="">Pilih template...</option>
                        @foreach($templates as $t)
                            <option value="{{ $t['id'] }}">{{ $t['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <span class="badge ok" id="commission-active-count">0 dari 7 hari aktif</span>
        </div>

        <div class="table-wrap">
            <table class="table qris-table commission-day-table">
                <thead>
                    <tr>
                        <th>Aktif</th>
                        <th>Hari Transaksi</th>
                        <th>Rate (%)</th>
                        <th>Fee TRX WD (Rp)</th>
                        <th>Report</th>
                        <th>Settlement</th>
                        <th>Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($days as $d => $label)
                        <tr>
                            <td data-label="Aktif">
                                <label class="commission-toggle">
                                    <input type="checkbox" class="commission-day-enabled" name="days[{{ $d }}][enabled]" value="1" data-day="{{ $d }}" @checked(in_array($d, $defaultEnabled))>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td data-label="Hari Transaksi">
                                <strong>{{ $label }}</strong>
                                @if(in_array($d, [5, 6]))
                                    <span class="badge warn commission-optional-badge">Opsional</span>
                                @endif
                                <input type="hidden" name="days[{{ $d }}][day_of_week]" value="{{ $d }}">
                            </td>
                            <td data-label="Rate (%)"><input type="number" step="0.01" min="0" max="100" class="commission-day-rate" name="days[{{ $d }}][rate_percent]" value="0.10" data-day="{{ $d }}"></td>
                            <td data-label="Fee TRX WD (Rp)"><input type="number" step="500" min="0" class="commission-day-wd-fee" name="days[{{ $d }}][wd_fee_fixed]" value="0" data-day="{{ $d }}"></td>
                            <td data-label="Report"><span class="badge commission-daybadge">&#128197; {{ $nextDay($d) }}</span></td>
                            <td data-label="Settlement"><span class="badge commission-daybadge">&#128197; {{ $nextDay($d) }}</span></td>
                            <td data-label="Catatan"><input type="text" name="days[{{ $d }}][notes]" placeholder="Opsional"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="commission-submit-row">
            <button class="btn danger" type="submit">SUBMIT</button>
        </div>
    </section>

    <div class="commission-sidebar">
        <section class="card pad commission-simulate">
            <div class="commission-sim-head">
                <h3 style="margin:0">Simulasi Komisi</h3>
                <span class="muted">Volume contoh</span>
            </div>
            <div class="commission-sim-tabs" id="commission-sim-tabs"></div>
            <div class="commission-sim-stats">
                <div><span class="muted" id="commission-sim-volume-label">Volume</span><strong id="commission-sim-volume">-</strong></div>
                <div><span class="muted">Rate</span><strong id="commission-sim-rate-display">-</strong></div>
            </div>
            <div class="commission-sim-result">
                <span class="muted">Komisi</span>
                <strong id="commission-sim-result">-</strong>
            </div>
            <p class="muted" id="commission-sim-note" style="margin-top:8px"></p>
        </section>

        <section class="card pad section">
            <h3 style="margin:0">Cara Kerja &amp; Aturan</h3>
            <ol class="commission-howto-list">
                <li>Aktifkan hari transaksi yang mau diikutkan, lalu atur rate per hari.</li>
                <li>Komisi dihitung harian dari transaksi sukses, bukan diakumulasi mingguan.</li>
                <li>Laporan dan settlement selalu muncul di hari berikutnya.</li>
            </ol>
            <div class="commission-rule-pills">
                <span class="commission-rule-pill">Siklus default: Minggu-Kamis</span>
                <span class="commission-rule-pill">Rate bisa beda tiap hari</span>
                <span class="commission-rule-pill warn">Jumat &amp; Sabtu sesuai kesepakatan</span>
            </div>
        </section>
    </div>
</div>

</form>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Daftar Konfigurasi Komisi</h2><span class="badge ok">{{ $configList->count() }} config</span></div>
    <div class="table-wrap">
        <table class="table qris-table">
            <thead><tr><th>Nama</th><th>Merchant Group</th><th>Berlaku Untuk</th><th>Status</th><th>Laporan</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse($configList as $c)
                <tr>
                    <td data-label="Nama"><strong>{{ $c['name'] }}</strong></td>
                    <td data-label="Merchant Group">{{ $c['agent_name'] }}</td>
                    <td data-label="Berlaku Untuk">{{ $c['scope_label'] }}</td>
                    <td data-label="Status"><span class="badge {{ $c['status'] === 'active' ? 'ok' : 'warn' }}">{{ $c['status'] === 'active' ? 'Aktif' : 'Dicopot' }}</span></td>
                    <td data-label="Laporan">{{ $c['report_count'] }} baris</td>
                    <td data-label="Aksi" class="commission-config-actions">
                        <form method="post" action="{{ route('ma.commission.toggle-status', $c['id']) }}">
                            @csrf
                            @method('PATCH')
                            <button class="btn compact-btn" type="submit">{{ $c['status'] === 'active' ? 'Copot' : 'Aktifkan' }}</button>
                        </form>
                        <form method="post" action="{{ route('ma.commission.destroy', $c['id']) }}" onsubmit="return confirm('Hapus template &quot;{{ $c['name'] }}&quot; permanen?{{ $c['report_count'] > 0 ? ' Ini juga akan menghapus '.$c['report_count'].' baris history laporan komisi yang nempel ke config ini - tidak bisa dibatalkan.' : '' }}')">
                            @csrf
                            @method('DELETE')
                            <button class="btn compact-btn danger" type="submit">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Belum ada konfigurasi komisi.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

@push('scripts')
<script>
(function () {
    var days = @json($days);
    var nextDayName = function (d) { return days[(d + 1) % 7]; };
    var fmt = function (n) { return n.toLocaleString('id-ID', {maximumFractionDigits: 2}); };
    var exampleVolume = 2000000000;

    var dayEnabled = function () { return Array.prototype.slice.call(document.querySelectorAll('.commission-day-enabled')); };
    var rateInput = function (d) { return document.querySelector('.commission-day-rate[data-day="' + d + '"]'); };

    var activeCount = document.getElementById('commission-active-count');
    var simTabs = document.getElementById('commission-sim-tabs');
    var simVolumeLabel = document.getElementById('commission-sim-volume-label');
    var simVolume = document.getElementById('commission-sim-volume');
    var simRateDisplay = document.getElementById('commission-sim-rate-display');
    var simResult = document.getElementById('commission-sim-result');
    var simNote = document.getElementById('commission-sim-note');
    var selectedSimDay = null;

    var renderSim = function () {
        var day = Number(selectedSimDay);
        var rate = parseFloat((rateInput(day) || {}).value) || 0;
        simVolumeLabel.textContent = 'Volume ' + days[day];
        simVolume.textContent = 'Rp' + fmt(exampleVolume);
        simRateDisplay.textContent = fmt(rate) + '%';
        simResult.textContent = 'Rp' + fmt(Math.round(exampleVolume * rate / 100));
        simNote.textContent = 'Muncul di laporan komisi hari ' + nextDayName(day) + '.';
    };

    var rebuildSimTabs = function () {
        var enabledDays = dayEnabled().filter(function (c) { return c.checked; }).map(function (c) { return c.dataset.day; });
        activeCount.textContent = enabledDays.length + ' dari 7 hari aktif';

        if (!enabledDays.length) {
            simTabs.innerHTML = '';
            simVolumeLabel.textContent = 'Volume';
            simVolume.textContent = '-';
            simRateDisplay.textContent = '-';
            simResult.textContent = '-';
            simNote.textContent = 'Aktifkan minimal satu hari untuk melihat simulasi.';
            selectedSimDay = null;
            return;
        }

        if (selectedSimDay === null || enabledDays.indexOf(String(selectedSimDay)) === -1) {
            selectedSimDay = enabledDays[0];
        }

        simTabs.innerHTML = '';
        enabledDays.forEach(function (d) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'commission-sim-tab' + (String(d) === String(selectedSimDay) ? ' active' : '');
            btn.textContent = days[d];
            btn.addEventListener('click', function () {
                selectedSimDay = d;
                rebuildSimTabs();
            });
            simTabs.appendChild(btn);
        });

        renderSim();
    };

    dayEnabled().forEach(function (c) { c.addEventListener('change', rebuildSimTabs); });
    document.querySelectorAll('.commission-day-rate').forEach(function (r) {
        r.addEventListener('input', function () {
            if (String(r.dataset.day) === String(selectedSimDay)) renderSim();
        });
    });
    rebuildSimTabs();

    // Merchant Name options are filtered down to whichever Merchant Group is selected.
    var agentSelect = document.getElementById('commission-agent-select');
    var merchantSelect = document.getElementById('commission-merchant-select');
    var merchantOptions = function () { return Array.prototype.slice.call(merchantSelect.options).filter(function (o) { return o.value; }); };
    var syncMerchantOptions = function () {
        var agentId = agentSelect.value;
        var selectedStillValid = false;
        merchantOptions().forEach(function (o) {
            var match = ! agentId || o.dataset.agentId === agentId;
            o.hidden = ! match;
            if (match && o.selected) selectedStillValid = true;
        });
        if (!selectedStillValid) merchantSelect.value = '';
    };
    agentSelect.addEventListener('change', syncMerchantOptions);
    syncMerchantOptions();

    // Load Template: repopulate day rows + name/group/merchant from a previously saved config.
    var templates = JSON.parse(document.getElementById('commission-template-data').textContent || '[]');
    document.getElementById('commission-template-select').addEventListener('change', function (e) {
        var template = templates.find(function (t) { return String(t.id) === e.target.value; });
        if (!template) return;

        template.days.forEach(function (day) {
            var enabledInput = document.querySelector('.commission-day-enabled[name="days[' + day.day_of_week + '][enabled]"]');
            var rateInputEl = document.querySelector('.commission-day-rate[name="days[' + day.day_of_week + '][rate_percent]"]');
            var wdFeeInputEl = document.querySelector('.commission-day-wd-fee[name="days[' + day.day_of_week + '][wd_fee_fixed]"]');
            if (enabledInput) enabledInput.checked = !! day.enabled;
            if (rateInputEl) rateInputEl.value = day.rate_percent;
            if (wdFeeInputEl) wdFeeInputEl.value = day.wd_fee_fixed;
        });
        rebuildSimTabs();

        document.getElementById('commission-name-input').value = template.name || '';
        agentSelect.value = template.agent_id || '';
        syncMerchantOptions();
        merchantSelect.value = template.merchant_id || '';
    });
})();
</script>
@endpush
