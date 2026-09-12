@extends('layouts.paygrid')

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">{{ $roleLabel }}</div>
        <h1>Create Ticket</h1>
    </div>
</div>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Toko dengan Fitur Ticket</h2></div>
    <div class="table-wrap">
        <table class="table qris-table">
            <thead>
                <tr><th>Toko</th><th>Agen</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($merchants as $merchant)
                <tr>
                    <td><strong>{{ $merchant->name }}</strong></td>
                    <td>{{ $merchant->agent?->name ?: '-' }}</td>
                    <td><a class="btn primary compact-btn" href="{{ route('merchant.tickets.index', $merchant) }}">Buat Tiket</a></td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">Belum ada toko dengan fitur Create Ticket aktif.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
