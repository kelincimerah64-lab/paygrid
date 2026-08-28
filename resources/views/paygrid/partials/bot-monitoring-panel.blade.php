@php($bm = $botMonitoring ?? ['kpis' => [], 'tickets' => [], 'categories' => [], 'statuses' => [], 'assignees' => [], 'error' => null])
@php($botFilters = ['status' => request('bot_status', ''), 'category' => request('bot_category', ''), 'assigned' => request('bot_assigned', ''), 'from' => request('bot_from', ''), 'to' => request('bot_to', ''), 'q' => request('bot_q', '')])
<form class="card filters section" method="get">
    <input class="search" name="bot_q" value="{{ $botFilters['q'] }}" placeholder="Cari ticket ID / requester...">
    <div class="actions">
        <select name="bot_status"><option value="" @selected($botFilters['status'] === '')>Semua status</option>@foreach($bm['statuses'] as $s)<option value="{{ $s }}" @selected($botFilters['status'] === $s)>{{ $s }}</option>@endforeach</select>
        <select name="bot_category"><option value="" @selected($botFilters['category'] === '')>Semua kategori</option>@foreach($bm['categories'] as $c)<option value="{{ $c }}" @selected($botFilters['category'] === $c)>{{ $c }}</option>@endforeach</select>
        <select name="bot_assigned"><option value="" @selected($botFilters['assigned'] === '')>Semua assignee</option>@foreach($bm['assignees'] as $a)<option value="{{ $a }}" @selected($botFilters['assigned'] === $a)>{{ $a }}</option>@endforeach</select>
        <input type="date" name="bot_from" value="{{ $botFilters['from'] }}"><input type="date" name="bot_to" value="{{ $botFilters['to'] }}">
        <button class="btn primary">Filter</button><a class="btn" href="{{ route($botRouteName) }}">Reset</a>
        <a class="btn" href="{{ route($botRouteName, array_merge(request()->query(), ['refresh' => 1])) }}">Refresh</a>
    </div>
</form>

@php($botResolvedPct = ($bm['kpis']['total'] ?? 0) > 0 ? round((($bm['kpis']['resolved'] ?? 0) / $bm['kpis']['total']) * 100) : 0)
@php($botFailedPct = ($bm['kpis']['total'] ?? 0) > 0 ? round((($bm['kpis']['failed'] ?? 0) / $bm['kpis']['total']) * 100) : 0)
@if($bm['error'])
    <section class="card pad section"><span class="badge danger">{{ $bm['error'] }}</span></section>
@else
    <section class="grid qris-metrics section">
        <div class="card pad qris-metric primary"><span>Total Ticket</span><strong>{{ number_format($bm['kpis']['total'] ?? 0, 0, ',', '.') }}</strong><small>Sesuai filter aktif</small></div>
        <div class="card pad qris-metric success"><span>Resolved</span><strong>{{ number_format($bm['kpis']['resolved'] ?? 0, 0, ',', '.') }}</strong><small>{{ $botResolvedPct }}% dari total</small></div>
        <div class="card pad qris-metric expired"><span>Failed</span><strong>{{ number_format($bm['kpis']['failed'] ?? 0, 0, ',', '.') }}</strong><small>{{ $botFailedPct }}% dari total</small></div>
        <div class="card pad qris-metric"><span>Avg Pickup</span><strong>{{ $bm['kpis']['avg_pickup_minutes'] ?? 0 }} min</strong><small>Respons awal</small></div>
        <div class="card pad qris-metric"><span>Avg Handling</span><strong>{{ $bm['kpis']['avg_handling_minutes'] ?? 0 }} min</strong><small>Waktu penanganan</small></div>
        <div class="card pad qris-metric"><span>Avg Resolusi</span><strong>{{ $bm['kpis']['avg_total_resolution_minutes'] ?? 0 }} min</strong><small>End-to-end</small></div>
    </section>

    <section class="grid bot-charts section">
        <div class="card pad bot-chart-card"><h2>Distribusi Status</h2><div class="bot-chart-box"><canvas id="bot-status-chart"></canvas></div></div>
        <div class="card pad bot-chart-card"><h2>Ticket per Kategori</h2><div class="bot-chart-box"><canvas id="bot-category-chart"></canvas></div></div>
    </section>
    <script type="application/json" id="bot-monitoring-kpis">@json($bm['kpis'])</script>

    <section class="card qris-panel section">
        <div class="qris-toolbar"><h2>Daftar Ticket</h2><span class="badge ok">{{ count($bm['tickets']) }} ticket</span></div>
        <div class="table-wrap sticky-head bot-monitoring-wrap">
            <table class="table qris-table bot-monitoring-table">
                <thead><tr><th>Ticket</th><th>Requester</th><th>Kategori</th><th>Status</th><th>Handler</th><th>SLA</th><th>Timeline</th><th>Detail</th></tr></thead>
                <tbody>
                @forelse($bm['tickets'] as $t)
                    @php
                        $ticketModalId = 'bot-ticket-detail-'.md5(($t['ticket_id'] ?? '') . '-' . $loop->index);
                        $status = $t['status'] ?? null;
                    @endphp
                    <tr>
                        <td><strong>{{ $t['ticket_id'] ?? '-' }}</strong><br><span class="muted">{{ ! empty($t['has_attachment']) ? 'Ada lampiran' : 'Tanpa lampiran' }}</span></td>
                        <td><strong>{{ ($t['requester_name'] ?? null) ?: '-' }}</strong><br><span class="muted">{{ ! empty($t['requester_username']) ? '@'.$t['requester_username'] : '-' }}</span></td>
                        <td>{{ ($t['category'] ?? null) ?: '-' }}</td>
                        <td><span class="badge {{ $status === 'RESOLVED' ? 'ok' : ($status === 'FAILED' ? 'danger' : 'warn') }}">{{ $status ?: '-' }}</span></td>
                        <td>{{ ($t['assigned_name'] ?? null) ?: '-' }}</td>
                        <td><strong>{{ $t['total_resolution_minutes'] ?? '-' }} min</strong><br><span class="muted">Pickup {{ $t['pickup_minutes'] ?? '-' }} / Handling {{ $t['handling_minutes'] ?? '-' }}</span></td>
                        <td class="time-cell">{{ $t['created_at']?->format('d/m/y') ?? '-' }}<span>{{ $t['created_at']?->format('H.i') ? $t['created_at']?->format('H.i').' WIB' : '-' }}</span></td>
                        <td><button class="btn compact-btn bot-detail-open" type="button" data-bot-detail="{{ $ticketModalId }}">Lihat semua data</button></td>
                    </tr>
                    <tr class="bot-note-row"><td colspan="8"><span class="muted">Update terakhir:</span> {{ ($t['last_update'] ?? null) ?: '-' }}</td></tr>
                    <tr class="bot-detail-row" id="{{ $ticketModalId }}" hidden>
                        <td colspan="8">
                            <div class="bot-detail-card">
                                <div class="qris-toolbar"><div><h3>Detail Ticket {{ $t['ticket_id'] ?? '-' }}</h3><p class="muted" style="margin:4px 0 0">Semua kolom dari Google Sheet ditampilkan di sini.</p></div><button class="btn compact-btn bot-detail-close" type="button" data-bot-detail="{{ $ticketModalId }}">Tutup</button></div>
                                <div class="approval-detail-grid">
                                    @foreach($t['sheet_fields'] ?? [] as $field)
                                        <div class="fee-pill"><span>{{ $field['label'] }}</span><strong class="truncate">{{ $field['value'] }}</strong></div>
                                    @endforeach
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty">Belum ada ticket untuk filter ini.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endif

@push('scripts')
<script src="{{ asset('js/vendor/chart.umd.min.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bot-detail]').forEach((button) => {
        button.addEventListener('click', () => {
            const target = document.getElementById(button.dataset.botDetail);
            if (!target) return;
            target.hidden = button.classList.contains('bot-detail-close') ? true : !target.hidden;
        });
    });

    if (typeof Chart === 'undefined') return;
    const kpiEl = document.getElementById('bot-monitoring-kpis');
    if (!kpiEl) return;
    const kpis = JSON.parse(kpiEl.textContent || '{}');

    const palette = ['#008450', '#c62828', '#b15a00', '#1557c2', '#4f2a8a', '#1f6fe5'];
    Chart.defaults.font.size = 11;
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;

    const statusCanvas = document.getElementById('bot-status-chart');
    if (statusCanvas && kpis.by_status) {
        new Chart(statusCanvas, {
            type: 'doughnut',
            data: {
                labels: Object.keys(kpis.by_status),
                datasets: [{ data: Object.values(kpis.by_status), backgroundColor: palette, borderWidth: 2, borderColor: '#fff' }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 10 } } },
            },
        });
    }

    const categoryCanvas = document.getElementById('bot-category-chart');
    if (categoryCanvas && kpis.by_category) {
        new Chart(categoryCanvas, {
            type: 'bar',
            data: {
                labels: Object.keys(kpis.by_category),
                datasets: [{ label: 'Ticket', data: Object.values(kpis.by_category), backgroundColor: '#1f6fe5', borderRadius: 4, maxBarThickness: 28 }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#eef2f8' } },
                    x: { grid: { display: false } },
                },
            },
        });
    }
});
</script>
@endpush
