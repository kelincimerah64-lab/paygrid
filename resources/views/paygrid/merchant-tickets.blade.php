@extends('layouts.paygrid')

@php
    $statusLabel = fn ($status) => App\Support\PayGridLabels::status($status);
    $statusClass = fn ($status) => match ($status) {
        'closed' => 'ok',
        'in_progress' => 'warn',
        default => 'danger',
    };
    $deptLabel = fn ($dept) => app(App\Services\MerchantTicketService::class)->departmentLabel($dept);
    $categoryLabel = fn ($ticket) => app(App\Services\MerchantTicketService::class)->categoryLabel($ticket->department, $ticket->category);
@endphp

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">{{ $merchant->name }}</div>
        <h1>Create Ticket</h1>
    </div>
</div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Buat Tiket Baru</h2></div>
    <form method="post" action="{{ route('merchant.tickets.store', $merchant) }}" enctype="multipart/form-data" class="approve-fee-form ticket-create-form">
        @csrf
        <label>Tujuan
            <select name="department" id="ticket-department" required>
                <option value="">Pilih tujuan</option>
                <option value="cs" @selected(old('department') === 'cs')>CS</option>
                <option value="tech" @selected(old('department') === 'tech')>Tech Support</option>
                <option value="finance" @selected(old('department') === 'finance')>Finance</option>
            </select>
        </label>
        <label>Menu
            <select name="category" id="ticket-category" required>
                <option value="">Pilih tujuan dulu</option>
            </select>
        </label>
        <label>Penjelasan
            <textarea name="description" rows="4" maxlength="2000" required placeholder="Jelaskan detail kendala/kebutuhan...">{{ old('description') }}</textarea>
        </label>
        <label class="file-pick" title="Lampiran opsional, maksimal 3 file">
            Lampiran (opsional, maks 3)
            <input type="file" name="attachments[]" id="ticket-attachments" accept="image/*" multiple>
        </label>
        <span class="muted" id="ticket-attachments-info"></span>
        @error('attachments')<span class="badge danger">{{ $message }}</span>@enderror
        @error('attachments.*')<span class="badge danger">{{ $message }}</span>@enderror
        <button class="btn primary compact-btn" style="width:100%; margin:8px 0" type="submit">Submit Tiket</button>
    </form>
</section>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Tiket Saya</h2></div>
    <div class="table-wrap">
        <table class="table qris-table ticket-table">
            <thead>
                <tr><th>Ticket</th><th>Tujuan</th><th>Menu</th><th>Update Terakhir</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($tickets as $ticket)
                <tr>
                    <td><strong>{{ $ticket->ticket_no }}</strong></td>
                    <td>{{ $deptLabel($ticket->department) }}</td>
                    <td><span class="truncate ref-line">{{ $categoryLabel($ticket) }}</span></td>
                    <td><span class="muted">{{ $ticket->last_message_at?->timezone('Asia/Jakarta')->format('d M Y H:i') ?? '-' }}</span></td>
                    <td><span class="badge {{ $statusClass($ticket->status) }}">{{ $statusLabel($ticket->status) }}</span></td>
                    <td><a class="btn compact-btn" href="{{ route('merchant.tickets.show', [$merchant, $ticket]) }}">Buka</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Belum ada tiket.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="qris-pagination pad">
        <div class="pager-summary">Showing {{ $tickets->firstItem() ?? 0 }} to {{ $tickets->lastItem() ?? 0 }}</div>
        <div class="pager-links">
            @if($tickets->onFirstPage())<span class="pager disabled">Prev</span>@else<a class="pager" href="{{ $tickets->previousPageUrl() }}">Prev</a>@endif
            @if($tickets->hasMorePages())<a class="pager" href="{{ $tickets->nextPageUrl() }}">Next</a>@else<span class="pager disabled">Next</span>@endif
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script>
(function () {
    var categories = {
        cs: @json($csCategories),
        tech: @json($techCategories),
        finance: @json($financeCategories),
    };
    var departmentSelect = document.getElementById('ticket-department');
    var categorySelect = document.getElementById('ticket-category');
    var attachmentsInput = document.getElementById('ticket-attachments');
    var attachmentsInfo = document.getElementById('ticket-attachments-info');
    if (attachmentsInput && attachmentsInfo) {
        attachmentsInput.addEventListener('change', function () {
            var count = attachmentsInput.files.length;
            if (count > 3) {
                attachmentsInfo.textContent = 'Maksimal 3 file, hanya 3 pertama yang dipakai.';
                var limited = new DataTransfer();
                for (var i = 0; i < 3; i++) limited.items.add(attachmentsInput.files[i]);
                attachmentsInput.files = limited.files;
            } else {
                attachmentsInfo.textContent = count ? count + ' file dipilih.' : '';
            }
        });
    }
    if (!departmentSelect || !categorySelect) return;
    departmentSelect.addEventListener('change', function () {
        var options = categories[departmentSelect.value] || null;
        categorySelect.innerHTML = '';
        if (!options) {
            categorySelect.appendChild(new Option('Pilih tujuan dulu', ''));
            return;
        }
        categorySelect.appendChild(new Option('Pilih menu', ''));
        Object.keys(options).forEach(function (key) {
            categorySelect.appendChild(new Option(options[key], key));
        });
    });
})();
</script>
@endpush
