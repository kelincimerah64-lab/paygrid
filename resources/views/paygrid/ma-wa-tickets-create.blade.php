@extends('layouts.paygrid')

@section('content')
<div class="wat-hero">
    <div>
        <p class="eyebrow">{{ $roleLabel }} &middot; Pilot</p>
        <h1>Buat Tiket Percobaan</h1>
        <p class="muted" style="margin:6px 0 0; font-size:13px">Untuk menguji alur notifikasi WhatsApp &rarr; klaim &rarr; handling di web.</p>
    </div>
    <a class="wat-btn outline" href="{{ route('ma.wa-tickets.index') }}">&larr; Kembali</a>
</div>

<section class="wat-panel">
    <div class="wat-panel-head"><h2>Form Tiket</h2></div>
    <form method="post" action="{{ route('ma.wa-tickets.store') }}" class="pad" style="display:flex; flex-direction:column; gap:14px">
        @csrf
        <div class="form-grid">
            <label>Toko
                <select name="merchant_id" required>
                    <option value="">Pilih Toko</option>
                    @foreach($merchants as $merchant)
                        <option value="{{ $merchant->id }}" @selected(old('merchant_id') == $merchant->id)>{{ $merchant->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Department
                <select name="department" id="wa-ticket-department" required>
                    <option value="">Pilih Department</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept }}" @selected(old('department') === $dept)>{{ $departmentLabels[$dept] }}</option>
                    @endforeach
                </select>
            </label>
            <label>Kategori
                <select name="category" id="wa-ticket-category" required disabled>
                    <option value="">Pilih Department dulu</option>
                </select>
            </label>
        </div>
        <div id="wa-ticket-banner" class="ticket-info-banner" hidden><span aria-hidden="true">&#8505;</span> <span id="wa-ticket-banner-text"></span></div>
        <div id="wa-ticket-dynamic-fields"></div>
        <label>Deskripsi
            <textarea name="description" rows="4" maxlength="500" required placeholder="Jelaskan masalah percobaan ini...">{{ old('description') }}</textarea>
        </label>
        @error('merchant_id')<span class="badge danger">{{ $message }}</span>@enderror
        @error('department')<span class="badge danger">{{ $message }}</span>@enderror
        @error('category')<span class="badge danger">{{ $message }}</span>@enderror
        @error('description')<span class="badge danger">{{ $message }}</span>@enderror
        <div><button class="wat-btn primary" type="submit">Buat Tiket &amp; Kirim Notifikasi WA</button></div>
    </form>
</section>
@endsection

@push('scripts')
<script>
(function () {
    var categoriesByDepartment = @json($categoriesByDepartment);
    var categoryMeta = @json($categoryMeta);
    var oldCategory = @json(old('category'));
    var oldValues = @json(old()) || {};
    var deptSelect = document.getElementById('wa-ticket-department');
    var catSelect = document.getElementById('wa-ticket-category');
    var dynamicFieldsEl = document.getElementById('wa-ticket-dynamic-fields');
    var banner = document.getElementById('wa-ticket-banner');
    var bannerText = document.getElementById('wa-ticket-banner-text');

    function renderDynamicFields(fieldDefs) {
        dynamicFieldsEl.innerHTML = '';
        if (!fieldDefs || !fieldDefs.length) return;
        var grid = document.createElement('div');
        grid.className = 'form-grid';
        fieldDefs.forEach(function (f) {
            var label = document.createElement('label');
            label.textContent = f.label + ' ';
            var input = document.createElement('input');
            input.type = f.type === 'number' ? 'number' : 'text';
            input.name = f.key;
            if (f.placeholder) input.placeholder = f.placeholder;
            if (oldValues[f.key]) input.value = oldValues[f.key];
            label.appendChild(input);
            grid.appendChild(label);
        });
        dynamicFieldsEl.appendChild(grid);
    }

    function updateForCategory(categoryKey) {
        var meta = categoryMeta[categoryKey] || {banner: null, fields: []};
        banner.hidden = !meta.banner;
        bannerText.textContent = meta.banner || '';
        renderDynamicFields(meta.fields || []);
    }

    function fillCategories(dept) {
        var options = categoriesByDepartment[dept] || null;
        catSelect.innerHTML = '';
        if (!options) {
            catSelect.appendChild(new Option('Pilih Department dulu', ''));
            catSelect.disabled = true;
            renderDynamicFields([]);
            banner.hidden = true;
            return;
        }
        catSelect.disabled = false;
        catSelect.appendChild(new Option('Pilih Kategori', ''));
        Object.keys(options).forEach(function (key) {
            catSelect.appendChild(new Option(options[key], key));
        });
        var chosen = (oldCategory && options[oldCategory]) ? oldCategory : Object.keys(options)[0];
        catSelect.value = chosen;
        updateForCategory(chosen);
    }

    deptSelect.addEventListener('change', function () { fillCategories(deptSelect.value); });
    catSelect.addEventListener('change', function () { updateForCategory(catSelect.value); });
    if (deptSelect.value) fillCategories(deptSelect.value);
})();
</script>
@endpush
