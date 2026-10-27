<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LogistikController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\PanduanController;
use App\Http\Controllers\RegisterController;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('beranda');
Route::get('peta', MapController::class)->name('peta');
Route::get('cuaca', \App\Http\Controllers\WeatherPageController::class)->name('cuaca');
Route::get('berita', [NewsController::class, 'index'])->name('berita');
Route::get('berita/{id}', [NewsController::class, 'show'])->name('berita.show');
Route::get('panduan', PanduanController::class)->name('panduan');
Route::get('tentang', AboutController::class)->name('tentang');
Route::get('feed.xml', FeedController::class)->name('feed');

Route::get('masuk', [SessionController::class, 'create'])->name('login');
Route::post('masuk', [SessionController::class, 'store'])->middleware('throttle:30,1,masuk');
Route::post('keluar', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');

// Pengajuan akun petugas: terbuka untuk umum, tetapi akun baru tidak punya akses sampai disetujui operator.
Route::get('daftar', [RegisterController::class, 'create'])->name('daftar');
Route::post('daftar', [RegisterController::class, 'store'])->middleware('throttle:6,60,daftar');
Route::get('daftar/terkirim', [RegisterController::class, 'done'])->name('daftar.terkirim');

// Manifes PWA. Lewat rute (bukan berkas statis) supaya jenis kontennya pasti application/manifest+json.
Route::get('manifest.webmanifest', fn () => response()->json([
    'id' => '/lapangan',
    'name' => 'IGI - Info Gempa Indonesia',
    'short_name' => 'IGI',
    'description' => 'Info gempa Indonesia, dan laporan lapangan posko pengungsian yang tetap bisa dipakai tanpa sinyal.',
    'lang' => 'id',
    'start_url' => '/lapangan',
    'scope' => '/',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'background_color' => '#0A1630',
    'theme_color' => '#0A1630',
    'icons' => [
        ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], 200, ['Content-Type' => 'application/manifest+json'])->setPublic()->setMaxAge(3600));

// Aplikasi lapangan (PWA, bisa dipakai offline) untuk koordinator posko dan relawan.
Route::get('lapangan', fn () => Inertia\Inertia::render('Lapangan'))->middleware(['auth', RequirePasswordChange::class, 'can:field-work'])->name('lapangan');

// Halaman akun untuk semua pengguna yang login: peringatan posko yang diampu dan penautan Telegram.
Route::get('akun', fn () => Inertia\Inertia::render('Akun'))->middleware('auth')->name('akun');

// Konsol logistik untuk pengelola gudang dan petugas koordinasi.
Route::middleware(['auth', RequirePasswordChange::class, 'can:access-logistics'])->prefix('logistik')->group(function () {
    Route::get('/', [LogistikController::class, 'permintaan'])->name('logistik');
    Route::get('pengiriman', [LogistikController::class, 'pengiriman'])->name('logistik.pengiriman');
    Route::get('stok', [LogistikController::class, 'stok'])->name('logistik.stok');
    Route::get('laporan', [LogistikController::class, 'laporan'])->name('logistik.laporan');
    Route::get('petugas', [LogistikController::class, 'petugas'])->name('logistik.petugas');
    Route::get('pengumuman', [LogistikController::class, 'pengumuman'])->name('logistik.pengumuman');
    Route::get('audit', [LogistikController::class, 'audit'])->name('logistik.audit');
});
