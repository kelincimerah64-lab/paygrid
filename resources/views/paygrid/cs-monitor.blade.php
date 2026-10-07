@extends((request()->boolean('partial') || request()->header('X-PayGrid-Partial') === '1') ? 'layouts.partial' : 'layouts.paygrid')

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">CS Monitor</div>
        <h1>Monitor CS Absen</h1>
    </div>
</div>

<div data-live-root data-live-interval="5000" data-live-ignore-visibility>

<section class="card pad section">
    <form method="get" class="merchant-workspace-filter" data-auto-filter>
        <label><span>Tanggal</span><input type="date" name="date" value="{{ $selectedDate }}"></label>
        <button class="btn primary compact-btn">Tampilkan</button>
    </form>
</section>

<div data-live-region="cs-monitor-kpi">
<section class="grid qris-metrics section">
    <div class="card pad qris-metric"><span>Semua CS</span><strong>{{ $kpi['semua'] }}</strong></div>
    <div class="card pad qris-metric primary"><span>Total Aktif</span><strong>{{ $kpi['total'] }}</strong></div>
    <div class="card pad qris-metric success"><span>Sudah Absen</span><strong>{{ $kpi['hadir'] }}</strong></div>
    <div class="card pad qris-metric pending"><span>Belum Absen</span><strong>{{ $kpi['belum'] }}</strong></div>
    <div class="card pad qris-metric expired"><span>Member Grup Telegram</span><strong>{{ $kpi['grup'] ?? '-' }}</strong><small>Dibanding Semua CS: beda berarti ada yang belum kedetect</small></div>
</section>
</div>

<div data-live-region="cs-monitor-suspects">
<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Belum Terverifikasi</h2><div class="muted">Masih di grup, belum selesai /activate. Perhatikan - ini calon penyusup.</div></div>
    <div class="table-wrap">
        <table class="table qris-table">
            <thead><tr><th>Nama Telegram</th><th>Telegram ID</th><th>Status</th><th>Absen</th><th>Masuk Grup</th><th>PIN</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse($suspects as $telegramUser)
                @php($absence = $absencesForDate->get($telegramUser->id))
                <tr>
                    <td><strong>{{ $telegramUser->displayName() }}</strong></td>
                    <td><code>{{ $telegramUser->telegram_user_id }}</code></td>
                    <td><span class="badge danger">Suspect</span></td>
                    <td>
                        @if($absence)
                            <span class="badge ok">Hadir {{ $absence->absen_at->timezone('Asia/Jakarta')->format('d/m/y H:i') }}</span>
                        @else
                            <span class="badge warn">Belum Absen</span>
                        @endif
                    </td>
                    <td>{{ $telegramUser->joined_group_at?->timezone('Asia/Jakarta')->format('d/m/y H:i') ?? '-' }}</td>
                    <td>
                        @if($telegramUser->pinIsActive())
                            <div style="font-size:20px;font-weight:800;letter-spacing:.08em">{{ $telegramUser->readablePin() }}</div>
                            <span class="muted">Dibuat {{ $telegramUser->pin_expires_at->copy()->subMinutes(30)->timezone('Asia/Jakarta')->format('d/m/y H:i') }} &middot; s/d {{ $telegramUser->pin_expires_at->timezone('Asia/Jakarta')->format('H:i') }}</span>
                        @else
                            <span class="muted">Belum ada PIN aktif</span>
                        @endif
                    </td>
                    <td>
                        @unless($telegramUser->pinIsActive())
                            <form method="post" action="{{ route('cs-monitor.generate-pin', $telegramUser) }}" class="compact-actions">
                                @csrf
                                <button class="btn primary compact-btn" type="submit">Generate PIN</button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">Tidak ada yang mencurigakan saat ini.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
</div>

<div data-live-region="cs-monitor-activated">
<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Sudah Aktif</h2><div class="muted">Terverifikasi - status absen sesuai tanggal terpilih.</div></div>
    <div class="table-wrap">
        <table class="table qris-table">
            <thead><tr><th>Nama</th><th>Telegram ID</th><th>Status</th><th>Absen</th></tr></thead>
            <tbody>
            @forelse($activated as $telegramUser)
                @php($absence = $absencesForDate->get($telegramUser->id))
                <tr>
                    <td><strong>{{ $telegramUser->displayName() }}</strong></td>
                    <td><code>{{ $telegramUser->telegram_user_id }}</code></td>
                    <td><span class="badge ok">Aman</span></td>
                    <td>
                        @if($absence)
                            <span class="badge ok">Hadir {{ $absence->absen_at->timezone('Asia/Jakarta')->format('d/m/y H:i') }}</span>
                        @else
                            <span class="badge warn">Belum Absen</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Belum ada yang aktif.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
</div>

<div data-live-region="cs-monitor-left">
@if($left->isNotEmpty())
<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Sudah Keluar Grup</h2></div>
    <div class="table-wrap">
        <table class="table qris-table">
            <thead><tr><th>Nama Telegram</th><th>Telegram ID</th><th>Keluar Pada</th></tr></thead>
            <tbody>
            @foreach($left as $telegramUser)
                <tr>
                    <td>{{ $telegramUser->displayName() }}</td>
                    <td><code>{{ $telegramUser->telegram_user_id }}</code></td>
                    <td>{{ $telegramUser->left_group_at?->timezone('Asia/Jakarta')->format('d/m/y H:i') ?? '-' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@endif
</div>

</div>
@endsection
