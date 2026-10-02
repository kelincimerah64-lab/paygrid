<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>PayGrid Toko - Login</title>
<link rel="manifest" href="{{ asset('mobile-manifest.json') }}">
<link rel="apple-touch-icon" href="{{ asset('images/mobile-icon-apple.png') }}">
<meta name="theme-color" content="#1557c2">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="PayGrid Toko">
<meta name="mobile-web-app-capable" content="yes">
<style>
    :root { --blue:#1557c2; --ink:#06162f; --muted:#55657a; --line:#dbe5f2; --bg:#f5f8fc; --danger:#c62828; }
    * { box-sizing:border-box; }
    body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; font-family:"Plus Jakarta Sans", Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Arial, sans-serif; background:radial-gradient(circle at top right, #eaf2ff 0, transparent 34%), var(--bg); color:var(--ink); }
    .card { width:100%; max-width:360px; background:#fff; border:1px solid var(--line); border-radius:16px; padding:28px 24px; box-shadow:0 20px 44px rgba(14,35,70,.08); }
    .card img { width:56px; height:56px; display:block; margin:0 auto 10px; }
    h1 { font-size:17px; text-align:center; margin:0 0 2px; }
    .sub { text-align:center; color:var(--muted); font-size:12.5px; margin:0 0 22px; }
    label { display:block; font-size:12px; font-weight:800; color:var(--muted); margin:14px 0 6px; }
    input { width:100%; padding:12px 14px; border:1px solid var(--line); border-radius:10px; font-size:15px; font-family:inherit; background:#fbfdff; }
    input:focus { outline:2px solid var(--blue); outline-offset:1px; }
    button { width:100%; margin-top:22px; padding:13px; border:none; border-radius:10px; background:var(--blue); color:#fff; font-size:15px; font-weight:800; cursor:pointer; }
    button:active { opacity:.85; }
    .error { margin-top:14px; padding:10px 12px; border-radius:8px; background:#fdecec; color:var(--danger); font-size:12.5px; font-weight:700; }
</style>
</head>
<body>
    <div class="card">
        <img src="{{ asset('images/mobile-icon-192.png') }}" alt="PayGrid">
        <h1>PayGrid Toko</h1>
        <p class="sub">Login pakai akun PayGrid Anda</p>

        <form method="post" action="{{ route('mobile.login.attempt') }}">
            @csrf
            <label for="email">Email / Username</label>
            <input type="text" name="email" id="email" value="{{ old('email') }}" required autocomplete="username" placeholder="email@domain.com atau username">

            <label for="password">Password</label>
            <input type="password" name="password" id="password" required autocomplete="current-password">

            <button type="submit">Masuk</button>
        </form>

        @if($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif
    </div>
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/js/mobile-sw.js');
        }
    </script>
</body>
</html>
