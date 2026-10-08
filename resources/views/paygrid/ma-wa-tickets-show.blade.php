@extends('layouts.paygrid')

@php
    $statusLabel = fn ($status) => App\Support\PayGridLabels::status($status);
    $statusPillClass = match ($ticket->status) {
        'closed' => 'closed',
        'in_progress' => 'progress',
        default => 'open',
    };
    $toToko = $ticket->messages->where('is_internal', false);
    $internal = $ticket->messages->where('is_internal', true);
    $initials = fn (?string $name) => $name ? strtoupper(substr(trim($name), 0, 1).substr(trim(strrchr(' '.$name, ' ')), 1, 1)) : '?';
    $readersFor = function ($message) use ($views) {
        return $views->filter(fn ($v) => $v->user_id !== $message->user_id && $v->last_viewed_at->gte($message->created_at))
            ->map(fn ($v) => $v->user?->name)->filter()->values();
    };
@endphp

@section('content')
<div data-live-root data-live-interval="15000">
<div class="wat-hero">
    <div>
        <p class="eyebrow">{{ $ticket->merchant?->name ?: '-' }} &middot; {{ ucfirst($ticket->department) }}</p>
        <h1>{{ $ticket->ticket_no }}</h1>
    </div>
    <div style="display:flex; align-items:center; gap:10px" data-live-region="wat-hero-status">
        <span class="wat-pill {{ $statusPillClass }}">{{ $statusLabel($ticket->status) }}</span>
        @if(in_array(auth()->user()->role, ['ma', 'superadmin'], true))
            <a class="btn compact-btn" href="{{ route('ma.wa-tickets.index') }}">&larr; Kembali</a>
        @elseif(auth()->user()->role === 'approver')
            <a class="btn compact-btn" href="{{ route('wa-tickets.pending') }}">&larr; Kembali</a>
        @else
            <a class="btn compact-btn" href="{{ route('dept-tickets.index') }}">&larr; Kembali</a>
        @endif
    </div>
</div>

<div data-live-region="wat-status">
@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<section class="card pad">
    <div class="wat-meta-row">
        <div class="wat-meta-chip"><span>Kategori</span><strong>{{ $ticket->category }}</strong></div>
        <div class="wat-meta-chip"><span>Dibuat</span><strong>{{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB</strong></div>
        @foreach($ticket->metadata ?? [] as $key => $value)
            <div class="wat-meta-chip"><span>{{ ucfirst(str_replace('_', ' ', $key)) }}</span><strong>{{ is_numeric($value) ? 'Rp '.number_format((float) $value, 0, ',', '.') : $value }}</strong></div>
        @endforeach
    </div>
    <p style="margin:0; white-space:pre-wrap; font-size:13.5px; color:var(--ink)">{{ $ticket->description }}</p>

    @if($ticket->approval_status === 'waiting')
        <div class="wat-approval-card waiting">
            <div class="wat-approval-text"><b>&#9203; Menunggu Approval</b><span>Tiket ini butuh persetujuan sebelum bisa dikerjakan.</span></div>
            @if($canApprove && !$isCreator)
                <div class="wat-approval-actions">
                    <form method="post" action="{{ route('wa-tickets.approve', $ticket) }}" data-live-form>@csrf<button class="wat-btn approve" type="submit">&#10003; Approve</button></form>
                    <form method="post" action="{{ route('wa-tickets.reject', $ticket) }}" data-live-form>@csrf<button class="wat-btn reject" type="submit">&#10005; Reject</button></form>
                </div>
            @elseif($canApprove && $isCreator)
                <span class="wat-pill">Tidak bisa approve tiket buatan sendiri</span>
            @else
                <span class="wat-pill">Menunggu approval dari tim Approval</span>
            @endif
        </div>
    @elseif($ticket->approval_status === 'rejected')
        <div class="wat-approval-card rejected">
            <div class="wat-approval-text"><b>&#10005; Ditolak</b><span>Oleh {{ $ticket->approval_by }}{{ $ticket->approval_note ? ' — '.$ticket->approval_note : '' }}</span></div>
        </div>
    @else
        @if($ticket->approval_status === 'approved')
            <div style="margin:14px 0 0"><span class="wat-pill approved">&#10003; Disetujui oleh {{ $ticket->approval_by }}</span></div>
        @endif

        @if(!$ticket->claimed_by_user_id)
            <div class="wat-claim-box">
                <div class="wat-claim-who"><div class="wat-avatar">?</div><div><b>Belum ada yang pegang</b><span>Tiket ini masih di antrean</span></div></div>
                @unless(auth()->user()->role === 'approver')
                    <form method="post" action="{{ route('wa-tickets.claim', $ticket) }}" data-live-form>
                        @csrf
                        <button class="wat-btn primary" type="submit" @disabled($ticket->status === 'closed')>Ambil Tiket Ini</button>
                    </form>
                @endunless
            </div>
        @else
            <div class="wat-claim-box held">
                <div class="wat-claim-who">
                    <div class="wat-avatar">{{ $initials($ticket->claimedBy?->name) }}</div>
                    <div><b>{{ $ticket->claimedBy?->name }}</b><span>Diambil {{ $ticket->claimed_at?->timezone('Asia/Jakarta')->diffForHumans() }}</span></div>
                </div>
                @if($ticket->status !== 'closed' && auth()->user()->role !== 'approver')
                    <form method="post" action="{{ route('wa-tickets.transfer', $ticket) }}" class="wat-transfer-form" data-live-form>
                        @csrf
                        <select name="to_user_id">
                            <option value="">&#8634; Lepas ke Antrean</option>
                            @foreach($teammates as $mate)
                                <option value="{{ $mate->id }}" @selected($mate->id === $ticket->claimed_by_user_id)>{{ $mate->name }}</option>
                            @endforeach
                        </select>
                        <button class="wat-btn outline" type="submit">&#8646; Lempar Tiket</button>
                    </form>
                @endif
            </div>
        @endif
    @endif
</section>

@if($ticket->status !== 'closed' && $ticket->approval_status !== 'waiting' && auth()->user()->role !== 'approver')
<section class="wat-panel">
    <div class="wat-panel-head"><h2>Tandai Selesai</h2></div>
    <form method="post" action="{{ route('wa-tickets.close', $ticket) }}" class="wat-close-card" data-live-form>
        @csrf
        <textarea name="note" rows="2" maxlength="2000" placeholder="Catatan penutup buat toko (opsional)..."></textarea>
        <div><button class="wat-btn success" type="submit">&#10003; Tandai Selesai &amp; Tutup Tiket</button></div>
    </form>
</section>
@endif
</div>

<div data-live-region="wat-thread">
<section class="wat-panel">
    <div class="wat-panel-head">
        <h2>Percakapan</h2>
        <div class="wat-tabs">
            <button class="wat-tab active" type="button" data-wa-tab="toko">Ke Toko</button>
            <button class="wat-tab" type="button" data-wa-tab="internal">&#128274; Internal</button>
        </div>
    </div>

    <div class="wat-filter-bar">
        <input type="search" class="wat-filter-search" name="wat_search" data-preserve-key="wat-search" placeholder="Cari teks pesan...">
        <input type="date" class="wat-filter-date" name="wat_filter_date" data-preserve-key="wat-filter-date">
        <button type="button" class="wat-filter-reset">Reset</button>
    </div>

    <div data-wa-panel="toko">
        <div class="wat-thread" data-live-scroll data-live-background-ok>
            @forelse($toToko as $message)
                @php $isMine = $message->user_id === auth()->id(); $readers = $readersFor($message); @endphp
                <div class="wat-msg {{ $message->is_staff === false ? 'in' : 'out' }}" data-date="{{ $message->created_at->timezone('Asia/Jakarta')->format('Y-m-d') }}">
                    <span class="wat-msg-who">{{ $message->is_staff === false ? ($message->user->name ?? 'Toko') : ($message->user->name ?? 'Tim '.ucfirst($ticket->department)) }}</span>
                    <span class="wat-msg-body" data-msg-body>{{ $message->body }}</span>
                    @if($isMine)
                        <form method="post" action="{{ route('wa-tickets.messages.update', [$ticket, $message]) }}" class="wat-msg-edit-form" data-live-form hidden>
                            @csrf @method('PATCH')
                            <textarea name="body" maxlength="2000" required>{{ $message->body }}</textarea>
                            <div class="wat-msg-edit-actions">
                                <button type="submit" class="wat-btn compact-btn primary">Simpan</button>
                                <button type="button" class="wat-btn compact-btn wat-msg-edit-cancel">Batal</button>
                            </div>
                        </form>
                    @endif
                    <span class="wat-msg-time">{{ $message->created_at->timezone('Asia/Jakarta')->format('d M, H:i') }}@if($message->edited_at) &middot; <em>diedit</em>@endif @if($isMine) &middot; <button type="button" class="wat-msg-edit-trigger">Edit</button>@endif</span>
                    @if($readers->isNotEmpty())
                        <span class="wat-msg-read">&#128065; Dibaca: {{ $readers->join(', ') }}</span>
                    @endif
                </div>
            @empty
                <p class="wat-empty">Belum ada pesan ke toko.</p>
            @endforelse
            <p class="wat-empty wat-filter-empty" hidden>Tidak ada pesan yang cocok dengan filter.</p>
            <button type="button" class="wat-new-msg-banner" data-new-msg-banner hidden>&#8595; Pesan baru</button>
        </div>
        @if($ticket->status !== 'closed' && $ticket->approval_status !== 'waiting' && auth()->user()->role !== 'approver')
            @if($ticket->claimed_by_user_id)
                <form method="post" action="{{ route('wa-tickets.reply', $ticket) }}" class="wat-composer" data-live-form data-scroll-to-bottom>
                    @csrf
                    <input type="hidden" name="is_internal" value="0">
                    <textarea name="body" data-preserve-key="wat-reply-toko" data-live-background-ok maxlength="2000" required placeholder="Tulis update buat toko..."></textarea>
                    <button class="wat-btn primary" type="submit">Kirim</button>
                </form>
            @else
                <p class="wat-empty" style="padding:14px 20px">Ambil tiket ini dulu sebelum kirim pesan ke toko.</p>
            @endif
        @endif
    </div>

    <div data-wa-panel="internal" hidden>
        <div class="wat-thread" data-live-scroll data-live-background-ok>
            @forelse($internal as $message)
                @php $isMine = $message->user_id === auth()->id(); $readers = $readersFor($message); @endphp
                <div class="wat-msg internal {{ $isMine ? 'mine' : '' }}" data-date="{{ $message->created_at->timezone('Asia/Jakarta')->format('Y-m-d') }}">
                    <span class="wat-msg-who">{{ $message->user->name ?? 'Sistem' }}</span>
                    <span class="wat-msg-body" data-msg-body>{{ $message->body }}</span>
                    @if($isMine)
                        <form method="post" action="{{ route('wa-tickets.messages.update', [$ticket, $message]) }}" class="wat-msg-edit-form" data-live-form hidden>
                            @csrf @method('PATCH')
                            <textarea name="body" maxlength="2000" required>{{ $message->body }}</textarea>
                            <div class="wat-msg-edit-actions">
                                <button type="submit" class="wat-btn compact-btn primary">Simpan</button>
                                <button type="button" class="wat-btn compact-btn wat-msg-edit-cancel">Batal</button>
                            </div>
                        </form>
                    @endif
                    <span class="wat-msg-time">{{ $message->created_at->timezone('Asia/Jakarta')->format('d M, H:i') }}@if($message->edited_at) &middot; <em>diedit</em>@endif @if($isMine) &middot; <button type="button" class="wat-msg-edit-trigger">Edit</button>@endif</span>
                    @if($readers->isNotEmpty())
                        <span class="wat-msg-read">&#128065; Dibaca: {{ $readers->join(', ') }}</span>
                    @endif
                </div>
            @empty
                <p class="wat-empty">Belum ada diskusi internal. Cuma tim CS yang lihat ini, toko nggak bisa baca.</p>
            @endforelse
            <p class="wat-empty wat-filter-empty" hidden>Tidak ada pesan yang cocok dengan filter.</p>
            <button type="button" class="wat-new-msg-banner" data-new-msg-banner hidden>&#8595; Pesan baru</button>
        </div>
        @if($ticket->status !== 'closed' && $ticket->approval_status !== 'waiting' && auth()->user()->role !== 'approver')
            <form method="post" action="{{ route('wa-tickets.reply', $ticket) }}" class="wat-composer internal" data-live-form data-scroll-to-bottom>
                @csrf
                <input type="hidden" name="is_internal" value="1">
                <textarea name="body" data-preserve-key="wat-reply-internal" data-live-background-ok maxlength="2000" required placeholder="Diskusi internal (toko nggak lihat)..."></textarea>
                <button class="wat-btn outline" type="submit">Kirim</button>
            </form>
        @endif
    </div>
</section>
</div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/paygrid-realtime.js') }}?v={{ filemtime(public_path('js/paygrid-realtime.js')) }}"></script>
<script>
(function () {
    var root = document.querySelector('[data-live-root]');
    var lastMsgCounts = { toko: null, internal: null };
    var audioCtx = null;

    function autoGrow(textarea) {
        textarea.style.height = 'auto';
        textarea.style.height = textarea.scrollHeight + 'px';
    }

    function setupMessageEdit() {
        document.querySelectorAll('.wat-msg-edit-trigger').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var msg = btn.closest('.wat-msg');
                if (!msg) return;
                var body = msg.querySelector('[data-msg-body]');
                var time = msg.querySelector('.wat-msg-time');
                var form = msg.querySelector('.wat-msg-edit-form');
                if (body) body.hidden = true;
                if (time) time.hidden = true;
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
        document.querySelectorAll('.wat-msg-edit-cancel').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var msg = btn.closest('.wat-msg');
                if (!msg) return;
                var body = msg.querySelector('[data-msg-body]');
                var time = msg.querySelector('.wat-msg-time');
                var form = msg.querySelector('.wat-msg-edit-form');
                if (form) form.hidden = true;
                if (body) body.hidden = false;
                if (time) time.hidden = false;
            });
        });
    }

    function playNotifSound() {
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            var osc = audioCtx.createOscillator();
            var gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.value = 880;
            gain.gain.setValueAtTime(0.18, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.35);
            osc.connect(gain).connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.35);
        } catch (e) { /* autoplay blocked or unsupported - silently skip */ }
    }

    function isNearBottom(el) {
        return el.scrollHeight - el.scrollTop - el.clientHeight < 60;
    }

    function setupThreadNotifications() {
        ['toko', 'internal'].forEach(function (key) {
            var panel = document.querySelector('[data-wa-panel="' + key + '"]');
            var thread = panel && panel.querySelector('.wat-thread');
            var banner = panel && panel.querySelector('[data-new-msg-banner]');
            if (!thread) return;

            var count = thread.querySelectorAll('.wat-msg').length;
            var isNew = lastMsgCounts[key] !== null && count > lastMsgCounts[key];
            lastMsgCounts[key] = count;

            if (isNew) {
                playNotifSound();
                if (isNearBottom(thread)) {
                    thread.scrollTop = thread.scrollHeight;
                } else if (banner) {
                    banner.hidden = false;
                }
            }

            if (banner && !banner.dataset.ready) {
                banner.dataset.ready = 'true';
                banner.addEventListener('click', function () {
                    thread.scrollTop = thread.scrollHeight;
                    banner.hidden = true;
                });
            }
            if (banner) {
                thread.addEventListener('scroll', function () {
                    if (isNearBottom(thread)) banner.hidden = true;
                });
            }
        });
    }

    function setupTabs() {
        var buttons = document.querySelectorAll('[data-wa-tab]');
        var panels = document.querySelectorAll('[data-wa-panel]');
        var activeKey = (root && root.dataset.waActiveTab) || 'toko';
        buttons.forEach(function (btn) {
            btn.classList.toggle('active', btn.dataset.waTab === activeKey);
            btn.addEventListener('click', function () {
                if (root) root.dataset.waActiveTab = btn.dataset.waTab;
                buttons.forEach(function (b) { b.classList.toggle('active', b === btn); });
                panels.forEach(function (p) { p.hidden = p.dataset.waPanel !== btn.dataset.waTab; });
            });
        });
        panels.forEach(function (p) { p.hidden = p.dataset.waPanel !== activeKey; });
    }

    function applyFilter() {
        var search = document.querySelector('.wat-filter-search');
        var dateInput = document.querySelector('.wat-filter-date');
        if (!search || !dateInput) return;
        var query = search.value.trim().toLowerCase();
        var date = dateInput.value;
        document.querySelectorAll('[data-wa-panel]').forEach(function (panel) {
            var messages = panel.querySelectorAll('.wat-msg');
            var hadMessages = messages.length > 0;
            var visibleCount = 0;
            messages.forEach(function (msg) {
                var show = (!query || msg.textContent.toLowerCase().includes(query)) && (!date || msg.dataset.date === date);
                msg.hidden = !show;
                if (show) visibleCount++;
            });
            var emptyFilterMsg = panel.querySelector('.wat-filter-empty');
            if (emptyFilterMsg) emptyFilterMsg.hidden = !(hadMessages && visibleCount === 0 && (query || date));
        });
    }

    function setupFilter() {
        var search = document.querySelector('.wat-filter-search');
        var dateInput = document.querySelector('.wat-filter-date');
        var reset = document.querySelector('.wat-filter-reset');
        if (search && !search.dataset.filterReady) {
            search.dataset.filterReady = 'true';
            search.addEventListener('input', applyFilter);
        }
        if (dateInput && !dateInput.dataset.filterReady) {
            dateInput.dataset.filterReady = 'true';
            dateInput.addEventListener('change', applyFilter);
        }
        if (reset && !reset.dataset.filterReady) {
            reset.dataset.filterReady = 'true';
            reset.addEventListener('click', function () {
                search.value = '';
                dateInput.value = '';
                applyFilter();
            });
        }
        applyFilter();
    }

    setupTabs();
    setupFilter();
    setupThreadNotifications();
    setupMessageEdit();
    if (root) root.addEventListener('paygrid:refreshed', function () {
        setupTabs();
        setupFilter();
        setupThreadNotifications();
        setupMessageEdit();
    });

    if (root && window.PayGridRealtime) {
        var realtime = window.PayGridRealtime.connect({
            key: @json(config('broadcasting.connections.reverb.key')),
            host: @json(config('broadcasting.connections.reverb.options.host')),
            port: @json(config('broadcasting.connections.reverb.options.port')),
            scheme: @json(config('broadcasting.connections.reverb.options.scheme')),
        });
        realtime.subscribePrivate('private-ticket.{{ $ticket->id }}', 'message.sent', function () {
            root.dispatchEvent(new CustomEvent('paygrid:request-refresh'));
        });
    }
})();
</script>
@endpush
