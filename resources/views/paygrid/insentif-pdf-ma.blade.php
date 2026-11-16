<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rekapan Insentif {{ $roleLabel }} - {{ $monthLabel }}</title>
    <style>
        @page { margin: 10mm 12mm; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #1a1a1a; }
        h1 { font-size: 16px; margin: 0 0 3px; }
        .sub { color: #555; margin: 0 0 10px; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 4px 5px; border: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { background: #f4f4f4; font-weight: bold; }
        .meta { margin-bottom: 10px; }
        .meta td { border: none; padding: 1px 8px 1px 0; }
        .group-head th { background: #e8edf6; font-size: 10px; text-align: center; }
        .detail th, .detail td { text-align: right; }
        .detail th:first-child, .detail td:first-child { text-align: left; }
        .total-row td { font-weight: bold; background: #f4f4f4; }
        .footer { margin-top: 10px; color: #888; font-size: 8px; }
    </style>
</head>
<body>
    <h1>Rekapan Insentif Fee {{ $roleLabel }}</h1>
    <p class="sub">PayGrid - Rekapan Insentif Bulanan</p>

    <table class="meta">
        <tr><td><strong>{{ $roleLabel }}</strong></td><td>{{ $recipientName }}</td></tr>
        <tr><td><strong>Periode</strong></td><td>{{ $monthLabel }}</td></tr>
        <tr><td><strong>Jumlah Toko</strong></td><td>{{ count($rows) }}</td></tr>
    </table>

    @php $rp = fn ($v) => 'Rp '.number_format($v, 0, ',', '.'); @endphp
    <table class="detail">
        <thead>
            <tr class="group-head">
                <th></th>
                <th colspan="5">Fee Distribution</th>
                <th colspan="3">Cash Back &amp; Incentives</th>
            </tr>
            <tr>
                <th>Nama Toko</th>
                <th>Transaction Amount</th>
                <th>Combined</th>
                <th>MA</th>
                <th>Agent</th>
                <th>Merchant</th>
                <th>Cashback</th>
                <th>Marketing</th>
                <th>Disbursement</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $rp($row['volume']) }}</td>
                    <td>{{ $rp($row['combined']) }}</td>
                    <td>{{ $rp($row['ma']) }}</td>
                    <td>{{ $rp($row['agent']) }}</td>
                    <td>{{ $rp($row['merchant']) }}</td>
                    <td>{{ $rp($row['cashback']) }}</td>
                    <td>{{ $rp($row['marketing']) }}</td>
                    <td>{{ $rp($row['disbursement']) }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td>Total</td>
                <td>{{ $rp($totals['volume']) }}</td>
                <td>{{ $rp($totals['combined']) }}</td>
                <td>{{ $rp($totals['ma']) }}</td>
                <td>{{ $rp($totals['agent']) }}</td>
                <td>{{ $rp($totals['merchant']) }}</td>
                <td>{{ $rp($totals['cashback']) }}</td>
                <td>{{ $rp($totals['marketing']) }}</td>
                <td>{{ $rp($totals['disbursement']) }}</td>
            </tr>
        </tbody>
    </table>

    <p class="footer">Dicetak dari dashboard PayGrid pada {{ now()->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB.</p>
</body>
</html>
