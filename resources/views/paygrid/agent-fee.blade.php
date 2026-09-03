@extends('layouts.paygrid')

@php
    $reportFilters = $reportFilters ?? ['q' => '', 'from' => '', 'to' => ''];
    $money = fn ($value) => 'Rp '.number_format((int) ($value ?? 0), 0, ',', '.');
    $num = fn ($value) => number_format((int) ($value ?? 0), 0, ',', '.');
    $pct = fn ($value) => number_format((float) ($value ?? 0), 2, ',', '.').'%';
@endphp

@section('content')
<div class="page-head">
    <div>
        <h1>Fee Agen</h1>
        <div class="sub">Estimasi fee agen berdasarkan rumus PayGrid, dihitung dari transaksi sukses per toko. Gunakan untuk cross-check hitungan kamu sendiri.</div>
    </div>
    <div class="page-actions"></div>
</div>

<section class="card agent-filter-card section">
    <form class="agent-filter-grid" method="get">
        <label class="agent-filter-search"><span>Pencarian Toko</span><input class="search" name="q" value="{{ $reportFilters['q'] }}" placeholder="Cari nama toko"></label>
        <label><span>Dari tanggal</span><input type="date" name="from" value="{{ $reportFilters['from'] }}"></label>
        <label><span>Sampai tanggal</span><input type="date" name="to" value="{{ $reportFilters['to'] }}"></label>
        <div class="agent-filter-actions"><button class="btn primary">Submit Filter</button><a class="btn" href="{{ route('agent.fee') }}">Reset</a></div>
    </form>
</section>

<section class="cards-compact">
    <div class="card pad metric success"><label>Total Fee Agen</label><strong>{{ $money($total) }}</strong></div>
    <div class="card pad metric"><label>Total MDR Toko</label><strong>{{ $money($totalMerchantFee) }}</strong></div>
    <div class="card pad metric"><label>Total Toko</label><strong>{{ $num($rows->count()) }}</strong></div>
</section>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Agent Cost</h2></div>
    @include('paygrid.partials.fee-menu-rates-readonly', ['role' => 'agent', 'feeMenus' => $feeMenus, 'rates' => $agent->fee_menu_rates ?? []])
</section>

<section class="card qris-panel section">
    <div class="qris-toolbar"><div><h2>Fee per Toko</h2><p class="muted" style="margin:4px 0 0">Estimasi fee agen per toko sesuai periode filter, plus detail menu fee toko masing-masing.</p></div><span class="badge ok">{{ $num($rows->count()) }} toko</span></div>
    <div class="table-wrap">
        <table class="table qris-table">
            <thead><tr><th>Toko</th><th>Kode Merchant</th><th>TRX Sukses</th><th>Volume Sukses</th><th>MDR Toko</th><th>Estimasi MDR Toko</th><th>% Fee Agen</th><th>Estimasi Fee Agen</th><th>Detail</th></tr></thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    <td><strong>{{ $row->name }}</strong></td>
                    <td>{{ $row->merchant_code ?: $row->slug }}</td>
                    <td>{{ $num($row->trx) }}</td>
                    <td>{{ $money($row->volume) }}</td>
                    <td>{{ $pct($row->merchant_mdr_percent) }}</td>
                    <td>{{ $money($row->merchant_fee_amount) }}</td>
                    <td>{{ $pct($row->agent_fee_percent) }}</td>
                    <td><strong>{{ $money($row->fee_amount) }}</strong></td>
                    <td><button class="btn compact-btn approval-detail-open" type="button" data-approval-detail="agent-fee-store-{{ $row->merchant_id }}">Detail</button>
                    <div class="approval-modal" id="agent-fee-store-{{ $row->merchant_id }}" hidden><div class="approval-modal-card"><div class="qris-toolbar"><div><h2>Detail Fee Menu</h2><p class="muted" style="margin:4px 0 0">{{ $row->name }}</p></div><button class="btn compact-btn approval-detail-close" type="button">Tutup</button></div>@include('paygrid.partials.fee-menu-rates-readonly', ['role' => 'merchant', 'feeMenus' => $feeMenus, 'rates' => $row->fee_menu_rates])</div></div></td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty">Belum ada transaksi sukses untuk filter ini.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-approval-detail]').forEach((button) => {
        button.addEventListener('click', () => {
            const target = document.getElementById(button.dataset.approvalDetail);
            if (target) target.hidden = false;
        });
    });
    document.querySelectorAll('.approval-detail-close, .approval-modal').forEach((item) => {
        item.addEventListener('click', (event) => {
            if (event.target.closest('.approval-modal-card') && !event.target.classList.contains('approval-detail-close')) return;
            item.closest('.approval-modal').hidden = true;
        });
    });
});
</script>
@endpush
