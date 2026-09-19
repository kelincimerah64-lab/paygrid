@extends('layouts.paygrid')

@php
    $deptLabel = fn ($dept) => app(App\Services\MerchantTicketService::class)->departmentLabel($dept);
    $statusLabel = fn ($status) => App\Support\PayGridLabels::status($status);
    $statusClass = fn ($status) => $status === 'in_progress' ? 'warn' : 'danger';
    $categoryLabel = fn ($ticket) => app(App\Services\MerchantTicketService::class)->categoryLabel($ticket->department, $ticket->category);
@endphp

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">{{ $roleLabel }}</div>
        <h1>Create Ticket</h1>
    </div>
</div>

@if(session('status'))
    <section class="card pad section"><span class="badge ok">{{ session('status') }}</span></section>
@endif

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Buat Tiket Baru</h2></div>
    <div class="pad split">
        <form method="post" action="{{ route('ma.tickets.store') }}" enctype="multipart/form-data" class="approve-fee-form ticket-create-form">
            @csrf
            <label>Merchant Group
                <select name="merchant_group" id="ticket-merchant-group" required>
                    <option value="">Pilih Merchant Group</option>
                    @foreach($groupedMerchants as $groupName => $groupMerchants)
                        <option value="{{ $groupName }}" @selected(old('merchant_group') === $groupName)>{{ $groupName }}</option>
                    @endforeach
                </select>
                <small class="muted">Pilih Merchant Group untuk menampilkan daftar merchant yang tersedia.</small>
            </label>
            <label>Merchant
                <select name="merchant_id" id="ticket-merchant" required disabled>
                    <option value="">Pilih Merchant Group dulu</option>
                </select>
                <small class="muted">Pilih merchant setelah memilih Merchant Group.</small>
            </label>
            <label>Kategori / Tujuan
                <select name="department" id="ticket-department" required>
                    <option value="">Pilih tujuan</option>
                    <option value="cs" @selected(old('department') === 'cs')>CS</option>
                    <option value="tech" @selected(old('department') === 'tech')>Tech Support</option>
                    <option value="finance" @selected(old('department') === 'finance')>Finance</option>
                </select>
            </label>
            <label>Sub Menu / Jenis Permintaan
                <select name="category" id="ticket-category" required>
                    <option value="">Pilih tujuan dulu</option>
                </select>
            </label>
            <label>Judul / Ringkasan Masalah
                <input type="text" name="title" id="ticket-title" maxlength="100" required placeholder="Contoh: Transaksi gagal, refund belum diterima, dsb." value="{{ old('title') }}">
                <small class="muted"><span id="ticket-title-count">0</span>/100</small>
            </label>
            <label>Penjelasan Detail
                <textarea name="description" rows="4" maxlength="2000" required placeholder="Jelaskan detail kendala/kebutuhan secara lengkap...">{{ old('description') }}</textarea>
            </label>
            <label class="file-pick" title="Lampiran opsional, maksimal 5 file">
                Lampiran (Opsional, Maks 5)
                <input type="file" name="attachments[]" id="ticket-attachments" accept=".jpg,.jpeg,.png,.pdf,.mp4" multiple>
                <small class="muted">Maksimal 5 file, ukuran maks. 10MB per file. Format: JPG, PNG, PDF, MP4.</small>
            </label>
            <div class="ticket-chip-list" id="ticket-attachments-chips"></div>
            @error('attachments')<span class="badge danger">{{ $message }}</span>@enderror
            @error('attachments.*')<span class="badge danger">{{ $message }}</span>@enderror
            @error('merchant_id')<span class="badge danger">{{ $message }}</span>@enderror
            @error('category')<span class="badge danger">{{ $message }}</span>@enderror
            <button class="btn primary compact-btn" style="width:100%; margin:8px 0" type="submit">Submit Tiket</button>
        </form>
        <aside class="card pad" style="background:#f7faff">
            <h3 style="margin:0 0 10px">Informasi Penting</h3>
            <ol style="margin:0 0 12px; padding-left:18px; font-size:13px; line-height:1.7">
                <li>Pilih Merchant Group terlebih dahulu</li>
                <li>Pilih Merchant</li>
                <li>Isi kategori dan jenis permintaan</li>
                <li>Jelaskan detail masalah</li>
                <li>Tambahkan lampiran jika diperlukan</li>
                <li>Klik Submit Tiket</li>
            </ol>
            <div class="badge ok" style="display:block; padding:10px; white-space:normal; text-align:left">Semakin lengkap informasi yang Anda berikan, semakin cepat tim kami dapat membantu.</div>
        </aside>
    </div>
</section>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Toko dengan Fitur Ticket</h2></div>
    <div class="table-wrap">
        <table class="table qris-table ma-paginate">
            <thead>
                <tr><th>Toko</th><th>Agen</th><th>Isu Terbuka</th><th>Tujuan</th><th>Menu</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($merchants as $merchant)
                @php($latest = $merchant->merchantTickets->first())
                <tr>
                    <td><strong>{{ $merchant->name }}</strong></td>
                    <td>{{ $merchant->agent?->name ?: '-' }}</td>
                    <td>
                        @if($latest)
                            <span class="badge {{ $statusClass($latest->status) }}">{{ $merchant->merchantTickets->count() }} terbuka</span>
                            <span class="muted truncate ref-line" style="max-width:180px">{{ $latest->ticket_no }}</span>
                        @else
                            <span class="muted">Tidak ada tiket terbuka</span>
                        @endif
                    </td>
                    <td>{{ $latest ? $deptLabel($latest->department) : '-' }}</td>
                    <td><span class="truncate ref-line" style="max-width:180px">{{ $latest ? $categoryLabel($latest) : '-' }}</span></td>
                    <td><a class="btn compact-btn" href="{{ route('merchant.tickets.index', $merchant) }}">Buka</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Belum ada toko dengan fitur Create Ticket aktif.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

@push('scripts')
<script>
(function () {
    var groupedMerchants = @json($groupedMerchants);
    var categories = {
        cs: @json($csCategories),
        tech: @json($techCategories),
        finance: @json($financeCategories),
    };

    var groupSelect = document.getElementById('ticket-merchant-group');
    var merchantSelect = document.getElementById('ticket-merchant');
    var departmentSelect = document.getElementById('ticket-department');
    var categorySelect = document.getElementById('ticket-category');
    var titleInput = document.getElementById('ticket-title');
    var titleCount = document.getElementById('ticket-title-count');
    var attachmentsInput = document.getElementById('ticket-attachments');
    var chipList = document.getElementById('ticket-attachments-chips');
    var selectedFiles = [];

    if (titleInput && titleCount) {
        titleInput.addEventListener('input', function () { titleCount.textContent = titleInput.value.length; });
        titleCount.textContent = titleInput.value.length;
    }

    if (groupSelect && merchantSelect) {
        groupSelect.addEventListener('change', function () {
            var list = groupedMerchants[groupSelect.value] || null;
            merchantSelect.innerHTML = '';
            if (!list) {
                merchantSelect.appendChild(new Option('Pilih Merchant Group dulu', ''));
                merchantSelect.disabled = true;
                return;
            }
            merchantSelect.disabled = false;
            merchantSelect.appendChild(new Option('Pilih Merchant', ''));
            list.forEach(function (m) { merchantSelect.appendChild(new Option(m.name, m.id)); });
        });
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

    if (attachmentsInput && chipList) {
        attachmentsInput.addEventListener('change', function () {
            Array.prototype.forEach.call(attachmentsInput.files, function (file) {
                if (selectedFiles.length >= 5) return;
                if (selectedFiles.some(function (existing) { return sameFile(existing, file); })) return;
                selectedFiles.push(file);
            });
            syncInput();
            renderChips();
        });
    }

    if (departmentSelect && categorySelect) {
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
    }
})();
</script>
@endpush
