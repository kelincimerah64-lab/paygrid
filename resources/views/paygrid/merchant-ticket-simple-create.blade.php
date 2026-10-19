@extends('layouts.paygrid')

@php
    $statusLabel = fn ($status) => match ($status) { 'open' => 'Belum Diproses', 'closed' => 'Selesai', default => App\Support\PayGridLabels::status($status) };
    $statusClass = fn ($status) => match ($status) {
        'closed' => 'ok',
        'in_progress', 'open' => 'warn',
        default => 'danger',
    };
@endphp

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">{{ $merchant->name }}</div>
        <h1>Buat Tiket Support</h1>
        <p class="sub muted">Sampaikan kendala Anda, tim kami akan membantu secepat mungkin.</p>
    </div>
</div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Tiket Baru</h2></div>
    <form method="post" action="{{ route('merchant.tickets.simple.store', $merchant) }}" enctype="multipart/form-data" id="ticket-simple-form" class="pad" style="display:flex; flex-direction:column; gap:14px">
        @csrf
        <label>
            <span class="ticket-field-label">Deskripsi Masalah <span class="req">*</span></span>
            <textarea name="description" id="ticket-description" rows="5" maxlength="2000" required placeholder="Jelaskan masalah yang Anda alami secara detail...">{{ old('description') }}</textarea>
            <div class="ticket-char-count"><span id="ticket-description-count">0</span>/2000</div>
        </label>
        @error('description')<span class="badge danger">{{ $message }}</span>@enderror

        <label>
            <span class="ticket-field-label">Lampiran (Opsional)</span>
            <div class="ticket-dropzone" id="ticket-dropzone">
                <span class="ticket-dropzone-icon" aria-hidden="true">⬆</span>
                <strong>Drag &amp; drop file di sini, atau klik untuk memilih</strong>
                <small>Format: JPG, PNG, PDF (Maks. 10 MB, maksimal 5 file)</small>
                <input type="file" name="attachments[]" id="ticket-attachments" accept=".jpg,.jpeg,.png,.pdf,.mp4" multiple>
            </div>
            <div class="ticket-chip-list" id="ticket-attachments-chips"></div>
        </label>
        @error('attachments')<span class="badge danger">{{ $message }}</span>@enderror
        @error('attachments.*')<span class="badge danger">{{ $message }}</span>@enderror

        <div class="ticket-form-actions">
            <button type="submit" class="btn primary">Kirim Tiket</button>
        </div>
    </form>
</section>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Tiket Saya</h2></div>
    <div class="ticket-list">
        @forelse($tickets as $ticket)
            <a class="ticket-list-row" href="{{ route('merchant.tickets.show', [$merchant, $ticket]) }}">
                <div class="ticket-list-icon" aria-hidden="true">!</div>
                <div class="ticket-list-main">
                    <div class="ticket-list-no">{{ $ticket->ticket_no }}</div>
                    <div class="ticket-list-sub">Update terakhir {{ $ticket->last_message_at?->timezone('Asia/Jakarta')->format('d M Y H:i') ?? '-' }}</div>
                </div>
                <div class="ticket-list-meta">
                    <span class="badge {{ $statusClass($ticket->status) }}">{{ $statusLabel($ticket->status) }}</span>
                </div>
            </a>
        @empty
            <div class="ticket-list-empty">Belum ada tiket.</div>
        @endforelse
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
    var descriptionField = document.getElementById('ticket-description');
    var descriptionCount = document.getElementById('ticket-description-count');
    var attachmentsInput = document.getElementById('ticket-attachments');
    var chipList = document.getElementById('ticket-attachments-chips');
    var dropzone = document.getElementById('ticket-dropzone');
    var selectedFiles = [];

    if (descriptionField && descriptionCount) {
        descriptionField.addEventListener('input', function () {
            descriptionCount.textContent = descriptionField.value.length;
        });
        descriptionCount.textContent = descriptionField.value.length;
    }

    function sameFile(a, b) {
        return a.name === b.name && a.size === b.size && a.lastModified === b.lastModified;
    }

    function syncInput() {
        var transfer = new DataTransfer();
        selectedFiles.forEach(function (file) { transfer.items.add(file); });
        attachmentsInput.files = transfer.files;
    }

    function renderChips() {
        chipList.innerHTML = '';
        selectedFiles.forEach(function (file, index) {
            var chip = document.createElement('span');
            chip.className = 'ticket-chip';
            var label = document.createElement('span');
            label.className = 'ticket-chip-label';
            label.textContent = file.name;
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'ticket-chip-remove';
            remove.setAttribute('aria-label', 'Hapus ' + file.name);
            remove.textContent = '×';
            remove.addEventListener('click', function () {
                selectedFiles.splice(index, 1);
                syncInput();
                renderChips();
            });
            chip.appendChild(label);
            chip.appendChild(remove);
            chipList.appendChild(chip);
        });
    }

    function addFiles(fileList) {
        Array.prototype.forEach.call(fileList, function (file) {
            if (selectedFiles.length >= 5) return;
            if (selectedFiles.some(function (existing) { return sameFile(existing, file); })) return;
            selectedFiles.push(file);
        });
        syncInput();
        renderChips();
    }

    if (attachmentsInput && chipList) {
        attachmentsInput.addEventListener('change', function () { addFiles(attachmentsInput.files); });
    }

    if (dropzone) {
        ['dragenter', 'dragover'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) { e.preventDefault(); dropzone.classList.add('dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) { e.preventDefault(); dropzone.classList.remove('dragover'); });
        });
        dropzone.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files);
        });
    }
})();
</script>
@endpush
