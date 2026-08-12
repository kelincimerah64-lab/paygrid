@extends('layouts.paygrid', ['roleLabel' => 'Monitoring Center', 'menus' => app(App\Services\Navigation\MenuBuilder::class)->admin(), 'active' => 'monitoring'])

@section('content')
<div class="page-head"><div><h1>Monitoring Center</h1><div class="sub">Status polling gateway, provisioning, dan queue dari database lokal.</div></div></div>
<section class="grid cards">
    <div class="card pad metric"><label>Queued Jobs</label><strong>{{ number_format($queuedJobs, 0, ',', '.') }}</strong></div>
    <div class="card pad metric"><label>Failed Jobs</label><strong>{{ number_format($failedJobs, 0, ',', '.') }}</strong></div>
    <div class="card pad metric success"><label>Sync Success</label><strong>{{ number_format($successCount, 0, ',', '.') }}</strong></div>
    <div class="card pad metric warn-soft"><label>Sync Failed</label><strong>{{ number_format($failedCount, 0, ',', '.') }}</strong></div>
</section>
<section class="card section">
    <form method="get" class="filters"><select name="gateway"><option value="">Semua gateway</option><option value="hilogate" @selected(request('gateway') === 'hilogate')>Hilogate</option></select><select name="status"><option value="">Semua status</option><option value="success" @selected(request('status') === 'success')>Success</option><option value="failed" @selected(request('status') === 'failed')>Failed</option></select><input type="date" name="from" value="{{ request('from') }}"><input type="date" name="to" value="{{ request('to') }}"><button class="btn primary">Filter</button></form>
    <div class="table-wrap"><table class="table"><thead><tr><th>Waktu</th><th>Merchant</th><th>Gateway</th><th>Status</th><th>HTTP</th><th>Pesan</th><th>Action</th></tr></thead><tbody>
    @forelse($logs as $log)
        <tr><td>{{ $log->created_at?->format('d/m/Y H:i:s') }}</td><td>{{ $log->merchant?->name ?? '-' }}</td><td>{{ strtoupper($log->gateway) }}</td><td><span class="badge {{ $log->status === 'success' ? 'ok' : ($log->status === 'failed' ? 'danger' : 'warn') }}">{{ strtoupper($log->status) }}</span></td><td>{{ $log->http_status ?? '-' }}</td><td class="truncate" style="max-width:300px">{{ $log->message }}</td><td>@if($log->merchant)<form method="post" action="{{ route('api.merchant.sync.retry', $log->merchant) }}">@csrf<button class="btn">Retry</button></form>@endif</td></tr>
    @empty
        <tr><td colspan="7">Belum ada log gateway.</td></tr>
    @endforelse
    </tbody></table></div><div class="pad">{{ $logs->links() }}</div>
</section>
@endsection
