@php($usable = ! isset($link) || ! $link || $link->isUsable())
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Form Registrasi Toko | PayGrid</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800;900&display=swap');
        :root { --blue:#1557c2; --ink:#06162f; --muted:#55657a; --line:#dbe5f2; --bg:#f5f8fc; --success:#008450; --danger:#c62828; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:"Plus Jakarta Sans", Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; color:var(--ink); background:radial-gradient(circle at top right, #eaf2ff 0, transparent 34%), var(--bg); }
        .page { min-height:100vh; padding:28px 18px 42px; }
        .wrap { width:min(980px, 100%); margin:0 auto; }
        .hero { margin-bottom:18px; padding:22px; border:1px solid #d8e5f7; border-radius:14px; background:#fff; box-shadow:0 8px 26px rgba(14, 35, 70, .055); }
        h1 { margin:0; font-size:30px; line-height:1.08; letter-spacing:-.035em; }
        h2 { margin:0 0 14px; font-size:18px; }
        .sub { margin-top:8px; color:#304158; line-height:1.5; }
        .muted { color:var(--muted); }
        .card { background:#fff; border:1px solid var(--line); border-radius:12px; box-shadow:0 8px 26px rgba(14, 35, 70, .055); }
        .pad { padding:20px; }
        .section { margin-top:22px; }
        .notice { margin-bottom:16px; }
        .notice.success { border-color:#81dfa9; color:var(--success); }
        .notice.danger { border-color:#f4b3b3; color:var(--danger); }
        .form-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px; }
        label { display:grid; gap:8px; font-size:12px; letter-spacing:.05em; color:#26364f; text-transform:uppercase; font-weight:900; }
        input, select { width:100%; border:1px solid #c9d6ea; border-radius:9px; min-height:40px; padding:9px 11px; font:inherit; font-size:13px; background:#fff; color:var(--ink); }
        input:disabled, select:disabled { background:#f3f6fa; color:#8b98aa; }
        .actions { display:flex; gap:10px; align-items:center; justify-content:flex-end; flex-wrap:wrap; }
        .btn { display:inline-flex; align-items:center; justify-content:center; min-height:38px; padding:9px 14px; border:1px solid #b9cef6; border-radius:9px; background:#fff; color:var(--blue); font:inherit; font-size:13px; font-weight:900; cursor:pointer; }
        .btn.primary { background:var(--blue); color:#fff; border-color:var(--blue); }
        .btn:disabled { color:#8b98aa; background:#f3f6fa; border-color:#d8e2f2; cursor:not-allowed; }
        @media (max-width: 720px) {
            .page { padding:20px 14px 34px; }
            .hero, .pad { padding:16px; }
            h1 { font-size:26px; }
            .form-grid { grid-template-columns:1fr; }
            .actions { justify-content:stretch; }
            .btn { width:100%; }
        }
    </style>
</head>
<body>
<main class="page">
<div class="wrap">
<section class="hero">
    <h1>Form Registrasi Toko</h1>
    <div class="sub">Form terhubung ke agen {{ $agent?->name ?? 'default' }}. Data masuk ke agen dahulu, tidak langsung dikirim ke HG.</div>
</section>

@if(session('status'))
    <div class="card pad notice success">{{ session('status') }}</div>
@endif
@if(! $usable)
    <div class="card pad notice danger"><strong>Link sudah expired.</strong><br><span class="muted">Link onboarding ini hanya bisa dipakai satu kali. Silakan minta agen generate link baru jika perlu submit ulang.</span></div>
@endif

<form method="post" action="{{ $link ? route('merchant-registration.token-store', $link) : route('merchant-registration.store') }}" class="card pad">
    @csrf
    <input type="hidden" name="agent_code" value="{{ $agent?->code ?? 'AG-EPC' }}">

    <h2>Data Dasar</h2>
    <div class="form-grid">
        <label>Nama Toko<input name="store_name" placeholder="contoh: Tambang SBO" required></label>
        <label>Engine Name<input name="engine_name" placeholder="contoh: GENESIS DIGITAL"></label>
        <label>Tipe Merchant<select name="merchant_type"><option value="cm">CM</option><option value="script">Script</option></select></label>
        <label>Payment Gateway<select name="gateway"><option value="hilogate">Hilogate</option><option value="alpha">Alpha</option><option value="artageto">Artageto</option><option value="kingspay">KingsPay</option></select></label>
        <label>Menu Fee<select name="fee_menu"><option value="">Belum dipilih</option>@foreach($feeMenuOptions as $key => $option)<option value="{{ $key }}">{{ $option['label'] }}</option>@endforeach</select></label>
        <label>Settlement Type ID<input name="settlement_type_id" placeholder="diisi MA jika belum tahu"></label>
    </div>

    <h2 class="section">PIC dan User Toko</h2>
    <div class="form-grid">
        <label>Email PIC<input name="pic_email" type="email" placeholder="pic@domain.com"></label>
        <label>Telegram PIC<input name="pic_telegram" placeholder="@usernamepic"></label>
        <label>Email Finance<input name="finance_email" type="email" placeholder="finance@domain.com"></label>
        <label>Telegram Finance<input name="finance_telegram" placeholder="@usernamefinance"></label>
        <label>Email CS<input name="cs_email" type="email" placeholder="cs@domain.com"></label>
        <label>Telegram CS<input name="cs_telegram" placeholder="@usernamecs"></label>
    </div>

    <h2 class="section">Akses dan Callback</h2>
    <div class="form-grid">
        <label>IP Dashboard<input name="ip_dashboard" placeholder="IP atau URL dashboard CS"></label>
        <label>IP Finance<input name="ip_finance" placeholder="IP atau URL finance/withdrawal"></label>
        <label>Transaction Callback<input name="transaction_callback_url" placeholder="https://domain/api/callback/transaction"></label>
        <label>Withdrawal Callback<input name="withdrawal_callback_url" placeholder="https://domain/api/callback/withdrawal"></label>
        <label>API IP Whitelist<input name="api_ip_whitelist" placeholder="pisahkan koma jika lebih dari satu"></label>
        <label>Whitelist<select name="is_whitelisted"><option value="0">Tidak</option><option value="1">Ya</option></select></label>
    </div>

    <h2 class="section">ID Gateway dan Fee</h2>
    <div class="form-grid">
        <label>Transaction Gateway ID<input name="transaction_gateway_ids" placeholder="default Hilogate jika kosong"></label>
        <label>Withdrawal Gateway ID<input name="withdrawal_gateway_ids" placeholder="default Hilogate jika kosong"></label>
        <label>Merchant MDR (%)<input name="merchant_mdr_percent" inputmode="decimal" placeholder="contoh: 1.2"></label>
        <label>Base MDR / HG (%)<input name="base_mdr_percent" inputmode="decimal" placeholder="contoh: 0.8"></label>
        <label>MA Fee (%)<input name="ma_fee_percent" inputmode="decimal" placeholder="contoh: 0.4"></label>
        <label>Agent Fee (%)<input name="agent_fee_percent" inputmode="decimal" placeholder="contoh: 0"></label>
        <label>Pay In Fee (%)<input name="payin_fee_percent" inputmode="decimal" placeholder="cashback/marketing fee"></label>
        <label>Disbursement Fee Fixed<input name="disbursement_fee_fixed" inputmode="numeric" placeholder="fee x jumlah disbursement"></label>
    </div>

    <div class="actions section">
        <button type="reset" class="btn" @disabled(! $usable)>Reset</button>
        <button class="btn primary" @disabled(! $usable)>Submit Data</button>
    </div>
</form>
@if(! $usable)
    <script>document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('form input, form select, form button').forEach((el) => el.disabled = true));</script>
@endif
</div>
</main>
</body>
</html>
