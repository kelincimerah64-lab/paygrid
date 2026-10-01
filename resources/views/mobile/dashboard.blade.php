<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>PayGrid Toko - {{ $merchant->name }}</title>
<link rel="manifest" href="{{ asset('mobile-manifest.json') }}">
<link rel="apple-touch-icon" href="{{ asset('images/mobile-icon-apple.png') }}">
<meta name="theme-color" content="#1557c2">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="PayGrid Toko">
<meta name="mobile-web-app-capable" content="yes">
@php
    $money = fn ($value) => 'Rp '.number_format((int) ($value ?? 0), 0, ',', '.');
    $badge = fn ($status) => App\Support\PayGridLabels::badge($status);
    $qs = fn (array $overrides) => http_build_query(array_merge(['tab' => $tab, 'from' => $from, 'to' => $to, 'status' => $status], $overrides));
    $withdrawalBadge = fn ($status) => match ($status) {
        'COMPLETED' => 'ok',
        'FAILED' => 'danger',
        default => 'warn',
    };
    $withdrawalLabel = fn ($status) => match ($status) {
        'COMPLETED' => 'Selesai',
        'FAILED' => 'Gagal',
        'PENDING' => 'Pending',
        default => $status,
    };
@endphp
<style>
    :root { --blue:#1557c2; --ink:#06162f; --muted:#55657a; --line:#dbe5f2; --bg:#f5f8fc; --success:#008450; --warn:#b15a00; --danger:#c62828; --soft:#eef4fb; }
    * { box-sizing:border-box; }
    body { margin:0; font-family:"Plus Jakarta Sans", Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Arial, sans-serif; background:var(--bg); color:var(--ink); padding-bottom:30px; }
    header { position:sticky; top:0; z-index:5; background:#fff; border-bottom:1px solid var(--line); padding:14px 16px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
    header .name { font-size:15px; font-weight:900; line-height:1.2; }
    header .sub { font-size:11px; color:var(--muted); font-weight:700; }
    header form { margin:0; }
    header button { border:1px solid var(--line); background:#fff; color:var(--muted); font-size:11px; font-weight:800; padding:7px 12px; border-radius:8px; }
    .tabs { position:sticky; top:53px; z-index:4; display:grid; grid-template-columns:1fr 1fr 1fr; gap:6px; background:#fff; border-bottom:1px solid var(--line); padding:10px 14px; }
    .tab { text-align:center; padding:9px 6px; border-radius:8px; font-size:12px; font-weight:800; color:var(--muted); text-decoration:none; background:var(--soft); }
    .tab.active { background:var(--blue); color:#fff; }
    main { padding:14px; max-width:520px; margin:0 auto; }
    .period-form { display:flex; gap:8px; margin-bottom:14px; }
    .period-form input { flex:1 1 0; min-width:0; padding:9px 10px; border:1px solid var(--line); border-radius:8px; font-size:12.5px; font-family:inherit; background:#fff; }
    .period-form button { flex:0 0 auto; padding:9px 14px; border:none; border-radius:8px; background:var(--blue); color:#fff; font-size:12.5px; font-weight:800; }
    .cards { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .card { display:block; text-decoration:none; color:inherit; background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px; }
    .card.active { border-color:var(--blue); box-shadow:0 0 0 2px rgba(21,87,194,.15); }
    .card .label { font-size:10.5px; letter-spacing:.04em; text-transform:uppercase; font-weight:900; color:var(--muted); }
    .card .value { font-size:22px; font-weight:900; margin-top:5px; }
    .card .amount { font-size:11.5px; color:var(--muted); font-weight:700; margin-top:3px; }
    .card.success .value, .card.completed .value { color:var(--success); }
    .card.pending .value { color:var(--warn); }
    .card.expired .value, .card.failed .value { color:var(--danger); }
    .card.tickets .value { color:var(--blue); }
    section.list { margin-top:18px; }
    .list-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .list-head h2 { font-size:14px; margin:0; }
    .chips { display:flex; gap:6px; overflow-x:auto; margin-bottom:12px; padding-bottom:2px; }
    .chip { flex:0 0 auto; padding:6px 12px; border-radius:999px; border:1px solid var(--line); background:#fff; font-size:11.5px; font-weight:800; color:var(--muted); text-decoration:none; }
    .chip.active { background:var(--blue); border-color:var(--blue); color:#fff; }
    .trx { background:#fff; border:1px solid var(--line); border-radius:10px; padding:12px 14px; margin-bottom:8px; }
    .trx-row { display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .trx-amount { font-size:15px; font-weight:900; }
    .trx-time { font-size:11px; color:var(--muted); font-weight:700; }
    .trx-ref { font-size:11px; color:var(--muted); margin-top:4px; word-break:break-all; }
    .trx-sub { font-size:11.5px; color:var(--muted); margin-top:2px; }
    .badge { display:inline-block; padding:3px 9px; border-radius:999px; font-size:10.5px; font-weight:800; }
    .badge.ok { background:#e5f6ee; color:var(--success); }
    .badge.warn { background:#fdf1e1; color:var(--warn); }
    .badge.danger { background:#fdecec; color:var(--danger); }
    .badge.muted { background:var(--soft); color:var(--muted); }
    .empty { text-align:center; color:var(--muted); font-size:13px; padding:30px 0; }
    .pager { display:flex; justify-content:space-between; margin-top:14px; }
    .pager a, .pager span { padding:8px 16px; border-radius:8px; border:1px solid var(--line); background:#fff; font-size:12.5px; font-weight:800; text-decoration:none; color:var(--ink); }
    .pager span.disabled { color:var(--muted); opacity:.5; }
</style>
</head>
<body>
    <header>
        <div>
            <div class="name">{{ $merchant->name }}</div>
            <div class="sub">PayGrid Toko</div>
        </div>
        <form method="post" action="{{ route('mobile.logout') }}">
            @csrf
            <button type="submit">Keluar</button>
        </form>
    </header>

    <div class="tabs">
        <a class="tab {{ $tab === 'trx' ? 'active' : '' }}" href="?{{ http_build_query(['tab' => 'trx', 'from' => $from, 'to' => $to]) }}#top">Transaksi</a>
        <a class="tab {{ $tab === 'disbursement' ? 'active' : '' }}" href="?{{ http_build_query(['tab' => 'disbursement', 'from' => $from, 'to' => $to]) }}#top">Disbursement</a>
        <a class="tab {{ $tab === 'tiket' ? 'active' : '' }}" href="?{{ http_build_query(['tab' => 'tiket', 'from' => $from, 'to' => $to]) }}#top">Tiket</a>
    </div>

    <main id="top">
        <form class="period-form" method="get">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <input type="hidden" name="status" value="{{ $status }}">
            <input type="date" name="from" value="{{ $from }}" placeholder="Dari">
            <input type="date" name="to" value="{{ $to }}" placeholder="Sampai">
            <button type="submit">Terapkan</button>
        </form>

        @if($tab === 'trx')
            <div class="cards">
                <a class="card success {{ $status === 'success' ? 'active' : '' }}" href="?{{ $qs(['status' => 'success']) }}#trx-list">
                    <div class="label">Sukses</div>
                    <div class="value">{{ number_format($stats['success'], 0, ',', '.') }}</div>
                    <div class="amount">{{ $money($stats['success_amount']) }}</div>
                </a>
                <a class="card pending {{ $status === 'pending' ? 'active' : '' }}" href="?{{ $qs(['status' => 'pending']) }}#trx-list">
                    <div class="label">Pending</div>
                    <div class="value">{{ number_format($stats['pending'], 0, ',', '.') }}</div>
                    <div class="amount">{{ $money($stats['pending_amount']) }}</div>
                </a>
                <a class="card expired {{ $status === 'expired' ? 'active' : '' }}" href="?{{ $qs(['status' => 'expired']) }}#trx-list">
                    <div class="label">Gagal/Expired</div>
                    <div class="value">{{ number_format($stats['expired'], 0, ',', '.') }}</div>
                    <div class="amount">{{ $money($stats['expired_amount']) }}</div>
                </a>
            </div>

            <section class="list" id="trx-list">
                <div class="list-head"><h2>Transaksi</h2></div>
                <div class="chips">
                    <a class="chip {{ $status === 'all' ? 'active' : '' }}" href="?{{ $qs(['status' => 'all']) }}#trx-list">Semua</a>
                    <a class="chip {{ $status === 'success' ? 'active' : '' }}" href="?{{ $qs(['status' => 'success']) }}#trx-list">Sukses</a>
                    <a class="chip {{ $status === 'pending' ? 'active' : '' }}" href="?{{ $qs(['status' => 'pending']) }}#trx-list">Pending</a>
                    <a class="chip {{ $status === 'expired' ? 'active' : '' }}" href="?{{ $qs(['status' => 'expired']) }}#trx-list">Gagal/Expired</a>
                </div>

                @forelse($transactions as $trx)
                    <div class="trx">
                        <div class="trx-row">
                            <span class="trx-amount">{{ $money($trx->amount) }}</span>
                            <span class="badge {{ $badge($trx->status) }}">{{ App\Support\PayGridLabels::status($trx->status) }}</span>
                        </div>
                        <div class="trx-time">{{ $trx->submitted_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB</div>
                        <div class="trx-ref">{{ $trx->customer_reference ?: $trx->gateway_ref_id ?: '-' }}</div>
                    </div>
                @empty
                    <p class="empty">Belum ada transaksi buat filter ini.</p>
                @endforelse

                <div class="pager">
                    @if($transactions->onFirstPage())
                        <span class="disabled">&larr; Prev</span>
                    @else
                        <a href="{{ $transactions->previousPageUrl() }}#trx-list">&larr; Prev</a>
                    @endif
                    @if($transactions->hasMorePages())
                        <a href="{{ $transactions->nextPageUrl() }}#trx-list">Next &rarr;</a>
                    @else
                        <span class="disabled">Next &rarr;</span>
                    @endif
                </div>
            </section>
        @elseif($tab === 'disbursement')
            <div class="cards">
                <a class="card completed {{ $status === 'COMPLETED' ? 'active' : '' }}" href="?{{ $qs(['status' => 'COMPLETED']) }}#wd-list">
                    <div class="label">Selesai</div>
                    <div class="value">{{ number_format($withdrawalStats['completed'], 0, ',', '.') }}</div>
                    <div class="amount">{{ $money($withdrawalStats['completed_amount']) }}</div>
                </a>
                <a class="card pending {{ $status === 'PENDING' ? 'active' : '' }}" href="?{{ $qs(['status' => 'PENDING']) }}#wd-list">
                    <div class="label">Pending</div>
                    <div class="value">{{ number_format($withdrawalStats['pending'], 0, ',', '.') }}</div>
                    <div class="amount">{{ $money($withdrawalStats['pending_amount']) }}</div>
                </a>
                <a class="card failed {{ $status === 'FAILED' ? 'active' : '' }}" href="?{{ $qs(['status' => 'FAILED']) }}#wd-list">
                    <div class="label">Gagal</div>
                    <div class="value">{{ number_format($withdrawalStats['failed'], 0, ',', '.') }}</div>
                    <div class="amount">{{ $money($withdrawalStats['failed_amount']) }}</div>
                </a>
            </div>

            <section class="list" id="wd-list">
                <div class="list-head"><h2>Disbursement</h2></div>
                <div class="chips">
                    <a class="chip {{ $status === 'all' ? 'active' : '' }}" href="?{{ $qs(['status' => 'all']) }}#wd-list">Semua</a>
                    <a class="chip {{ $status === 'COMPLETED' ? 'active' : '' }}" href="?{{ $qs(['status' => 'COMPLETED']) }}#wd-list">Selesai</a>
                    <a class="chip {{ $status === 'PENDING' ? 'active' : '' }}" href="?{{ $qs(['status' => 'PENDING']) }}#wd-list">Pending</a>
                    <a class="chip {{ $status === 'FAILED' ? 'active' : '' }}" href="?{{ $qs(['status' => 'FAILED']) }}#wd-list">Gagal</a>
                </div>

                @forelse($withdrawals as $wd)
                    <div class="trx">
                        <div class="trx-row">
                            <span class="trx-amount">{{ $money($wd->net_amount) }}</span>
                            <span class="badge {{ $withdrawalBadge($wd->status) }}">{{ $withdrawalLabel($wd->status) }}</span>
                        </div>
                        <div class="trx-time">{{ $wd->gateway_created_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB</div>
                        <div class="trx-sub">{{ $wd->bank_name }} &middot; {{ $wd->account_name }}</div>
                        <div class="trx-ref">{{ $wd->ref_id }}</div>
                    </div>
                @empty
                    <p class="empty">Belum ada disbursement buat filter ini.</p>
                @endforelse

                <div class="pager">
                    @if($withdrawals->onFirstPage())
                        <span class="disabled">&larr; Prev</span>
                    @else
                        <a href="{{ $withdrawals->previousPageUrl() }}#wd-list">&larr; Prev</a>
                    @endif
                    @if($withdrawals->hasMorePages())
                        <a href="{{ $withdrawals->nextPageUrl() }}#wd-list">Next &rarr;</a>
                    @else
                        <span class="disabled">Next &rarr;</span>
                    @endif
                </div>
            </section>
        @else
            <div class="cards">
                <div class="card tickets">
                    <div class="label">Total</div>
                    <div class="value">{{ number_format($ticketStats['total'], 0, ',', '.') }}</div>
                </div>
                <a class="card pending {{ $status === 'open' ? 'active' : '' }}" href="?{{ $qs(['status' => 'open']) }}#tk-list">
                    <div class="label">Open</div>
                    <div class="value">{{ number_format($ticketStats['open'], 0, ',', '.') }}</div>
                </a>
                <a class="card success {{ $status === 'done' ? 'active' : '' }}" href="?{{ $qs(['status' => 'done']) }}#tk-list">
                    <div class="label">Selesai</div>
                    <div class="value">{{ number_format($ticketStats['done'], 0, ',', '.') }}</div>
                </a>
            </div>

            <section class="list" id="tk-list">
                <div class="list-head"><h2>Tiket</h2></div>
                <div class="chips">
                    <a class="chip {{ $status === 'all' ? 'active' : '' }}" href="?{{ $qs(['status' => 'all']) }}#tk-list">Semua</a>
                    <a class="chip {{ $status === 'open' ? 'active' : '' }}" href="?{{ $qs(['status' => 'open']) }}#tk-list">Open</a>
                    <a class="chip {{ $status === 'done' ? 'active' : '' }}" href="?{{ $qs(['status' => 'done']) }}#tk-list">Selesai</a>
                </div>

                @forelse($tickets as $ticket)
                    <div class="trx">
                        <div class="trx-row">
                            <span class="trx-amount">{{ $ticket->ticket_no }}</span>
                            <span class="badge {{ $badge($ticket->status) }}">{{ App\Support\PayGridLabels::status($ticket->status) }}</span>
                        </div>
                        <div class="trx-sub">{{ $ticket->issue }}</div>
                        <div class="trx-time">{{ $ticket->created_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB</div>
                    </div>
                @empty
                    <p class="empty">Belum ada tiket buat filter ini.</p>
                @endforelse

                <div class="pager">
                    @if($tickets->onFirstPage())
                        <span class="disabled">&larr; Prev</span>
                    @else
                        <a href="{{ $tickets->previousPageUrl() }}#tk-list">&larr; Prev</a>
                    @endif
                    @if($tickets->hasMorePages())
                        <a href="{{ $tickets->nextPageUrl() }}#tk-list">Next &rarr;</a>
                    @else
                        <span class="disabled">Next &rarr;</span>
                    @endif
                </div>
            </section>
        @endif
    </main>
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/js/mobile-sw.js');
        }
    </script>
</body>
</html>
