@extends('layouts.paygrid')

@section('content')

<section class="card pad section">
    <h2>Form Insentif</h2>
    <p class="muted" style="margin-top:4px">Pilih toko dan bulan, lalu export rekapan insentif fee agen dalam bentuk PDF.</p>

    <form method="post" action="{{ route('agent.insentif.pdf') }}" id="insentif-form" style="margin-top:14px; display:flex; flex-direction:column; gap:14px; max-width:520px">
        @csrf
        <div>
            <label for="insentif-month" style="display:block; font-size:12px; font-weight:800; color:var(--muted); margin-bottom:6px">Bulan</label>
            <input type="month" name="month" id="insentif-month" value="{{ $defaultMonth }}" required>
        </div>
        <div>
            <label style="display:block; font-size:12px; font-weight:800; color:var(--muted); margin-bottom:6px">Toko</label>
            <div class="export-store-tags" id="insentif-store-tags"></div>
            <input type="text" id="insentif-store-search" placeholder="Ketik nama toko lalu pilih dari daftar..." list="insentif-store-datalist" autocomplete="off">
            <datalist id="insentif-store-datalist"></datalist>
            <script id="insentif-store-data" type="application/json">@json($merchants->map(fn ($m) => ['id' => $m->id, 'name' => $m->name]))</script>
        </div>
        <div>
            <button type="submit" class="btn primary">Export PDF</button>
        </div>
    </form>
</section>

<script>
(() => {
    const data = document.getElementById('insentif-store-data');
    const search = document.getElementById('insentif-store-search');
    const tags = document.getElementById('insentif-store-tags');
    const datalist = document.getElementById('insentif-store-datalist');
    const form = document.getElementById('insentif-form');
    if (!data || !search || !tags || !datalist || !form) return;

    const stores = JSON.parse(data.textContent || '[]');
    const selected = new Map();

    const renderOptions = () => {
        datalist.innerHTML = '';
        stores.filter((store) => !selected.has(store.id)).forEach((store) => {
            const option = document.createElement('option');
            option.value = store.name;
            datalist.appendChild(option);
        });
    };
    const renderTags = () => {
        tags.innerHTML = '';
        selected.forEach((name, id) => {
            const tag = document.createElement('span');
            tag.className = 'export-store-tag';
            tag.textContent = name;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.textContent = '×';
            remove.addEventListener('click', () => { selected.delete(id); renderTags(); renderOptions(); });
            tag.appendChild(remove);
            tags.appendChild(tag);
        });
    };

    renderOptions();
    search.addEventListener('input', () => {
        const match = stores.find((store) => store.name === search.value && !selected.has(store.id));
        if (match) {
            selected.set(match.id, match.name);
            search.value = '';
            renderTags();
            renderOptions();
        }
    });
    form.addEventListener('submit', (event) => {
        form.querySelectorAll('input[name="store_ids[]"]').forEach((el) => el.remove());
        selected.forEach((name, id) => {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'store_ids[]';
            hidden.value = id;
            form.appendChild(hidden);
        });
        if (selected.size === 0) {
            event.preventDefault();
            alert('Pilih minimal 1 toko dulu.');
        }
    });
})();
</script>

@endsection
