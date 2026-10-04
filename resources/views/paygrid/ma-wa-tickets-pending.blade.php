@extends('layouts.paygrid')

@php
    $initials = fn (?string $name) => $name ? strtoupper(substr(trim($name), 0, 1)) : '?';
@endphp

@section('content')
<div class="wat-hero">
    <div>
        <p class="eyebrow">{{ $roleLabel }}</p>
        <h1>Menunggu Approval</h1>
        <p class="muted" style="margin:6px 0 0; font-size:13px; max-width:56ch">Tiket IP Whitelist, Request Topup, dan Request Topdown Saldo yang butuh persetujuan sebelum bisa dikerjakan.</p>
    </div>
</div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<section class="wat-panel">
    <div class="wat-panel-head"><h2>Daftar Tiket</h2></div>
    <div class="table-wrap">
        <table class="table qris-table">
            <thead>
                <tr><th>No. Tiket</th><th>Toko</th><th>Kategori</th><th>Dibuat Oleh</th><th>Dibuat</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($tickets as $ticket)
                <tr>
                    <td data-label="No. Tiket"><strong>{{ $ticket->ticket_no }}</strong></td>
                    <td data-label="Toko">{{ $ticket->merchant?->name ?: '-' }}</td>
                    <td data-label="Kategori">{{ ucfirst($ticket->department) }} &middot; {{ $ticket->category }}</td>
                    <td data-label="Dibuat Oleh">
                        <div style="display:flex; align-items:center; gap:7px"><div class="wat-avatar" style="width:22px;height:22px;font-size:10px">{{ $initials($ticket->createdBy?->name) }}</div>{{ $ticket->createdBy?->name ?: '-' }}</div>
                    </td>
                    <td data-label="Dibuat">{{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M, H:i') }}</td>
                    <td data-label=""><a class="btn compact-btn" href="{{ route('wa-tickets.show', $ticket) }}">Buka</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Tidak ada tiket yang menunggu approval.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="pad">{{ $tickets->links() }}</div>
</section>
@endsection
