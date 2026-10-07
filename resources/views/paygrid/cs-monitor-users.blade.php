@extends('layouts.paygrid')

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">CS Monitor</div>
        <h1>List User</h1>
    </div>
</div>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Semua User Terdeteksi</h2><div class="muted">Tandai Ya/Tidak siapa yang benar CS - yang ditandai Tidak gak akan dihitung di Monitor CS Absen.</div></div>
    <div class="table-wrap">
        <table class="table qris-table">
            <thead><tr><th>Nama Telegram</th><th>Telegram ID</th><th>CS</th></tr></thead>
            <tbody>
            @forelse($telegramUsers as $telegramUser)
                <tr>
                    <td><strong>{{ $telegramUser->displayName() }}</strong></td>
                    <td><code>{{ $telegramUser->telegram_user_id }}</code></td>
                    <td>
                        <form method="post" action="{{ route('cs-monitor.users.cs-flag', $telegramUser) }}" class="compact-actions" data-auto-filter>
                            @csrf
                            <select name="is_cs">
                                <option value="1" @selected($telegramUser->is_cs)>Ya</option>
                                <option value="0" @selected(! $telegramUser->is_cs)>Tidak</option>
                            </select>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">Belum ada user terdeteksi.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
