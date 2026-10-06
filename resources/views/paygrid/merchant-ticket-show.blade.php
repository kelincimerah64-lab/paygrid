@extends('layouts.paygrid')

@php
    $statusLabel = fn ($status) => App\Support\PayGridLabels::status($status);
    $statusClass = fn ($status) => match ($status) {
        'closed' => 'ok',
        'in_progress' => 'warn',
        default => 'danger',
    };
    $deptLabel = app(App\Services\MerchantTicketService::class)->departmentLabel($ticket->department);
    $categoryLabel = app(App\Services\MerchantTicketService::class)->categoryLabel($ticket->department, $ticket->category);
    $approvalLabel = match ($ticket->approval_status) { 'waiting' => 'Menunggu Approval', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', default => null };
    $approvalClass = match ($ticket->approval_status) { 'approved' => 'ok', 'rejected' => 'danger', default => 'warn' };
    $ticketsService = app(App\Services\MerchantTicketService::class);
    $readersFor = function ($message) use ($views) {
        return $views->filter(fn ($v) => $v->user_id !== $message->user_id && $v->last_viewed_at->gte($message->created_at))
            ->map(fn ($v) => $v->user?->name)->filter()->values();
    };
@endphp

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">{{ $merchant->name }}</div>
        <h1>{{ $ticket->ticket_no }}</h1>
    </div>
    <a class="btn compact-btn" href="{{ route('merchant.tickets.index', $merchant) }}">Kembali</a>
</div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Detail Tiket</h2><span class="badge {{ $statusClass($ticket->status) }}">{{ $statusLabel($ticket->status) }}</span>@if($approvalLabel)<span class="badge {{ $approvalClass }}" style="margin-left:6px">{{ $approvalLabel }}</span>@endif</div>
    <div class="approval-detail-grid">
        <div class="fee-pill"><span>Tujuan</span><strong>{{ $deptLabel }}</strong></div>
        <div class="fee-pill"><span>Menu</span><strong>{{ $categoryLabel }}</strong></div>
        <div class="fee-pill"><span>Dibuat</span><strong>{{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M Y H:i') }}</strong></div>
    </div>
    @if($ticket->approval_status === 'rejected' && $ticket->approval_note)
        <p class="muted" style="margin-top:8px"><strong>Alasan penolakan:</strong> {{ $ticket->approval_note }}</p>
    @endif
    @if(!empty($ticket->metadata))
        <div class="approval-detail-grid" style="margin-top:8px">
            @foreach($ticket->metadata as $key => $value)
                @continue($value === null || $value === '')
                <div class="fee-pill"><span>{{ $ticketsService->fieldLabel($ticket->category, $key) }}</span><strong>{{ $ticketsService->fieldType($ticket->category, $key) === 'number' ? 'Rp'.number_format((float) $value, 0, ',', '.') : $value }}</strong></div>
            @endforeach
        </div>
    @endif
    @if($ticket->title)
        <h3 style="margin:12px 0 4px">{{ $ticket->title }}</h3>
    @endif
    <p style="margin-top:4px; white-space:pre-wrap">{{ $ticket->description }}</p>
    @foreach($ticket->attachments ?? [] as $index => $file)
        <a class="btn compact-btn" style="margin-right:6px" href="{{ route('merchant.tickets.attachment', [$merchant, $ticket, $index]) }}">Lampiran {{ $index + 1 }}</a>
    @endforeach
</section>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Percakapan</h2></div>
    <div class="ticket-thread">
        @forelse($ticket->messages as $message)
            @php $isMine = $message->user_id === auth()->id(); $readers = $readersFor($message); @endphp
            <div class="ticket-message {{ $message->is_staff ? 'staff' : 'store' }}">
                <div class="ticket-message-meta"><strong>{{ $message->is_staff ? $deptLabel : ($message->user->name ?? 'Toko') }}</strong><span class="muted">{{ $message->created_at->timezone('Asia/Jakarta')->format('d M Y H:i') }}@if($message->edited_at) &middot; diedit @endif @if($isMine) &middot; <button type="button" class="ticket-message-edit-trigger" data-edit-trigger>Edit</button>@endif</span></div>
                <div class="ticket-message-body" data-msg-body>{{ $message->body }}</div>
                @if($isMine)
                    <form method="post" action="{{ route('merchant.tickets.messages.update', [$merchant, $ticket, $message]) }}" class="ticket-message-edit-form" hidden>
                        @csrf @method('PATCH')
                        <textarea name="body" maxlength="2000" required>{{ $message->body }}</textarea>
                        <div class="ticket-message-edit-actions">
                            <button type="submit" class="primary">Simpan</button>
                            <button type="button" class="cancel" data-edit-cancel>Batal</button>
                        </div>
                    </form>
                @endif
                @if($readers->isNotEmpty())
                    <span class="ticket-message-read">&#128065; Dibaca: {{ $readers->join(', ') }}</span>
                @endif
            </div>
        @empty
            <p class="muted">Belum ada balasan.</p>
        @endforelse
    </div>

    @if($ticket->status !== 'closed')
        <form method="post" action="{{ route('merchant.tickets.reply', [$merchant, $ticket]) }}" class="ticket-reply-form">
            @csrf
            <textarea name="body" rows="3" maxlength="2000" required placeholder="Tulis balasan..."></textarea>
            <button class="btn primary compact-btn" type="submit">Kirim Balasan</button>
        </form>
    @else
        <p class="muted" style="margin-top:12px">Tiket ini sudah ditutup.</p>
    @endif
</section>
@endsection

@push('scripts')
<script>
(function () {
    function autoGrow(textarea) {
        textarea.style.height = 'auto';
        textarea.style.height = textarea.scrollHeight + 'px';
    }

    document.querySelectorAll('[data-edit-trigger]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var msg = btn.closest('.ticket-message');
            if (!msg) return;
            var body = msg.querySelector('[data-msg-body]');
            var form = msg.querySelector('.ticket-message-edit-form');
            if (body) body.hidden = true;
            if (form) {
                form.hidden = false;
                var textarea = form.querySelector('textarea');
                if (textarea) {
                    autoGrow(textarea);
                    textarea.addEventListener('input', function () { autoGrow(textarea); });
                    textarea.focus();
                    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
                }
            }
        });
    });
    document.querySelectorAll('[data-edit-cancel]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var msg = btn.closest('.ticket-message');
            if (!msg) return;
            var body = msg.querySelector('[data-msg-body]');
            var form = msg.querySelector('.ticket-message-edit-form');
            if (form) form.hidden = true;
            if (body) body.hidden = false;
        });
    });
})();
</script>
@endpush
