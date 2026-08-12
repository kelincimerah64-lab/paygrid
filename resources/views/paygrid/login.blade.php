<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | PayGrid Laravel</title>
    <style>
        * { box-sizing:border-box; }
        body { margin:0; font-family:Arial, sans-serif; background:#f4f7fb; color:#071832; }
        .login { min-height:100vh; display:grid; place-items:center; padding:24px; }
        .card { width:min(720px, 100%); background:#fff; border:1px solid #dce5f2; border-radius:10px; padding:34px; box-shadow:0 8px 30px rgba(12,31,62,.08); }
        .brand { text-align:center; font-weight:900; font-size:34px; margin-bottom:10px; }
        .brand span { color:#d65519; }
        h1 { text-align:center; margin:0 0 8px; }
        p { text-align:center; color:#526174; margin:0 0 28px; }
        label { display:block; font-weight:700; margin-top:16px; }
        input { width:100%; border:1px solid #c9d6ea; border-radius:8px; padding:14px; font:inherit; margin-top:8px; }
        button { width:100%; margin-top:22px; border:0; border-radius:8px; padding:15px; background:#1557c2; color:white; font-weight:800; font-size:16px; }
        .password-wrap { position:relative; display:block; width:100%; margin-top:8px; }
        .password-wrap input { margin-top:0; padding-right:52px; }
        .toggle-password { position:absolute; right:8px; top:50%; transform:translateY(-50%); width:36px; height:36px; margin:0; padding:0; border:1px solid #d4dfef; border-radius:7px; background:#f8fbff; color:#1557c2; display:grid; place-items:center; cursor:pointer; }
        .toggle-password svg { width:18px; height:18px; }
    </style>
</head>
<body>
<main class="login">
    <section class="card">
        <div class="brand">PAY<span>GRID</span></div>
        <h1>PayGrid Dashboard</h1>
        <p>Masuk ke dashboard PayGrid sesuai role akun Anda.</p>
        @if($errors->any())<div style="color:#c62828; margin-bottom:12px">{{ $errors->first() }}</div>@endif
        <form action="{{ route('login.store') }}" method="post">
            @csrf
            <label>Email<input name="email" type="email" value="{{ old('email') }}" autocomplete="email" required placeholder="email@domain.com"></label>
            <label>Password
                <span class="password-wrap">
                    <input id="login-password" name="password" type="password" autocomplete="current-password" required placeholder="Masukkan password">
                    <button class="toggle-password" type="button" aria-label="Tampilkan password" data-toggle-password="login-password">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </span>
            </label>
            <label style="display:flex; align-items:center; gap:8px; font-weight:600"><input name="remember" type="checkbox" value="1" style="width:auto; margin:0"> Ingat saya</label>
            <button>Login</button>
        </form>
    </section>
</main>
<script>
    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.togglePassword);
            if (!input) return;
            input.type = input.type === 'password' ? 'text' : 'password';
        });
    });
</script>
</body>
</html>
