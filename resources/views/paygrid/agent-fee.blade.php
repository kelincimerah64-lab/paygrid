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
    <div class="card pad metric success"><label>Total Estimasi Fee</label><strong>{{ $money($total) }}</strong></div>
    <div class="card pad metric"><label>Total Toko</label><strong>{{ $num($rows->count()) }}</strong></div>
</section>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Fee Saya (Agent)</h2></div>
    @include('paygrid.partials.fee-menu-rates-readonly', ['role' => 'agent', 'feeMenus' => $feeMenus, 'rates' => $agent->fee_menu_rates ?? []])
</section>

<section class="card qris-panel section">
    <div class="qris-toolbar"><div><h2>Fee per Toko</h2><p class="muted" style="margin:4px 0 0">Estimasi fee agen per toko sesuai periode filter, plus detail menu fee toko masing-masing.</p></div><span class="badge ok">{{ $num($rows->count()) }} toko</span></div>
    <div class="table-wrap">
        <table class="table qris-table">
            <thead><tr><th>Toko</th><th>Kode Merchant</th><th>TRX Sukses</th><th>Volume Sukses</th><th>% Fee Agen</th><th>Estimasi Fee</th><th>Fee Menu Toko</th></tr></thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    <td><strong>{{ $row->name }}</strong></td>
                    <td>{{ $row->merchant_code ?: $row->slug }}</td>
                    <td>{{ $num($row->trx) }}</td>
                    <td>{{ $money($row->volume) }}</td>
                    <td>{{ $pct($row->agent_fee_percent) }}</td>
                    <td><strong>{{ $money($row->fee_amount) }}</strong></td>
                    <td>{{ $row->fee_menu_summary }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">Belum ada transaksi sukses untuk filter ini.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
