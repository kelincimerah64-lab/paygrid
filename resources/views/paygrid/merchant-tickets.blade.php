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
    $approvalLabel = fn ($status) => match ($status) { 'waiting' => 'Menunggu Approval', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', default => null };
    $approvalClass = fn ($status) => match ($status) { 'approved' => 'ok', 'rejected' => 'danger', default => 'warn' };
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

<div class="ticket-wizard-steps">
    <div class="ticket-wizard-step active" data-step-indicator="1"><span class="circle">1</span><div><strong>Isi Form</strong><small>Lengkapi detail masalah</small></div></div>
    <div class="ticket-wizard-line"></div>
    <div class="ticket-wizard-step" data-step-indicator="2"><span class="circle">2</span><div><strong>Konfirmasi</strong><small>Periksa kembali data</small></div></div>
    <div class="ticket-wizard-line"></div>
    <div class="ticket-wizard-step" data-step-indicator="3"><span class="circle">3</span><div><strong>Tiket Dibuat</strong><small>Dapatkan nomor tiket</small></div></div>
</div>

<div class="ticket-wizard-grid section">
    <form method="post" action="{{ route('merchant.tickets.store', $merchant) }}" enctype="multipart/form-data" id="ticket-wizard-form">
        @csrf
        <input type="hidden" name="card" id="ticket-card-input" value="{{ old('card', 'transaksi') }}">

        <div id="ticket-step-1">
            <section class="card qris-panel section">
                <div class="qris-toolbar"><h2>Pilih Kategori Support</h2></div>
                <div class="pad ticket-category-cards" id="ticket-category-cards">
                    @foreach($cards as $key => $card)
                        <div class="ticket-category-card {{ old('card', 'transaksi') === $key ? 'selected' : '' }}" data-card-key="{{ $key }}">
                            <span class="icon" aria-hidden="true">{{ $card['icon'] }}</span>
                            <strong>{{ $card['label'] }}</strong>
                            <small>{{ $card['description'] }}</small>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="card qris-panel section">
                <div class="qris-toolbar"><h2 id="ticket-detail-title">Detail {{ $cards['transaksi']['label'] }}</h2></div>
                <div class="pad" style="display:flex; flex-direction:column; gap:14px">
                    <div class="ticket-info-banner" id="ticket-card-banner">
                        <span aria-hidden="true">ℹ</span>
                        <span id="ticket-card-banner-text"></span>
                    </div>

                    <label>
                        <span class="ticket-field-label">Jenis Permasalahan <span class="req">*</span></span>
                        <select name="category" id="ticket-category-select" required>
                            <option value="">Pilih jenis masalah</option>
                        </select>
                    </label>

                    <div id="ticket-dynamic-fields"></div>

                    <label>
                        <span class="ticket-field-label">Deskripsi Masalah <span class="req">*</span></span>
                        <textarea name="description" id="ticket-description" rows="4" maxlength="500" required placeholder="Jelaskan masalah yang Anda alami secara detail...">{{ old('description') }}</textarea>
                        <div class="ticket-char-count"><span id="ticket-description-count">0</span>/500</div>
                    </label>

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
                    @error('category')<span class="badge danger">{{ $message }}</span>@enderror

                    <div class="ticket-form-actions">
                        <a href="{{ route('merchant.tickets.index', $merchant) }}" class="btn">Batal</a>
                        <button type="button" class="btn primary" id="ticket-continue">Lanjutkan →</button>
                    </div>
                </div>
            </section>
        </div>

        <div id="ticket-step-2" hidden>
            <section class="card qris-panel section">
                <div class="qris-toolbar"><h2>Konfirmasi Tiket</h2></div>
                <div class="pad approval-detail-grid">
                    <div class="fee-pill"><span>Kategori</span><strong id="ticket-confirm-category">-</strong></div>
                    <div class="fee-pill"><span>Estimasi Respon</span><strong id="ticket-confirm-response">-</strong></div>
                </div>
                <div class="pad" id="ticket-confirm-metadata" style="display:flex; flex-wrap:wrap; gap:8px; padding-top:0"></div>
                <div class="pad" style="padding-top:0">
                    <span class="ticket-field-label">Deskripsi</span>
                    <p class="muted" id="ticket-confirm-description" style="margin-top:4px; white-space:pre-wrap">-</p>
                    <span class="ticket-field-label">Lampiran</span>
                    <p class="muted" id="ticket-confirm-attachments" style="margin-top:4px">Tidak ada lampiran</p>
                </div>
                <div class="ticket-form-actions">
                    <button type="button" class="btn" id="ticket-back">← Kembali</button>
                    <button type="submit" class="btn primary">📨 Kirim Tiket</button>
                </div>
            </section>
        </div>
    </form>

    <aside class="ticket-sidebar">
        <div class="card pad ticket-help-card">
            <div class="ticket-help-avatar" aria-hidden="true">🎧</div>
            <h3>Kami Siap Membantu</h3>
            <p class="muted">Tim Customer Service PayGrid akan merespon tiket Anda secepat mungkin.</p>
        </div>
        <div class="card pad">
            <h3 class="ticket-info-title"><span class="info-icon" aria-hidden="true">⏱</span> Estimasi Waktu Respon</h3>
            <ul class="ticket-response-list" id="ticket-response-list">
                @foreach($cards as $key => $card)
                    <li data-response-row="{{ $key }}" class="{{ old('card', 'transaksi') === $key ? 'active' : '' }}"><span>{{ $card['label'] }}</span><strong>{{ $card['response'] }}</strong></li>
                @endforeach
            </ul>
            <p class="muted" style="font-size:11px; margin-top:8px">*Waktu respon dapat berbeda sesuai kompleksitas masalah.</p>
        </div>
        <div class="card pad">
            <h3 class="ticket-info-title"><span class="info-icon" aria-hidden="true">✓</span> Tips Pengajuan Tiket</h3>
            <ul class="ticket-tips">
                <li>Pastikan RRN atau Reference ID benar</li>
                <li>Sertakan screenshot bukti transaksi</li>
                <li>Jelaskan kronologi masalah dengan jelas</li>
                <li>Gunakan satu tiket untuk satu masalah</li>
            </ul>
        </div>
        <div class="card pad">
            <h3 class="ticket-info-title"><span class="info-icon" aria-hidden="true">📋</span> Lihat Tiket Saya</h3>
            <p class="muted" style="font-size:12.5px">Cek status tiket yang sudah Anda buat.</p>
            <a class="btn compact-btn" href="#ticket-history" style="margin-top:6px; display:inline-block">Buka Daftar Tiket →</a>
        </div>
    </aside>
</div>

<section class="card qris-panel section" id="ticket-history">
    <div class="qris-toolbar"><h2>Tiket Saya</h2></div>
    <div class="table-wrap">
        <table class="table qris-table ticket-table merchant-ticket-list-table">
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
                    <td><span class="badge {{ $statusClass($ticket->status) }}">{{ $statusLabel($ticket->status) }}</span>@if($approvalLabel($ticket->approval_status))<br><span class="badge {{ $approvalClass($ticket->approval_status) }}" style="margin-top:4px">{{ $approvalLabel($ticket->approval_status) }}</span>@endif</td>
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
    var cards = @json($cards);
    var categoriesByDepartment = @json($categoriesByDepartment);
    var categoryMeta = @json($categoryMeta);
    var oldFieldValues = @json(old()) || {};
    var autoFieldValues = {
        merchant_group: @json($merchant->agent?->name ?: '-'),
        merchant_name: @json($merchant->name),
    };
    var cardInput = document.getElementById('ticket-card-input');
    var cardEls = document.querySelectorAll('#ticket-category-cards .ticket-category-card');
    var detailTitle = document.getElementById('ticket-detail-title');
    var dynamicFieldsEl = document.getElementById('ticket-dynamic-fields');
    var cardBanner = document.getElementById('ticket-card-banner');
    var cardBannerText = document.getElementById('ticket-card-banner-text');
    var categorySelect = document.getElementById('ticket-category-select');
    var responseRows = document.querySelectorAll('#ticket-response-list li');
    var descriptionField = document.getElementById('ticket-description');
    var descriptionCount = document.getElementById('ticket-description-count');
    var attachmentsInput = document.getElementById('ticket-attachments');
    var chipList = document.getElementById('ticket-attachments-chips');
    var dropzone = document.getElementById('ticket-dropzone');
    var selectedFiles = [];

    var initialCategory = @json(old('category'));

    function renderDynamicFields(fieldDefs) {
        dynamicFieldsEl.innerHTML = '';
        if (!fieldDefs.length) return;

        var grid = document.createElement('div');
        grid.className = fieldDefs.length > 2 ? 'ticket-field-grid-3' : 'form-grid';
        fieldDefs.forEach(function (f) {
            var label = document.createElement('label');
            var span = document.createElement('span');
            span.className = 'ticket-field-label';
            span.textContent = f.auto ? f.label + ' ' : f.label + ' (Opsional) ';
            if (f.tooltip) {
                var tip = document.createElement('span');
                tip.className = 'ticket-tooltip';
                tip.title = f.tooltip;
                tip.textContent = 'ⓘ';
                span.appendChild(tip);
            }
            label.appendChild(span);

            if (f.auto) {
                // Informational only - the server always fills this in from
                // the real merchant record, so there's nothing to edit here.
                var autoInput = document.createElement('input');
                autoInput.type = 'text';
                autoInput.readOnly = true;
                autoInput.value = autoFieldValues[f.key] || '-';
                label.appendChild(autoInput);
                grid.appendChild(label);
                return;
            }

            if (f.multi) {
                var wrap = PayGridMultiField.build(f.key, {mode: 'array', max: f.max || 5, placeholder: f.placeholder, type: f.type});
                label.appendChild(wrap);
                grid.appendChild(label);
                return;
            }

            var input;
            if (f.type === 'select') {
                input = document.createElement('select');
                input.appendChild(new Option('Pilih ' + f.label.toLowerCase(), ''));
                f.options.forEach(function (opt) { input.appendChild(new Option(opt, opt)); });
            } else {
                input = document.createElement('input');
                input.type = f.type;
                if (f.placeholder) input.placeholder = f.placeholder;
                if (f.type === 'number') input.min = '0';
            }
            input.name = f.key;
            if (oldFieldValues[f.key]) input.value = oldFieldValues[f.key];
            label.appendChild(input);
            grid.appendChild(label);
        });
        dynamicFieldsEl.appendChild(grid);
    }

    function updateForCategory(categoryKey) {
        var meta = categoryMeta[categoryKey] || {banner: null, fields: []};
        var options = categoriesByDepartment[cards[cardInput.value].department] || {};
        detailTitle.textContent = 'Detail ' + (options[categoryKey] || categoryKey);
        cardBanner.hidden = !meta.banner;
        cardBannerText.textContent = meta.banner || '';
        renderDynamicFields(meta.fields || []);
    }

    function selectCard(key, preselectCategory) {
        var card = cards[key];
        if (!card) return;
        cardInput.value = key;
        cardEls.forEach(function (el) { el.classList.toggle('selected', el.dataset.cardKey === key); });
        responseRows.forEach(function (el) { el.classList.toggle('active', el.dataset.responseRow === key); });

        var options = categoriesByDepartment[card.department] || {};
        categorySelect.innerHTML = '';
        Object.keys(options).forEach(function (optKey) {
            categorySelect.appendChild(new Option(options[optKey], optKey));
        });
        var chosenCategory = (preselectCategory && options[preselectCategory]) ? preselectCategory : card.default_category;
        categorySelect.value = chosenCategory;
        updateForCategory(chosenCategory);
    }

    cardEls.forEach(function (el) {
        el.addEventListener('click', function () { selectCard(el.dataset.cardKey); });
    });
    categorySelect.addEventListener('change', function () { updateForCategory(categorySelect.value); });
    selectCard(cardInput.value || 'transaksi', initialCategory);

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

    var step1 = document.getElementById('ticket-step-1');
    var step2 = document.getElementById('ticket-step-2');
    var stepIndicators = document.querySelectorAll('[data-step-indicator]');
    var continueBtn = document.getElementById('ticket-continue');
    var backBtn = document.getElementById('ticket-back');
    var form = document.getElementById('ticket-wizard-form');
    form.addEventListener('submit', function () {
        PayGridMultiField.collect(form);
    });

    function setStepIndicator(step) {
        stepIndicators.forEach(function (el) {
            var n = parseInt(el.dataset.stepIndicator, 10);
            el.classList.toggle('active', n === step);
            el.classList.toggle('done', n < step);
        });
    }

    function goToConfirm() {
        if (!form.reportValidity()) return;
        var key = cardInput.value;
        var card = cards[key];
        var categoryLabel = (categoriesByDepartment[card.department] || {})[categorySelect.value] || categorySelect.value;
        document.getElementById('ticket-confirm-category').textContent = card.label + ' - ' + categoryLabel;
        document.getElementById('ticket-confirm-response').textContent = card.response;
        document.getElementById('ticket-confirm-description').textContent = descriptionField.value || '-';

        var metaEl = document.getElementById('ticket-confirm-metadata');
        metaEl.innerHTML = '';
        ((categoryMeta[categorySelect.value] || {}).fields || []).forEach(function (f) {
            var display;
            if (f.auto) {
                display = autoFieldValues[f.key] || '-';
            } else if (f.multi) {
                var wrap = dynamicFieldsEl.querySelector('[data-multi-field="' + f.key + '"]');
                var values = wrap ? Array.from(wrap.querySelectorAll('.multi-field-input')).map(function (i) { return i.value.trim(); }).filter(Boolean) : [];
                if (!values.length) return;
                display = values.join(', ');
            } else {
                var el = form[f.key];
                if (!el || !el.value) return;
                display = f.type === 'number' ? 'Rp' + Number(el.value).toLocaleString('id-ID') : el.value;
            }
            var pill = document.createElement('div');
            pill.className = 'fee-pill';
            pill.innerHTML = '<span>' + f.label + '</span><strong>' + display + '</strong>';
            metaEl.appendChild(pill);
        });

        var attachmentsText = selectedFiles.length ? selectedFiles.map(function (f) { return f.name; }).join(', ') : 'Tidak ada lampiran';
        document.getElementById('ticket-confirm-attachments').textContent = attachmentsText;

        step1.hidden = true;
        step2.hidden = false;
        setStepIndicator(2);
        window.scrollTo({top: 0, behavior: 'smooth'});
    }

    if (continueBtn) continueBtn.addEventListener('click', goToConfirm);
    if (backBtn) backBtn.addEventListener('click', function () {
        step2.hidden = true;
        step1.hidden = false;
        setStepIndicator(1);
        window.scrollTo({top: 0, behavior: 'smooth'});
    });
})();
</script>
@endpush
