<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Request Toko - {{ $registration->store_name }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .sub { color: #555; margin: 0 0 16px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 6px 8px; border: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { width: 220px; background: #f4f4f4; font-weight: bold; }
        .meta { margin-bottom: 16px; }
        .meta td { border: none; padding: 2px 8px 2px 0; }
        .footer { margin-top: 20px; color: #888; font-size: 10px; }
        .signatures { width: 100%; margin-top: 40px; }
        .signatures td { border: none; padding: 0 12px; text-align: center; }
        .signature-box { border: 1px solid #999; height: 70px; margin-bottom: 6px; }
    </style>
</head>
<body>
    <h1>Request Toko - {{ $registration->store_name }}</h1>
    <p class="sub">PayGrid - Data Request Onboarding Toko</p>

    <table class="meta">
        <tr><td><strong>Agen</strong></td><td>{{ $registration->agent?->name ?? '-' }}</td></tr>
        <tr><td><strong>Tanggal Request</strong></td><td>{{ $registration->created_at->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB</td></tr>
        <tr><td><strong>Status</strong></td><td>{{ ucfirst(str_replace('_', ' ', $registration->status)) }}</td></tr>
        @isset($admin)
            <tr><td><strong>Admin</strong></td><td>{{ $admin['name'] }} - {{ $admin['email'] }} (PW: {{ $admin['password'] }})</td></tr>
        @endisset
    </table>

    <table>
        @foreach($detailRows as $label => $value)
            <tr>
                <th>{{ $label }}</th>
                <td>{{ $value ?: '-' }}</td>
            </tr>
        @endforeach
    </table>

    <table class="signatures">
        <tr>
            <td><div class="signature-box"></div>Approved By</td>
            <td><div class="signature-box"></div>Check By</td>
            <td><div class="signature-box"></div>Requested By</td>
        </tr>
    </table>

    <p class="footer">Dicetak dari dashboard PayGrid pada {{ now()->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB.</p>
</body>
</html>
