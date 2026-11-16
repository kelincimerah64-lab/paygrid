@extends('layouts.paygrid')

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">CS Pusat</div>
        <h1>Broadcast Telegram</h1>
    </div>
</div>

@if(session('status'))
    <div class="status-banner"><span class="badge ok">{{ session('status') }}</span></div>
@endif
@if($errors->any())
    <div class="status-banner"><span class="badge danger">{{ $errors->first() }}</span></div>
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

<form id="broadcast-bulk-delete-form" method="post" action="{{ route('center-support.broadcast.destroyMany') }}" onsubmit="return confirm('Hapus semua pesan terpilih?')">
    @csrf
    @method('DELETE')
</form>

<section class="card qris-panel section">
    <div class="qris-toolbar">
        <h2>History Broadcast</h2>
        <button class="btn compact-btn danger" type="submit" form="broadcast-bulk-delete-form" id="broadcast-bulk-delete-btn" disabled>Hapus Terpilih</button>
    </div>
    <div class="table-wrap">
        <table class="table qris-table broadcast-table">
            <thead><tr><th class="broadcast-check-col"><input type="checkbox" id="broadcast-select-all" aria-label="Pilih semua"></th><th>Waktu</th><th>Grup</th><th>Pesan</th><th>Pengirim</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
                @forelse($history as $row)
                    <tr class="{{ $row->status === 'deleted' ? 'is-deleted' : '' }}">
                        <td class="broadcast-check-col" data-label="">
                            @if($row->status === 'sent')
                                <input type="checkbox" class="broadcast-row-check" name="ids[]" value="{{ $row->id }}" form="broadcast-bulk-delete-form">
                            @endif
                        </td>
                        <td data-label="Waktu">{{ $row->created_at->format('d M Y H:i') }}</td>
                        <td data-label="Grup"><strong>{{ $row->group_name }}</strong></td>
                        <td data-label="Pesan">
                            <div class="broadcast-msg-preview">{{ $row->message }}</div>
                            <div class="approval-modal" id="broadcast-msg-{{ $row->id }}" hidden>
                                <div class="approval-modal-card">
                                    <div class="qris-toolbar">
                                        <div><h2>Pesan Broadcast</h2><p class="muted" style="margin:4px 0 0">{{ $row->group_name }} &middot; {{ $row->created_at->format('d M Y H:i') }}</p></div>
                                        <button class="btn compact-btn approval-detail-close" type="button" aria-label="Tutup" style="font-size:18px; line-height:1; padding:4px 12px">&times;</button>
                                    </div>
                                    <div class="pad broadcast-msg-full">{{ $row->message }}</div>
                                </div>
                            </div>
                        </td>
                        <td data-label="Pengirim">{{ $row->sender?->name ?: '-' }}</td>
                        <td data-label="Status">
                            @if($row->status === 'sent')<span class="badge ok">&bull; Terkirim</span>
                            @elseif($row->status === 'deleted')<span class="badge">&bull; Dihapus</span>
                            @else<span class="badge danger">&bull; Gagal</span>@endif
                        </td>
                        <td data-label="Aksi">
                            <div class="broadcast-actions">
                                <button type="button" class="btn compact-btn" data-approval-detail="broadcast-msg-{{ $row->id }}">Detail</button>
                                @if($row->status === 'sent')
                                    <form method="post" action="{{ route('center-support.broadcast.destroy', $row) }}" onsubmit="return confirm('Hapus pesan ini dari grup {{ $row->group_name }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn compact-btn danger">Hapus</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">Belum ada broadcast.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="qris-pagination pad">
        <div class="pager-summary">Menampilkan {{ $history->count() }} data</div>
        <div class="pager-links">
            @if($history->onFirstPage())<span class="pager disabled">Prev</span>@else<a class="pager" href="{{ $history->previousPageUrl() }}">Prev</a>@endif
            @if($history->hasMorePages())<a class="pager" href="{{ $history->nextPageUrl() }}">Next</a>@else<span class="pager disabled">Next</span>@endif
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script>
(function () {
    var selectAll = document.getElementById('broadcast-select-all');
    var bulkDeleteBtn = document.getElementById('broadcast-bulk-delete-btn');
    if (!selectAll || !bulkDeleteBtn) return;

    var rowChecks = function () { return document.querySelectorAll('.broadcast-row-check'); };
    var syncBulkDeleteBtn = function () {
        bulkDeleteBtn.disabled = ! Array.prototype.some.call(rowChecks(), function (checkbox) { return checkbox.checked; });
    };

    selectAll.addEventListener('change', function () {
        rowChecks().forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
        syncBulkDeleteBtn();
    });
    rowChecks().forEach(function (checkbox) {
        checkbox.addEventListener('change', syncBulkDeleteBtn);
    });
})();
</script>
@endpush
