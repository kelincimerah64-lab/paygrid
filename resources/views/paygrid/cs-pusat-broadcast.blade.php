@extends('layouts.paygrid')

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">CS Pusat</div>
        <h1>Broadcast Telegram</h1>
    </div>
</div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif
@if($errors->any())
    <section class="card pad section"><span class="badge danger">{{ $errors->first() }}</span></section>
@endif

<section class="card pad section" style="max-width:640px">
    <div class="sub">Tidak centang grup = kirim ke semua grup. Centang grup = kirim ke grup yang dicentang saja.</div>
    @unless($configured)
        <div class="section"><span class="badge danger">Belum dikonfigurasi. Isi TELEGRAM_BROADCAST_BOT_TOKEN dan TELEGRAM_BROADCAST_GROUPS di .env.</span></div>
    @endunless
    <form method="post" action="{{ route('center-support.broadcast.send') }}" class="section" onsubmit="return confirm('Kirim broadcast sekarang?')">
        @csrf
        <label>Pesan<textarea name="message" rows="6" maxlength="4096" required style="width:100%">{{ old('message') }}</textarea></label>
        <div class="export-status-group section">
            @foreach($groups as $group)
                <label><input type="checkbox" name="chat_ids[]" value="{{ $group['chat_id'] }}" @checked(in_array($group['chat_id'], old('chat_ids', []), true))> {{ $group['name'] }}</label>
            @endforeach
        </div>
        <button class="btn primary section" @disabled(! $configured)>Kirim Broadcast</button>
    </form>
</section>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>History Broadcast</h2></div>
    <div class="table-wrap">
        <table class="table qris-table">
            <thead><tr><th>Waktu</th><th>Grup</th><th>Pesan</th><th>Pengirim</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
                @forelse($history as $row)
                    <tr>
                        <td data-label="Waktu">{{ $row->created_at->format('d M Y H:i') }}</td>
                        <td data-label="Grup">{{ $row->group_name }}</td>
                        <td data-label="Pesan" style="white-space:pre-line">{{ $row->message }}</td>
                        <td data-label="Pengirim">{{ $row->sender?->name ?: '-' }}</td>
                        <td data-label="Status">
                            @if($row->status === 'sent')<span class="badge ok">Terkirim</span>
                            @elseif($row->status === 'deleted')<span class="badge">Dihapus {{ $row->deleted_from_telegram_at?->format('d M H:i') }}</span>
                            @else<span class="badge danger">Gagal</span>@endif
                        </td>
                        <td data-label="Aksi">
                            @if($row->status === 'sent')
                                <form method="post" action="{{ route('center-support.broadcast.destroy', $row) }}" onsubmit="return confirm('Hapus pesan ini dari grup {{ $row->group_name }}?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn compact-btn">Hapus</button>
                                </form>
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">Belum ada broadcast.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="qris-pagination pad">
        <div class="pager-links">
            @if($history->onFirstPage())<span class="pager disabled">Prev</span>@else<a class="pager" href="{{ $history->previousPageUrl() }}">Prev</a>@endif
            @if($history->hasMorePages())<a class="pager" href="{{ $history->nextPageUrl() }}">Next</a>@else<span class="pager disabled">Next</span>@endif
        </div>
    </div>
</section>
@endsection
