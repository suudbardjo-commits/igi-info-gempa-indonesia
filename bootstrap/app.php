<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Autentikasi channel private di /api/broadcasting/auth. Grup 'api' + Sanctum menerima sesi peramban
    // (konsol dan halaman akun) maupun token Bearer (aplikasi lapangan).
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            // Sesi peramban terputus bila kata sandi akunnya berubah (ganti sendiri atau diatur ulang operator).
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // Konsol di peramban memakai API lewat cookie sesi; aplikasi lapangan tetap memakai token.
        $middleware->statefulApi();

        // Di belakang terowongan https (Cloudflare Tunnel) permintaan tiba sebagai http dari proksi lokal; percayai header
        // X-Forwarded-* agar tautan, pengalihan, dan cookie memakai https. Server hanya dijangkau lewat proksi/terowongan.
        $middleware->trustProxies(at: '*');

        $middleware->redirectGuestsTo('/masuk');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Error domain logistik dipetakan ke kode HTTP yang bisa ditindaklanjuti klien.
        $exceptions->render(fn (\App\Exceptions\DispatchStateException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (\App\Exceptions\InsufficientStockException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (\App\Exceptions\IdConflictException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (\App\Exceptions\GeofenceViolationException $e) => response()->json([
            'message' => $e->getMessage(),
            'distance_m' => round($e->distanceMeters),
            'allowed_m' => $e->allowedMeters,
        ], 422));
        $exceptions->render(function (\InvalidArgumentException $e, Request $request) {
            // Hanya untuk API: input tidak valid dari service (jumlah, rencana, item tidak dikenal).
            return $request->is('api/*') ? response()->json(['message' => $e->getMessage()], 422) : null;
        });
    })->create();
