@extends('layouts.paygrid')

@php
    $money = fn ($value) => 'Rp '.number_format((int) ($value ?? 0), 0, ',', '.');
    $num = fn ($value) => number_format((int) ($value ?? 0), 0, ',', '.');
@endphp

@section('content')
<div class="page-head">
    <div>
        <h1>Hasil Komisi</h1>
        <div class="sub">Rekap komisi yang sudah kehitung per toko, per bulan.</div>
    </div>
    <div class="page-actions">
        <form class="commission-report-filter" method="get">
            <label><span>Bulan</span><input type="month" name="month" value="{{ $month }}"></label>
            <label><span>Merchant Group</span>
                <select name="agent_id">
                    <option value="">Semua Group</option>
                    @foreach($agents as $a)
                        <option value="{{ $a->id }}" @selected($selectedAgentId === $a->id)>{{ $a->name }}</option>
                    @endforeach
                </select>
            </label>
            <button class="btn primary">Submit Filter</button>
            <a class="btn" href="{{ route('ma.commission.report') }}">Reset</a>
        </form>
    </div>
</div>

<section class="cards-compact">
    <div class="card pad metric success commission-metric-card-wide"><label>Total Komisi &middot; {{ $monthLabel }}</label><strong class="commission-metric-amount">{{ $money($totals['total_commission']) }}</strong></div>
    <div class="card pad metric"><label>Total Revenue</label><strong class="commission-metric-amount">{{ $money($totals['revenue']) }}</strong></div>
    <div class="card pad metric"><label>Total CashBack</label><strong class="commission-metric-amount">{{ $money($totals['cashback']) }}</strong></div>
    <div class="card pad metric"><label>Toko Ada Komisi</label><strong>{{ $num($totals['active_stores']) }}<span class="commission-metric-sub">dari {{ $num($rows->count()) }} toko</span></strong></div>
</section>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Rekap per Toko</h2></div>
    <div class="table-wrap">
        <table class="table qris-table commission-report-table">
            <thead>
                <tr><th>Nama Toko</th><th>Merchant Group</th><th>Revenue (Rp)</th><th>TRX</th><th>CashBack (Rp)</th><th>Volume CB (Rp)</th><th>Komisi CB (Rp)</th><th>Volume WD (Rp)</th><th>Komisi WD (Rp)</th><th>Hari Dihitung</th><th>Total Komisi (Rp)</th></tr>
            </thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    <td><strong>{{ $row['name'] }}</strong></td>
                    <td>
                        @if($row['group'])
                            <span class="badge commission-daybadge">{{ $row['group'] }}</span>
                        @else
                            -
                        @endif
                    </td>
                    <td class="{{ $row['revenue'] > 0 ? '' : 'commission-zero' }}">{{ $num($row['revenue']) }}</td>
                    <td class="{{ $row['trx'] > 0 ? '' : 'commission-zero' }}">{{ $num($row['trx']) }}</td>
                    <td class="{{ $row['cashback'] > 0 ? '' : 'commission-zero' }}">{{ $num($row['cashback']) }}</td>
                    <td class="{{ $row['volume'] > 0 ? '' : 'commission-zero' }}">{{ $num($row['volume']) }}</td>
                    <td class="{{ $row['commission'] > 0 ? '' : 'commission-zero' }}">{{ $num($row['commission']) }}</td>
                    <td class="{{ $row['wd_volume'] > 0 ? '' : 'commission-zero' }}">{{ $num($row['wd_volume']) }}</td>
                    <td class="{{ $row['wd_commission'] > 0 ? '' : 'commission-zero' }}">{{ $num($row['wd_commission']) }}</td>
                    <td class="{{ $row['days_counted'] > 0 ? '' : 'commission-zero' }}">{{ $num($row['days_counted']) }}</td>
                    <td>
                        @if($row['days_counted'] > 0)
                            <strong class="commission-report-amount">{{ $num($row['total_commission']) }}</strong>
                        @else
                            <span class="badge warn">Belum ada komisi</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" class="empty">Belum ada toko.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
