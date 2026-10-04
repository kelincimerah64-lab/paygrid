@extends('layouts.paygrid')

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">{{ $roleLabel }}</div>
        <h1>Buat Tiket Percobaan</h1>
        <p class="sub muted">Buat tiket uji coba untuk menguji alur notifikasi WhatsApp &rarr; klaim &rarr; handling di web.</p>
    </div>
    <a class="btn compact-btn" href="{{ route('ma.wa-tickets.index') }}">Kembali</a>
</div>

<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Form Tiket</h2></div>
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
        <label>Deskripsi
            <textarea name="description" rows="4" maxlength="500" required placeholder="Jelaskan masalah percobaan ini...">{{ old('description') }}</textarea>
        </label>
        @error('merchant_id')<span class="badge danger">{{ $message }}</span>@enderror
        @error('department')<span class="badge danger">{{ $message }}</span>@enderror
        @error('category')<span class="badge danger">{{ $message }}</span>@enderror
        @error('description')<span class="badge danger">{{ $message }}</span>@enderror
        <div><button class="btn primary" type="submit">Buat Tiket &amp; Kirim Notifikasi WA</button></div>
    </form>
</section>
@endsection

@push('scripts')
<script>
(function () {
    var categoriesByDepartment = @json($categoriesByDepartment);
    var oldCategory = @json(old('category'));
    var deptSelect = document.getElementById('wa-ticket-department');
    var catSelect = document.getElementById('wa-ticket-category');

    function fillCategories(dept) {
        var options = categoriesByDepartment[dept] || null;
        catSelect.innerHTML = '';
        if (!options) {
            catSelect.appendChild(new Option('Pilih Department dulu', ''));
            catSelect.disabled = true;
            return;
        }
        catSelect.disabled = false;
        catSelect.appendChild(new Option('Pilih Kategori', ''));
        Object.keys(options).forEach(function (key) {
            catSelect.appendChild(new Option(options[key], key));
        });
        if (oldCategory && options[oldCategory]) catSelect.value = oldCategory;
    }

    deptSelect.addEventListener('change', function () { fillCategories(deptSelect.value); });
    if (deptSelect.value) fillCategories(deptSelect.value);
})();
</script>
@endpush
