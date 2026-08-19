<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->intended($this->homeFor(Auth::user()->role)) : view('paygrid.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'string'], 'password' => ['required', 'string']]);
        $login = Str::lower($credentials['email']);
        $throttleKey = $login.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors(['email' => "Terlalu banyak percobaan login. Coba lagi {$seconds} detik."])->onlyInput('email');
        }

        $candidates = User::query()
            ->whereRaw('LOWER(email) = ?', [$login])
            ->orWhereRaw('LOWER(username) = ?', [$login])
            ->get();
        $user = $candidates->first(fn (User $candidate): bool => $this->passwordMatches($credentials['password'], (string) $candidate->password));

        if (! $user) {
            RateLimiter::hit($throttleKey, 60);
            $this->auditAuth('auth.login_failed', $user, $request, ['login' => $credentials['email']]);

            return back()->withErrors(['email' => 'Email atau password tidak valid.'])->onlyInput('email');
        }

        Auth::login($user, $request->boolean('remember'));

        $request->session()->regenerate();
        RateLimiter::clear($throttleKey);

        if (! $request->user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors(['email' => 'Akun nonaktif. Hubungi admin.'])->onlyInput('email');
        }

        if (in_array($request->user()->role, ['cs', 'finance', 'admin', 'readonly_admin', 'readonly_cs'], true) && ! $request->user()->merchant_id) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors(['email' => 'Akun belum terhubung ke merchant. Hubungi admin.'])->onlyInput('email');
        }

        $this->auditAuth('auth.login_success', $request->user(), $request);

        if (in_array($request->user()->role, ['cs', 'finance', 'admin', 'readonly_admin', 'readonly_cs'], true)) {
            return redirect($this->homeFor($request->user()->role));
        }

        return redirect()->intended($this->homeFor($request->user()->role));
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($request->user()) {
            $this->auditAuth('auth.logout', $request->user(), $request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function homeFor(string $role): string
    {
        $user = Auth::user();

        return match ($role) {
            'superadmin' => route('superadmin.overview'),
            'cs_pusat' => route('center-support.tickets'),
            'agent' => route('agent.overview'),
            'admin' => $user?->merchant ? route('merchant.admin.users', $user->merchant) : route('login'),
            'readonly_admin' => $user?->merchant ? route('merchant.admin.users', $user->merchant) : route('login'),
            'ma' => route('ma.overview'),
            'cs' => $user?->merchant ? route('merchant.cs.tickets', $user->merchant) : route('login'),
            'readonly_cs' => $user?->merchant ? route('merchant.cs.tickets', $user->merchant) : route('login'),
            'finance' => $user?->merchant ? route('merchant.finance.overview', $user->merchant) : route('login'),
            default => route('login'),
        };
    }

    private function passwordMatches(string $plain, string $hash): bool
    {
        try {
            return Hash::check($plain, $hash);
        } catch (\RuntimeException) {
            return password_verify($plain, $hash);
        }
    }

    private function auditAuth(string $action, ?User $user, Request $request, array $payload = []): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $user?->id,
            'actor_role' => $user?->role,
            'action' => $action,
            'target_type' => User::class,
            'target_id' => $user?->id,
            'after_payload' => $payload ?: ['email' => $user?->email],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }
}
