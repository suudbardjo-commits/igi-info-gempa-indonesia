<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akun yang kata sandinya baru diatur ulang operator hanya boleh dipakai untuk menggantinya. Halaman konsol dan
 * lapangan dialihkan ke /akun, dan API menjawab 403 dengan kode yang bisa dikenali klien.
 */
class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum') ?? $request->user();

        if ($user && $user->must_change_password) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Ganti kata sandi sementara Anda dulu di halaman Akun.',
                    'code' => 'password_change_required',
                ], 403);
            }

            return redirect('/akun');
        }

        return $next($request);
    }
}
