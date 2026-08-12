@extends('layouts.paygrid', ['roleLabel' => 'Onboarding', 'menus' => [], 'active' => ''])

@section('content')
@php($usable = ! isset($link) || ! $link || $link->isUsable())
<div class="page-head">
    <div>
        <h1>Form Registrasi Toko</h1>
        <div class="sub">Form terhubung ke agen {{ $agent?->name ?? 'default' }}. Data masuk ke agen dahulu, tidak langsung dikirim ke HG.</div>
    </div>
</div>

@if(session('status'))
    <div class="card pad" style="margin-bottom:16px; border-color:#81dfa9">{{ session('status') }}</div>
@endif
@if(! $usable)
    <div class="card pad" style="margin-bottom:16px; border-color:#f4b3b3"><strong>Link sudah expired.</strong><br><span class="muted">Link onboarding ini hanya bisa dipakai satu kali. Silakan minta agen generate link baru jika perlu submit ulang.</span></div>
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
        <label>Settlement Method<select name="settlement_method"><option value="">Belum dipilih</option><option>Standard H+1</option><option>Everyday 1x settle / 1x WS</option><option>Sameday 3x settle / 3x WS</option></select></label>
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
@endsection
