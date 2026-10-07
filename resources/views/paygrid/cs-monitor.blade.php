@extends('layouts.paygrid')

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">CS Monitor</div>
        <h1>Monitor CS Absen</h1>
    </div>
</div>

<section class="card pad section">
    <form method="get" class="merchant-workspace-filter" data-auto-filter>
        <label><span>Tanggal</span><input type="date" name="date" value="{{ $selectedDate }}"></label>
        <button class="btn primary compact-btn">Tampilkan</button>
    </form>
</section>

<section class="grid qris-metrics cs-monitor-metrics section">
    <div class="card pad qris-metric primary"><span>Total Aktif</span><strong>{{ $kpi['total'] }}</strong></div>
    <div class="card pad qris-metric success"><span>Sudah Absen</span><strong>{{ $kpi['hadir'] }}</strong></div>
    <div class="card pad qris-metric pending"><span>Belum Absen</span><strong>{{ $kpi['belum'] }}</strong></div>
</section>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Belum Terverifikasi</h2><div class="muted">Masih di grup, belum selesai /activate. Perhatikan - ini calon penyusup.</div></div>
    <div class="table-wrap">
        <table class="table qris-table cs-monitor-table">
            <thead><tr><th>Nama Telegram</th><th>Telegram ID</th><th>Absen</th><th>Masuk Grup</th><th>PIN</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse($suspects as $telegramUser)
                @php($absence = $absencesForDate->get($telegramUser->id))
                <tr>
                    <td><strong>{{ $telegramUser->displayName() }}</strong></td>
                    <td><code>{{ $telegramUser->telegram_user_id }}</code></td>
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
                            <span class="badge danger">Suspect</span>
                            <br><span class="muted">Belum ada PIN aktif</span>
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
                <tr><td colspan="6" class="empty">Tidak ada yang mencurigakan saat ini.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Sudah Aktif</h2><div class="muted">Terverifikasi - status absen sesuai tanggal terpilih.</div></div>
    <div class="table-wrap">
        <table class="table qris-table cs-monitor-table">
            <thead><tr><th>Nama</th><th>Telegram ID</th><th>Absen</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse($activated as $telegramUser)
                @php($absence = $absencesForDate->get($telegramUser->id))
                <tr>
                    <td><strong>{{ $telegramUser->displayName() }}</strong></td>
                    <td><code>{{ $telegramUser->telegram_user_id }}</code></td>
                    <td>
                        @if($absence)
                            <span class="badge ok">Hadir {{ $absence->absen_at->timezone('Asia/Jakarta')->format('d/m/y H:i') }}</span>
                        @else
                            <span class="badge warn">Belum Absen</span>
                        @endif
                    </td>
                    <td>
                        <form method="post" action="{{ route('cs-monitor.generate-pin', $telegramUser) }}" class="compact-actions">
                            @csrf
                            <button class="btn compact-btn" type="submit" onclick="return confirm('Generate PIN baru untuk re-verifikasi {{ $telegramUser->displayName() }}?')">Generate PIN Baru</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Belum ada yang aktif.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>

@if($left->isNotEmpty())
<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Sudah Keluar Grup</h2></div>
    <div class="table-wrap">
        <table class="table qris-table cs-monitor-table">
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
@endsection
