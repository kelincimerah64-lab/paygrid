@extends('layouts.paygrid', ['roleLabel' => $merchant->name, 'menus' => [], 'active' => ''])

@section('content')
<section class="card pad" style="max-width:640px; margin:80px auto; text-align:center">
    <h1>Status Top Up</h1>
    <p class="sub">Reference: {{ $topupRequest->idempotency_key }}</p>
    <p>Nominal: <strong>Rp {{ number_format($topupRequest->amount, 0, ',', '.') }}</strong></p>
    <p>Status: <strong id="topup-status">{{ strtoupper($topupRequest->status) }}</strong></p>
    <p class="sub">Batas pembayaran: <strong id="topup-expiry">{{ $topupRequest->expires_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') ?? '-' }}</strong></p>
    <p class="sub">Sisa waktu: <strong id="topup-countdown">-</strong></p>
    @if($topupRequest->qr_string)
        <p>QRIS berhasil dibuat. Silakan lanjutkan pembayaran.</p>
        <img src="{{ route('topup.qr', [$merchant, $topupRequest]) }}" alt="QRIS pembayaran" style="display:block; max-width:280px; width:100%; margin:20px auto; border:1px solid #dbe5f2; padding:12px; border-radius:8px">
    @elseif($topupRequest->payment_url)
        <a class="btn primary" href="{{ $topupRequest->payment_url }}" target="_blank" rel="noreferrer">Buka Pembayaran</a>
    @endif
</section>
<div id="topup-modal" hidden style="position:fixed; inset:0; z-index:10; place-items:center; padding:24px; background:rgba(6,22,47,.5)">
    <div class="card pad" style="max-width:440px; width:100%; text-align:center; border-radius:18px; box-shadow:0 24px 80px rgba(6,22,47,.22)">
        <div id="topup-modal-icon" hidden style="width:64px; height:64px; margin:0 auto 18px; border-radius:999px; display:grid; place-items:center; font-size:34px; font-weight:900; line-height:1"></div>
        <h2 id="topup-modal-title" style="margin-bottom:10px">Pembayaran</h2>
        <p id="topup-modal-message" class="sub" style="font-size:17px; line-height:1.55; margin:0 auto 22px; max-width:340px"></p>
        <div style="display:flex; flex-wrap:wrap; justify-content:center; gap:12px">
        <form id="regenerate-form" method="post" action="{{ route('topup.regenerate', [$merchant, $topupRequest]) }}" hidden style="margin:0">
            @csrf
            <button class="btn primary" type="submit">Generate Ulang QR</button>
        </form>
        <button id="topup-modal-close" class="btn" type="button">Tutup</button>
        </div>
    </div>
</div>
<script>
    const statusUrl = @json(route('api.topup.status', [$merchant, $topupRequest]));
    const statusNode = document.getElementById('topup-status');
    const expiryNode = document.getElementById('topup-expiry');
    const countdownNode = document.getElementById('topup-countdown');
    const expiryMs = @json($topupRequest->expires_at ? $topupRequest->expires_at->getTimestamp() * 1000 : null);
    const modal = document.getElementById('topup-modal');
    const modalTitle = document.getElementById('topup-modal-title');
    const modalMessage = document.getElementById('topup-modal-message');
    const modalIcon = document.getElementById('topup-modal-icon');
    const regenerateForm = document.getElementById('regenerate-form');
    const showModal = (title, message, regenerate = false, variant = 'info') => {
        modalTitle.textContent = title;
        modalMessage.textContent = message;
        modalIcon.hidden = false;
        modalIcon.textContent = variant === 'success' ? '✓' : '×';
        modalIcon.style.background = variant === 'success' ? '#dcfce7' : '#fee2e2';
        modalIcon.style.color = variant === 'success' ? '#15803d' : '#b91c1c';
        regenerateForm.hidden = !regenerate;
        modal.hidden = false;
        modal.style.display = 'grid';
    };
    document.getElementById('topup-modal-close').addEventListener('click', () => {
        modal.hidden = true;
        modal.style.display = 'none';
    });
    const finalStatuses = ['success', 'expired', 'failed', 'rejected'];
    let expiredModalShown = false;

    async function refreshTopupStatus() {
        try {
            const response = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const data = await response.json();
            const nextStatus = String(data.status || 'pending');
            const previousStatus = statusNode.textContent.toLowerCase();
            statusNode.textContent = nextStatus.toUpperCase();
            if (nextStatus === 'success' && previousStatus !== 'success') showModal('Pembayaran Berhasil', 'Pembayaran berhasil kami terima.', false, 'success');
            if (nextStatus === 'expired' && previousStatus !== 'expired') showModal('QRIS Expired', 'QRIS sudah kedaluwarsa. Generate ulang QR untuk melanjutkan pembayaran.', true, 'expired');
            if (data.expires_at && expiryNode) expiryNode.textContent = new Date(data.expires_at).toLocaleString('id-ID');
            if (!finalStatuses.includes(data.status)) window.setTimeout(refreshTopupStatus, 8000);
        } catch (_) {
            window.setTimeout(refreshTopupStatus, 12000);
        }
    }

    if (!finalStatuses.includes(@json($topupRequest->status))) refreshTopupStatus();
    if (expiryMs && countdownNode) {
        const tick = () => {
            const remaining = expiryMs - Date.now();
            if (remaining <= 0 && !finalStatuses.includes(statusNode.textContent.toLowerCase())) {
                statusNode.textContent = 'EXPIRED';
                countdownNode.textContent = '00:00';
                if (!expiredModalShown) {
                    expiredModalShown = true;
                    showModal('QRIS Expired', 'QRIS sudah kedaluwarsa. Generate ulang QR untuk melanjutkan pembayaran.', true, 'expired');
                }
            }
            else if (remaining > 0) {
                const totalSeconds = Math.ceil(remaining / 1000);
                const minutes = String(Math.floor(totalSeconds / 60)).padStart(2, '0');
                const seconds = String(totalSeconds % 60).padStart(2, '0');
                countdownNode.textContent = `${minutes}:${seconds}`;
                window.setTimeout(tick, 1000);
            }
        };
        tick();
    }
    if (@json($topupRequest->status) === 'success') showModal('Pembayaran Berhasil', 'Pembayaran berhasil kami terima.', false, 'success');
    if (@json($topupRequest->status) === 'expired') showModal('QRIS Expired', 'QRIS sudah kedaluwarsa. Generate ulang QR untuk melanjutkan pembayaran.', true, 'expired');
</script>
@endsection
