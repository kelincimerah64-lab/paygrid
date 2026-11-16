@php($usable = ! isset($link) || ! $link || $link->isUsable())
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Form Registrasi Toko Magnetopay | PayGrid</title>
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
        .form-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px; align-items:start; }
        .field-span-full { grid-column:1 / -1; }
        .form-grid[hidden] { display:none; }
        label[hidden] { display:none; }
        label { display:grid; gap:8px; font-size:12px; letter-spacing:.05em; color:#26364f; text-transform:uppercase; font-weight:900; }
        input, select { width:100%; border:1px solid #c9d6ea; border-radius:9px; min-height:40px; padding:9px 11px; font:inherit; font-size:13px; background:#fff; color:var(--ink); }
        input:disabled, select:disabled { background:#f3f6fa; color:#8b98aa; }
        .actions { display:flex; gap:10px; align-items:center; justify-content:flex-end; flex-wrap:wrap; }
        .btn { display:inline-flex; align-items:center; justify-content:center; min-height:38px; padding:9px 14px; border:1px solid #b9cef6; border-radius:9px; background:#fff; color:var(--blue); font:inherit; font-size:13px; font-weight:900; cursor:pointer; }
        .btn.primary { background:var(--blue); color:#fff; border-color:var(--blue); }
        .btn:disabled { color:#8b98aa; background:#f3f6fa; border-color:#d8e2f2; cursor:not-allowed; }
        .hint { text-transform:none; letter-spacing:normal; font-weight:600; font-size:11.5px; color:var(--muted); }
        .field-label-text { display:grid; gap:8px; font-size:12px; letter-spacing:.05em; color:#26364f; text-transform:uppercase; font-weight:900; }
        .radio-group { display:flex; flex-wrap:wrap; align-items:center; gap:20px; margin-top:2px; }
        .radio-row { display:flex; align-items:center; gap:8px; }
        .radio-row input[type="radio"] { width:auto; min-height:0; flex:0 0 auto; }
        .radio-row label { display:inline; text-transform:none; letter-spacing:normal; font-weight:600; font-size:13px; color:var(--ink); cursor:pointer; }
        .multi-field-row { position:relative; margin-top:8px; }
        .multi-field-row:first-child { margin-top:0; }
        .multi-field-row .multi-field-input { margin-top:0 !important; width:100%; padding-right:34px; }
        .multi-field-toggle { position:absolute; right:4px; top:50%; transform:translateY(-50%); width:26px; height:26px; min-height:26px; padding:0; display:flex; align-items:center; justify-content:center; border:1px solid #b9cef6; background:#fff; color:var(--blue); border-radius:6px; font-size:15px; font-weight:900; line-height:1; cursor:pointer; }
        .multi-field-toggle.remove { color:var(--danger); }
        .repeat-rows { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px 16px; margin-top:10px; }
        .bank-combobox { position:relative; display:block; }
        .bank-combobox-list { position:absolute; top:calc(100% + 4px); left:0; right:0; z-index:20; max-height:220px; overflow:auto; background:#fff; border:1px solid #c9d6ea; border-radius:9px; box-shadow:0 10px 26px rgba(14, 35, 70, .14); }
        .bank-combobox-item { padding:8px 11px; font-size:13px; font-weight:600; text-transform:none; letter-spacing:normal; color:var(--ink); cursor:pointer; }
        .bank-combobox-item:hover, .bank-combobox-item.active { background:#eef4fb; }
        .bank-combobox-empty { padding:8px 11px; font-size:12.5px; color:var(--muted); text-transform:none; letter-spacing:normal; }
        @media (max-width: 720px) {
            .page { padding:20px 14px 34px; }
            .hero, .pad { padding:16px; }
            h1 { font-size:26px; }
            .form-grid { grid-template-columns:1fr; }
            .repeat-rows { grid-template-columns:1fr; }
            .actions { justify-content:stretch; }
            .btn { width:100%; }
        }
    </style>
</head>
<body>
<main class="page">
<div class="wrap">
<section class="hero">
    <h1>Form Registrasi Toko Magnetopay</h1>
</section>

@if(session('status'))
    <div class="card pad notice success">{{ session('status') }}</div>
@endif
@if(! $usable)
    <div class="card pad notice danger"><strong>Link sudah expired.</strong><br><span class="muted">Link onboarding ini hanya bisa dipakai satu kali. Silakan minta MA generate link baru jika perlu submit ulang.</span></div>
@endif

<form method="post" action="{{ route('merchant-registration.magnetopay.token-store', $link) }}" class="card pad" id="onboarding-magnetopay-form">
    @csrf

    <h2>Data Dasar</h2>
    <div class="form-grid">
        <label>Merchant Group<span class="hint">Nama Group Anda jika ada beberapa toko</span><input name="store_name" placeholder="contoh: NP x Nemo Group (Everyday)" required></label>
        <label>Merchant Name<span class="hint">Nama toko Anda</span><input name="engine_name" placeholder="contoh: nm HHT | IDN"></label>
        <label>Nama Agent<span class="hint">Isi nama Agent Anda</span><input name="agent_name" placeholder="contoh: Galaxy Pay"></label>
        <label>Engine Selection<span class="hint">API: callback dari Engine. Script: callback dari Alpha. CM: tidak perlu pilihan lain</span>
            <select name="engine_type" id="engine-type-select">
                <option value="">Pilih Engine</option>
                <option value="api">API</option>
                <option value="sc">Script</option>
                <option value="cm">CM</option>
            </select>
        </label>
        <label id="engine-api-custom-wrap" hidden>Nama Engine<span class="hint">Isi sendiri nama engine Anda</span><input type="text" name="engine_api_name" id="engine-api-name" placeholder="contoh: Nama Engine Anda"></label>
        <label id="engine-script-platform-wrap" hidden>Platform Script<span class="hint">Pilih dari list</span>
            <select name="engine_script_platform" id="engine-script-platform-select">
                <option value="">Pilih Platform</option>
                <option value="One Live Group">One Live Group</option>
                <option value="UG">UG</option>
                <option value="Luxe">Luxe</option>
                <option value="Onix">Onix</option>
                <option value="IDN">IDN</option>
                <option value="Nexus">Nexus</option>
                <option value="NXT">NXT</option>
                <option value="IDN (2)">IDN</option>
                <option value="OLX Toto">OLX Toto</option>
                <option value="IDN Sport">IDN Sport</option>
                <option value="3MP">3MP</option>
                <option value="Vigor">Vigor</option>
                <option value="Luxe (2)">Luxe</option>
                <option value="MPO">MPO</option>
                <option value="3Mplay">3Mplay</option>
                <option value="Oxplay">Oxplay</option>
                <option value="P4D">P4D</option>
                <option value="custom">Isi Sendiri</option>
            </select>
        </label>
        <label id="engine-script-custom-wrap" hidden>Nama Platform<span class="hint">Isi sendiri nama platform Anda</span><input type="text" name="engine_script_platform_custom" id="engine-script-platform-custom" placeholder="contoh: Nama Platform Anda"></label>
    </div>

    <div class="form-grid section" id="engine-script-section" hidden>
        <div class="hint field-span-full" style="margin:-6px 0 2px">Info tambahan untuk Engine Script (contoh: IDN)</div>
        <label>Base URL<input name="engine_base_url" placeholder="https://engine.example.com"></label>
        <label>Token API<input name="engine_token_api" placeholder="token API engine"></label>
        <label>Agent Back Panel<input name="engine_agent_back_panel" placeholder="https://backpanel.example.com"></label>
        <label class="field-span-full">Whitelist IP Alpha<span class="hint">IP yang perlu di-whitelist di Alpha</span>
            <div class="multi-field" data-multi-field="ip_alpha" data-mode="array">
                <div class="multi-field-row"><input type="text" class="multi-field-input" placeholder="103.10.20.30"><button type="button" class="multi-field-toggle" data-action="add">+</button></div>
            </div>
        </label>
    </div>

    <h2 class="section">PIC dan User Toko</h2>
    <div class="form-grid">
        <div class="field-span-full">
            <span class="field-label-text">PIC untuk Kredensial (API &amp; Dashboard)<span class="hint" style="display:block; margin-top:4px; text-transform:none">Sampai 3 orang yang akan menerima kredensial API &amp; akses dashboard</span></span>
            <div class="repeat-rows">
                <label>Nama<input name="pic_credentials[0][name]" placeholder="Nama PIC 1"></label>
                <label>Telegram/WhatsApp<input name="pic_credentials[0][contact]" placeholder="@username atau nomor"></label>
                <label>Email<input type="email" name="pic_credentials[0][email]" placeholder="pic1@email.com"></label>
                <label>Nama<input name="pic_credentials[1][name]" placeholder="Nama PIC 2"></label>
                <label>Telegram/WhatsApp<input name="pic_credentials[1][contact]" placeholder="@username atau nomor"></label>
                <label>Email<input type="email" name="pic_credentials[1][email]" placeholder="pic2@email.com"></label>
                <label>Nama<input name="pic_credentials[2][name]" placeholder="Nama PIC 3"></label>
                <label>Telegram/WhatsApp<input name="pic_credentials[2][contact]" placeholder="@username atau nomor"></label>
                <label>Email<input type="email" name="pic_credentials[2][email]" placeholder="pic3@email.com"></label>
            </div>
        </div>
        <label>Email Group Finance<span class="hint">Jikalau memiliki lebih dari 1 toko bisa akses WD semua toko</span><input name="finance_group_user_id" type="email" placeholder="groupfinance@email.com"></label>
        <label>Email Finance<span class="hint">User ID Merchant Finance untuk Withdraw Saldo Settlement</span><input name="admin_email" type="email" placeholder="finance@email.com" required></label>
        <label>Telegram Finance<input name="finance_telegram" placeholder="@usernamefinance"></label>
        <label>Telegram CS<input name="cs_telegram" placeholder="@usernamecs"></label>
        <label class="field-span-full">Email Finance Role (maks 3)<span class="hint">Lihat semua transaksi &amp; withdrawal toko ini, bisa create withdrawal</span>
            <div class="multi-field" data-multi-field="finance_role_email" data-mode="array" data-multi-field-max="3">
                <div class="multi-field-row"><input type="email" class="multi-field-input" placeholder="finance-role@email.com"><button type="button" class="multi-field-toggle" data-action="add">+</button></div>
            </div>
        </label>
        <label class="field-span-full">Email CS / Operational (maks 3)<span class="hint">View only - cek transaksi di BO Magneto</span>
            <div class="multi-field" data-multi-field="cs_email" data-mode="array" data-multi-field-max="3">
                <div class="multi-field-row"><input type="email" class="multi-field-input" placeholder="cs@email.com"><button type="button" class="multi-field-toggle" data-action="add">+</button></div>
            </div>
        </label>
        <label class="field-span-full">User ID Telegram Admin/CS<span class="hint">Untuk Group Support 24/7 - bisa lebih dari satu</span>
            <div class="multi-field" data-multi-field="support_telegram_ids" data-mode="array">
                <div class="multi-field-row"><input type="text" class="multi-field-input" placeholder="@usernamesupport"><button type="button" class="multi-field-toggle" data-action="add">+</button></div>
            </div>
        </label>
    </div>

    <h2 class="section">Callback dan Settlement</h2>
    <div class="form-grid">
        <label>Transaction Callback URL<input name="transaction_callback_url" placeholder="https://domain/api/callback/transaction"></label>
        <label>Withdrawal Callback URL<input name="withdrawal_callback_url" placeholder="https://domain/api/callback/withdrawal"></label>
        <label>VA Callback URL<input name="va_callback_url" placeholder="https://domain/api/callback/va"></label>
        <label>Catatan Tambahan<input name="additional_notes" placeholder="Catatan lain-lain (opsional)"></label>
        <div class="field-label-text field-span-full">QRIS Settlement Type
            <span class="radio-group">
                <span class="radio-row"><input type="radio" name="qris_settlement_type" value="1x Sehari (Jam 14:00)" id="qst-1x"><label for="qst-1x">Settlement 1x Sehari Jam 14:00</label></span>
                <span class="radio-row"><input type="radio" name="qris_settlement_type" value="3x Sehari (Jam 10:00 | 15:00 | 20:00)" id="qst-3x"><label for="qst-3x">Settlement 3x Sehari Jam 10:00 | 15:00 | 20:00</label></span>
            </span>
        </div>
        <label class="field-span-full">IP API Whitelist<span class="hint">IP API jikalau menggunakan API dari Engine</span>
            <div class="multi-field" data-multi-field="api_ip_whitelist" data-mode="array">
                <div class="multi-field-row"><input type="text" class="multi-field-input" placeholder="103.10.20.30"><button type="button" class="multi-field-toggle" data-action="add">+</button></div>
            </div>
        </label>
        <label class="field-span-full">IP Dashboard Whitelist<span class="hint">IP Dashboard untuk Finance / CS / Admin</span>
            <div class="multi-field" data-multi-field="ip_dashboard" data-mode="array">
                <div class="multi-field-row"><input type="text" class="multi-field-input" placeholder="103.10.20.30"><button type="button" class="multi-field-toggle" data-action="add">+</button></div>
            </div>
        </label>
    </div>

    <h2 class="section">Rekening Bank</h2>
    <div class="form-grid">
        <div class="field-span-full">
            <span class="field-label-text">Whitelist Rekening Bank<span class="hint" style="display:block; margin-top:4px; text-transform:none">Sampai 5 rekening tujuan yang diizinkan untuk settlement/withdrawal</span></span>
            <div class="repeat-rows">
                <label>Nama Rekening<input name="bank_account[0][name]" placeholder="Nama pemilik rekening"></label>
                <label>Nomor Rekening<input name="bank_account[0][number]" placeholder="1234567890"></label>
                <label>Nama Bank<span class="bank-combobox"><input type="text" class="bank-search-input" name="bank_account[0][bank]" placeholder="Cari / pilih bank" autocomplete="off"><div class="bank-combobox-list" hidden></div></span></label>
                <label>Nama Rekening<input name="bank_account[1][name]" placeholder="Nama pemilik rekening"></label>
                <label>Nomor Rekening<input name="bank_account[1][number]" placeholder="1234567890"></label>
                <label>Nama Bank<span class="bank-combobox"><input type="text" class="bank-search-input" name="bank_account[1][bank]" placeholder="Cari / pilih bank" autocomplete="off"><div class="bank-combobox-list" hidden></div></span></label>
                <label>Nama Rekening<input name="bank_account[2][name]" placeholder="Nama pemilik rekening"></label>
                <label>Nomor Rekening<input name="bank_account[2][number]" placeholder="1234567890"></label>
                <label>Nama Bank<span class="bank-combobox"><input type="text" class="bank-search-input" name="bank_account[2][bank]" placeholder="Cari / pilih bank" autocomplete="off"><div class="bank-combobox-list" hidden></div></span></label>
                <label>Nama Rekening<input name="bank_account[3][name]" placeholder="Nama pemilik rekening"></label>
                <label>Nomor Rekening<input name="bank_account[3][number]" placeholder="1234567890"></label>
                <label>Nama Bank<span class="bank-combobox"><input type="text" class="bank-search-input" name="bank_account[3][bank]" placeholder="Cari / pilih bank" autocomplete="off"><div class="bank-combobox-list" hidden></div></span></label>
                <label>Nama Rekening<input name="bank_account[4][name]" placeholder="Nama pemilik rekening"></label>
                <label>Nomor Rekening<input name="bank_account[4][number]" placeholder="1234567890"></label>
                <label>Nama Bank<span class="bank-combobox"><input type="text" class="bank-search-input" name="bank_account[4][bank]" placeholder="Cari / pilih bank" autocomplete="off"><div class="bank-combobox-list" hidden></div></span></label>
            </div>
        </div>
    </div>

    <div class="actions section">
        <button type="reset" class="btn" @disabled(! $usable)>Reset</button>
        <button class="btn primary" @disabled(! $usable)>Submit Data</button>
    </div>
</form>
<script src="{{ asset('js/paygrid-multi-field.js') }}"></script>
<script>
    var onboardingMagnetopayForm = document.getElementById('onboarding-magnetopay-form');
    if (onboardingMagnetopayForm) {
        onboardingMagnetopayForm.addEventListener('submit', function () { PayGridMultiField.collect(onboardingMagnetopayForm); });
    }

    var engineTypeSelect = document.getElementById('engine-type-select');
    var engineScriptSection = document.getElementById('engine-script-section');
    var engineApiCustomWrap = document.getElementById('engine-api-custom-wrap');
    var engineScriptPlatformWrap = document.getElementById('engine-script-platform-wrap');
    var engineScriptPlatformSelect = document.getElementById('engine-script-platform-select');
    var engineScriptCustomWrap = document.getElementById('engine-script-custom-wrap');
    if (engineTypeSelect && engineScriptSection) {
        var updateEngineUi = function () {
            var value = engineTypeSelect.value;
            engineApiCustomWrap.hidden = value !== 'api';
            engineScriptPlatformWrap.hidden = value !== 'sc';
            engineScriptSection.hidden = value !== 'sc';
            engineScriptCustomWrap.hidden = !(value === 'sc' && engineScriptPlatformSelect.value === 'custom');
        };
        engineTypeSelect.addEventListener('change', updateEngineUi);
        engineScriptPlatformSelect.addEventListener('change', updateEngineUi);
        updateEngineUi();
    }
</script>
<script>
    var BANK_NAMES = ["Allo Bank/Bank Harda Internasional","ANZ Indonesia","Bank Aceh Syariah","Bank Aladin Syariah","Bank Amar Indonesia","Bank Artha Graha Internasional","Bank Bank Shinhan Indonesia","Bank Bengkulu","Bank BPD DIY","Bank BPD DIY Syariah","Bank BTPN Syariah","Bank Bukopin Syariah","Bank Bumi Arta","Bank Capital Indonesia","Bank China Construction Bank Indonesia","Bank CNB (Centratama Nasional Bank)","Bank Dinar Indonesia","Bank DKI","Bank DKI Syariah","Bank Ganesha","Bank IBK Indonesia","Bank Ina Perdana","Bank Index Selindo","Bank Jago Syariah","Bank Jambi","Bank Jambi Syariah","Bank Jasa Jakarta","Bank Jateng","Bank Jateng Syariah","Bank Jatim","Bank Jatim Syariah","Bank Kalbar","Bank Kalbar Syariah","Bank Kalsel","Bank Kalsel Syariah","Bank Kalteng","Bank Kaltim Syariah","Bank Kaltimtara","Bank Lampung","Bank Maluku","Bank MANTAP (Mandiri Taspen)","Bank Maspion Indonesia","Bank Mayapada","Bank Mayora Indonesia","Bank Mega","Bank Mega Syariah","Bank Mestika Dharma","Bank Mizuho Indonesia","Bank Multi Arta Sentosa (Bank MAS)","Bank Mutiara","Bank Nagari","Bank Nagari Syariah","Bank NTB Syariah","Bank NTT","Bank Nusantara Parahyangan","Bank OCBC NISP","Bank OCBC NISP Syariah","Bank of America NA","Bank of China (Hong Kong) Limited","Bank of India Indonesia","Bank of Tokyo Mitsubishi UFJ","Bank Papua","Bank Prima Master","Bank Resona Perdania","Bank Riau Kepri","Bank Sahabat Sampoerna","Bank Sinarmas","Bank Sinarmas Syariah","Bank Sulselbar","Bank Sulselbar Syariah","Bank Sulteng","Bank Sultra","Bank SulutGo","Bank Sumsel Babel","Bank Sumsel Babel Syariah","Bank Sumut","Bank Sumut Syariah","Bank Victoria International","Bank Victoria Syariah","Bank Woori Saudara","BCA","BCA (Bank Central Asia) Syariah","BJB","BJB Syariah","Blu/BCA Digital","BNI","BNP Paribas Indonesia","BPD Bali","BPD Banten","BRI","BRI Agroniaga","BSI (Bank Syariah Indonesia)","BTN","BTN Syariah","BTPN","CIMB Niaga & CIMB Niaga Syariah","Citibank","Commonwealth Bank","CTBC (Chinatrust) Indonesia","DANA Wallet","DANAMON","DBS Indonesia","Gopay Wallet","HSBC Indonesia","ICBC Indonesia","Jago/Artos","LINE Bank/KEB Hana","Link Aaja Wallet","MANDIRI","Maybank Indonesia","Maybank Syariah","Motion/MNC Bank","Muamalat","Neo Commerce/Yudha Bhakti","Nobu (Nationalnobu) Bank","OVO Wallet","Panin Bank","Panin Dubai Syariah","Permata","Permata Syariah","QNB Indonesia","Rabobank International Indonesia","SBI Indonesia","Seabank/Bank BKE","Shopee Pay Wallet","Standard Chartered Bank","Super Bank","Superbank","TMRW/UOB","Wokee/Bukopin"];

    function paygridDebounce(fn, delay) {
        var timer;
        return function () {
            var args = arguments, ctx = this;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
        };
    }

    function setupBankCombobox(wrap) {
        var input = wrap.querySelector('.bank-search-input');
        var list = wrap.querySelector('.bank-combobox-list');

        function render(query) {
            var q = query.trim().toLowerCase();
            var matches = q === '' ? BANK_NAMES : BANK_NAMES.filter(function (name) {
                return name.toLowerCase().indexOf(q) !== -1;
            });
            list.innerHTML = '';
            if (matches.length === 0) {
                var empty = document.createElement('div');
                empty.className = 'bank-combobox-empty';
                empty.textContent = 'Tidak ketemu - boleh ketik manual.';
                list.appendChild(empty);
            } else {
                matches.slice(0, 20).forEach(function (name) {
                    var item = document.createElement('div');
                    item.className = 'bank-combobox-item';
                    item.textContent = name;
                    item.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        input.value = name;
                        list.hidden = true;
                    });
                    list.appendChild(item);
                });
            }
            list.hidden = false;
        }

        var debouncedRender = paygridDebounce(render, 200);

        input.addEventListener('input', function () { debouncedRender(input.value); });
        input.addEventListener('focus', function () { render(input.value); });
        input.addEventListener('blur', function () { setTimeout(function () { list.hidden = true; }, 150); });
    }

    document.querySelectorAll('.bank-combobox').forEach(setupBankCombobox);
</script>
@if(! $usable)
    <script>document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('form input, form select, form button').forEach((el) => el.disabled = true));</script>
@endif
</div>
</main>
</body>
</html>
