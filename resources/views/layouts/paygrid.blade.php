<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $roleLabel ?? 'PayGrid' }} | PayGrid</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800;900&display=swap');
        :root {
            --blue:#1557c2; --blue-2:#1f6fe5; --orange:#d95b18; --purple:#4f2a8a;
            --ink:#06162f; --muted:#55657a; --line:#dbe5f2; --soft:#eef4fb;
            --bg:#f5f8fc; --card:#fff; --success:#008450; --warn:#b15a00; --danger:#c62828;
            --shadow:0 10px 28px rgba(14, 35, 70, .06);
        }
        * { box-sizing:border-box; }
        body { margin:0; overflow-x:hidden; font-family:"Plus Jakarta Sans", Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; color:var(--ink); background:radial-gradient(circle at top right, #eaf2ff 0, transparent 34%), var(--bg); }
        a { color:inherit; }
        .shell { display:grid; grid-template-columns:224px minmax(0, 1fr); min-height:100vh; }
        .sidebar { background:#fff; border-right:1px solid var(--line); padding:18px 12px; display:flex; flex-direction:column; gap:18px; position:sticky; top:0; height:100vh; }
        .brand { display:flex; align-items:center; justify-content:center; }
        .brand img { display:block; width:190px; max-width:100%; height:auto; }
        .role { margin-top:8px; text-align:center; font-size:12px; font-weight:900; color:#111d32; }
        .notif-bell { position:relative; display:inline-flex; }
        .notif-bell summary { list-style:none; cursor:pointer; width:26px; height:26px; border-radius:50%; border:1px solid #c6d5ea; background:#fff; display:grid; place-items:center; font-size:13px; position:relative; }
        .notif-bell summary::-webkit-details-marker { display:none; }
        .notif-bell .notif-count { position:absolute; top:-4px; right:-4px; background:var(--danger); color:#fff; font-size:10px; font-weight:900; border-radius:999px; min-width:15px; height:15px; display:grid; place-items:center; padding:0 3px; }
        .notif-bell[open] summary { border-color:#9dbaf1; }
        .notif-panel { position:absolute; top:32px; right:0; left:auto; width:260px; max-height:320px; overflow-y:auto; background:#fff; border:1px solid var(--line); border-radius:10px; box-shadow:0 12px 30px rgba(14,35,70,.12); padding:10px; z-index:20; }
        .notif-panel h3 { margin:0 0 8px; font-size:11px; letter-spacing:.05em; color:#26364f; text-transform:uppercase; font-weight:900; }
        .notif-panel .empty { font-size:12px; color:var(--muted); padding:6px 2px; }
        .notif-panel .ma-detail-row { min-height:auto; padding:8px; margin-bottom:6px; }
        .notif-panel .ma-detail-row:last-child { margin-bottom:0; }
        .menu-title { margin:12px 6px 8px; font-size:10px; letter-spacing:.18em; color:#7a879b; font-weight:900; text-transform:uppercase; }
        .nav { display:grid; gap:6px; }
        .nav a { position:relative; display:flex; align-items:center; gap:11px; min-height:42px; padding:9px 12px; border:1px solid transparent; border-radius:10px; text-decoration:none; color:#43536d; font-size:13px; font-weight:850; transition:background .18s ease, border-color .18s ease, color .18s ease, box-shadow .18s ease, transform .18s ease; }
        .nav a:hover { background:#f4f8ff; border-color:#d8e5f7; color:#1557c2; transform:translateX(2px); box-shadow:0 8px 20px rgba(21, 87, 194, .08); }
        .nav a.active { background:linear-gradient(135deg, #1f6fe5, #1557c2); border-color:#1d67d7; color:#fff; font-weight:950; box-shadow:0 14px 30px rgba(21, 87, 194, .24); }
        .nav a.active:hover { transform:translateX(0); }
        .nav-icon { width:24px; height:24px; flex:0 0 24px; display:grid; place-items:center; border:1px solid #c6d5ea; border-radius:8px; background:#fff; color:#1557c2; font-size:13px; font-weight:950; line-height:1; box-shadow:0 4px 12px rgba(14, 35, 70, .05); transition:background .18s ease, border-color .18s ease, color .18s ease, transform .18s ease; }
        .nav a:hover .nav-icon { border-color:#9dbaf1; transform:scale(1.04); }
        .nav a.active .nav-icon { background:rgba(255,255,255,.16); border-color:rgba(255,255,255,.72); color:#fff; box-shadow:none; }
        .nav-label { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .spacer { flex:1; }
        .logout { border-top:1px solid var(--line); padding:18px 10px 0; color:#4b5870; }
        .logout > div:first-child { min-height:40px; display:flex; align-items:center; font-weight:650; }
        .user { display:flex; align-items:center; gap:10px; font-weight:900; }
        .session-note { margin-top:10px; padding:9px 10px; border:1px solid #dbe5f2; border-radius:8px; background:#f7faff; color:#5b6b82; font-size:11px; font-weight:750; line-height:1.35; }
        .avatar { width:42px; height:42px; border-radius:50%; display:grid; place-items:center; border:2px solid var(--blue); color:var(--blue); background:#f2f7ff; font-size:13px; }
        main { min-width:0; padding:18px 22px 36px; }
        .page-head { display:flex; justify-content:space-between; gap:18px; align-items:flex-start; margin-bottom:20px; }
        .page-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:flex-end; }
        h1 { margin:0; font-size:28px; line-height:1.05; letter-spacing:-.02em; }
        h2 { margin:0; font-size:18px; line-height:1.15; }
        .sub { color:#304158; margin-top:6px; line-height:1.45; }
        .muted { color:var(--muted); }
        .card { background:var(--card); border:1px solid var(--line); border-radius:8px; box-shadow:0 8px 22px rgba(14, 35, 70, .045); }
        .pad { padding:14px; }
        .section { margin-top:16px; }
        .grid { display:grid; gap:16px; }
        .cards { grid-template-columns:repeat(4, minmax(0, 1fr)); }
        .cards-compact { display:flex; flex-wrap:wrap; gap:16px; }
        .cards-compact .metric { flex:0 1 220px; }
        .metric { min-height:130px; }
        .metric strong { display:block; font-size:30px; line-height:1.05; margin-top:12px; overflow-wrap:anywhere; }
        .metric label, th, label, .label { font-size:12px; letter-spacing:.05em; color:#26364f; text-transform:uppercase; font-weight:900; }
        .metric.blue { background:linear-gradient(135deg, #1f6fe5, #1557c2); color:#fff; border-color:#1d67d7; }
        .metric.success { background:#ecfff5; border-color:#a4ebc4; }
        .metric.warn-soft { background:#fff9e9; border-color:#ffd46d; }
        .table-wrap { max-width:100%; overflow-x:hidden; }
        .table { width:100%; min-width:0; border-collapse:collapse; }
        .table th, .table td { border-top:1px solid #e7edf6; padding:7px 10px; text-align:left; vertical-align:middle; }
        .table th { background:#f7faff; white-space:nowrap; }
        .table-wrap.sticky-head { max-height:70vh; }
        .sticky-head thead th { position:sticky; top:0; z-index:2; box-shadow:0 1px 0 #e7edf6; }
        .table td { line-height:1.18; }
        .table strong { font-weight:900; }
        .badge { display:inline-flex; align-items:center; justify-content:center; min-height:24px; padding:4px 9px; border-radius:999px; font-weight:900; font-size:11px; white-space:nowrap; max-width:100%; overflow:hidden; text-overflow:ellipsis; }
        .ok { background:#dff8ec; color:var(--success); }
        .warn { background:#fff0c7; color:var(--warn); }
        .danger { background:#ffe0dd; color:var(--danger); }
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; border:1px solid #b9cef6; background:#fff; color:var(--blue); border-radius:7px; min-height:34px; padding:7px 11px; font-size:13px; font-weight:900; text-decoration:none; cursor:pointer; white-space:nowrap; }
        .btn.primary { background:var(--blue); color:#fff; border-color:var(--blue); }
        .btn.danger { border-color:#d9a4a4; color:#b52727; background:#fff; }
        .btn.ghost { background:#f7faff; }
        .eyebrow { color:var(--blue); font-size:10px; font-weight:900; letter-spacing:.18em; text-transform:uppercase; margin-bottom:6px; }
        .qris-hero { display:flex; justify-content:space-between; gap:16px; align-items:center; margin-bottom:12px; padding:12px 14px; border:1px solid #d8e5f7; border-radius:10px; background:#fff; box-shadow:0 8px 22px rgba(14, 35, 70, .045); }
        .qris-hero h1 { font-size:26px; letter-spacing:-.035em; }
        .qris-hero .sub { font-size:13px; margin-top:5px; }
        .hero-actions { display:flex; gap:8px; align-items:center; justify-content:flex-end; flex-wrap:wrap; }
        .hero-btn { border-radius:7px; min-height:34px; padding-inline:12px; }
        .qris-metrics { grid-template-columns:repeat(4, minmax(0, 1fr)); margin-bottom:12px; gap:12px; }
        .qris-metrics.history-metrics { grid-template-columns:repeat(5, minmax(0, 1fr)); }
        .qris-metrics.ticket-metrics { grid-template-columns:1.4fr 1fr 1fr; }
        .qris-metric { min-height:102px; border-radius:10px; position:relative; overflow:hidden; }
        .qris-metric::after { content:""; position:absolute; width:64px; height:64px; right:-24px; top:-24px; border-radius:999px; background:rgba(255,255,255,.35); }
        .qris-metric span { display:block; font-size:10px; letter-spacing:.09em; text-transform:uppercase; font-weight:950; color:#42526b; }
        .qris-metric strong { display:block; margin-top:10px; font-size:23px; letter-spacing:-.035em; overflow-wrap:anywhere; }
        .qris-metric small { display:block; margin-top:6px; color:#5a6b82; font-size:12px; font-weight:700; line-height:1.35; }
        .qris-metric.primary { background:linear-gradient(135deg, #1557c2, #1f6fe5); color:#fff; border-color:#1d67d7; }
        .qris-metric.primary span, .qris-metric.primary small { color:rgba(255,255,255,.82); }
        .qris-metric.success { background:#ecfff5; border-color:#a4ebc4; }
        .qris-metric.pending { background:#fff9e9; border-color:#ffd46d; }
        .qris-metric.expired { background:#fff1f0; border-color:#f0b4ae; }
        .bot-charts { grid-template-columns:1fr 1fr; }
        .bot-chart-card { display:flex; flex-direction:column; gap:10px; }
        .bot-chart-card h2 { font-size:13px; font-weight:900; letter-spacing:.02em; }
        .bot-chart-box { position:relative; height:200px; }
        .bot-monitoring-table { min-width:1080px; }
        .bot-monitoring-wrap { overflow-x:auto; }
        .bot-monitoring-table th:nth-child(1) { width:12%; }
        .bot-monitoring-table th:nth-child(2) { width:18%; }
        .bot-monitoring-table th:nth-child(3) { width:14%; }
        .bot-monitoring-table th:nth-child(4) { width:10%; }
        .bot-monitoring-table th:nth-child(5) { width:14%; }
        .bot-monitoring-table th:nth-child(6) { width:14%; }
        .bot-monitoring-table th:nth-child(7) { width:10%; }
        .bot-monitoring-table th:nth-child(8) { width:8%; }
        .bot-note-row td { height:auto; padding-top:0; color:var(--muted); background:#fbfdff; font-size:11px; }
        .bot-detail-row td { height:auto; padding:0 8px 10px; background:#fbfdff; }
        .bot-detail-card { border:1px solid #dbe5f2; border-radius:10px; background:#fff; box-shadow:0 8px 22px rgba(14, 35, 70, .045); overflow:hidden; }
        .bot-detail-card .approval-detail-grid { padding:12px; }
        .ma-metric-card { width:100%; text-align:left; font:inherit; cursor:pointer; color:inherit; }
        .ma-metric-card:hover, .ma-metric-card.active { border-color:#78a7ff; box-shadow:0 0 0 1px #78a7ff inset, 0 12px 28px rgba(21, 87, 194, .12); transform:translateY(-1px); }
        .ma-detail-list { display:grid; gap:8px; margin-top:12px; }
        .ma-detail-row { display:grid; grid-template-columns:minmax(0, 1.25fr) auto auto; gap:12px; align-items:center; min-height:48px; padding:9px 11px; border:1px solid #e7edf6; border-radius:8px; background:#fbfdff; }
        .ma-detail-row span { display:block; margin-top:3px; font-size:12px; font-weight:750; color:var(--muted); }
        .ma-detail-row strong { white-space:nowrap; }
        .ma-tabs { display:flex; gap:6px; }
        .ma-tabs .active, .ma-click-row.active, .report-pill.active { background:#eef5ff; border-color:#78a7ff; color:#1557c2; }
        .ma-click-row { cursor:pointer; }
        .ma-click-row:hover { background:#f7faff; }
        .ma-agent-report-table th:nth-child(n+3), .ma-agent-report-table td:nth-child(n+3) { text-align:right; }
        .ma-report-shops { display:flex; gap:8px; align-items:center; flex-wrap:wrap; border-top:1px solid #e7edf6; }
        .report-pill { display:inline-flex; flex-direction:column; gap:2px; border:1px solid #c9d6ea; border-radius:12px; padding:8px 12px; background:#fff; color:#10233f; font-size:12px; font-weight:900; cursor:pointer; text-decoration:none; }
        .report-pill span { color:var(--muted); font-size:11px; font-weight:800; }
        .ma-period-form { display:flex; gap:10px; align-items:end; flex-wrap:wrap; }
        .ma-period-form label { display:grid; gap:5px; margin:0; }
        .ma-period-form input, .ma-period-form select { min-height:32px; height:32px; padding:5px 9px; }
        .ma-mapping-table th:nth-child(1) { width:28%; }
        .ma-mapping-table th:nth-child(2), .ma-mapping-table th:nth-child(3) { width:27%; }
        .ma-mapping-table th:nth-child(4) { width:18%; }
        .ma-mapping-table td { height:auto; padding:8px 10px; }
        .ma-mapping-table select { width:100%; height:32px; min-height:32px; padding:5px 9px; font-weight:900; }
        .current-agent-box { max-width:260px; border:1px solid #dbe5f2; border-radius:8px; background:#fbfdff; padding:8px 10px; }
        .current-agent-box span { display:block; color:#52637a; font-size:10px; font-weight:950; text-transform:uppercase; letter-spacing:.08em; }
        .current-agent-box strong { display:block; margin-top:4px; font-size:13px; }
        .ma-store-panel { overflow-x:auto; }
        .ma-store-panel .table-wrap { overflow-x:auto; }
        .ma-store-summary-table { min-width:1040px; table-layout:auto; }
        .ma-store-summary-table th { white-space:nowrap; }
        .ma-store-summary-table th:nth-child(1) { width:22%; }
        .ma-store-summary-table th:nth-child(2) { width:16%; }
        .ma-store-summary-table th:nth-child(n+3) { width:15%; }
        .ma-store-summary-table th:nth-child(n+3), .ma-store-summary-table td:nth-child(n+3) { text-align:right; }
        .ma-report-table { table-layout:fixed; min-width:1180px; }
        .ma-report-table th:nth-child(1) { width:7%; }
        .ma-report-table th:nth-child(2) { width:7%; }
        .ma-report-table th:nth-child(3) { width:5%; }
        .ma-report-table th:nth-child(4) { width:9%; }
        .ma-report-table th:nth-child(5) { width:8%; }
        .ma-report-table th:nth-child(6) { width:7%; }
        .ma-report-table th:nth-child(7) { width:8%; }
        .ma-report-table th:nth-child(8) { width:11%; }
        .ma-report-table th:nth-child(9) { width:8%; }
        .ma-report-table th:nth-child(10) { width:10%; }
        .ma-report-table th:nth-child(11) { width:8%; }
        .ma-report-table th:nth-child(12) { width:7%; }
        .ma-report-table th:nth-child(13) { width:5%; }
        .ma-report-table td:nth-child(5), .ma-report-table td:nth-child(9) { text-align:right; white-space:nowrap; }
        .ma-report-table td:nth-child(6), .ma-report-table td:nth-child(8), .ma-report-table td:nth-child(10), .ma-report-table td:nth-child(13) { overflow-wrap:anywhere; word-break:break-word; }
        .topup-cards { grid-template-columns:repeat(6, minmax(0, 1fr)); gap:12px; margin-bottom:12px; }
        .checklist-cards { grid-template-columns:repeat(5, minmax(0, 1fr)); gap:14px; margin-bottom:12px; }
        .topup-card { min-height:128px; border-radius:10px; text-decoration:none; color:inherit; overflow:hidden; }
        .topup-card:hover, .topup-card.active-card { background:#eef5ff; border-color:#78a7ff; }
        .topup-card.active-card { box-shadow:0 0 0 1px #78a7ff inset; }
        .topup-card span { display:block; min-height:28px; font-size:11px; letter-spacing:.07em; text-transform:uppercase; font-weight:950; color:#35445c; text-align:center; }
        .topup-card-row { display:grid; grid-template-columns:1fr auto; align-items:center; gap:8px; padding-top:10px; margin-top:8px; border-top:1px solid #edf2f8; }
        .topup-card-row:first-of-type { border-top:0; }
        .topup-card-row small { font-size:10px; text-transform:uppercase; font-weight:950; color:#26364f; }
        .topup-card-row strong { font-size:20px; letter-spacing:-.035em; }
        .balance-card { background:#ecfff5; border-color:#a4ebc4; }
        .pending-balance-card { background:#fff9e9; border-color:#ffd46d; }
        .balance-card span, .pending-balance-card span { text-align:left; }
        .balance-number { margin-top:18px; color:#008450; font-size:18px; font-weight:950; line-height:1.15; letter-spacing:-.04em; white-space:nowrap; text-align:left; }
        .pending-balance-card .balance-number { color:#b15a00; }
        .status-dot { width:15px; height:15px; display:inline-grid; place-items:center; border-radius:999px; background:#d7e4f4; }
        .status-dot::after { content:""; width:8px; height:8px; border-radius:999px; background:#6b7b91; }
        .status-dot.success { background:#dff8ec; }
        .status-dot.success::after { background:#008450; }
        .status-dot.pending { background:#fff0c7; }
        .status-dot.pending::after { background:#b15a00; }
        .status-dot.expired, .status-dot.failed, .status-dot.rejected { background:#ffe0dd; }
        .status-dot.expired::after, .status-dot.failed::after, .status-dot.rejected::after { background:#c62828; }
        .checked-btn { background:#dff8ec; color:#008450; border-color:#dff8ec; }
        .mini-check { width:16px; height:16px; display:inline-grid; place-items:center; border-radius:4px; background:#1557c2; color:#fff; font-size:12px; font-weight:950; }
        .mini-check.empty { background:#fff; border:2px solid #9dbaf1; color:transparent; }
        .check-icon { width:22px; height:22px; display:inline-grid; place-items:center; border:2px solid #9dbaf1; border-radius:6px; background:#fff; cursor:pointer; vertical-align:middle; }
        .check-icon:hover { border-color:#1557c2; background:#f7faff; }
        .check-icon.checked { border-color:#1557c2; background:#1557c2; cursor:default; }
        .check-icon.checked::after { content:"✓"; color:#fff; font-size:17px; font-weight:950; line-height:1; transform:translateY(-1px); }
        .qris-panel { border-radius:10px; overflow:hidden; box-shadow:0 8px 22px rgba(14, 35, 70, .045); }
        .qris-toolbar { display:flex; justify-content:space-between; gap:14px; align-items:center; padding:11px 14px; border-bottom:1px solid #e7edf6; background:#fff; }
        .qris-toolbar .muted { font-size:13px; }
        .qris-filters { display:flex; gap:10px; justify-content:flex-end; align-items:center; flex-wrap:wrap; }
        .qris-filters .search { width:220px; }
        .qris-table { min-width:0; table-layout:fixed; font-size:12px; }
        .qris-table th { height:42px; padding-top:0; padding-bottom:0; background:#f6f9fd; font-size:11px; letter-spacing:.09em; white-space:normal; }
        .qris-table td { height:44px; padding:6px 8px; color:#001634; font-weight:750; }
        .qris-table .btn { min-height:30px; padding:5px 12px; font-size:13px; border-radius:7px; }
        .qris-table .btn:disabled { min-width:96px; color:#607087; border-color:#d8e2f2; background:#f7faff; cursor:not-allowed; }
        .qris-table button:not(:disabled) { cursor:pointer; }
        .fee-rate-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(96px, 1fr)); gap:0 8px; max-height:168px; overflow:auto; border:1px solid var(--line); border-radius:8px; padding:6px 8px; background:#fbfdff; }
        .fee-rate-row { display:flex; flex-direction:column; gap:2px; padding:4px 0; border-bottom:1px solid #e7edf6; min-width:0; }
        .fee-rate-row:last-child { border-bottom:0; }
        .fee-rate-label { font-size:10px; line-height:1.15; color:#33415a; font-weight:750; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .fee-rate-label small { display:block; font-weight:500; color:var(--muted); }
        .fee-rate-input { width:100%; box-sizing:border-box; padding:4px 6px; font-size:12px; min-width:64px; }
        .fee-rate-row .field-hint { font-size:9px; color:var(--danger); line-height:1.1; }
        .ticket-table { min-width:0; table-layout:fixed; }
        .ticket-table th:nth-child(1) { width:9%; }
        .ticket-table th:nth-child(2) { width:24%; }
        .ticket-table th:nth-child(3) { width:15%; }
        .ticket-table th:nth-child(4) { width:12%; }
        .ticket-table th:nth-child(5) { width:9%; }
        .ticket-table th:nth-child(6) { width:11%; }
        .ticket-table th:nth-child(7) { width:20%; }
        .time-cell { line-height:1.18; white-space:nowrap; }
        .time-cell span { display:block; color:#26364f; font-size:12px; font-weight:650; margin-top:2px; }
        .ref-line { display:block; max-width:100%; }
        .col-time { width:9%; }
        .col-time-mini { width:7%; }
        .col-duration { width:5%; }
        .col-payment { width:18%; }
        .col-reference { width:18%; }
        .col-rrn { width:11%; }
        .col-trx { width:7%; }
        .col-amount { width:8%; }
        .col-status { width:7%; }
        .col-check { width:7%; }
        .col-note { width:10%; }
        .col-follow { width:12%; }
        .col-checked-by { width:12%; }
        .topup-table th:nth-child(6), .topup-table td:nth-child(6),
        .topup-table th:nth-child(7), .topup-table td:nth-child(7),
        .topup-table th:nth-child(8), .topup-table td:nth-child(8),
        .history-table th:nth-child(6), .history-table td:nth-child(6),
        .history-table th:nth-child(8), .history-table td:nth-child(8) { text-align:center; }
        .ticket-done { min-width:118px; padding-inline:10px; color:#52637a; border-color:#d8e2f2; background:#f7faff; }
        .compact-actions { gap:8px; flex-wrap:nowrap; }
        .compact-actions form { margin:0; }
        .ticket-submit { display:flex; gap:8px; align-items:center; justify-content:flex-start; min-width:0; }
        .ticket-submit .btn { min-width:82px; }
        .file-pick { position:relative; display:inline-flex; align-items:center; justify-content:center; min-height:30px; padding:5px 10px; border:1px solid #b9cef6; border-radius:7px; background:#fff; color:#1557c2; font-size:12px; font-weight:900; cursor:pointer; white-space:nowrap; }
        .file-pick input { position:absolute; inset:0; opacity:0; cursor:pointer; }
        .file-name { display:inline-block; max-width:82px; color:#52637a; font-size:11px; font-weight:800; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .qris-pagination { display:flex; justify-content:space-between; gap:12px; align-items:center; border-top:1px solid #e7edf6; background:#fbfdff; }
        .pager-summary { color:var(--muted); font-size:12px; font-weight:750; }
        .pager-links { display:flex; gap:5px; align-items:center; flex-wrap:wrap; }
        .pager { display:inline-flex; align-items:center; justify-content:center; min-width:30px; min-height:30px; padding:6px 9px; border:1px solid #c9d6ea; border-radius:7px; background:#fff; color:#1557c2; font-size:12px; font-weight:900; text-decoration:none; }
        .pager.active { background:#1557c2; border-color:#1557c2; color:#fff; }
        .pager.disabled { color:#8b98aa; background:#f3f6fa; pointer-events:none; }
        input, select { border:1px solid #c9d6ea; border-radius:7px; min-height:36px; padding:8px 10px; font:inherit; font-size:13px; background:#fff; color:var(--ink); }
        .search { width:min(520px, 100%); border-radius:7px; padding-left:12px; color:#68768a; background:#fbfdff; }
        .filters { display:flex; justify-content:space-between; gap:12px; align-items:center; padding:16px 18px; }
        .actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .agent-filter-card { overflow:hidden; }
        .agent-filter-grid { display:flex; flex-wrap:wrap; gap:12px; align-items:end; padding:16px 18px; background:linear-gradient(180deg, #fff, #fbfdff); }
        .agent-filter-grid label { display:grid; gap:7px; margin:0; flex:1 1 150px; min-width:150px; }
        .agent-filter-grid label span { font-size:10px; letter-spacing:.11em; text-transform:uppercase; color:#52637a; font-weight:950; }
        .agent-filter-grid input, .agent-filter-grid select { width:100%; height:36px; }
        .agent-filter-search { flex:2 1 220px; }
        .agent-filter-search .search { width:100%; color:var(--ink); }
        .agent-filter-actions { flex:1 1 100%; display:flex; gap:8px; align-items:center; justify-content:flex-end; flex-wrap:wrap; padding-top:12px; margin-top:2px; border-top:1px solid #e7edf6; }
        .agent-filter-actions .btn { min-height:32px; padding:6px 13px; font-size:12.5px; }
        .agent-bulk-bar { display:flex; justify-content:space-between; gap:14px; align-items:center; padding:13px 18px; border-top:1px solid #e7edf6; background:#f7faff; }
        .agent-bulk-bar strong { display:block; font-size:13px; }
        .agent-bulk-bar span { display:block; margin-top:3px; color:var(--muted); font-size:12px; font-weight:700; }
        .agent-request-table th { background:#f3f7fc; color:#20324d; font-size:11px; letter-spacing:.08em; }
        .agent-request-table td { padding:10px 12px; }
        .agent-link-table { table-layout:fixed; min-width:980px; }
        .agent-link-table th:nth-child(1) { width:6%; text-align:center; }
        .agent-link-table th:nth-child(2) { width:12%; }
        .agent-link-table th:nth-child(3) { width:20%; }
        .agent-link-table th:nth-child(4) { width:10%; }
        .agent-link-table th:nth-child(5) { width:30%; }
        .agent-link-table th:nth-child(6) { width:14%; }
        .agent-link-table th:nth-child(7) { width:8%; }
        .agent-link-table td { height:auto; padding:8px 10px; vertical-align:middle; }
        .agent-link-table td:first-child { text-align:center; }
        .agent-link-table .time-cell span { display:block; margin-top:3px; color:var(--muted); font-size:12px; font-weight:800; }
        .agent-link-table input[readonly] { width:100%; min-height:30px; height:30px; padding:5px 8px; font-size:12px; }
        .agent-store-report-table th:nth-child(1) { width:23%; }
        .agent-store-report-table th:nth-child(2) { width:7%; }
        .agent-store-report-table th:nth-child(n+3), .agent-store-report-table td:nth-child(n+3) { text-align:right; }
        .agent-store-report-table { min-width:1040px; }
        .agent-ticket-table { min-width:1180px; }
        .agent-ticket-table th:nth-child(1) { width:9%; }
        .agent-ticket-table th:nth-child(2) { width:13%; }
        .agent-ticket-table th:nth-child(3) { width:12%; }
        .agent-ticket-table th:nth-child(4) { width:15%; }
        .agent-ticket-table th:nth-child(5) { width:12%; }
        .agent-ticket-table th:nth-child(6) { width:11%; }
        .agent-ticket-table th:nth-child(7) { width:10%; }
        .agent-ticket-table th:nth-child(8) { width:12%; }
        .agent-ticket-table th:nth-child(9) { width:6%; }
        .agent-volume-row { display:grid; grid-template-columns:minmax(130px, 220px) minmax(140px, 1fr) minmax(120px, 170px); gap:12px; align-items:center; margin:12px 0; }
        .agent-volume-track { height:12px; background:#e4ecf7; border-radius:999px; overflow:hidden; }
        .agent-volume-track div { height:100%; background:linear-gradient(90deg, var(--blue), var(--blue-2)); border-radius:999px; }
        .agent-volume-row span { text-align:right; font-weight:950; white-space:nowrap; }
        .agent-onboarding-card { padding:0; overflow:hidden; }
        .compact-toolbar { padding:16px 18px; }
        .merchant-workspace-head { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, .95fr); gap:18px; align-items:start; margin-bottom:20px; }
        .merchant-workspace-head h1 { font-size:25px; letter-spacing:-.035em; }
        .merchant-workspace-head p { margin:4px 0 6px; color:#304158; line-height:1.45; max-width:560px; }
        .merchant-workspace-head span { color:#52637a; font-size:12px; font-weight:750; }
        .workspace-link { display:inline-flex; margin-top:4px; color:#1557c2; font-size:13px; font-weight:950; text-decoration:none; }
        .merchant-workspace-filter { display:grid; grid-template-columns:minmax(0, 1fr) minmax(130px, 160px) minmax(130px, 160px) auto; gap:10px; align-items:end; min-width:0; }
        .merchant-workspace-filter label { display:grid; gap:6px; margin:0; }
        .merchant-workspace-filter label span { font-size:10px; letter-spacing:.11em; text-transform:uppercase; color:#52637a; font-weight:950; }
        .merchant-workspace-filter input { height:44px; min-height:44px; }
        .merchant-workspace-filter .search { width:100%; border-radius:14px; background:#fbfdff; }
        .merchant-workspace-filter .btn { height:44px; border-radius:10px; }
        .merchant-workspace-cards { margin:0 0 18px; }
        .workspace-tabs { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0 0 18px; padding:10px; border:1px solid #dbe5f2; border-radius:14px; background:#f8fbff; }
        .workspace-tabs .btn { min-height:38px; padding-inline:18px; border-radius:11px; }
        .workspace-table { width:100%; min-width:0; table-layout:fixed; }
        .workspace-table th { height:38px; padding:0 6px; background:#f6f9fd; color:#30415b; font-size:10px; letter-spacing:.035em; }
        .workspace-table td { height:66px; padding:8px 6px; color:#001634; font-size:12px; font-weight:750; vertical-align:middle; line-height:1.18; overflow:hidden; }
        .workspace-table td:nth-child(3) strong { font-size:15px; }
        .workspace-table code { color:#001634; font-size:11px; background:transparent; white-space:normal; overflow-wrap:anywhere; }
        .workspace-table textarea { width:100%; min-height:44px; resize:none; border:1px solid #c9d6ea; border-radius:8px; padding:7px; background:#fff; color:#52637a; font:inherit; font-size:11px; }
        .workspace-table .checked-row, .workspace-table .topup-success-checked-row { background:#d7f9e5; }
        .workspace-table .checked-row td, .workspace-table .topup-success-checked-row td { border-top-color:#8bddae; }
        .workspace-table .workspace-checked-by { padding-left:14px; padding-right:14px; overflow-wrap:anywhere; word-break:break-word; line-height:1.15; }
        .workspace-table .workspace-checked-by .muted { display:block; margin-top:2px; }
        .workspace-table .checkbox-cell { text-align:center; vertical-align:middle; }
        .workspace-table .checkbox-cell .compact-actions { display:flex; align-items:center; justify-content:center; margin:0; }
        .workspace-table .checkbox-cell .check-icon { margin:0 auto; }
        .workspace-table .checkbox-cell button.check-icon.checked { cursor:pointer; }
        .workspace-table .badge { min-width:60px; padding-inline:8px; }
        .workspace-table .btn { min-height:34px; padding:6px 10px; font-size:12px; border-radius:8px; width:auto; white-space:nowrap; }
        .workspace-table .trx-time-stack { display:grid; gap:2px; font-size:11px; line-height:1.12; color:#30415b; }
        .workspace-table .trx-time-stack b { color:#001634; font-size:12px; }
        .workspace-table .trx-time-stack span { display:block; white-space:nowrap; }
        .workspace-table .trx-time-stack .duration { color:#1557c2; font-weight:950; }
        .workspace-table .id-stack { display:grid; gap:2px; font-size:11px; line-height:1.15; overflow-wrap:anywhere; }
        .workspace-table .id-stack .muted { font-size:10px; }
        .time-mini { white-space:nowrap; font-size:12px; font-weight:900; }
        .duration-cell { white-space:nowrap; color:#1557c2; font-size:12px; font-weight:950; }
        .history-table th:nth-child(1), .history-table th:nth-child(2), .history-table th:nth-child(3) { width:7%; }
        .history-table th:nth-child(4) { width:15%; }
        .history-table th:nth-child(5) { width:11%; }
        .history-table th:nth-child(6) { width:8%; }
        .history-table th:nth-child(7) { width:9%; }
        .history-table th:nth-child(8) { width:9%; }
        .history-table th:nth-child(9) { width:17%; }
        .history-table th:nth-child(10) { width:10%; }
        .workspace-table.topup-table th:nth-child(1) { width:14%; }
        .workspace-table.topup-table th:nth-child(2) { width:7%; }
        .workspace-table.topup-table th:nth-child(3) { width:7%; }
        .workspace-table.topup-table th:nth-child(4) { width:6%; }
        .workspace-table.topup-table th:nth-child(5) { width:8%; }
        .workspace-table.topup-table th:nth-child(6) { width:8%; }
        .workspace-table.topup-table th:nth-child(7) { width:12%; }
        .workspace-table.topup-table th:nth-child(8) { width:6%; }
        .workspace-table.topup-table th:nth-child(9) { width:16%; }
        .workspace-table.topup-table th:nth-child(10) { width:7%; }
        .workspace-table.topup-table th:nth-child(11) { width:9%; }
        .workspace-table.checklist-table th:nth-child(1) { width:15%; }
        .workspace-table.checklist-table th:nth-child(2) { width:7%; }
        .workspace-table.checklist-table th:nth-child(3) { width:7%; }
        .workspace-table.checklist-table th:nth-child(4) { width:6%; }
        .workspace-table.checklist-table th:nth-child(5) { width:8%; }
        .workspace-table.checklist-table th:nth-child(6) { width:13%; }
        .workspace-table.checklist-table th:nth-child(7) { width:6%; }
        .workspace-table.checklist-table th:nth-child(8) { width:18%; }
        .workspace-table.checklist-table th:nth-child(9) { width:8%; }
        .workspace-table.checklist-table th:nth-child(10) { width:12%; }
        .workspace-table.checklist-table td:nth-child(9) { text-align:center; }
        .split { display:grid; grid-template-columns:1.1fr 1fr; gap:18px; }
        .mini-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; }
        .fee-row { display:grid; grid-template-columns:minmax(78px, 1fr) auto; gap:12px; align-items:center; padding:7px 0; border-bottom:1px solid #edf2f8; }
        .fee-list { min-width:160px; }
        .fee-list .fee-row { border-bottom:0; padding:3px 0; }
        .fee-pill { display:grid; grid-template-columns:1fr auto; gap:8px; align-items:center; border:1px solid var(--line); border-radius:8px; padding:10px 12px; background:#fbfdff; min-height:44px; }
        .compact-user-table th, .compact-user-table td { padding:4px 10px; }
        .compact-user-table td { height:auto; min-height:0; line-height:1.1; }
        .compact-user-table input { min-height:28px; height:28px; padding:4px 8px; max-width:150px; font-size:12px; }
        .compact-user-table .btn, .compact-btn { min-height:28px; height:28px; padding:4px 10px; font-size:12px; border-radius:6px; justify-self:start; width:auto; }
        .reset-inline { flex-wrap:nowrap; gap:6px; }
        .admin-create-card { padding:18px; }
        .admin-create-card h2 { font-size:20px; letter-spacing:-.02em; }
        .admin-create-card .muted { margin-top:6px; font-size:13px; }
        .admin-create-form { display:grid; grid-template-columns:minmax(260px, 1.2fr) minmax(150px, .55fr) minmax(230px, 1fr) auto; gap:18px; align-items:end; }
        .admin-create-form label { display:grid; gap:8px; margin:0; min-width:0; }
        .admin-create-form input, .admin-create-form select { width:100%; height:40px; min-height:40px; padding:8px 12px; font-weight:800; }
        .admin-create-form .btn { min-height:40px; padding-inline:16px; margin-bottom:0; }
        .admin-minimum-card { max-width:520px; }
        .admin-minimum-card h2 { font-size:17px; }
        .admin-minimum-form { display:grid; grid-template-columns:auto auto; gap:10px; align-items:end; }
        .admin-minimum-form label { margin:0; }
        .admin-minimum-form input { height:32px; min-height:32px; padding:5px 9px; width:160px; }
        .center-ticket-table th:nth-child(1) { width:17%; }
        .center-ticket-table th:nth-child(2) { width:9%; }
        .center-ticket-table th:nth-child(3) { width:22%; }
        .center-ticket-table th:nth-child(4) { width:15%; }
        .center-ticket-table th:nth-child(5) { width:8%; }
        .center-ticket-table th:nth-child(6) { width:18%; }
        .center-ticket-table th:nth-child(7) { width:11%; }
        .center-ticket-table th, .center-ticket-table td { padding:6px 10px; }
        .center-ticket-table td { height:auto; line-height:1.12; vertical-align:middle; }
        .center-ticket-table .btn { min-height:28px; padding:4px 10px; font-size:12px; }
        .center-ticket-table td:nth-child(7) .btn { min-width:88px; }
        .center-ticket-table input { width:100%; min-height:28px; height:28px; padding:4px 8px; font:inherit; font-size:12px; border:1px solid #c9d6ea; border-radius:7px; }
        .center-update-row { margin:0; }
        .center-status { width:100%; min-height:30px; height:30px; padding:4px 8px; font-size:12px; font-weight:900; }
        .center-status-not-started { background:#e8f1ff; border-color:#9fc0ff; color:#1557c2; }
        .center-status-checking { background:#fff0c7; border-color:#ffc94d; color:#9a4a00; }
        .center-status-issue-bank { background:#ffe0dd; border-color:#f0a6a0; color:#b52727; }
        .center-status-success { background:#dff8ec; border-color:#8be2b6; color:#008450; }
        .center-status-issue-switching { background:#f0e8ff; border-color:#c3adff; color:#4f2a8a; }
        .super-fee-table th:nth-child(1) { width:15%; }
        .super-fee-table th:nth-child(2) { width:10%; }
        .super-fee-table th:nth-child(3), .super-fee-table th:nth-child(4), .super-fee-table th:nth-child(6), .super-fee-table th:nth-child(7), .super-fee-table th:nth-child(8) { width:9%; }
        .super-fee-table th:nth-child(5) { width:13%; }
        .super-fee-table th:nth-child(9) { width:8%; }
        .super-fee-table th:nth-child(10) { width:9%; }
        .super-fee-table td { height:54px; }
        .super-fee-table input, .super-fee-table select { width:100%; height:30px; min-height:30px; padding:4px 8px; font-size:12px; font-weight:850; }
        .settle-fee-field { display:grid; grid-template-columns:1fr 74px; gap:6px; align-items:center; }
        .super-create-table th:nth-child(1), .super-create-table th:nth-child(2), .super-create-table th:nth-child(5) { width:14%; }
        .super-create-table th:nth-child(3) { width:10%; }
        .super-create-table th:nth-child(4), .super-create-table th:nth-child(8), .super-create-table th:nth-child(11) { width:8%; }
        .super-create-table th:nth-child(6), .super-create-table th:nth-child(7), .super-create-table th:nth-child(9), .super-create-table th:nth-child(10) { width:6%; }
        .super-create-table td { height:50px; padding:5px 6px; }
        .super-create-table input, .super-create-table select { width:100%; height:30px; min-height:30px; padding:4px 7px; font-size:12px; font-weight:850; }
        .super-group-create-table th:nth-child(1), .super-group-create-table th:nth-child(2), .super-group-create-table th:nth-child(3) { width:12%; }
        .super-group-create-table th:nth-child(4), .super-group-create-table th:nth-child(7), .super-group-create-table th:nth-child(11), .super-group-create-table th:nth-child(12) { width:8%; }
        .super-group-create-table th:nth-child(5), .super-group-create-table th:nth-child(6), .super-group-create-table th:nth-child(8), .super-group-create-table th:nth-child(9), .super-group-create-table th:nth-child(10) { width:6%; }
        .super-group-create-table td { height:50px; padding:5px 6px; }
        .super-group-create-table input, .super-group-create-table select { width:100%; height:30px; min-height:30px; padding:4px 7px; font-size:12px; font-weight:850; }
        .approval-card { display:grid; grid-template-columns:minmax(220px, 1.1fr) minmax(180px, .9fr) minmax(310px, 1.35fr) minmax(130px, .55fr); gap:18px; align-items:start; }
        .approval-card > div { min-width:0; }
        .initial { width:52px; height:52px; border-radius:50%; display:grid; place-items:center; background:var(--blue-2); color:#fff; font-size:22px; font-weight:950; flex:0 0 auto; }
        .store-title { display:flex; gap:14px; align-items:center; min-width:0; }
        .approval-review-card { display:grid; grid-template-columns:1.25fr .95fr 1.15fr 2.15fr .65fr; gap:18px; align-items:start; }
        .approval-store-cell { display:flex; gap:14px; align-items:flex-start; min-width:0; }
        .merchant-id-box { width:38px; height:38px; margin:12px 0 10px; border-radius:10px; display:grid; place-items:center; background:#eef5ff; color:#1557c2; font-size:12px; font-weight:950; }
        .user-access-card { margin-top:12px; padding:12px; border:1px solid #dbe5f2; border-radius:10px; background:#fbfdff; }
        .user-access-card h2 { margin:6px 0; font-size:16px; }
        .fee-pill.wide { margin:8px 0; background:#f7faff; }
        .metric-line { position:relative; overflow:hidden; }
        .metric-line::after { content:""; position:absolute; left:10px; right:10px; bottom:6px; height:4px; border-radius:999px; background:#1557c2; }
        .metric-line.green::after { background:#11945b; }
        .approval-detail-open { width:100%; margin-top:8px; }
        .approval-modal { position:fixed; inset:0; z-index:50; display:grid; place-items:center; padding:20px; background:rgba(15, 28, 48, .45); }
        .approval-modal[hidden] { display:none; }
        .approval-modal-card { width:min(880px, 96vw); max-height:88vh; overflow:auto; border:1px solid #dbe5f2; border-radius:14px; background:#fff; box-shadow:0 24px 70px rgba(0,0,0,.22); }
        .approval-detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; padding:14px; }
        .truncate { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:100%; }
        .form-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px; }
        .form-grid label { font-weight:900; color:#10233f; }
        .form-grid input, .form-grid select { width:100%; margin-top:8px; }
        .empty { padding:24px 18px; color:var(--muted); text-align:center; line-height:1.45; }
        .empty strong { display:block; color:var(--ink); margin-bottom:4px; }
        .ops-panel { display:grid; gap:9px; margin:0 0 18px; }
        .ops-health { display:flex; gap:10px; align-items:center; justify-content:space-between; min-height:42px; padding:9px 12px; border:1px solid #d8e5f7; border-radius:13px; background:linear-gradient(135deg, #fff, #f7fbff); box-shadow:0 8px 22px rgba(14, 35, 70, .045); }
        .ops-live { display:inline-flex; align-items:center; gap:8px; min-width:max-content; color:#0f2c57; font-size:12px; font-weight:950; letter-spacing:.06em; text-transform:uppercase; }
        .ops-dot { width:9px; height:9px; border-radius:999px; background:#00a86b; box-shadow:0 0 0 0 rgba(0,168,107,.45); animation:ops-pulse 1.8s ease-out infinite; }
        .ops-dot.warn { background:#d99a00; box-shadow:0 0 0 0 rgba(217,154,0,.45); }
        .ops-stats { display:flex; gap:7px; align-items:center; justify-content:flex-end; flex-wrap:wrap; }
        .ops-pill { display:inline-flex; align-items:center; gap:6px; min-height:24px; padding:4px 9px; border-radius:999px; background:#eef5ff; color:#1557c2; font-size:11px; font-weight:950; white-space:nowrap; }
        .ops-pill.ok { background:#dff8ec; color:#008450; }
        .ops-pill.warn { background:#fff0c7; color:#8a4b00; }
        .ops-pill.danger { background:#ffe0dd; color:#b52727; }
        .ops-ticker { position:relative; min-height:42px; overflow:hidden; border:1px solid #d8e5f7; border-radius:13px; background:#071a38; color:#eaf2ff; box-shadow:0 12px 28px rgba(7, 26, 56, .12); }
        .ops-ticker::before, .ops-ticker::after { content:""; position:absolute; top:0; bottom:0; width:44px; z-index:1; pointer-events:none; }
        .ops-ticker::before { left:0; background:linear-gradient(90deg, #071a38, rgba(7,26,56,0)); }
        .ops-ticker::after { right:0; background:linear-gradient(270deg, #071a38, rgba(7,26,56,0)); }
        .ops-track { display:flex; width:max-content; align-items:center; gap:24px; min-height:42px; padding:0 22px; animation:ops-marquee 42s linear infinite; }
        .ops-ticker:hover .ops-track { animation-play-state:paused; }
        .ops-item { display:inline-flex; align-items:center; gap:8px; font-size:12px; font-weight:850; white-space:nowrap; }
        .ops-mark { width:8px; height:8px; border-radius:999px; background:#6aa8ff; box-shadow:0 0 0 3px rgba(106,168,255,.14); }
        .ops-mark.ok { background:#21d07a; box-shadow:0 0 0 3px rgba(33,208,122,.14); }
        .ops-mark.warn { background:#ffc857; box-shadow:0 0 0 3px rgba(255,200,87,.14); }
        .ops-mark.danger { background:#ff6b6b; box-shadow:0 0 0 3px rgba(255,107,107,.14); }
        .ops-alerts { display:flex; gap:8px; align-items:center; flex-wrap:wrap; padding:0 2px; }
        .ops-alert { display:inline-flex; align-items:center; gap:7px; min-height:28px; padding:5px 10px; border:1px solid #f1b8b8; border-radius:999px; background:#fff1f0; color:#a51f1f; font-size:12px; font-weight:950; }
        @keyframes ops-pulse { 0% { box-shadow:0 0 0 0 rgba(0,168,107,.45); } 75%, 100% { box-shadow:0 0 0 9px rgba(0,168,107,0); } }
        @keyframes ops-marquee { from { transform:translateX(0); } to { transform:translateX(-50%); } }
        @media (prefers-reduced-motion: reduce) { .ops-dot, .ops-track { animation:none; } }
        @media (max-width: 1280px) {
            .shell { grid-template-columns:190px minmax(0, 1fr); }
            .sidebar { padding:16px 10px; }
            .brand img { width:164px; }
            main { padding:16px 18px 34px; }
            .cards, .qris-metrics, .qris-metrics.history-metrics { grid-template-columns:repeat(2, minmax(0, 1fr)); }
            .metric { min-height:112px; }
            .metric strong { font-size:27px; }
            .agent-filter-grid { padding:14px; }
            .qris-table { table-layout:fixed; }
            .table-wrap { overflow-x:hidden; }
        }
        @media (max-width: 1100px) {
            .cards, .qris-metrics, .qris-metrics.history-metrics { grid-template-columns:repeat(2, minmax(0, 1fr)); }
            .topup-cards { grid-template-columns:repeat(3, minmax(0, 1fr)); }
            .checklist-cards { grid-template-columns:repeat(2, minmax(0, 1fr)); }
            .approval-card, .approval-review-card { grid-template-columns:1fr 1fr; }
            .shell { grid-template-columns:172px minmax(0, 1fr); }
            .brand img { width:148px; }
            .nav a { gap:8px; padding:8px 9px; font-size:12px; }
            .nav-icon { width:22px; height:22px; flex-basis:22px; border-radius:7px; font-size:12px; }
            main { padding:14px 14px 32px; }
            h1 { font-size:25px; }
            .page-head { gap:12px; margin-bottom:16px; }
            .ops-health { align-items:flex-start; flex-direction:column; }
            .ops-stats { justify-content:flex-start; }
            .qris-table { font-size:11px; }
            .qris-table th { font-size:8px; letter-spacing:.08em; }
            .qris-table .btn { min-height:28px; padding:4px 8px; font-size:11px; }
        }
        @media (max-width: 900px) {
            .shell { grid-template-columns:1fr; }
            .sidebar { position:relative; height:auto; padding:14px; }
            .brand img { width:170px; }
            .nav { grid-template-columns:repeat(3, minmax(0, 1fr)); }
            .logout { padding-top:12px; }
            main { padding:18px 14px 36px; }
            .page-head { flex-direction:column; align-items:stretch; }
            .page-actions { justify-content:flex-start; }
            .agent-filter-search { flex-basis:100%; }
            .agent-filter-actions { justify-content:flex-start; }
            .ops-panel { margin-bottom:14px; }
        }
        @media (max-width: 800px) {
            .shell { grid-template-columns:1fr; }
            .sidebar { position:relative; height:auto; padding:18px 14px; }
            .nav { grid-template-columns:repeat(2, minmax(0, 1fr)); }
            main { padding:20px 14px 40px; }
            h1 { font-size:28px; }
            .cards, .qris-metrics, .topup-cards, .checklist-cards, .split, .approval-card, .approval-review-card, .approval-detail-grid, .form-grid, .bot-charts { grid-template-columns:1fr; }
            .bot-chart-box { height:170px; }
            .admin-create-form, .admin-minimum-form { grid-template-columns:1fr; }
            .page-head, .filters, .qris-hero, .qris-toolbar { flex-direction:column; align-items:stretch; }
            .agent-filter-grid label { flex-basis:100%; }
            .agent-filter-actions, .agent-bulk-bar { align-items:stretch; justify-content:flex-start; }
            .agent-bulk-bar { flex-direction:column; }
            .agent-volume-row { grid-template-columns:1fr; }
            .agent-volume-row span { text-align:left; }
            .merchant-workspace-head, .merchant-workspace-filter { grid-template-columns:1fr; }
            .workspace-tabs .btn { width:100%; }
            .page-actions, .actions { justify-content:flex-start; }
            .qris-hero h1 { font-size:32px; }
            .qris-filters, .hero-actions { justify-content:flex-start; }
            .qris-filters .search { width:100%; }
            .metric { min-height:auto; }
            .table-wrap { overflow-x:hidden; }
            .qris-table, .qris-table thead, .qris-table tbody, .qris-table tr, .qris-table td { display:block; width:100%; }
            .qris-table { table-layout:auto; }
            .qris-table thead { display:none; }
            .qris-table tr { padding:10px 12px; border-top:1px solid #e7edf6; }
            .qris-table td { display:grid; grid-template-columns:118px minmax(0, 1fr); gap:10px; align-items:center; height:auto; min-height:30px; border-top:0; padding:4px 0; text-align:left !important; }
            .ma-detail-row { grid-template-columns:1fr; gap:5px; }
            .qris-table td::before { color:#52637a; font-size:10px; font-weight:950; letter-spacing:.08em; text-transform:uppercase; }
            .topup-table td:nth-child(1)::before { content:'Timestamp'; }
            .topup-table td:nth-child(2)::before { content:'Payment ID'; }
            .topup-table td:nth-child(3)::before { content:'RRN'; }
            .topup-table td:nth-child(4)::before { content:'TRX ID'; }
            .topup-table td:nth-child(5)::before { content:'Amount'; }
            .topup-table td:nth-child(6)::before { content:'Status'; }
            .topup-table td:nth-child(7)::before { content:'Checklist'; }
            .topup-table td:nth-child(8)::before { content:'Tindak Lanjut'; }
            .history-table td:nth-child(1)::before { content:'Timestamp'; }
            .history-table td:nth-child(2)::before { content:'Reference'; }
            .history-table td:nth-child(3)::before { content:'RRN'; }
            .history-table td:nth-child(4)::before { content:'TRX ID'; }
            .history-table td:nth-child(5)::before { content:'Amount'; }
            .history-table td:nth-child(6)::before { content:'Status'; }
            .history-table td:nth-child(7)::before { content:'Keterangan'; }
            .history-table td:nth-child(8)::before { content:'Tindak Lanjut'; }
            .checklist-table td:nth-child(1)::before { content:'Timestamp'; }
            .checklist-table td:nth-child(2)::before { content:'Reference'; }
            .checklist-table td:nth-child(3)::before { content:'RRN'; }
            .checklist-table td:nth-child(4)::before { content:'Amount'; }
            .checklist-table td:nth-child(5)::before { content:'Status'; }
            .checklist-table td:nth-child(6)::before { content:'Checked By'; }
            .checklist-table td:nth-child(7)::before { content:'Keterangan'; }
            .checklist-table td:nth-child(8)::before { content:'Checklist'; }
            .ticket-table td:nth-child(1)::before { content:'Dibuat'; }
            .ticket-table td:nth-child(2)::before { content:'Ticket'; }
            .ticket-table td:nth-child(3)::before { content:'Customer'; }
            .ticket-table td:nth-child(4)::before { content:'Issue'; }
            .ticket-table td:nth-child(5)::before { content:'Status'; }
            .ticket-table td:nth-child(6)::before { content:'Catatan'; }
            .ticket-table td:nth-child(7)::before { content:'Submit'; }
            .finance-table td:nth-child(1)::before { content:'Tanggal'; }
            .finance-table td:nth-child(2)::before { content:'Reference'; }
            .finance-table td:nth-child(3)::before { content:'RRN'; }
            .finance-table td:nth-child(4)::before { content:'Gross'; }
            .finance-table td:nth-child(5)::before { content:'Fee'; }
            .finance-table td:nth-child(6)::before { content:'Net'; }
            .finance-table td:nth-child(7)::before { content:'Status'; }
            .settlement-table td:nth-child(1)::before { content:'Settlement'; }
            .settlement-table td:nth-child(2)::before { content:'Reference'; }
            .settlement-table td:nth-child(3)::before { content:'Batch'; }
            .settlement-table td:nth-child(4)::before { content:'TRX'; }
            .settlement-table td:nth-child(5)::before { content:'Gross'; }
            .settlement-table td:nth-child(6)::before { content:'Fee'; }
            .settlement-table td:nth-child(7)::before { content:'Net'; }
            .settlement-table td:nth-child(8)::before { content:'Status'; }
            .admin-user-table td:nth-child(1)::before { content:'Nama'; }
            .admin-user-table td:nth-child(2)::before { content:'Email'; }
            .admin-user-table td:nth-child(3)::before { content:'Role'; }
            .admin-user-table td:nth-child(4)::before { content:'Password'; }
            .admin-user-table td:nth-child(5)::before { content:'Reset'; }
            .admin-log-table td:nth-child(1)::before { content:'Waktu'; }
            .admin-log-table td:nth-child(2)::before { content:'User'; }
            .admin-log-table td:nth-child(3)::before { content:'Aktivitas'; }
            .admin-log-table td:nth-child(4)::before { content:'Data'; }
            .admin-log-table td:nth-child(5)::before { content:'Keterangan'; }
            .admin-trx-table td:nth-child(1)::before { content:'Waktu'; }
            .admin-trx-table td:nth-child(2)::before { content:'Reference'; }
            .admin-trx-table td:nth-child(3)::before { content:'RRN'; }
            .admin-trx-table td:nth-child(4)::before { content:'Amount'; }
            .admin-trx-table td:nth-child(5)::before { content:'Status'; }
            .admin-trx-table td:nth-child(6)::before { content:'Checklist'; }
            .center-ticket-table td:nth-child(1)::before { content:'Ticket'; }
            .center-ticket-table td:nth-child(2)::before { content:'Tgl'; }
            .center-ticket-table td:nth-child(3)::before { content:'Transaksi'; }
            .center-ticket-table td:nth-child(4)::before { content:'Status'; }
            .center-ticket-table td:nth-child(5)::before { content:'Bukti'; }
            .center-ticket-table td:nth-child(6)::before { content:'Catatan'; }
            .center-ticket-table td:nth-child(7)::before { content:'Aksi'; }
            .agent-link-table { min-width:0; }
            .agent-link-table td:nth-child(1)::before { content:'Pilih'; }
            .agent-link-table td:nth-child(2)::before { content:'Dibuat'; }
            .agent-link-table td:nth-child(3)::before { content:'Penerima'; }
            .agent-link-table td:nth-child(4)::before { content:'Status'; }
            .agent-link-table td:nth-child(5)::before { content:'Link'; }
            .agent-link-table td:nth-child(6)::before { content:'Request'; }
            .agent-link-table td:nth-child(7)::before { content:'Aksi'; }
            .agent-store-report-table, .agent-ticket-table { min-width:0; }
            .agent-store-report-table td:nth-child(1)::before { content:'Toko'; }
            .agent-store-report-table td:nth-child(2)::before { content:'Tipe'; }
            .agent-store-report-table td:nth-child(3)::before { content:'Total TRX'; }
            .agent-store-report-table td:nth-child(4)::before { content:'Sukses'; }
            .agent-store-report-table td:nth-child(5)::before { content:'Pending'; }
            .agent-store-report-table td:nth-child(6)::before { content:'Expired'; }
            .agent-store-report-table td:nth-child(7)::before { content:'Volume'; }
            .agent-store-report-table td:nth-child(8)::before { content:'Pending HG'; }
            .agent-store-report-table td:nth-child(9)::before { content:'Settlement'; }
            .agent-ticket-table td:nth-child(1)::before { content:'Dibuat'; }
            .agent-ticket-table td:nth-child(2)::before { content:'Toko'; }
            .agent-ticket-table td:nth-child(3)::before { content:'Ticket'; }
            .agent-ticket-table td:nth-child(4)::before { content:'Reference'; }
            .agent-ticket-table td:nth-child(5)::before { content:'Customer'; }
            .agent-ticket-table td:nth-child(6)::before { content:'Issue'; }
            .agent-ticket-table td:nth-child(7)::before { content:'Status'; }
            .agent-ticket-table td:nth-child(8)::before { content:'Catatan'; }
            .agent-ticket-table td:nth-child(9)::before { content:'Submitted'; }
        }
        @media (max-width: 560px) {
            main { padding:14px 10px 30px; }
            .sidebar { padding:12px 10px; }
            .nav { grid-template-columns:1fr; }
            .cards, .qris-metrics, .topup-cards, .checklist-cards { grid-template-columns:1fr; }
            .metric strong { font-size:24px; }
            .agent-filter-grid { padding:12px; }
            .agent-filter-actions { align-items:stretch; }
            .agent-filter-actions .btn, .page-actions .btn { width:100%; }
            .qris-table td { grid-template-columns:96px minmax(0, 1fr); gap:8px; }
            .ops-track { animation-duration:34s; }
            .ops-item { font-size:11px; }
            h1 { font-size:24px; }
            h2 { font-size:16px; }
        }
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div>
            <div class="brand"><img src="{{ asset('images/paygrid-logo.png') }}" alt="PayGrid Transaction Monitoring Dashboard"></div>
            <div class="role">{{ $roleLabel ?? 'PayGrid' }}</div>
        </div>
        <div>
            <div class="menu-title">Menu</div>
            <nav class="nav">
                @foreach($menus as $item)
                    @php($navIcon = [
                        'overview' => '⌂',
                        'report' => '▥',
                        'fee' => '%',
                        'approval' => '✓',
                        'mapping' => '⇄',
                        'stores' => '▦',
                        'agents' => '◎',
                        'create-store' => '+',
                        'bot-monitoring' => '◈',
                        'status-request' => '↗',
                        'users' => 'U',
                        'logs' => '≡',
                        'monitoring' => '◌',
                        'topup' => '↑',
                        'qris' => '↑',
                        'checklist' => '✓',
                        'finance' => '$',
                        'cs' => 'CS',
                        'settings' => '*',
                        'history' => '↺',
                        'tickets' => '!',
                        'settlement' => '⇣',
                    ][$item['key']] ?? '•')
                    <a href="{{ $item['url'] }}" class="{{ ($active ?? '') === $item['key'] ? 'active' : '' }}" data-key="{{ $item['key'] }}"><span class="nav-icon" aria-hidden="true">{{ $navIcon }}</span><span class="nav-label">{{ $item['label'] }}</span></a>
                @endforeach
            </nav>
        </div>
        <div class="spacer"></div>
        <div class="logout">
            <form method="post" action="{{ route('logout') }}">@csrf<button class="btn" style="width:100%; justify-content:flex-start">Logout</button></form>
            <div class="user" style="margin-top:18px"><div class="avatar">{{ substr(auth()->user()?->name ?? 'PG', 0, 2) }}</div><div>{{ auth()->user()?->name ?? ($roleLabel ?? 'PayGrid') }}</div></div>
            <div class="session-note">Sesi otomatis berakhir setelah {{ config('session.lifetime') }} menit tidak aktif.</div>
        </div>
    </aside>
    <main>
        @if(in_array(auth()->user()?->role, ['ma', 'superadmin', 'cs_pusat'], true))
            @php($ops = $paygridOps ?? ['healthy' => true, 'queueLag' => null, 'failedJobs' => null, 'latestPullAge' => null, 'items' => [], 'alerts' => []])
            @php($opsItems = count($ops['items'] ?? []) ? $ops['items'] : [['tone' => 'ok', 'text' => 'Live monitoring normal']])
            <section class="ops-panel" aria-label="PayGrid live operations">
                <div class="ops-health">
                    <div class="ops-live"><span class="ops-dot {{ ($ops['healthy'] ?? false) ? '' : 'warn' }}"></span>Live Operations</div>
                    <div class="ops-stats">
                        @php($maNotifications = $maNotifications ?? collect())
                        @if($maNotifications->isNotEmpty())
                        <details class="notif-bell">
                            <summary aria-label="Notifikasi">🔔<span class="notif-count">{{ $maNotifications->count() }}</span></summary>
                            <div class="notif-panel">
                                <h3>{{ auth()->user()?->role === 'cs_pusat' ? 'Reminder Bot Telegram' : 'Notifikasi MA' }}</h3>
                                @foreach($maNotifications as $notification)
                                    <a class="ma-detail-row" href="{{ $notification->data['url'] ?? route('ma.approvals') }}"><div><b>{{ $notification->data['title'] ?? 'Notifikasi' }}</b><span>{{ $notification->data['message'] ?? '-' }}</span></div></a>
                                @endforeach
                            </div>
                        </details>
                        @endif
                        <span class="ops-pill {{ ($ops['healthy'] ?? false) ? 'ok' : 'warn' }}">Sync {{ ($ops['latestPullAge'] ?? null) !== null ? ($ops['latestPullAge'].'s') : 'n/a' }}</span>
                        <span class="ops-pill {{ (($ops['queueLag'] ?? 0) < 60) ? 'ok' : 'warn' }}">Queue {{ $ops['queueLag'] ?? 'n/a' }}s</span>
                        <span class="ops-pill {{ (($ops['failedJobs'] ?? 0) === 0) ? 'ok' : 'danger' }}">Failed {{ $ops['failedJobs'] ?? 'n/a' }}</span>
                        <span class="ops-pill">Refresh 3s</span>
                    </div>
                </div>
                <div class="ops-ticker">
                    <div class="ops-track">
                        @foreach(array_merge($opsItems, $opsItems) as $item)
                            <span class="ops-item"><span class="ops-mark {{ $item['tone'] ?? 'info' }}"></span>{{ $item['text'] ?? '' }}</span>
                        @endforeach
                    </div>
                </div>
                @if(! empty($ops['alerts']))
                    <div class="ops-alerts">
                        @foreach($ops['alerts'] as $alert)
                            <span class="ops-alert"><span class="ops-mark danger"></span>{{ $alert['text'] }}</span>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif
        @yield('content')
    </main>
</div>
@stack('scripts')
<script src="{{ asset('js/paygrid-live.js') }}?v={{ filemtime(public_path('js/paygrid-live.js')) }}" defer></script>
</body>
</html>
