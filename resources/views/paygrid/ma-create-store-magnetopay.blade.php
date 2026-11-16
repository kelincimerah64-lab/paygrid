@extends('layouts.paygrid')

@section('content')
<div class="qris-hero">
    <div>
        <div class="eyebrow">MA</div>
        <h1>Create Toko (Magneto)</h1>
    </div>
</div>

@if(session('status'))
    <div class="status-banner"><span class="badge ok">{{ session('status') }}</span></div>
@endif
@if($errors->any())
    <div class="status-banner"><span class="badge danger">{{ $errors->first() }}</span></div>
@endif

{{--
    Manual "Create Toko Magnetopay" form - hidden per request (2026-11-09): MA
    should only onboard via the Generate Link flow below for now. Kept here
    (not deleted) so it's easy to bring back later; the route/controller
    (ma.create-store-magnetopay.store / MaController::storeMagnetopayMerchant)
    are untouched.
<section class="card qris-panel section">
    <div class="qris-toolbar"><h2>Merchant Profile</h2></div>
    <form method="post" action="{{ route('ma.create-store-magnetopay.store') }}" class="pad" id="create-store-magnetopay-form">
        @csrf
        <div class="form-grid create-store-grid">
            <label>Nama Toko<input name="name" required></label>
            <label>Agen<select name="agent_id" required><option value="">Pilih agen</option>@foreach($allAgents as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
            <label>Email Admin<div class="multi-field" data-multi-field="admin_email" data-mode="array"><div class="multi-field-row"><input type="email" class="multi-field-input" placeholder="admin@domain.com" required><button type="button" class="multi-field-toggle" data-action="add">+</button></div></div></label>
            <label>Merchant ID<input name="merchant_id" placeholder="Kosongkan jika belum real"></label>
            <label>Merchant Secret Key<input name="merchant_key" placeholder="Kosongkan jika belum real"></label>
            <label>Merchant Group<input name="merchant_group_name" placeholder="NP x Nemo Group (Everyday)"></label>
            <label>Transaction Callback URL<input name="transaction_callback_url" value="{{ url('/api/callbacks/hilogate/transaction') }}"></label>
            <label>Withdrawal Callback URL<input name="withdrawal_callback_url" value="{{ url('/api/callbacks/hilogate/withdrawal') }}"></label>
            <label>VA Callback URL<input name="va_callback_url" placeholder="https://..."></label>
            <label>QRIS Settlement Type<input name="qris_settlement_type" list="magnetopay-settlement-presets" placeholder="Everyday"></label>
            <label>VA Settlement Type<input name="va_settlement_type" list="magnetopay-settlement-presets" placeholder="H+1"></label>
            <datalist id="magnetopay-settlement-presets"><option value="Everyday"><option value="H+1"><option value="H+2"></datalist>

            <div class="export-status-group section field-span-full" style="align-items:center">
                <label><input type="checkbox" name="ip_whitelist_enabled" value="1"> IP Whitelist Enabled</label>
            </div>
            <label>Dashboard IP Whitelisting<div class="multi-field" data-multi-field="dashboard_ip_whitelist" data-mode="array" data-multi-field-max="5"><div class="multi-field-row"><input type="text" class="multi-field-input" value="15.232.137.74" placeholder="103.10.20.30"><button type="button" class="multi-field-toggle" data-action="add">+</button></div></div></label>
            <label>API IP Whitelisting<div class="multi-field" data-multi-field="api_ip_whitelist" data-mode="array" data-multi-field-max="5"><div class="multi-field-row"><input type="text" class="multi-field-input" value="15.232.137.74" placeholder="103.10.20.30"><button type="button" class="multi-field-toggle" data-action="add">+</button></div></div></label>

            <p class="muted field-span-full" style="margin:0">Status toko otomatis <strong>Approved</strong> begitu disimpan - tidak ada input terpisah.</p>
        </div>

        <div class="qris-toolbar" style="margin:20px 0 0; background:transparent; border:0; padding:0"><h2>Merchant Payment Setup</h2></div>
        <div class="form-grid create-store-grid" style="margin-top:16px">
            <label>Transaction Payment Gateways<input name="transaction_payment_gateway" placeholder="JAYA DIGITAL RTS"></label>
            <label>Withdrawal Payment Gateways<input name="withdrawal_payment_gateway" placeholder="INA WD"></label>
            <label>VA Payment Gateways<input name="va_payment_gateway"></label>
            <label>Fee Calculation 1 (%)<input type="number" step="0.01" min="0" max="100" name="magnetopay_fee_1_percent" value="0"></label>
            <label>Fee Calculation 2 (%)<input type="number" step="0.01" min="0" max="100" name="magnetopay_fee_2_percent" value="0"></label>
            <label>Fee Calculation 3 (%)<input type="number" step="0.01" min="0" max="100" name="magnetopay_fee_3_percent" value="0"></label>
            <label>Fee VA (%)<input type="number" step="0.01" min="0" max="100" name="magnetopay_fee_va_percent" value="0"></label>
            <label>Fee Withdrawal (Rp)<input type="number" step="1" name="magnetopay_fee_withdrawal_fixed" placeholder="-499"></label>
            <label>Fee Withdrawal (%)<input type="number" step="0.01" min="0" max="100" name="magnetopay_fee_withdrawal_percent" value="0"></label>
        </div>

        <button class="btn primary" style="margin-top:16px">Buat Toko Magnetopay</button>
    </form>
</section>
--}}

@php($latestMaLinkMagnetopay = session('onboarding_link_magnetopay') ?: '')
<section class="card pad section agent-onboarding-card">
    <div class="qris-toolbar compact-toolbar"><div><h2>Generate Link Onboarding (Magneto)</h2><p class="muted">Agen, Settlement Type, dan MDR dipilih di sini dan nempel ke link ini - tiap toko yang submit lewat link ini pakai terms yang sama. Generate ulang kapan saja buat bikin link baru dengan terms baru (link lama otomatis expired), setiap link dapat Form ID sendiri walau belum ada yang submit.</p></div></div>
    <form method="post" action="{{ route('ma.onboarding-links.magnetopay.store') }}" id="onboarding-link-magnetopay-form">
        @csrf
        <div class="generate-link-columns">
            <div class="generate-link-col">
                <h3>Merchant</h3>
                <label>Agen / Grup
                    <span style="display:flex; gap:8px; align-items:center">
                        <select name="agent_id" id="onboarding-link-agent-select" required style="flex:1"><option value="">Pilih agen</option>@foreach($allAgents as $a)<option value="{{ $a->id }}" @selected(session('new_agent_id') == $a->id)>{{ $a->name }}</option>@endforeach</select>
                        <button type="button" class="btn compact-btn" data-approval-detail="agent-create-ob-modal" style="flex-shrink:0">+ Agen baru</button>
                    </span>
                </label>
                <div class="form-grid" style="grid-template-columns:1fr 1fr; gap:12px">
                    <label>Email Penerima<input name="recipient_email" type="email" placeholder="merchant@domain.com"></label>
                    <label>Username Telegram<input name="recipient_telegram" placeholder="@merchantusername"></label>
                </div>
                <h3 style="margin-top:10px">Disbursement Fee</h3>
                <p class="muted" style="margin:0 0 8px; font-size:12px">Isi salah satu aja, % atau Rp - gak perlu dua-duanya.</p>
                <div class="form-grid" style="grid-template-columns:1fr 1fr; gap:12px">
                    <label>Fee Disbursement %<input type="text" inputmode="decimal" name="disbursement_fee_percent" placeholder="0"></label>
                    <label>Fee Disbursement (Rp)<input type="number" step="1" name="disbursement_fee_fixed" placeholder="1900"></label>
                </div>
            </div>
            <div class="generate-link-col">
                <h3>Settlement Type + MDR</h3>
                <div class="fee-rate-callout">Pilih tepat satu menu. Pilihan ini berlaku untuk semua toko yang submit lewat link ini.</div>
                @include('paygrid.partials.fee-menu-rates', ['role' => 'merchant', 'typeCategory' => null, 'feeMenus' => $feeMenus, 'style' => 'grouped'])
            </div>
        </div>
        <div class="generate-link-footer">
            <label>Link Form Unik<input data-onboarding-link readonly value="{{ $latestMaLinkMagnetopay ?: 'Link akan muncul di sini.' }}"></label>
            <div class="actions"><button class="btn" type="button" data-copy-onboarding-link>Copy Link</button><button class="btn primary">Generate Link</button></div>
        </div>
    </form>
</section>

<div class="approval-modal" id="agent-create-ob-modal" hidden><div class="approval-modal-card">
    <div class="qris-toolbar"><div><h2>Buat Agen Baru</h2><p class="muted" style="margin:4px 0 0">Agen baru langsung muncul di pilihan Agen / Grup pada form.</p></div><button class="btn compact-btn approval-detail-close" type="button">✕ Tutup</button></div>
    <div class="pad">
        <form id="agent-create-ob" method="post" action="{{ route('ma.agents.store') }}">@csrf</form>
        <h3 style="margin:0 0 12px; font-size:12px; font-weight:900; color:var(--muted); text-transform:uppercase; letter-spacing:.03em">Data Agen</h3>
        <div class="agent-create-grid">
            <label class="field-span-full">Nama<input form="agent-create-ob" name="name" required></label>
            <label>Email<input form="agent-create-ob" name="email" type="email" required></label>
            <label>Kontak<input form="agent-create-ob" name="contact"></label>
            <label>Status<select form="agent-create-ob" name="status"><option>Active</option><option>Review</option><option>Suspended</option></select></label>
            <label>Tipe
                <select id="agent-create-ob-type" onchange="paygridSetAgentCreateObType(this)">
                    <option value="api">API</option>
                    <option value="script">Script</option>
                    <option value="cm" selected>CM</option>
                </select>
                <input type="hidden" form="agent-create-ob" name="connection_type" id="agent-create-ob-connection-type" value="cm">
                <input type="hidden" form="agent-create-ob" name="engine_type" id="agent-create-ob-engine-type" value="" disabled>
            </label>
            <label>Password<input form="agent-create-ob" name="password" value="{{ config('paygrid.demo_password') }}"></label>
        </div>
        <h3 style="margin:18px 0 12px; font-size:12px; font-weight:900; color:var(--muted); text-transform:uppercase; letter-spacing:.03em">Fee per Menu (Agen)</h3>
        @include('paygrid.partials.fee-menu-rates', ['role' => 'agent', 'typeCategory' => null, 'feeMenus' => $feeMenus, 'formId' => 'agent-create-ob', 'style' => 'grouped'])
        <div style="margin-top:16px; text-align:right"><button form="agent-create-ob" class="btn primary">Buat Agen</button></div>
    </div>
</div></div>

<section class="card section">
    <div class="qris-toolbar"><h2>Link Terakhir (Magneto)</h2><span class="muted">{{ $onboardingLinks->count() }} link</span></div>
    <div class="table-wrap"><table class="table qris-table agent-link-table ma-link-table"><thead><tr><th>Form ID</th><th>Dibuat</th><th>Agen</th><th>Settlement/MDR</th><th>Penerima</th><th>Status</th><th>Link</th><th>Request</th></tr></thead><tbody>@forelse($onboardingLinks as $row)@php($effectiveStatus = $row->isUsable() ? $row->status : 'expired')<tr><td><strong>{{ $row->form_number ?: '-' }}</strong></td><td class="time-cell">{{ $row->created_at->timezone('Asia/Jakarta')->format('d M y') }}<span>{{ $row->created_at->timezone('Asia/Jakarta')->format('H:i') }}</span></td><td>{{ $row->agent?->name ?: '-' }}</td><td>{{ $row->fee_menu ? $feeMenus->ratesSummary($row->fee_menu_rates ?? [], 'merchant') : '-' }}</td><td><strong>{{ $row->recipient_email ?: '-' }}</strong><br><span class="muted">{{ $row->recipient_telegram ?: '-' }}</span></td><td><span class="badge {{ $effectiveStatus === 'active' ? 'ok' : 'warn' }}">{{ ucfirst($effectiveStatus) }}{{ $row->reusable && $effectiveStatus === 'active' ? ' (Permanen)' : '' }}</span></td><td><button type="button" class="btn compact-btn" data-copy-row-link="{{ route('merchant-registration.magnetopay.token-form', $row) }}">Copy</button></td><td>{{ $row->registration?->store_name ?: '-' }}</td></tr>@empty<tr><td colspan="8" class="empty">Belum ada link.</td></tr>@endforelse</tbody></table></div>
</section>
@endsection

@push('scripts')
<script>
var createStoreMagnetopayForm = document.getElementById('create-store-magnetopay-form');
if (createStoreMagnetopayForm) {
    createStoreMagnetopayForm.addEventListener('submit', function () {
        PayGridMultiField.collect(createStoreMagnetopayForm);
    });
}

function paygridSetAgentCreateObType(select) {
    var map = { api: ['script', 'api'], script: ['script', 'sc'], cm: ['cm', ''] };
    var values = map[select.value] || map.cm;
    document.getElementById('agent-create-ob-connection-type').value = values[0];
    var engineType = document.getElementById('agent-create-ob-engine-type');
    engineType.value = values[1];
    engineType.disabled = values[1] === '';
}

function paygridCopyText(text, btn) {
    if (!text) return;
    var original = btn.textContent;
    var done = () => { btn.textContent = 'Tersalin!'; setTimeout(() => btn.textContent = original, 1500); };
    var fallback = () => {
        var tmp = document.createElement('textarea');
        tmp.value = text;
        tmp.style.position = 'fixed';
        tmp.style.opacity = '0';
        document.body.appendChild(tmp);
        tmp.select();
        tmp.setSelectionRange(0, 99999);
        try { document.execCommand('copy'); done(); } catch (e) {}
        document.body.removeChild(tmp);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(fallback);
    } else {
        fallback();
    }
}
document.addEventListener('DOMContentLoaded', () => {
    var btn = document.querySelector('[data-copy-onboarding-link]');
    var input = document.querySelector('[data-onboarding-link]');
    if (btn && input) {
        btn.addEventListener('click', () => paygridCopyText(input.value || '', btn));
    }
    document.addEventListener('click', (e) => {
        var rowBtn = e.target.closest('[data-copy-row-link]');
        if (rowBtn) paygridCopyText(rowBtn.getAttribute('data-copy-row-link'), rowBtn);
    });
});
</script>
@endpush
