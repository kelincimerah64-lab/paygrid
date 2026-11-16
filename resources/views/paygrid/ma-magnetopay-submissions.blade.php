@extends('layouts.paygrid')

@php
    $badge = fn ($status) => App\Support\PayGridLabels::badge($status);
@endphp

@section('content')
<div class="page-head">
    <div>
        <h1>Submission Magneto</h1>
        <div class="sub">Semua submission form onboarding Magnetopay. Reassign Merchant Group atau lengkapi Merchant ID &amp; Secret Key di sini - begitu keduanya keisi di toko yang udah approved, data otomatis ketarik lewat sync yang jalan tiap 5 detik.</div>
    </div>
    <div class="page-actions"></div>
</div>

<section class="card filters section">
    <form method="get" data-auto-filter class="ma-magnetopay-filter-form">
        <input class="search" type="text" name="q" value="{{ $filters['q'] }}" placeholder="Cari nama toko...">
        <div class="actions">
            <select name="status">
                <option value="all" @selected($filters['status'] === 'all')>Semua Status</option>
                <option value="pending" @selected($filters['status'] === 'pending')>Pending</option>
                <option value="pending_agent" @selected($filters['status'] === 'pending_agent')>Pending Agen</option>
                <option value="pending_ma" @selected($filters['status'] === 'pending_ma')>Pending MA</option>
                <option value="approved" @selected($filters['status'] === 'approved')>Approved</option>
                <option value="rejected" @selected($filters['status'] === 'rejected')>Rejected</option>
            </select>
            <select name="agent_id">
                <option value="all" @selected($filters['agent_id'] === 'all')>Semua Merchant Group</option>
                @foreach($agents as $a)
                    <option value="{{ $a->id }}" @selected((string) $filters['agent_id'] === (string) $a->id)>{{ $a->name }}</option>
                @endforeach
            </select>
            <button class="btn primary">Filter</button>
            <a class="btn" href="{{ route('ma.magnetopay-submissions') }}">Reset</a>
        </div>
    </form>
</section>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Daftar Submission</h2><span class="badge ok">{{ $registrations->count() }} submission</span></div>
    <div class="table-wrap">
        <table class="table qris-table ma-magnetopay-table">
            <thead><tr><th>Toko</th><th>Status</th><th>Tanggal Submit</th><th>Merchant Group</th><th>Merchant ID</th><th>Secret Key</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse($registrations as $r)
                @php
                    $payload = (array) ($r->payload ?? []);
                    $existingKey = $r->merchant?->merchant_key ?: ($payload['merchant_key'] ?? $payload['hg_merchant_key'] ?? '');
                    $existingMerchantId = $r->merchant?->merchant_id ?: ($payload['merchant_id'] ?? $payload['hg_merchant_id'] ?? '');
                    $rowFormId = 'magnetopay-row-form-'.$r->id;
                @endphp
                <tr>
                    <td data-label="Toko"><strong>{{ $r->store_name }}</strong></td>
                    <td data-label="Status"><span class="badge {{ $badge($r->status) }}">&bull; {{ ucfirst(str_replace('_', ' ', $r->status)) }}</span></td>
                    <td data-label="Tanggal Submit">{{ $r->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }}</td>
                    <td data-label="Merchant Group">
                        <form id="{{ $rowFormId }}" method="post" action="{{ route('ma.magnetopay-submissions.update', $r) }}">@csrf</form>
                        <select name="agent_id" form="{{ $rowFormId }}">
                            <option value="">Belum ditentukan</option>
                            @foreach($agents as $a)
                                <option value="{{ $a->id }}" @selected((string) $r->agent_id === (string) $a->id)>{{ $a->name }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td data-label="Merchant ID">
                        <input type="text" name="gateway_merchant_id" value="{{ $existingMerchantId }}" placeholder="Merchant ID" form="{{ $rowFormId }}">
                    </td>
                    <td data-label="Secret Key">
                        <input type="text" name="merchant_key" value="{{ $existingKey }}" placeholder="Secret Key" form="{{ $rowFormId }}">
                    </td>
                    <td data-label="Aksi">
                        <button class="btn compact-btn primary" form="{{ $rowFormId }}">Simpan</button>
                        <button class="btn compact-btn approval-detail-open" type="button" data-approval-detail="magnetopay-submission-{{ $r->id }}">Detail</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">Belum ada submission Magnetopay.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>

@foreach($registrations as $r)
    <div class="approval-modal" id="magnetopay-submission-{{ $r->id }}" hidden>
        <div class="approval-modal-card">
            <div class="qris-toolbar">
                <div><h2>{{ $r->store_name }}</h2><p class="muted" style="margin:4px 0 0">Agen: {{ $r->agent?->name ?: '-' }} &mdash; <span class="badge {{ $badge($r->status) }}">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</span></p></div>
                <button class="btn compact-btn approval-detail-close" type="button">Tutup</button>
            </div>
            <div class="approval-detail-grid" style="margin-top:12px">
                @foreach($r->fullPayloadRowsForMa() as $label => $value)
                    <div class="fee-pill"><span>{{ $label }}</span><strong class="truncate">{{ $value ?: '-' }}</strong></div>
                @endforeach
            </div>
        </div>
    </div>
@endforeach
@endsection
