<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Login berbasis sesi untuk konsol logistik di peramban. (Aplikasi lapangan memakai token API.) */
class SessionController extends Controller
{
    public function create(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->to($this->home());
        }

        return Inertia::render('Masuk');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.",
            ]);
        }

        $user = User::whereRaw('lower(email) = ?', [Str::lower($credentials['email'])])->first();

        // Pembanding tetap dijalankan walau email tidak ada, supaya waktu respons tidak membocorkan email terdaftar.
        $valid = Hash::check($credentials['password'], $user->password ?? Hash::make(Str::random(40)));

        if (! $user || ! $valid) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages(['email' => 'Email atau kata sandi salah.']);
        }

        RateLimiter::clear($throttleKey);

        // Status akun baru diberitahukan setelah kata sandi terbukti benar, supaya halaman ini tidak membocorkan
        // siapa yang punya akun.
        if (! $user->isApproved()) {
            throw ValidationException::withMessages(['email' => $user->statusMessage()]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        // Halaman tujuan sebelumnya diabaikan bila kata sandi harus diganti dulu.
        return $user->must_change_password ? redirect()->to($this->home()) : redirect()->intended($this->home());
    }

    /** Petugas logistik ke konsol; koordinator dan relawan ke aplikasi lapangan; selebihnya ke halaman Akun. */
    private function home(): string
    {
        return match (true) {
            // Kata sandi sementara: satu-satunya tujuan yang berguna adalah halaman untuk menggantinya.
            (bool) Auth::guard('web')->user()?->must_change_password => route('akun'),
            Gate::allows('access-logistics') => route('logistik'),
            Gate::allows('field-work') => route('lapangan'),
            default => route('akun'),
        };
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
