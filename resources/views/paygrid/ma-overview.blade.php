@extends('layouts.paygrid')

@section('content')
<div class="page-head">
    <div>
        <h1>Global Overview</h1>
        <div class="sub">Overview memakai periode month-to-date dan sumber data DB summary.</div>
    </div>
    <div class="page-actions">
        <a class="btn primary" href="{{ route('ma.stores') }}">List Toko</a>
    </div>
</div>

<section class="grid cards">
    <div class="card pad metric"><label>Total Toko</label><strong>{{ $merchants->count() }}</strong></div>
    <div class="card pad metric"><label>Total Transaksi</label><strong>{{ number_format($merchants->sum('metric_trx_total'), 0, ',', '.') }}</strong></div>
    <div class="card pad metric blue"><label>Volume Sukses</label><strong>{{ number_format($merchants->sum('metric_volume_success'), 0, ',', '.') }}</strong></div>
    <div class="card pad metric"><label>Belum Assign Agen</label><strong>{{ $merchants->whereNull('agent_id')->count() }}</strong></div>
</section>

<section class="card" style="margin-top:24px">
    <div class="filters">
        <input class="search" placeholder="Cari toko, agen, balance, settlement...">
        <span class="muted">{{ $merchants->count() }} toko dari DB</span>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nama Toko</th><th>Agen</th><th>Total Transaksi</th><th>Volume Sukses</th><th>Status</th></tr></thead>
            <tbody>
            @foreach($merchants as $merchant)
                <tr>
                    <td><strong>{{ $merchant->name }}</strong><br><span class="muted">{{ strtoupper($merchant->merchant_type) }} / {{ $merchant->gateway }}</span></td>
                    <td>{{ $merchant->agent?->name ?? '-' }}</td>
                    <td>{{ number_format($merchant->metric_trx_total ?? 0, 0, ',', '.') }}</td>
                    <td>{{ number_format($merchant->metric_volume_success ?? 0, 0, ',', '.') }}</td>
                    <td><span class="badge ok">Approved</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@endsection
