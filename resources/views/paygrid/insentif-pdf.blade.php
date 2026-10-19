<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rekapan Insentif {{ $roleLabel }} - {{ $monthLabel }}</title>
    <style>
        @page { margin: 10mm 12mm; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #1a1a1a; }
        h1 { font-size: 16px; margin: 0 0 3px; }
        .sub { color: #555; margin: 0 0 10px; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 4px 6px; border: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { background: #f4f4f4; font-weight: bold; }
        .meta { margin-bottom: 10px; }
        .meta td { border: none; padding: 1px 8px 1px 0; }
        .detail th:nth-child(2), .detail td:nth-child(2) { text-align: right; }
        .detail th:nth-child(3), .detail td:nth-child(3) { text-align: right; }
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

    <table class="detail">
        <thead>
            <tr><th>Nama Toko</th><th>Volume Transaksi</th><th>Insentif Fee</th></tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td>Rp {{ number_format($row['volume'], 0, ',', '.') }}</td>
                    <td>Rp {{ number_format($row['fee'], 0, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td>Total</td>
                <td></td>
                <td>Rp {{ number_format($total, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    <p class="footer">Dicetak dari dashboard PayGrid pada {{ now()->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB.</p>
</body>
</html>
