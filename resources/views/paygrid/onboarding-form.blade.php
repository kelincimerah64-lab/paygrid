@php($usable = ! isset($link) || ! $link || $link->isUsable())
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Form Registrasi Toko | PayGrid</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800;900&display=swap');
        :root { --blue:#1557c2; --blue-2:#1f6fe5; --ink:#06162f; --muted:#55657a; --line:#dbe5f2; --bg:#f5f8fc; --soft:#eef4fb; --success:#008450; --danger:#c62828; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:"Plus Jakarta Sans", Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; color:var(--ink); background:radial-gradient(circle at top right, #eaf2ff 0, transparent 34%), var(--bg); }
        .page { min-height:100vh; padding:28px 18px 42px; }
        .wrap { width:min(1040px, 100%); margin:0 auto; }
        .muted { color:var(--muted); }
        .card { background:#fff; border:1px solid var(--line); border-radius:14px; box-shadow:0 8px 26px rgba(14, 35, 70, .055); }
        .pad { padding:20px; }
        .section { margin-top:18px; }
        .notice { margin-bottom:16px; }
        .notice.success { border-color:#81dfa9; color:var(--success); }
        .notice.danger { border-color:#f4b3b3; color:var(--danger); }
        .form-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:16px; }
        label { display:grid; gap:8px; font-size:11.5px; letter-spacing:.04em; color:#26364f; text-transform:uppercase; font-weight:800; }
        input, select { width:100%; border:1px solid #c9d6ea; border-radius:9px; min-height:40px; padding:9px 11px; font:inherit; font-size:13px; background:#fff; color:var(--ink); }
        input:disabled, select:disabled { background:#f3f6fa; color:#8b98aa; }
        .actions { display:flex; gap:10px; align-items:center; justify-content:flex-end; flex-wrap:wrap; }
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; min-height:40px; padding:9px 16px; border:1px solid #b9cef6; border-radius:9px; background:#fff; color:var(--blue); font:inherit; font-size:13px; font-weight:800; cursor:pointer; }
        .btn.primary { background:var(--blue); color:#fff; border-color:var(--blue); }
        .btn:disabled { color:#8b98aa; background:#f3f6fa; border-color:#d8e2f2; cursor:not-allowed; }

        /* Onboarding-specific redesign chrome */
        .ob-header { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:18px; padding:22px; border:1px solid #d8e5f7; border-radius:14px; background:#fff; box-shadow:0 8px 26px rgba(14, 35, 70, .055); }
        .ob-header-left { display:flex; align-items:center; gap:14px; }
        .ob-header-icon { width:48px; height:48px; flex:0 0 48px; border-radius:12px; background:var(--blue); color:#fff; display:grid; place-items:center; }
        .ob-header-icon svg { width:24px; height:24px; }
        .ob-header h1 { margin:0; font-size:26px; line-height:1.1; letter-spacing:-.03em; }
        .ob-header .sub { margin-top:4px; color:#304158; line-height:1.4; font-size:13.5px; }
        .ob-step { display:inline-flex; align-items:center; gap:8px; padding:8px 14px; border-radius:999px; background:var(--soft); color:var(--blue); font-size:12.5px; font-weight:800; white-space:nowrap; }
        .ob-step-dot { width:20px; height:20px; border-radius:50%; background:var(--blue); color:#fff; display:grid; place-items:center; font-size:11px; font-weight:900; }

        .ob-card { margin-top:16px; overflow:hidden; }
        .ob-card-head { display:flex; align-items:center; gap:10px; padding:14px 20px; background:var(--soft); border-bottom:1px solid var(--line); }
        .ob-card-icon { width:30px; height:30px; flex:0 0 30px; border-radius:8px; background:var(--blue); color:#fff; display:grid; place-items:center; }
        .ob-card-icon svg { width:16px; height:16px; }
        .ob-card-head h2 { margin:0; font-size:15px; }
        .ob-card-body { padding:20px; }

        .ob-toggle { position:relative; display:inline-flex; align-items:center; gap:10px; }
        .ob-toggle-switch { position:relative; display:inline-block; width:42px; height:23px; flex:0 0 auto; }
        .ob-toggle-switch input { position:absolute; opacity:0; width:100%; height:100%; margin:0; cursor:pointer; }
        .ob-toggle-switch .slider { position:absolute; inset:0; background:#cbd5e1; border-radius:999px; transition:background .15s ease; pointer-events:none; }
        .ob-toggle-switch .slider::before { content:""; position:absolute; width:17px; height:17px; left:3px; top:3px; background:#fff; border-radius:50%; transition:transform .15s ease; box-shadow:0 1px 3px rgba(14,35,70,.25); }
        .ob-toggle-switch input:checked + .slider { background:var(--blue); }
        .ob-toggle-switch input:checked + .slider::before { transform:translateX(19px); }
        .ob-toggle-label { font-size:12px; font-weight:700; color:var(--ink); text-transform:none; letter-spacing:normal; }

        .ob-iplist { border:1px solid var(--line); border-radius:10px; padding:10px; background:#fbfdff; }
        .ob-iplist-title { font-size:11px; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; margin-bottom:8px; }
        .ob-iplist-rows input { margin-top:0; }
        .ob-iplist-rows input + input { margin-top:6px; }
        .ob-iplist-add { margin-top:8px; background:none; border:none; color:var(--blue); font-size:12px; font-weight:800; cursor:pointer; padding:0; text-transform:none; letter-spacing:normal; }
        .ob-iplist-add:disabled { color:#8b98aa; cursor:not-allowed; }
        .ob-required { color:var(--danger); }
        .ob-note { margin-top:14px; padding:12px 14px; border-radius:10px; background:#fff5f5; border:1px solid #f4c7c7; color:#9a2020; font-size:12px; line-height:1.5; text-transform:none; letter-spacing:normal; font-weight:600; }
        .ob-card-head-note { margin-left:auto; font-size:11px; font-weight:800; color:#9a2020; text-transform:uppercase; letter-spacing:.04em; }
        .ob-locked { position:relative; }
        .ob-locked-input { padding-right:38px; background:#f3f6fa; color:#8b98aa; cursor:not-allowed; }
        .ob-locked-icon { position:absolute; right:11px; top:50%; transform:translateY(-50%); width:15px; height:15px; color:#8b98aa; pointer-events:none; }
        .ob-script-only[hidden] { display:none; }

        @media (max-width: 860px) { .form-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 720px) {
            .page { padding:20px 14px 34px; }
            .pad { padding:16px; }
            .ob-header { padding:16px; }
            .ob-header h1 { font-size:22px; }
            .ob-card-body { padding:16px; }
            .form-grid { grid-template-columns:1fr; }
            .actions { justify-content:stretch; }
            .btn { width:100%; }
        }
    </style>
</head>
<body>
<main class="page">
<div class="wrap">

<header class="ob-header">
    <div class="ob-header-left">
        <div class="ob-header-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l1-5h16l1 5"/><path d="M3 9a2 2 0 0 0 4 0 2 2 0 0 0 4 0 2 2 0 0 0 4 0 2 2 0 0 0 4 0"/><path d="M5 9v10h14V9"/><path d="M9 21v-6h6v6"/></svg></div>
        <div>
            <h1>Merchant Onboarding</h1>
        </div>
    </div>
    <div class="ob-step"><span class="ob-step-dot">1</span> Data Merchant</div>
</header>

@if(session('status'))
    <div class="card pad notice success">{{ session('status') }}</div>
@endif
@if(! $usable)
    <div class="card pad notice danger"><strong>Link sudah expired.</strong><br><span class="muted">Link onboarding ini hanya bisa dipakai satu kali. Silakan minta agen generate link baru jika perlu submit ulang.</span></div>
@endif

<form method="post" action="{{ $link ? route('merchant-registration.token-store', $link) : route('merchant-registration.store') }}">
    @csrf
    <input type="hidden" name="agent_code" value="{{ $agent?->code ?? 'AG-EPC' }}">

    <section class="card ob-card">
        <div class="ob-card-head">
            <span class="ob-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a7 7 0 0 1 7-7h2a7 7 0 0 1 7 7v1"/></svg></span>
            <h2>Informasi Merchant</h2>
        </div>
        <div class="ob-card-body form-grid">
            <label>Merchant Group<input name="store_name" placeholder="contoh: Tambang SBO" required></label>
            <label>Merchant Name<input name="engine_name" placeholder="contoh: GENESIS DIGITAL"></label>
            <label>Tipe Merchant
                <div style="display:flex; align-items:center; gap:18px; border:1px solid #c9d6ea; border-radius:9px; min-height:40px; padding:9px 11px; background:#fff">
                    <span style="display:flex; align-items:center; gap:6px"><input type="checkbox" class="ob-engine-type-checkbox" data-engine-type="api" onchange="paygridSelectEngineType(this)" style="width:auto; min-height:auto; margin:0"><span style="text-transform:none; font-weight:700; font-size:13px; color:var(--ink)">API</span></span>
                    <span style="display:flex; align-items:center; gap:6px"><input type="checkbox" class="ob-engine-type-checkbox" data-engine-type="sc" onchange="paygridSelectEngineType(this)" style="width:auto; min-height:auto; margin:0"><span style="text-transform:none; font-weight:700; font-size:13px; color:var(--ink)">Script</span></span>
                </div>
            </label>
            <input type="hidden" name="merchant_type" value="script">
            <input type="hidden" name="engine_type" id="onboarding-engine-type-value" value="">
            <label>Merchant MDR
                <div class="ob-locked">
                    <input type="text" class="ob-locked-input" value="Di isi Pihak PG" readonly tabindex="-1">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="ob-locked-icon"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
            </label>
            <label>Merchant Disbursement
                <div class="ob-locked">
                    <input type="text" class="ob-locked-input" value="Di isi Pihak PG" readonly tabindex="-1">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="ob-locked-icon"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
            </label>
        </div>
    </section>

    <section class="card ob-card">
        <div class="ob-card-head">
            <span class="ob-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
            <h2>PIC &amp; Kontak Toko</h2>
            <span class="ob-card-head-note">Harus di isi untuk verifikasi</span>
        </div>
        <div class="ob-card-body">
            <div class="form-grid">
                <label><span>Email untuk User ID Merchant <span class="ob-required">*</span></span><input name="admin_email" type="email" placeholder="admin@domain.com" required></label>
                <label><span>Email FINANCE [ Bisa Disburse ] <span class="ob-required">*</span></span><input name="pic_email" type="email" placeholder="finance@domain.com" required></label>
                <label><span>Email CS / OPS Merchant <span class="ob-required">*</span></span><input name="pic_telegram" type="email" placeholder="cs@domain.com" required></label>
                <label><span>Email Untuk PIC Tambahan <span class="ob-required">*</span></span><input name="finance_email" type="email" placeholder="pic@domain.com" required></label>
                <label><span>Tele / WA untuk Verifikasi <span class="ob-required">*</span></span><input name="finance_telegram" placeholder="@username atau 0812xxxxxxx" required></label>
                <label><span>Tele Head CS / Head OPS Merchant <span class="ob-required">*</span></span><input name="cs_email" placeholder="@username atau 0812xxxxxxx" required></label>
                <label><span>Tele / WA untuk Notifikasi Manager <span class="ob-required">*</span></span><input name="cs_telegram" placeholder="@username atau 0812xxxxxxx" required></label>
            </div>
            <div class="ob-note">Data yang di berikan di sini harus account aktif karena akan di gunakan untuk VERIFIKASI di masa depan untuk perubahan perubahan akun Merchant anda. Tidak ada nya perubahan terhadap akun anda, menjadi tanggung Jawab anda sepenuhnya.</div>
        </div>
    </section>

    <section class="card ob-card">
        <div class="ob-card-head">
            <span class="ob-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
            <h2>Akses, Callback &amp; Whitelist</h2>
        </div>
        <div class="ob-card-body">
            <div class="form-grid">
                <label>Transaction Callback<input name="transaction_callback_url" placeholder="https://domain/api/callback/transaction"></label>
                <label>Disbursement Callback<input name="withdrawal_callback_url" placeholder="https://domain/api/callback/withdrawal"></label>
                <label>Settlement Type ID
                    <select name="settlement_type_id">
                        <option value="">diisi MA jika belum tahu</option>
                        <option value="H+1">H+1</option>
                        <option value="Everyday [ED]">Everyday [ED]</option>
                        <option value="Sameday [SD]">Sameday [SD]</option>
                    </select>
                </label>
            </div>
            <input type="hidden" name="is_whitelisted" value="1">

            <div class="form-grid section">
                <div class="ob-iplist" data-ip-list="api_ip_whitelist">
                    <div class="ob-iplist-title">API IP Whitelisting</div>
                    <div class="ob-iplist-rows"><input name="api_ip_whitelist[]" placeholder="IP Whitelist #1"></div>
                    <button type="button" class="ob-iplist-add" data-ip-add>+ Tambah IP</button>
                </div>
                <div class="ob-iplist" data-ip-list="ip_dashboard">
                    <div class="ob-iplist-title">Dashboard IP Whitelisting</div>
                    <div class="ob-iplist-rows"><input name="ip_dashboard[]" placeholder="IP/URL Dashboard CS #1"></div>
                    <button type="button" class="ob-iplist-add" data-ip-add>+ Tambah IP</button>
                </div>
                <div class="ob-iplist" data-ip-list="ip_finance">
                    <div class="ob-iplist-title">IP Finance</div>
                    <div class="ob-iplist-rows"><input name="ip_finance[]" placeholder="IP/URL Finance #1"></div>
                    <button type="button" class="ob-iplist-add" data-ip-add>+ Tambah IP</button>
                </div>
                <label>URL Web Panel Admin<input name="web_panel_admin_url" placeholder="https://domain/admin"></label>
            </div>
            <div class="ob-note">WHITELIST IP ADALAH KEHARUSAN - tanpa IP terdaftar di sini, akses ke BO / Dashboard tidak bisa dilakukan.</div>

            <div class="form-grid section ob-script-only" hidden>
                <label>Callback Alpha<input name="callback_alpha_url" placeholder="https://domain/api/callback/alpha" data-script-required></label>
            </div>
            <div class="form-grid section ob-script-only" hidden>
                <div class="ob-iplist" data-ip-list="ip_pg">
                    <div class="ob-iplist-title">IP PG</div>
                    <div class="ob-iplist-rows"><input name="ip_pg[]" placeholder="IP PG #1" data-script-required></div>
                    <button type="button" class="ob-iplist-add" data-ip-add>+ Tambah IP</button>
                </div>
                <div class="ob-iplist" data-ip-list="ip_alpha">
                    <div class="ob-iplist-title">IP Alpha</div>
                    <div class="ob-iplist-rows"><input name="ip_alpha[]" placeholder="IP Alpha #1" data-script-required></div>
                    <button type="button" class="ob-iplist-add" data-ip-add>+ Tambah IP</button>
                </div>
            </div>
        </div>
    </section>

    <div class="actions section">
        <button type="reset" class="btn" @disabled(! $usable)>Reset</button>
        <button class="btn primary" @disabled(! $usable)>Submit Data</button>
    </div>
</form>
@if(! $usable)
    <script>document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('form input, form select, form button').forEach((el) => el.disabled = true));</script>
@endif
<script>
function paygridSelectEngineType(checkbox) {
    document.querySelectorAll('.ob-engine-type-checkbox').forEach(function (cb) {
        if (cb !== checkbox) cb.checked = false;
    });
    var engineType = checkbox.checked ? checkbox.dataset.engineType : '';
    document.getElementById('onboarding-engine-type-value').value = engineType;

    var isScript = engineType === 'sc';
    document.querySelectorAll('.ob-script-only').forEach(function (el) { el.hidden = ! isScript; });
    document.querySelectorAll('[data-script-required]').forEach(function (el) { el.required = isScript; });
}
document.querySelectorAll('[data-ip-add]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var wrap = btn.closest('[data-ip-list]');
        var rows = wrap.querySelector('.ob-iplist-rows');
        var input = document.createElement('input');
        input.name = wrap.dataset.ipList + '[]';
        input.placeholder = 'IP/URL tambahan';
        rows.appendChild(input);
    });
});
</script>
</div>
</main>
</body>
</html>
