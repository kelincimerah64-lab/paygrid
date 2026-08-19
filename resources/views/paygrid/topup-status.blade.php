<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Status Top Up | PayGrid</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800;900&display=swap');
        :root { --blue:#1557c2; --ink:#06162f; --muted:#55657a; --line:#dbe5f2; --bg:#f5f8fc; --success:#008450; --danger:#c62828; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; font-family:"Plus Jakarta Sans", Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; color:var(--ink); background:radial-gradient(circle at top right, #eaf2ff 0, transparent 34%), var(--bg); }
        .page { min-height:100vh; display:grid; place-items:center; padding:28px 16px; }
        .wrap { width:min(680px, 100%); }
        .card { background:#fff; border:1px solid var(--line); border-radius:14px; box-shadow:0 14px 40px rgba(14, 35, 70, .07); }
        .pad { padding:24px; }
        h1 { margin:0; font-size:30px; line-height:1.05; letter-spacing:-.035em; text-align:center; }
        h2 { margin:0; font-size:22px; }
        p { margin:12px 0 0; text-align:center; line-height:1.45; }
        .sub { color:#304158; }
        .merchant { margin:16px auto 0; width:max-content; max-width:100%; padding:7px 12px; border:1px solid #d8e5f7; border-radius:999px; background:#f8fbff; color:#1557c2; font-size:12px; font-weight:900; }
        .status { display:inline-flex; margin-top:18px; min-height:34px; align-items:center; justify-content:center; padding:7px 13px; border-radius:999px; background:#fff9e9; color:#9a4a00; font-weight:950; }
        .qr { display:block; max-width:280px; width:100%; margin:20px auto; border:1px solid #dbe5f2; padding:12px; border-radius:10px; background:#fff; }
        .btn { display:inline-flex; align-items:center; justify-content:center; min-height:38px; padding:9px 14px; border:1px solid #b9cef6; border-radius:9px; background:#fff; color:var(--blue); font:inherit; font-size:13px; font-weight:950; cursor:pointer; text-decoration:none; }
        .btn.primary { background:var(--blue); color:#fff; border-color:var(--blue); }
        .modal { position:fixed; inset:0; z-index:10; display:none; place-items:center; padding:24px; background:rgba(6,22,47,.5); }
        .modal[hidden] { display:none; }
        .modal-card { max-width:440px; width:100%; text-align:center; border-radius:18px; box-shadow:0 24px 80px rgba(6,22,47,.22); }
        .modal-icon { width:64px; height:64px; margin:0 auto 18px; border-radius:999px; display:grid; place-items:center; font-size:34px; font-weight:900; line-height:1; }
        .modal-actions { display:flex; flex-wrap:wrap; justify-content:center; gap:12px; margin-top:22px; }
        @media (max-width: 720px) {
            .page { align-items:start; padding-top:22px; }
            .pad { padding:18px; }
            h1 { font-size:27px; }
            .btn { width:100%; }
            .modal-actions { align-items:stretch; }
        }
    </style>
</head>
<body>
<main class="page">
    <div class="wrap">
        <section class="card pad">
            <h1>Status Top Up</h1>
            <div class="merchant">{{ $merchant->name }}</div>
            <p class="sub">Reference: {{ $topupRequest->idempotency_key }}</p>
            <p>Nominal: <strong>Rp {{ number_format($topupRequest->amount, 0, ',', '.') }}</strong></p>
            <p><span class="status" id="topup-status">{{ strtoupper($topupRequest->status) }}</span></p>
            <p class="sub">Batas pembayaran: <strong id="topup-expiry">{{ $topupRequest->expires_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') ?? '-' }}</strong></p>
            <p class="sub">Sisa waktu: <strong id="topup-countdown">-</strong></p>
            @if($topupRequest->qr_string)
                <p>QRIS berhasil dibuat. Silakan lanjutkan pembayaran.</p>
                <img src="{{ route('topup.qr', [$merchant, $topupRequest->public_token]) }}" alt="QRIS pembayaran" class="qr">
            @elseif($topupRequest->payment_url)
                <p><a class="btn primary" href="{{ $topupRequest->payment_url }}" target="_blank" rel="noreferrer">Buka Pembayaran</a></p>
            @endif
        </section>
    </div>
</main>
<div id="topup-modal" class="modal" hidden>
    <div class="card pad modal-card">
        <div id="topup-modal-icon" class="modal-icon" hidden></div>
        <h2 id="topup-modal-title">Pembayaran</h2>
        <p id="topup-modal-message" class="sub"></p>
        <div class="modal-actions">
            <form id="regenerate-form" method="post" action="{{ route('topup.regenerate', [$merchant, $topupRequest->public_token]) }}" hidden style="margin:0">
                @csrf
                <button class="btn primary" type="submit">Generate Ulang QR</button>
            </form>
            <button id="topup-modal-close" class="btn" type="button">Tutup</button>
        </div>
    </div>
</div>
<script>
    const statusUrl = @json(route('api.topup.status', [$merchant, $topupRequest->public_token]));
    const statusNode = document.getElementById('topup-status');
    const expiryNode = document.getElementById('topup-expiry');
    const countdownNode = document.getElementById('topup-countdown');
    let expiryMs = @json($topupRequest->expires_at ? $topupRequest->expires_at->getTimestamp() * 1000 : null);
    let countdownStarted = false;
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
            if (data.expires_at) {
                expiryMs = Date.parse(data.expires_at);
                if (expiryNode) expiryNode.textContent = new Date(data.expires_at).toLocaleString('id-ID');
                startCountdown();
            }
            if (!finalStatuses.includes(data.status)) window.setTimeout(refreshTopupStatus, 2000);
        } catch (_) {
            window.setTimeout(refreshTopupStatus, 5000);
        }
    }

    function startCountdown() {
        if (!expiryMs || !countdownNode || countdownStarted) return;
        countdownStarted = true;
        tickCountdown();
    }

    function tickCountdown() {
        const remaining = expiryMs - Date.now();
        if (remaining <= 0 && !finalStatuses.includes(statusNode.textContent.toLowerCase())) {
            statusNode.textContent = 'EXPIRED';
            countdownNode.textContent = '00:00';
            if (!expiredModalShown) {
                expiredModalShown = true;
                showModal('QRIS Expired', 'QRIS sudah kedaluwarsa. Generate ulang QR untuk melanjutkan pembayaran.', true, 'expired');
            }

            return;
        }

        if (remaining > 0) {
            const totalSeconds = Math.ceil(remaining / 1000);
            const minutes = String(Math.floor(totalSeconds / 60)).padStart(2, '0');
            const seconds = String(totalSeconds % 60).padStart(2, '0');
            countdownNode.textContent = `${minutes}:${seconds}`;
            window.setTimeout(tickCountdown, 1000);
        }
    }

    if (!finalStatuses.includes(@json($topupRequest->status))) refreshTopupStatus();
    startCountdown();
    if (@json($topupRequest->status) === 'success') showModal('Pembayaran Berhasil', 'Pembayaran berhasil kami terima.', false, 'success');
    if (@json($topupRequest->status) === 'expired') showModal('QRIS Expired', 'QRIS sudah kedaluwarsa. Generate ulang QR untuk melanjutkan pembayaran.', true, 'expired');
</script>
</body>
</html>
