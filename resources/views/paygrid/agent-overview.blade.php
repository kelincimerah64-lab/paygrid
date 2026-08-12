@extends('layouts.paygrid')

@section('content')
<div class="page-head">
    <div>
        <h1>Agen Overview</h1>
        <div class="sub">Data toko approved milik agen. Merchant pending tidak dihitung ke grafik.</div>
    </div>
    <div class="page-actions">
        <a class="btn primary" href="{{ route('agent.create-store') }}">Create Toko</a>
    </div>
</div>

<section class="grid cards">
    <div class="card pad metric"><label>Total Toko</label><strong>{{ $merchants->count() }}</strong></div>
    <div class="card pad metric blue"><label>Total Revenue</label><strong>{{ number_format($merchants->sum('metric_volume_success'), 0, ',', '.') }}</strong></div>
    <div class="card pad metric"><label>Total Pendapatan</label><strong>0</strong></div>
    <div class="card pad metric warn-soft"><label>Transaksi Gantung</label><strong>0</strong></div>
</section>

<section class="card pad" style="margin-top:24px">
    <h2>Volume Toko Saya</h2>
    <div class="sub">Volume sukses per toko approved di bawah agen ini.</div>
    @foreach($merchants as $merchant)
        <div style="display:grid; grid-template-columns:minmax(120px, 180px) minmax(140px, 1fr) minmax(90px, 130px); gap:12px; align-items:center; margin:12px 0">
            <strong class="truncate">{{ $merchant->name }}</strong>
            <div style="height:12px; background:#e4ecf7; border-radius:999px; overflow:hidden">
                <div style="height:100%; width:{{ min(100, (($merchant->metric_volume_success ?? 0) / max(1, $merchants->max('metric_volume_success'))) * 100) }}%; background:linear-gradient(90deg, var(--blue), var(--blue-2))"></div>
            </div>
            <span style="text-align:right; font-weight:900">{{ number_format($merchant->metric_volume_success ?? 0, 0, ',', '.') }}</span>
        </div>
    @endforeach
    @if($merchants->isEmpty())
        <div class="empty">Belum ada toko approved untuk agen ini.</div>
    @endif
</section>
@endsection
