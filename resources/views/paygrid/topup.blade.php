<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $merchant?->name ?? 'Top Up' }} | PayGrid</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800;900&display=swap');
        :root { --blue:#1557c2; --ink:#06162f; --muted:#55657a; --line:#dbe5f2; --bg:#f5f8fc; --danger:#c62828; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; font-family:"Plus Jakarta Sans", Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; color:var(--ink); background:radial-gradient(circle at top right, #eaf2ff 0, transparent 34%), var(--bg); }
        .page { min-height:100vh; display:grid; place-items:center; padding:28px 16px; }
        .wrap { width:min(860px, 100%); }
        .card { background:#fff; border:1px solid var(--line); border-radius:14px; box-shadow:0 14px 40px rgba(14, 35, 70, .07); }
        .pad { padding:24px; }
        h1 { margin:0; font-size:32px; line-height:1.05; letter-spacing:-.035em; text-align:center; }
        .sub { margin:8px 0 0; color:#304158; text-align:center; line-height:1.45; }
        .merchant { margin:16px auto 0; width:max-content; max-width:100%; padding:7px 12px; border:1px solid #d8e5f7; border-radius:999px; background:#f8fbff; color:#1557c2; font-size:12px; font-weight:900; }
        .section { margin-top:22px; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        label { display:grid; gap:8px; font-size:12px; letter-spacing:.05em; color:#26364f; text-transform:uppercase; font-weight:900; }
        input { width:100%; border:1px solid #c9d6ea; border-radius:9px; min-height:46px; padding:10px 13px; font:inherit; font-size:14px; background:#fff; color:var(--ink); }
        .btn { display:inline-flex; align-items:center; justify-content:center; width:100%; min-height:46px; border:1px solid var(--blue); border-radius:9px; background:var(--blue); color:#fff; font:inherit; font-size:14px; font-weight:950; cursor:pointer; }
        .notice { border-radius:10px; padding:13px 14px; font-size:13px; font-weight:800; line-height:1.45; }
        .notice.warn { background:#fff9e9; border:1px solid #ffd46d; color:#8a4b00; }
        .notice.danger { background:#fff1f0; border:1px solid #f0b4ae; color:var(--danger); }
        @media (max-width: 720px) {
            .page { align-items:start; padding-top:22px; }
            .pad { padding:18px; }
            h1 { font-size:28px; }
            .form-grid { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<main class="page">
    <div class="wrap">
        <section class="card pad">
            <h1>Top Up</h1>
            <p class="sub">Masukkan User ID member dan nominal deposit.</p>
            @if($merchant)<div class="merchant">{{ $merchant->name }}</div>@endif

            @if(!$merchant || !$merchant->topup_enabled)
                <div class="notice warn section">Toko ini tidak memakai link topup PayGrid. Silakan hubungi admin toko.</div>
            @else
                @php($minimumAmount = $merchant->minimumTopupAmount())
                @if($errors->any())<div class="notice danger section">{{ $errors->first() }}</div>@endif
                <form method="POST" action="{{ route('topup.submit', $merchant) }}" class="form-grid section">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
                    <label>User ID<input name="customer_reference" value="{{ old('customer_reference') }}" required placeholder="User ID member"></label>
                    <label>Nominal<input data-money-input inputmode="numeric" type="text" value="{{ old('amount') ? number_format((int) old('amount'), 0, ',', '.') : '' }}" required placeholder="Minimal Rp {{ number_format($minimumAmount, 0, ',', '.') }}"><input data-money-raw name="amount" type="hidden" value="{{ old('amount') }}"></label>
                    <button class="btn" style="grid-column:1 / -1">Submit Top Up</button>
                </form>
            @endif
        </section>
    </div>
</main>
<script>
    document.querySelectorAll('[data-money-input]').forEach((input) => {
        const raw = input.parentElement.querySelector('[data-money-raw]');
        const format = (value) => value.replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        const sync = () => {
            const digits = input.value.replace(/\D/g, '');
            input.value = format(digits);
            if (raw) raw.value = digits;
        };
        input.addEventListener('input', sync);
        input.closest('form')?.addEventListener('submit', sync);
        sync();
    });
</script>
</body>
</html>
