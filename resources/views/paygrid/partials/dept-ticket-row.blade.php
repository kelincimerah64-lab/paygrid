<div class="ticket-list-icon" aria-hidden="true">{{ strtoupper(mb_substr($ticket->merchant?->name ?: '-', 0, 1)) }}</div>
<div class="ticket-list-main">
    <div class="ticket-list-no">{{ $ticket->ticket_no }} &middot; {{ $ticket->merchant?->name ?: '-' }}</div>
    <div class="ticket-list-sub"><span>{{ $deptLabel($ticket->department) }}</span><span>&middot;</span><span class="truncate ref-line">{{ $categoryLabel($ticket) }}</span></div>
</div>
<div class="ticket-list-meta">
    <span class="badge {{ $statusClass($ticket->status) }}">{{ $statusLabel($ticket->status) }}</span>
    <span class="ticket-list-time">{{ $ticket->last_message_at?->timezone('Asia/Jakarta')->format('d M Y H:i') ?? '-' }}</span>
    <span class="ticket-list-time">{{ $ticket->claimedBy ? 'Diambil: '.$ticket->claimedBy->name : 'Belum diambil' }}</span>
</div>
